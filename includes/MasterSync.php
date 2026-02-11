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
        add_action('admin_init', [self::class, 'maybe_schedule_cron']);
        add_action('update_option_' . self::OPTION_MASTER_URL, [self::class, 'maybe_schedule_cron']);
        add_action('update_option_' . self::OPTION_MASTER_SECRET, [self::class, 'maybe_schedule_cron']);
        add_action('update_option_' . self::OPTION_SYNC_INTERVAL, [self::class, 'maybe_schedule_cron']);
    }

    /**
     * Schedula il cron se configurato; riesegue su cambio opzioni per rispettare nuovo intervallo
     */
    public static function maybe_schedule_cron(): void
    {
        $url = get_option(self::OPTION_MASTER_URL, '');
        $secret = get_option(self::OPTION_MASTER_SECRET, '');
        if (empty($url) || empty($secret)) {
            self::unschedule_cron();
            return;
        }

        $interval = get_option(self::OPTION_SYNC_INTERVAL, 'hourly');
        self::unschedule_cron();
        wp_schedule_event(time() + 60, $interval, self::CRON_HOOK);
    }

    public static function unschedule_cron(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Esegue il polling verso il Master e, se ci sono aggiornamenti, triggera FP Updater o PluginInstaller
     */
    public static function run_sync(): void
    {
        $url = get_option(self::OPTION_MASTER_URL, '');
        $secret = get_option(self::OPTION_MASTER_SECRET, '');

        if (empty($url) || empty($secret)) {
            return;
        }

        $endpoint = rtrim($url, '/');
        if (strpos($endpoint, '/wp-json/') === false) {
            $endpoint .= '/wp-json/fp-git-updater/v1/master-updates-status';
        } elseif (strpos($endpoint, 'master-updates-status') === false) {
            $endpoint = preg_replace('#/wp-json/.*$#', '/wp-json/fp-git-updater/v1/master-updates-status', $endpoint);
        }

        $response = wp_remote_get($endpoint, [
            'timeout' => 30,
            'headers' => [
                'X-FP-Client-Secret' => $secret,
            ],
        ]);

        if (is_wp_error($response)) {
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['updates_available'])) {
            return;
        }

        $plugins = $data['plugins'] ?? [];

        if (class_exists('FP\GitUpdater\Updater')) {
            $updater = \FP\GitUpdater\Updater::get_instance();
            $updater->check_for_updates();
            $updater->run_update(null, null);
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
        $url = get_option(self::OPTION_MASTER_URL, '');
        $secret = get_option(self::OPTION_MASTER_SECRET, '');

        if (empty($url) || empty($secret)) {
            return ['success' => false, 'error' => 'Master non configurato'];
        }

        $endpoint = rtrim($url, '/');
        if (strpos($endpoint, '/wp-json/') === false) {
            $endpoint .= '/wp-json/fp-git-updater/v1/master-updates-status';
        } elseif (strpos($endpoint, 'master-updates-status') === false) {
            $endpoint = preg_replace('#/wp-json/.*$#', '/wp-json/fp-git-updater/v1/master-updates-status', $endpoint);
        }

        $response = wp_remote_get($endpoint, [
            'timeout' => 30,
            'headers' => [
                'X-FP-Client-Secret' => $secret,
            ],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200) {
            return ['success' => false, 'error' => 'HTTP ' . $code, 'body' => $body];
        }

        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Risposta non valida'];
        }

        $updates_available = !empty($data['updates_available']);
        $plugins = $data['plugins'] ?? [];
        $installed = [];

        if ($updates_available && $install) {
            if (class_exists('FP\GitUpdater\Updater')) {
                $updater = \FP\GitUpdater\Updater::get_instance();
                $updater->check_for_updates();
                $updater->run_update(null, null);
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
            'pending_count' => $data['pending_count'] ?? 0,
            'plugins' => $plugins,
            'installed_by_bridge' => $installed,
        ];
    }
}
