<?php
/**
 * Endpoint AJAX per ingest errori JavaScript e console dal browser.
 *
 * @package FP\RemoteBridge\Diagnostics
 */

declare(strict_types=1);

namespace FP\RemoteBridge\Diagnostics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Riceve eventi client-side e li salva nel buffer diagnostico.
 */
final class ClientErrorIngest
{
    public const AJAX_ACTION = 'fp_remote_bridge_client_error';
    public const NONCE_ACTION = 'fp_remote_bridge_client_error';
    public const RATE_LIMIT_MAX = 30;
    public const RATE_LIMIT_WINDOW = 300;

    /**
     * Registra gli handler AJAX pubblici e admin.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'handle']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [self::class, 'handle']);
    }

    /**
     * Gestisce una richiesta di ingest.
     *
     * @return void
     */
    public static function handle(): void
    {
        if (!DiagnosticsSettings::is_client_error_collection_enabled()) {
            wp_send_json_error(['message' => __('Raccolta errori client disattivata.', 'fp-remote-bridge')], 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $honeypot = isset($_POST['fp_bridge_hp']) ? sanitize_text_field(wp_unslash((string) $_POST['fp_bridge_hp'])) : '';
        if ($honeypot !== '') {
            wp_send_json_error(['message' => __('Richiesta non valida.', 'fp-remote-bridge')], 400);
        }

        if (!self::allow_request()) {
            wp_send_json_error(['message' => __('Troppe richieste. Riprova tra qualche minuto.', 'fp-remote-bridge')], 429);
        }

        $rawEvents = isset($_POST['events']) ? wp_unslash($_POST['events']) : '';
        if (!is_string($rawEvents) || $rawEvents === '') {
            wp_send_json_error(['message' => __('Payload eventi mancante.', 'fp-remote-bridge')], 400);
        }

        $decoded = json_decode($rawEvents, true);
        if (!is_array($decoded)) {
            wp_send_json_error(['message' => __('Payload eventi non valido.', 'fp-remote-bridge')], 400);
        }

        $saved = 0;
        foreach (array_slice($decoded, 0, 10) as $event) {
            if (!is_array($event)) {
                continue;
            }

            $normalized = ClientErrorStore::normalize_payload($event);
            if ($normalized === null) {
                continue;
            }

            if (ClientErrorStore::append($normalized)) {
                ++$saved;
            }
        }

        wp_send_json_success([
            'saved' => $saved,
        ]);
    }

    /**
     * Applica rate limiting per IP.
     *
     * @return bool True se la richiesta puo proseguire.
     */
    private static function allow_request(): bool
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : 'unknown';
        $key = 'fp_bridge_client_err_' . md5($ip);
        $attempts = (int) get_transient($key);
        if ($attempts >= self::RATE_LIMIT_MAX) {
            return false;
        }

        set_transient($key, $attempts + 1, self::RATE_LIMIT_WINDOW);

        return true;
    }
}
