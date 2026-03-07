<?php
/**
 * Endpoint REST per avvio backup da remoto
 *
 * Permette a script esterni o al Master di avviare un backup on-demand
 * tramite POST con autenticazione X-FP-Update-Secret.
 *
 * @package FP\RemoteBridge
 */

namespace FP\RemoteBridge;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

class BackupEndpoint
{
    /**
     * Registra l'endpoint REST
     */
    public static function register(): void
    {
        register_rest_route('fp-remote-bridge/v1', '/run-backup', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle_request'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);
    }

    /**
     * Verifica permessi: usa lo stesso secret di PluginUpdateEndpoint
     */
    public static function permission_check(WP_REST_Request $request): bool
    {
        $configured = get_option(PluginUpdateEndpoint::OPTION_SECRET, '');
        if (empty($configured) || !is_string($configured)) {
            return false;
        }

        $secret = $request->get_header('X-FP-Update-Secret');
        if (empty($secret)) {
            $secret = $request->get_param('secret');
        }
        if (empty($secret) || !is_string($secret)) {
            return false;
        }

        return hash_equals($configured, $secret);
    }

    /**
     * Gestisce la richiesta POST: crea backup e lo carica sul Master
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_request(WP_REST_Request $request): WP_REST_Response
    {
        $result = BackupSync::create_and_upload();

        // Elimina il file temporaneo solo se l'upload è riuscito.
        if (!empty($result['path']) && !empty($result['success'])) {
            BackupService::cleanup_temp();
        }

        if ($result['success']) {
            return new WP_REST_Response([
                'success' => true,
                'size' => $result['size'] ?? 0,
                'uploaded' => $result['uploaded'] ?? true,
            ], 200);
        }

        return new WP_REST_Response([
            'success' => false,
            'error' => $result['error'] ?? __('Errore sconosciuto.', 'fp-remote-bridge'),
            'size' => $result['size'] ?? 0,
            'uploaded' => false,
        ], 500);
    }
}
