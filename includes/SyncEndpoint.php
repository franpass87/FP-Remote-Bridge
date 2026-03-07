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
        register_rest_route('fp-remote-bridge/v1', '/reload', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_reload'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);

        register_rest_route('fp-remote-bridge/v1', '/install-log', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle_install_log'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);

        register_rest_route('fp-remote-bridge/v1', '/flush-cache', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_flush_cache'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);

        register_rest_route('fp-remote-bridge/v1', '/status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle_status'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);

        register_rest_route('fp-remote-bridge/v1', '/trigger-sync', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_request'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);

        register_rest_route('fp-remote-bridge/v1', '/plugin-versions', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle_plugin_versions'],
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
    public static function handle_reload(WP_REST_Request $request): WP_REST_Response
    {
        if (!function_exists('deactivate_plugins') || !function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $basename = plugin_basename(FP_REMOTE_BRIDGE_FILE);
        deactivate_plugins($basename, true);
        $result = activate_plugin($basename, '', false, true);
        return new WP_REST_Response([
            'success'  => !is_wp_error($result),
            'basename' => $basename,
            'error'    => is_wp_error($result) ? $result->get_error_message() : null,
        ], 200);
    }

    public static function handle_install_log(WP_REST_Request $request): WP_REST_Response
    {
        // Legge i dati di installazione salvati nel DB
        $data = [];
        foreach (['fp-remote-bridge', 'fp-restaurant-reservations', 'fp-experiences'] as $slug) {
            $v = get_option('fp_bridge_last_install_' . $slug);
            if ($v) {
                $data[$slug] = $v;
            }
        }
        return new WP_REST_Response(['installs' => $data, 'bridge_mem' => defined('FP_REMOTE_BRIDGE_VERSION') ? FP_REMOTE_BRIDGE_VERSION : '?'], 200);
    }

    public static function handle_flush_cache(WP_REST_Request $request): WP_REST_Response
    {
        $reset = false;
        if (function_exists('opcache_reset')) {
            $reset = @opcache_reset();
        } elseif (function_exists('opcache_invalidate')) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(WP_PLUGIN_DIR, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if ($file->getExtension() === 'php') {
                    @opcache_invalidate($file->getPathname(), true);
                }
            }
            $reset = true;
        }
        wp_clean_plugins_cache(false);
        return new WP_REST_Response(['success' => true, 'opcache_reset' => $reset], 200);
    }

    public static function handle_status(WP_REST_Request $request): WP_REST_Response
    {
        $active_plugins = (array) get_option('active_plugins', []);
        $bridge_entry   = '';
        foreach ($active_plugins as $a) {
            if (stripos($a, 'fp-remote-bridge') !== false) {
                $bridge_entry = $a;
                break;
            }
        }

        $dirs = glob(WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR) ?: [];
        $bridge_dirs = array_filter($dirs, fn($d) => stripos(basename($d), 'fp-remote-bridge') !== false);

        // Legge la versione direttamente dal file su disco (bypassa PHP in memoria)
        $disk_versions = [];
        foreach ($bridge_dirs as $dir) {
            $main = glob($dir . '/fp-remote-bridge.php')[0] ?? null;
            if ($main) {
                $content = @file_get_contents($main, false, null, 0, 2048);
                if ($content && preg_match('/Version\s*:\s*([^\s\r\n*]+)/i', $content, $m)) {
                    $disk_versions[basename($dir)] = trim($m[1]);
                }
            }
        }

        $opcache_status = null;
        if (function_exists('opcache_get_status')) {
            $s = @opcache_get_status(false);
            $opcache_status = $s['opcache_enabled'] ?? null;
        }

        return new WP_REST_Response([
            'bridge_version_memory' => FP_REMOTE_BRIDGE_VERSION,  // versione in memoria (PHP)
            'bridge_version_disk'   => $disk_versions,             // versione sul disco
            'bridge_entry'          => $bridge_entry,
            'bridge_dirs'           => array_values(array_map('basename', $bridge_dirs)),
            'opcache_enabled'       => $opcache_status,
        ], 200);
    }

    /**
     * Restituisce tutti i plugin installati con versioni.
     * Chiamato dal Master per aggiornare i dati cliente in tempo reale.
     */
    public static function handle_plugin_versions(WP_REST_Request $request): WP_REST_Response
    {
        $slugs = MasterSync::get_installed_plugin_slugs();
        $versions = [];
        foreach ($slugs as $entry) {
            if (strpos($entry, ':') !== false) {
                [$slug, $version] = explode(':', $entry, 2);
                $versions[$slug] = $version;
            } else {
                $versions[$entry] = '';
            }
        }
        return new WP_REST_Response([
            'success'  => true,
            'plugins'  => $versions,
            'site_url' => site_url(),
        ], 200);
    }

    public static function handle_request(WP_REST_Request $request): WP_REST_Response
    {
        // Rimuove il lock cron se presente (potrebbe bloccare l'esecuzione)
        delete_transient('fp_bridge_sync_lock');

        $result = MasterSync::run_manual_sync(true);

        // Se sono stati installati plugin, ri-pinga il Master con le versioni aggiornate
        if (!empty($result['installed_by_bridge'])) {
            // Svuota la cache plugin di WordPress
            wp_clean_plugins_cache(false);
            if (function_exists('get_plugins')) {
                get_plugins('/');
            }

            // Invalida opcache per i file aggiornati
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            } elseif (function_exists('opcache_invalidate')) {
                $iter = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(WP_PLUGIN_DIR, \RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($iter as $file) {
                    if ($file->getExtension() === 'php') {
                        @opcache_invalidate($file->getPathname(), true);
                    }
                }
            }

            // Cancella il flag cleanup per forzare maybe_cleanup alla prossima richiesta
            $cleanup_key = PluginInstaller::OPTION_CLEANUP_DONE_VERSION . FP_REMOTE_BRIDGE_VERSION;
            delete_option($cleanup_key);

            // Cleanup immediato solo se il Bridge NON è tra i plugin appena installati.
            // Se il Bridge si è aggiornato, active_plugins è stato modificato nel DB ma
            // PHP ha ancora il vecchio valore in memoria: cleanup ora rimuoverebbe la nuova cartella.
            if (!isset($result['installed_by_bridge']['fp-remote-bridge'])) {
                PluginInstaller::cleanup_duplicate_dirs();
            }

            delete_transient('fp_bridge_sync_lock');
            MasterSync::run_manual_sync(false); // solo registra le versioni aggiornate
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
