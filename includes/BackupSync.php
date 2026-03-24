<?php
/**
 * Sincronizzazione backup verso il Master
 *
 * Cron che crea backup locali e li invia al sito Master.
 *
 * @package FP\RemoteBridge
 */

namespace FP\RemoteBridge;

if (!defined('ABSPATH')) {
    exit;
}

class BackupSync
{
    public const OPTION_BACKUP_ENABLED = 'fp_remote_bridge_backup_enabled';
    public const OPTION_BACKUP_INTERVAL = 'fp_remote_bridge_backup_interval';
    public const OPTION_BACKUP_CLIENT_ID = 'fp_remote_bridge_backup_client_id';
    public const CRON_HOOK = 'fp_remote_bridge_backup_sync';

    /**
     * Inizializza cron e hook
     */
    public static function init(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'run_backup']);
        add_action('admin_init', [self::class, 'ensure_cron_scheduled']);
        if (!is_admin()) {
            add_action('wp_loaded', [self::class, 'ensure_cron_scheduled']);
        }
        add_action('update_option_' . self::OPTION_BACKUP_ENABLED, [self::class, 'reschedule_cron']);
        add_action('update_option_' . self::OPTION_BACKUP_INTERVAL, [self::class, 'reschedule_cron']);
        add_action('update_option_' . MasterSync::OPTION_MASTER_URL, [self::class, 'reschedule_cron']);
        add_action('update_option_' . MasterSync::OPTION_MASTER_SECRET, [self::class, 'reschedule_cron']);
    }

    /**
     * Verifica che il cron sia schedulato (admin_init). Non rischedula se gia attivo con lo stesso intervallo.
     */
    public static function ensure_cron_scheduled(): void
    {
        if (!get_option(self::OPTION_BACKUP_ENABLED, false)) {
            self::unschedule_cron();
            return;
        }

        $url = get_option(MasterSync::OPTION_MASTER_URL, '');
        $secret = get_option(MasterSync::OPTION_MASTER_SECRET, '');
        if (empty($url) || empty($secret)) {
            self::unschedule_cron();
            return;
        }

        $interval = get_option(self::OPTION_BACKUP_INTERVAL, 'daily');

        if (wp_next_scheduled(self::CRON_HOOK)) {
            $current = wp_get_schedule(self::CRON_HOOK);
            if ($current === $interval) {
                return;
            }
        }

        self::unschedule_cron();
        wp_schedule_event(time() + 120, $interval, self::CRON_HOOK);
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
     * Esegue il backup e lo invia al Master
     */
    public static function run_backup(): void
    {
        $result = self::create_and_upload();
        // Elimina il file temporaneo solo se l'upload è riuscito.
        // In caso di errore il file rimane per un eventuale retry manuale.
        if (!empty($result['path']) && !empty($result['success'])) {
            BackupService::cleanup_temp();
        }
    }

    /**
     * Crea il backup e lo carica sul Master
     *
     * @return array{success: bool, path?: string, size?: int, uploaded?: bool, error?: string}
     */
    public static function create_and_upload(): array
    {
        $url = get_option(MasterSync::OPTION_MASTER_URL, '');
        $secret = get_option(MasterSync::OPTION_MASTER_SECRET, '');

        if (empty($url) || empty($secret)) {
            return ['success' => false, 'error' => 'Master non configurato'];
        }

        $result = BackupService::create_backup([]);
        if (!empty($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        if (empty($result['path']) || !is_file($result['path'])) {
            return ['success' => false, 'error' => 'File backup non creato'];
        }

        $upload = self::upload_to_master($result['path']);
        return [
            'success' => $upload['success'],
            'path' => $result['path'],
            'size' => $result['size'] ?? 0,
            'uploaded' => $upload['success'],
            'error' => $upload['error'] ?? null,
        ];
    }

    /**
     * Carica un file backup sul Master
     *
     * @param string $file_path
     * @return array{success: bool, error?: string}
     */
    public static function upload_to_master(string $file_path): array
    {
        $url = get_option(MasterSync::OPTION_MASTER_URL, '');
        $secret = get_option(MasterSync::OPTION_MASTER_SECRET, '');
        $client_id = get_option(self::OPTION_BACKUP_CLIENT_ID, '');

        if (empty($url) || empty($secret)) {
            return ['success' => false, 'error' => 'Master non configurato'];
        }

        if (!is_file($file_path) || !is_readable($file_path)) {
            return ['success' => false, 'error' => 'File non leggibile'];
        }

        $file_size = filesize($file_path);
        $memory_limit = self::get_memory_limit_bytes();
        $memory_used = memory_get_usage(true);
        $available = $memory_limit - $memory_used;

        if ($file_size > $available * 0.8) {
            return self::upload_via_curl($file_path, $url, $secret, $client_id);
        }

        $endpoint = MasterSync::build_master_endpoint('fp-git-updater/v1/receive-backup');
        if ($endpoint === null) {
            return ['success' => false, 'error' => 'URL Master non valido'];
        }

        if (empty($client_id)) {
            $client_id = sanitize_file_name(parse_url(site_url(), PHP_URL_HOST) ?: 'client');
        }

        $safe_filename = sanitize_file_name(basename($file_path));
        $boundary = wp_generate_password(24, false);
        $body = '';
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="client_id"' . "\r\n\r\n";
        $body .= $client_id . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . $safe_filename . '"' . "\r\n";
        $body .= 'Content-Type: application/zip' . "\r\n\r\n";
        $body .= file_get_contents($file_path) . "\r\n";
        $body .= '--' . $boundary . '--';

        $response = wp_remote_post($endpoint, [
            'timeout' => 600,
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                'X-FP-Client-Secret' => $secret,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_resp = wp_remote_retrieve_body($response);
        $data = json_decode($body_resp, true);

        if ($code !== 200) {
            $msg = is_array($data) && !empty($data['message']) ? $data['message'] : 'HTTP ' . $code;
            return ['success' => false, 'error' => $msg];
        }

        if (is_array($data) && !empty($data['success'])) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => $data['message'] ?? 'Risposta non valida'];
    }

    /**
     * Upload via cURL nativo per file grandi (evita di caricare tutto in memoria)
     */
    private static function upload_via_curl(string $file_path, string $url, string $secret, string $client_id): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'cURL non disponibile e file troppo grande per wp_remote_post'];
        }

        $endpoint = MasterSync::build_master_endpoint('fp-git-updater/v1/receive-backup');
        if ($endpoint === null) {
            return ['success' => false, 'error' => 'URL Master non valido'];
        }

        if (empty($client_id)) {
            $client_id = sanitize_file_name(parse_url(site_url(), PHP_URL_HOST) ?: 'client');
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_HTTPHEADER => [
                'X-FP-Client-Secret: ' . $secret,
            ],
            CURLOPT_POSTFIELDS => [
                'client_id' => $client_id,
                'file' => new \CURLFile($file_path, 'application/zip', basename($file_path)),
            ],
        ]);

        $response = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!empty($error)) {
            return ['success' => false, 'error' => 'cURL: ' . $error];
        }

        if ($http_code !== 200) {
            $data = json_decode($response, true);
            $msg = is_array($data) && !empty($data['message']) ? $data['message'] : 'HTTP ' . $http_code;
            return ['success' => false, 'error' => $msg];
        }

        $data = json_decode($response, true);
        if (is_array($data) && !empty($data['success'])) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => $data['message'] ?? 'Risposta non valida'];
    }

    private static function get_memory_limit_bytes(): int
    {
        $limit = ini_get('memory_limit');
        if ((int) $limit <= 0) {
            return PHP_INT_MAX;
        }
        $unit = strtolower(substr(trim($limit), -1));
        $value = (int) $limit;
        switch ($unit) {
            case 'g': $value *= 1024;
            // fall through
            case 'm': $value *= 1024;
            // fall through
            case 'k': $value *= 1024;
        }
        return $value;
    }
}
