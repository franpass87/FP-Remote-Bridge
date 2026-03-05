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

            // Invalida opcache per i file aggiornati: senza questo, PHP riusa il vecchio
            // bytecode in memoria anche se i file sul disco sono stati sostituiti.
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            } elseif (function_exists('opcache_invalidate')) {
                // Invalida ricorsivamente la cartella plugin
                $plugin_dir = WP_PLUGIN_DIR;
                $iter = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($plugin_dir, \RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($iter as $file) {
                    if ($file->getExtension() === 'php') {
                        @opcache_invalidate($file->getPathname(), true);
                    }
                }
            }

            // Forza cleanup cartelle duplicate alla prossima richiesta (non ora):
            // se il Bridge stesso è stato aggiornato, active_plugins è stato modificato
            // nel DB ma PHP ha ancora il vecchio valore in memoria. Chiamare cleanup_duplicate_dirs()
            // ora rimuoverebbe la nuova cartella appena installata.
            // Invece, cancella il flag "cleanup done" così maybe_cleanup si riesegue
            // alla prossima richiesta quando active_plugins è già aggiornato.
            $cleanup_key = PluginInstaller::OPTION_CLEANUP_DONE_VERSION . FP_REMOTE_BRIDGE_VERSION;
            delete_option($cleanup_key);
            // Cleanup immediato solo se il Bridge NON è tra i plugin appena installati
            if (!isset($result['installed_by_bridge']['fp-remote-bridge'])) {
                PluginInstaller::cleanup_duplicate_dirs();
            }

            delete_transient('fp_bridge_sync_lock');
            MasterSync::run_manual_sync(false); // install=false: solo registra le versioni aggiornate
        }

        return new WP_REST_Response([
            'success'            => $result['success'] ?? false,
            'updates_available'  => $result['updates_available'] ?? false,
            'deploy_authorized'  => $result['deploy_authorized'] ?? false,
            'installed'          => $result['installed_by_bridge'] ?? [],
            'error'              => $result['error'] ?? null,
            'bridge_version'     => FP_REMOTE_BRIDGE_VERSION,
        ], ($result['success'] ?? false) ? 200 : 500);
    }
}
