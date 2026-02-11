<?php
/**
 * Endpoint REST per aggiornamento remoto di tutti i plugin tramite FP Updater
 *
 * Utilizzato da un hub centrale o da script per triggerare check + update su siti remoti
 * che hanno FP-Remote-Bridge e FP Updater installati.
 *
 * @package FP\RemoteBridge
 */

namespace FP\RemoteBridge;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

class PluginUpdateEndpoint
{
    /**
     * Nome opzione per il secret di autorizzazione
     */
    public const OPTION_SECRET = 'fp_remote_bridge_plugin_update_secret';

    /**
     * Header richiesto per l'autenticazione (alternativa: param secret nel body)
     */
    public const HEADER_SECRET = 'X-FP-Update-Secret';

    /**
     * Registra l'endpoint REST
     */
    public static function register(): void
    {
        register_rest_route('fp-publisher/v1', '/update-plugins', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle_request'],
            'permission_callback' => [self::class, 'permission_check'],
            'args' => [
                'secret' => [
                    'required' => false,
                    'type' => 'string',
                    'description' => 'Secret di autorizzazione (alternativa all\'header X-FP-Update-Secret)',
                ],
                'check_only' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Se true, esegue solo il controllo aggiornamenti senza installare',
                ],
            ],
        ]);
    }

    /**
     * Verifica permessi: confronta secret da header o body con opzione
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public static function permission_check(WP_REST_Request $request): bool
    {
        $configured = get_option(self::OPTION_SECRET, '');
        if (empty($configured) || !is_string($configured)) {
            return false;
        }

        $secret = $request->get_header(self::HEADER_SECRET);
        if (empty($secret)) {
            $secret = $request->get_param('secret');
        }
        if (empty($secret) || !is_string($secret)) {
            return false;
        }

        return hash_equals($configured, $secret);
    }

    /**
     * Gestisce la richiesta POST: check e/o update di tutti i plugin gestiti da FP Updater
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_request(WP_REST_Request $request): WP_REST_Response
    {
        $check_only = (bool) $request->get_param('check_only');

        if (!class_exists('FP\GitUpdater\Updater')) {
            $master_url = get_option(MasterSync::OPTION_MASTER_URL, '');
            $master_secret = get_option(MasterSync::OPTION_MASTER_SECRET, '');
            if (empty($master_url) || empty($master_secret)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Configura URL Master e Secret in Impostazioni → FP Remote Bridge.',
                    'code' => 'master_not_configured',
                ], 503);
            }
            $result = MasterSync::run_manual_sync(!$check_only);
            $msg = $result['success']
                ? ($check_only ? 'Controllo completato.' : 'Sincronizzazione Master completata.')
                : ($result['error'] ?? 'Errore');
            return new WP_REST_Response([
                'success' => $result['success'],
                'message' => $msg,
                'check_only' => $check_only,
                'updates_available' => $result['updates_available'] ?? false,
                'installed_by_bridge' => $result['installed_by_bridge'] ?? [],
            ], $result['success'] ? 200 : 500);
        }


        try {
            /** @var \FP\GitUpdater\Updater $updater */
            $updater = \FP\GitUpdater\Updater::get_instance();

            $pending_before = $updater->get_pending_updates();
            $pending_count_before = count($pending_before);

            // Controlla aggiornamenti per tutti i plugin
            $updater->check_for_updates();

            $pending_after = $updater->get_pending_updates();
            $pending_count_after = count($pending_after);

            if ($check_only) {
                return new WP_REST_Response([
                    'success' => true,
                    'message' => 'Controllo aggiornamenti completato.',
                    'check_only' => true,
                    'pending_updates' => $pending_count_after,
                    'pending_plugins' => array_map(static function ($p) {
                        return [
                            'id' => $p['plugin']['id'] ?? '',
                            'name' => $p['plugin']['name'] ?? '',
                            'current_version' => $p['current_version'] ?? '',
                            'available_version' => $p['available_version'] ?? '',
                        ];
                    }, $pending_after),
                ], 200);
            }

            // Esegui aggiornamento di tutti i plugin con pending (e quelli che hanno auto_update)
            $result = $updater->run_update(null, null);

            return new WP_REST_Response([
                'success' => (bool) $result,
                'message' => $result
                    ? 'Controllo e aggiornamento completati.'
                    : 'Controllo eseguito; alcuni aggiornamenti potrebbero essere falliti o non disponibili.',
                'pending_before' => $pending_count_before,
                'pending_after' => count($updater->get_pending_updates()),
                'updated' => $result,
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Errore durante aggiornamento: ' . $e->getMessage(),
                'code' => 'update_error',
            ], 500);
        }
    }

    /**
     * Restituisce l'URL dell'endpoint per questo sito
     *
     * @return string
     */
    public static function get_endpoint_url(): string
    {
        return rest_url('fp-publisher/v1/update-plugins');
    }
}
