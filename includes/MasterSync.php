<?php
/**
 * Sincronizzazione con il sito Master FP Updater
 *
 * Il Bridge (sul sito client) contatta periodicamente il Master per verificare
 * se ci sono aggiornamenti. Se sì, esegue check + update localmente tramite FP Updater o PluginInstaller.
 *
 * @package FP\RemoteBridge
 */

namespace FP\RemoteBridge;

if (!defined('ABSPATH')) {
    exit;
}

class MasterSync
{
    public const OPTION_MASTER_URL = 'fp_remote_bridge_master_url';
    public const OPTION_MASTER_SECRET = 'fp_remote_bridge_master_secret';
    public const OPTION_SYNC_INTERVAL = 'fp_remote_bridge_sync_interval';
    public const CRON_HOOK = 'fp_remote_bridge_master_sync';

    /**
     * Inizializza cron e hook
     */
    public static function init(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'run_sync']);
        add_action('admin_init', [self::class, 'ensure_cron_scheduled']);
        add_action('update_option_' . self::OPTION_MASTER_URL, [self::class, 'reschedule_cron']);
        add_action('update_option_' . self::OPTION_MASTER_SECRET, [self::class, 'reschedule_cron']);
        add_action('update_option_' . self::OPTION_SYNC_INTERVAL, [self::class, 'reschedule_cron']);
    }

    /**
     * Verifica che il cron sia schedulato (admin_init). Non rischedula se gia attivo con lo stesso intervallo.
     */
    public static function ensure_cron_scheduled(): void
    {
        $url = get_option(self::OPTION_MASTER_URL, '');
        $secret = get_option(self::OPTION_MASTER_SECRET, '');
        if (empty($url) || empty($secret)) {
            self::unschedule_cron();
            return;
        }

        $interval = get_option(self::OPTION_SYNC_INTERVAL, 'hourly');

        if (wp_next_scheduled(self::CRON_HOOK)) {
            $current = wp_get_schedule(self::CRON_HOOK);
            if ($current === $interval) {
                return;
            }
        }

        self::unschedule_cron();
        wp_schedule_event(time() + 60, $interval, self::CRON_HOOK);
    }

    /**
     * Forza rischedulazione (chiamato su cambio opzioni)
     */
    public static function reschedule_cron(): void
    {
        self::unschedule_cron();
        self::ensure_cron_scheduled();
    }

    public static function unschedule_cron(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Recupera lo stato aggiornamenti dal Master.
     *
     * @return array{ok: bool, data?: array, error?: string, body?: string}
     */
    private static function fetch_master_status(): array
    {
        $url = get_option(self::OPTION_MASTER_URL, '');
        $secret = get_option(self::OPTION_MASTER_SECRET, '');

        if (empty($url) || empty($secret)) {
            return ['ok' => false, 'error' => 'Master non configurato'];
        }

        $endpoint = rtrim($url, '/');
        if (strpos($endpoint, '/wp-json/') === false) {
            $endpoint .= '/wp-json/fp-git-updater/v1/master-updates-status';
        } elseif (strpos($endpoint, 'master-updates-status') === false) {
            $endpoint = preg_replace('#/wp-json/.*$#', '/wp-json/fp-git-updater/v1/master-updates-status', $endpoint);
        }

        // client_id e secret PRIMA (essenziali) - installed_plugins può essere lunghissimo
        $client_id = self::get_client_identifier();
        $args_query = [
            'secret' => $secret,
            'client_id' => $client_id,
            '_t' => time(), // cache-busting: evita risposta in cache
        ];
        $installed_slugs = self::get_installed_plugin_slugs();
        if (!empty($installed_slugs)) {
            $plugins_str = implode(',', array_slice($installed_slugs, 0, 80)); // max 80 per evitare URL troppo lunga
            if (strlen($plugins_str) > 1500) {
                $plugins_str = substr($plugins_str, 0, 1500); // taglia se eccede
            }
            $args_query['installed_plugins'] = $plugins_str;
        }
        $endpoint = add_query_arg($args_query, $endpoint);

        $response = wp_remote_get($endpoint, [
            'timeout' => 30,
            'headers' => [
                'X-FP-Client-Secret' => $secret,
                'X-FP-Client-ID' => self::get_client_identifier(),
            ],
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200) {
            return ['ok' => false, 'error' => 'HTTP ' . $code, 'body' => $body];
        }

        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Risposta non valida'];
        }

        return ['ok' => true, 'data' => $data];
    }

    /**
     * Esegue il polling verso il Master e, se ci sono aggiornamenti, triggera FP Updater o PluginInstaller
     */
    public static function run_sync(): void
    {
        $result = self::fetch_master_status();
        if (!$result['ok']) {
            return;
        }

        $data = $result['data'];
        if (empty($data['updates_available'])) {
            return;
        }

        // I client installano SOLO se il Master ha autorizzato il deploy (pulsante "Distribuisci ai client")
        if (empty($data['deploy_authorized'])) {
            return;
        }

        $plugins = $data['plugins'] ?? [];

        if (class_exists('FP\GitUpdater\Updater')) {
            $updater = \FP\GitUpdater\Updater::get_instance();
            $updater->check_for_updates();
            // Aggiorna SOLO i plugin restituiti dal Master (già filtrati per selezione)
            foreach ($plugins as $plugin) {
                $plugin_id = $plugin['id'] ?? $plugin['slug'] ?? '';
                if (!empty($plugin_id)) {
                    $updater->run_update_by_id($plugin_id);
                }
            }
            return;
        }

        // Senza FP Updater: Bridge installa direttamente
        foreach ($plugins as $plugin) {
            if (empty($plugin['github_repo']) && empty($plugin['zip_url'] ?? '')) {
                continue;
            }
            PluginInstaller::install_from_master($plugin);
        }
    }

    /**
     * Esegue sync manualmente (per chiamata admin o endpoint)
     *
     * @param bool $install Se false, non installa (solo controlla e restituisce status)
     */
    public static function run_manual_sync(bool $install = true): array
    {
        $result = self::fetch_master_status();
        if (!$result['ok']) {
            $out = ['success' => false, 'error' => $result['error'] ?? 'Errore sconosciuto'];
            if (!empty($result['body'])) {
                $out['body'] = $result['body'];
            }
            return $out;
        }

        $data = $result['data'];
        $updates_available = !empty($data['updates_available']);
        $deploy_authorized = !empty($data['deploy_authorized']);
        $plugins = $data['plugins'] ?? [];
        $installed = [];

        // I client installano SOLO se il Master ha autorizzato il deploy
        if ($updates_available && $deploy_authorized && $install) {
            if (class_exists('FP\GitUpdater\Updater')) {
                $updater = \FP\GitUpdater\Updater::get_instance();
                $updater->check_for_updates();
                foreach ($plugins as $plugin) {
                    $plugin_id = $plugin['id'] ?? $plugin['slug'] ?? '';
                    if (!empty($plugin_id)) {
                        $updater->run_update_by_id($plugin_id);
                    }
                }
            } else {
                foreach ($plugins as $plugin) {
                    if (!empty($plugin['github_repo']) || !empty($plugin['zip_url'] ?? '')) {
                        $result = PluginInstaller::install_from_master($plugin);
                        $installed[$plugin['slug'] ?? $plugin['id'] ?? '?'] = $result === true ? 'ok' : ($result['error'] ?? 'error');
                    }
                }
            }
        }

        return [
            'success' => true,
            'updates_available' => $updates_available,
            'deploy_authorized' => $deploy_authorized,
            'pending_count' => $data['pending_count'] ?? 0,
            'plugins' => $plugins,
            'installed_by_bridge' => $installed,
        ];
    }

    /**
     * Restituisce gli slug dei plugin installati nel formato "slug:version".
     * Usato per inviare al Master la lista "chi ha cosa" con le versioni installate.
     *
     * @return array<string>
     */
    public static function get_installed_plugin_slugs(): array
    {
        $entries = [];

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (function_exists('get_plugins')) {
            $all = get_plugins();
            foreach ($all as $path => $data) {
                if (strpos($path, '/') !== false) {
                    $slug = strtolower(dirname($path));
                    $version = isset($data['Version']) ? $data['Version'] : '';
                    $entries[$slug] = $slug . (!empty($version) ? ':' . $version : '');
                }
            }
        }

        return array_values(array_unique(array_filter($entries)));
    }

    /**
     * Identificativo inviato al Master per la lista "Client collegati".
     * Usa l'identificativo backup se configurato, altrimenti l'URL del sito.
     */
    public static function get_client_identifier(): string
    {
        $id = get_option(BackupSync::OPTION_BACKUP_CLIENT_ID, '');
        if (!empty($id) && is_string($id)) {
            return sanitize_text_field($id);
        }
        $url = site_url();
        $host = parse_url($url, PHP_URL_HOST);
        return $host ?: (is_string($url) ? $url : 'client');
    }
}
