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
        add_filter('cron_schedules', [self::class, 'add_cron_intervals']);
        add_action(self::CRON_HOOK, [self::class, 'run_sync']);
        // Verifica cron su admin_init (contesto admin) e su wp_loaded (contesto frontend/cron)
        add_action('admin_init', [self::class, 'ensure_cron_scheduled']);
        if (!is_admin()) {
            add_action('wp_loaded', [self::class, 'ensure_cron_scheduled']);
        }
        add_action('update_option_' . self::OPTION_MASTER_URL, [self::class, 'reschedule_cron']);
        add_action('update_option_' . self::OPTION_MASTER_SECRET, [self::class, 'reschedule_cron']);
        add_action('update_option_' . self::OPTION_SYNC_INTERVAL, [self::class, 'reschedule_cron']);
    }

    /**
     * Aggiunge intervalli cron personalizzati
     */
    public static function add_cron_intervals(array $schedules): array
    {
        if (!isset($schedules['every_minute'])) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => __('Ogni minuto', 'fp-remote-bridge'),
            ];
        }
        if (!isset($schedules['every_5_minutes'])) {
            $schedules['every_5_minutes'] = [
                'interval' => 300,
                'display'  => __('Ogni 5 minuti', 'fp-remote-bridge'),
            ];
        }
        return $schedules;
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

        $interval = get_option(self::OPTION_SYNC_INTERVAL, 'every_minute');

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

        $endpoint = self::build_master_endpoint('fp-git-updater/v1/master-updates-status');
        if ($endpoint === null) {
            return ['ok' => false, 'error' => 'URL Master non valido'];
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
            // Costruisce la stringa rispettando il limite senza troncare a metà uno slug
            $chunks      = [];
            $total_len   = 0;
            $max_len     = 1500;
            foreach (array_slice($installed_slugs, 0, 80) as $slug_entry) {
                $piece = empty($chunks) ? $slug_entry : ',' . $slug_entry;
                if ($total_len + strlen($piece) > $max_len) {
                    break;
                }
                $chunks[]   = $slug_entry;
                $total_len += strlen($piece);
            }
            if (!empty($chunks)) {
                $args_query['installed_plugins'] = implode(',', $chunks);
            }
        }
        $endpoint = add_query_arg($args_query, $endpoint);

        $response = wp_remote_get($endpoint, [
            'timeout' => 30,
            'headers' => [
                'X-FP-Client-Secret' => $secret,
                'X-FP-Client-ID'     => $client_id,
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
     * Esegue il polling verso il Master e, se ci sono aggiornamenti, triggera FP Updater o PluginInstaller.
     * Usa un transient lock per evitare esecuzioni concorrenti (cron ogni minuto + traffico HTTP).
     */
    public static function run_sync(): void
    {
        $lock_key = 'fp_bridge_sync_lock';
        if (get_transient($lock_key)) {
            return; // già in esecuzione
        }
        set_transient($lock_key, 1, 90); // lock per max 90 secondi

        try {
            self::do_sync();
        } finally {
            delete_transient($lock_key);
        }
    }

    private static function do_sync(): void
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

        foreach ($plugins as $plugin) {
            $has_source = !empty($plugin['github_repo']) || !empty($plugin['zip_url'] ?? '');

            // Se il plugin ha una sorgente diretta (github_repo o zip_url), installa/aggiorna
            // direttamente tramite PluginInstaller — funziona sia per nuove installazioni
            // che per aggiornamenti di plugin già presenti, indipendentemente da FP Updater.
            if ($has_source) {
                PluginInstaller::install_from_master($plugin);
                continue;
            }

            // Per plugin senza sorgente diretta (gestiti solo da FP Updater locale), usa l'Updater
            if (class_exists('FP\GitUpdater\Updater')) {
                $plugin_id = $plugin['id'] ?? $plugin['slug'] ?? '';
                if (!empty($plugin_id)) {
                    $updater = \FP\GitUpdater\Updater::get_instance();
                    $updater->check_for_updates();
                    $updater->run_update_by_id($plugin_id);
                }
            }
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
            foreach ($plugins as $plugin) {
                $has_source = !empty($plugin['github_repo']) || !empty($plugin['zip_url'] ?? '');

                if ($has_source) {
                    $res = PluginInstaller::install_from_master($plugin);
                    $installed[$plugin['slug'] ?? $plugin['id'] ?? '?'] = $res === true ? 'ok' : ($res['error'] ?? 'error');
                    continue;
                }

                if (class_exists('FP\GitUpdater\Updater')) {
                    $plugin_id = $plugin['id'] ?? $plugin['slug'] ?? '';
                    if (!empty($plugin_id)) {
                        $updater = \FP\GitUpdater\Updater::get_instance();
                        $updater->check_for_updates();
                        $updater->run_update_by_id($plugin_id);
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
     * Costruisce un endpoint REST sicuro a partire dall'URL Master configurato.
     * Estrae schema + host + sottocartella WP, ignora qualsiasi path REST esistente.
     *
     * @param string $rest_path Percorso REST dopo /wp-json/ (es. "fp-git-updater/v1/receive-backup")
     * @return string|null URL completo oppure null se l'URL è malformato
     */
    public static function build_master_endpoint(string $rest_path): ?string
    {
        $url = get_option(self::OPTION_MASTER_URL, '');
        if (empty($url)) {
            return null;
        }
        $parsed = parse_url(rtrim($url, '/'));
        if ($parsed === false || empty($parsed['host'])) {
            return null;
        }
        $base = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'];
        if (!empty($parsed['port'])) {
            $base .= ':' . $parsed['port'];
        }
        $path = $parsed['path'] ?? '';
        $wp_json_pos = strpos($path, '/wp-json/');
        if ($wp_json_pos !== false) {
            $path = substr($path, 0, $wp_json_pos);
        }
        return $base . rtrim($path, '/') . '/wp-json/' . ltrim($rest_path, '/');
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
