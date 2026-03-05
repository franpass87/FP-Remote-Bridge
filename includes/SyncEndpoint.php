<?php
/**
 * Endpoint REST per trigger sync da Master
 *
 * Permette al Master di chiamare direttamente il Bridge per avviare
 * un sync immediato, senza aspettare il cron WordPress del client.
 * Autenticato con lo stesso secret Master già configurato sul Bridge.
 *
 * @package FP\RemoteBridge
 */

namespace FP\RemoteBridge;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

class SyncEndpoint
{
    /**
     * Registra l'endpoint REST
     */
    public static function register(): void
    {
        register_rest_route('fp-remote-bridge/v1', '/trigger-sync', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_request'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);
    }

    /**
     * Verifica permessi: usa il secret Master già configurato sul Bridge.
     * Solo il Master conosce questo secret, quindi è sicuro.
     */
    public static function permission_check(WP_REST_Request $request): bool
    {
        $configured = get_option(MasterSync::OPTION_MASTER_SECRET, '');
        if (empty($configured) || !is_string($configured)) {
            return false;
        }

        $provided = $request->get_header('X-FP-Client-Secret');
        if (empty($provided)) {
            $provided = $request->get_param('secret');
        }
        if (empty($provided) || !is_string($provided)) {
            return false;
        }

        return hash_equals($configured, $provided);
    }

    /**
     * Esegue il sync immediato e restituisce il risultato.
     * Dopo l'installazione fa un secondo ping al Master per aggiornare
     * le versioni mostrate nella UI in tempo reale.
     */
    public static function handle_request(WP_REST_Request $request): WP_REST_Response
    {
        // Rimuove il lock cron se presente (potrebbe bloccare l'esecuzione)
        delete_transient('fp_bridge_sync_lock');

        $result = MasterSync::run_manual_sync(true);

        // Se sono stati installati plugin, ri-pinga il Master con le versioni aggiornate
        // così la UI del Master mostra subito la versione corretta senza aspettare il cron
        if (!empty($result['installed_by_bridge'])) {
            // Svuota la cache plugin di WordPress per leggere le versioni aggiornate dal filesystem
            wp_clean_plugins_cache(false);
            if (function_exists('get_plugins')) {
                get_plugins('/');
            }

            // Forza cleanup cartelle duplicate: cancella il flag per questa versione
            // così maybe_cleanup si riesegue e rimuove eventuali cartelle doppie create
            // dall'installazione (es. "FP-Remote-Bridge" + "fp-remote-bridge")
            $cleanup_key = PluginInstaller::OPTION_CLEANUP_DONE_VERSION . FP_REMOTE_BRIDGE_VERSION;
            delete_option($cleanup_key);
            PluginInstaller::cleanup_duplicate_dirs();

            delete_transient('fp_bridge_sync_lock');
            MasterSync::run_manual_sync(false); // install=false: solo registra le versioni aggiornate
        }

        return new WP_REST_Response([
            'success'            => $result['success'] ?? false,
            'updates_available'  => $result['updates_available'] ?? false,
            'deploy_authorized'  => $result['deploy_authorized'] ?? false,
            'installed'          => $result['installed_by_bridge'] ?? [],
            'error'              => $result['error'] ?? null,
        ], ($result['success'] ?? false) ? 200 : 500);
    }
}
