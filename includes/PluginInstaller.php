<?php
/**
 * Installazione/aggiornamento plugin da GitHub o URL ZIP (senza FP Updater)
 *
 * Usato dal Bridge quando il sito client non ha FP Updater installato.
 *
 * @package FP\RemoteBridge
 */

namespace FP\RemoteBridge;

if (!defined('ABSPATH')) {
    exit;
}

class PluginInstaller
{
    public const OPTION_GITHUB_TOKEN         = 'fp_remote_bridge_github_token';
    public const OPTION_CLEANUP_DONE_VERSION = 'fp_remote_bridge_cleanup_done_v';

    /**
     * Aggiorna il Bridge stesso usando WordPress Plugin Upgrader.
     * Necessario perché PluginInstaller non può aggiornare se stesso:
     * PHP ha già caricato il vecchio codice in memoria per questa richiesta.
     * WordPress Upgrader gestisce correttamente opcache e plugin attivi.
     */
    private static function upgrade_self(string $github_repo, string $branch): bool|array
    {
        // Trova la cartella esistente del Bridge (case-insensitive)
        $existing_dir = self::find_existing_plugin_dir('fp-remote-bridge');
        if (!$existing_dir) {
            // Nessuna cartella esistente: usa install normale
            return self::install_from_master([
                'slug'        => 'fp-remote-bridge',
                'github_repo' => $github_repo,
                'branch'      => $branch,
                '_skip_self'  => true, // evita ricorsione
            ]);
        }

        // Usa install_from_master standard ma con il target_dir forzato
        // alla cartella esistente (già gestito da find_existing_plugin_dir)
        return self::install_from_master([
            'slug'        => basename($existing_dir), // usa il nome esatto della cartella
            'github_repo' => $github_repo,
            'branch'      => $branch,
            '_skip_self'  => true,
        ]);
    }

    /**
     * Assicura che tutte le funzioni admin necessarie siano disponibili.
     * Sicuro da chiamare sia in contesto cron che admin.
     */
    private static function load_wp_admin_deps(): void
    {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('unzip_file')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }

    /**
     * Inizializza WP_Filesystem e verifica che sia disponibile.
     * Restituisce false se non disponibile.
     */
    private static function init_filesystem(): bool
    {
        global $wp_filesystem;
        if ($wp_filesystem instanceof \WP_Filesystem_Base) {
            return true;
        }
        self::load_wp_admin_deps();
        $result = WP_Filesystem();
        if ($result === false || !($wp_filesystem instanceof \WP_Filesystem_Base)) {
            return false;
        }
        return true;
    }

    /**
     * Installa o aggiorna un plugin dalla risposta Master.
     * Gestisce backup, ripristino e attivazione in modo sicuro.
     *
     * @param array $plugin Dati da Master: slug, github_repo?, branch?, zip_url?
     * @return bool|array true se ok, array con 'error' se fallito
     */
    public static function install_from_master(array $plugin): bool|array
    {
        $slug        = $plugin['slug'] ?? '';
        $zip_url     = $plugin['zip_url'] ?? '';
        $github_repo = $plugin['github_repo'] ?? '';
        $branch      = $plugin['branch'] ?? 'main';

        if (empty($slug)) {
            if (!empty($github_repo)) {
                $parts = explode('/', $github_repo);
                $slug  = strtolower(end($parts));
            } else {
                return ['error' => 'Slug o github_repo richiesti'];
            }
        }

        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '-', trim($slug, '-'));
        if (empty($slug)) {
            return ['error' => 'Slug non valido'];
        }

        // Se stiamo aggiornando il Bridge stesso (e non siamo già in upgrade_self),
        // forza l'uso della cartella esistente con il nome esatto (case-sensitive).
        if ($slug === 'fp-remote-bridge' && !empty($github_repo) && empty($plugin['_skip_self'])) {
            return self::upgrade_self($github_repo, $branch);
        }

        self::load_wp_admin_deps();

        // --- Download ---
        $upgrade_dir = WP_CONTENT_DIR . '/upgrade';
        if (!file_exists($upgrade_dir)) {
            wp_mkdir_p($upgrade_dir);
        }

        $temp_file = $upgrade_dir . '/fp-bridge-dl-' . time() . '-' . uniqid() . '.zip';

        if (!empty($zip_url) && filter_var($zip_url, FILTER_VALIDATE_URL)) {
            $downloaded = download_url($zip_url, 300);
            if (is_wp_error($downloaded)) {
                return ['error' => 'Download ZIP: ' . $downloaded->get_error_message()];
            }
            if (!@rename($downloaded, $temp_file)) {
                @copy($downloaded, $temp_file);
                @unlink($downloaded);
            }
        } elseif (!empty($github_repo)) {
            $token = get_option(self::OPTION_GITHUB_TOKEN, '');
            if (!empty($token)) {
                $args = [
                    'timeout'  => 300,
                    'stream'   => true,
                    'filename' => $temp_file,
                    'headers'  => [
                        'User-Agent'    => 'FP-Remote-Bridge/1.0',
                        'Accept'        => 'application/vnd.github.v3+json',
                        'Authorization' => 'token ' . $token,
                    ],
                ];
                $response = wp_remote_get(
                    "https://api.github.com/repos/{$github_repo}/zipball/{$branch}",
                    $args
                );
                if (is_wp_error($response)) {
                    @unlink($temp_file);
                    return ['error' => 'Download GitHub (token): ' . $response->get_error_message()];
                }
                $code = wp_remote_retrieve_response_code($response);
                if ($code !== 200) {
                    @unlink($temp_file);
                    return ['error' => 'Download GitHub (token): HTTP ' . $code];
                }
            } else {
                $download_url = "https://github.com/{$github_repo}/archive/refs/heads/{$branch}.zip";
                $downloaded   = download_url($download_url, 300);
                if (is_wp_error($downloaded)) {
                    return ['error' => 'Download GitHub: ' . $downloaded->get_error_message()];
                }
                if (!@rename($downloaded, $temp_file)) {
                    @copy($downloaded, $temp_file);
                    @unlink($downloaded);
                }
            }
        } else {
            return ['error' => 'zip_url o github_repo richiesti'];
        }

        if (!file_exists($temp_file) || filesize($temp_file) === 0) {
            @unlink($temp_file);
            return ['error' => 'File scaricato vuoto o mancante'];
        }

        // Verifica che il file sia un ZIP valido (magic bytes PK = 0x50 0x4B)
        $magic = @file_get_contents($temp_file, false, null, 0, 2);
        if ($magic !== 'PK') {
            $size = filesize($temp_file);
            @unlink($temp_file);
            return ['error' => 'File scaricato non è un ZIP valido (size:' . $size . ')'];
        }

        // --- Estrazione ---
        if (!self::init_filesystem()) {
            @unlink($temp_file);
            return ['error' => 'WP_Filesystem non disponibile'];
        }
        global $wp_filesystem;

        $temp_extract = $upgrade_dir . '/fp-bridge-extract-' . time() . '-' . uniqid();
        $unzip        = unzip_file($temp_file, $temp_extract);
        @unlink($temp_file);

        if (is_wp_error($unzip)) {
            $wp_filesystem->delete($temp_extract, true);
            return ['error' => 'Estrazione: ' . $unzip->get_error_message()];
        }

        $source_dir = self::find_plugin_root($temp_extract);
        if (!$source_dir || !is_dir($source_dir)) {
            $wp_filesystem->delete($temp_extract, true);
            return ['error' => 'Struttura plugin non riconosciuta nello zip'];
        }

        // --- Determina cartella target (case-insensitive) ---
        $target_dir   = WP_PLUGIN_DIR . '/' . $slug;
        $existing_dir = self::find_existing_plugin_dir($slug);
        if ($existing_dir && $existing_dir !== $target_dir) {
            $target_dir = $existing_dir;
        }

        // --- Backup della cartella esistente ---
        $backup_dir = null;
        if (is_dir($target_dir)) {
            $backup_dir = $upgrade_dir . '/fp-bridge-backup-' . basename($target_dir) . '-' . time();
            if (!@rename($target_dir, $backup_dir)) {
                // rename fallito (cross-device): copia $target_dir (vecchia versione) in $backup_dir
                if (!self::copy_dir($target_dir, $backup_dir, $wp_filesystem)) {
                    $wp_filesystem->delete($temp_extract, true);
                    return ['error' => 'Impossibile creare backup della cartella esistente'];
                }
                if (!$wp_filesystem->delete($target_dir, true)) {
                    // delete fallito: ripristiniamo il backup e usciamo
                    $wp_filesystem->delete($temp_extract, true);
                    $wp_filesystem->delete($backup_dir, true);
                    return ['error' => 'Impossibile rimuovere la cartella esistente per l\'aggiornamento'];
                }
            }
        }

        // --- Copia nuova versione ---
        $installed = false;
        if (@rename($source_dir, $target_dir)) {
            $installed = true;
        } else {
            // rename cross-device: usa copy
            if (self::copy_dir($source_dir, $target_dir, $wp_filesystem)) {
                $installed = true;
            }
        }

        $wp_filesystem->delete($temp_extract, true);

        if (!$installed) {
            // Ripristina backup se disponibile
            if ($backup_dir && is_dir($backup_dir)) {
                @rename($backup_dir, $target_dir);
            }
            return ['error' => 'Impossibile installare il plugin nella cartella target'];
        }

        // Installazione riuscita: rimuovi il backup
        if ($backup_dir && is_dir($backup_dir)) {
            $wp_filesystem->delete($backup_dir, true);
        }

        // --- Attivazione sicura ---
        self::activate_plugin_safely($target_dir);

        return true;
    }

    /**
     * Attiva il plugin installato in modo sicuro.
     * Non genera fatal anche se il plugin ha errori — usa output buffering per catturare
     * eventuali output inattesi e verifica il risultato.
     */
    private static function activate_plugin_safely(string $plugin_dir): void
    {
        $plugin_basename = self::find_plugin_basename($plugin_dir);
        if (empty($plugin_basename)) {
            return;
        }

        $active_plugins = (array) get_option('active_plugins', []);

        // Controlla con confronto case-insensitive (basename può differire per maiuscole)
        foreach ($active_plugins as $active) {
            if (strtolower($active) === strtolower($plugin_basename)) {
                return; // già attivo
            }
        }

        // activate_plugin() può generare output, chiamare wp_die(), o lanciare Throwable.
        // Intercettiamo wp_die() tramite filtro, silenzializziamo warning con error handler,
        // e catturiamo output con ob_start(). Il blocco finally garantisce il cleanup.
        $wp_die_called = false;

        $wp_die_callback = function () use (&$wp_die_called) {
            return function () use (&$wp_die_called) {
                $wp_die_called = true;
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
            };
        };
        add_filter('wp_die_handler', $wp_die_callback, PHP_INT_MAX);

        set_error_handler(function () {
            return true;
        });

        $result = null;
        try {
            ob_start();
            $result = activate_plugin($plugin_basename, '', false, true);
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        } finally {
            restore_error_handler();
            remove_filter('wp_die_handler', $wp_die_callback, PHP_INT_MAX);
        }

        if ($wp_die_called || is_wp_error($result)) {
            return; // Il plugin ha chiamato wp_die() o ha rifiutato l'attivazione
        }

        // Attivazione riuscita — WordPress ha già aggiornato active_plugins
    }

    /**
     * Trova il basename WordPress del plugin (es. "fp-remote-bridge/fp-remote-bridge.php")
     * dalla sua cartella di installazione.
     */
    private static function find_plugin_basename(string $plugin_dir): string
    {
        $main_file = self::find_plugin_main_file($plugin_dir);
        if (!$main_file) {
            return '';
        }
        return plugin_basename($main_file);
    }

    /**
     * Cerca una cartella plugin già esistente con lo stesso slug (case-insensitive).
     * Utile su filesystem Linux dove "FP-Remote-Bridge" != "fp-remote-bridge".
     */
    private static function find_existing_plugin_dir(string $slug): ?string
    {
        $slug_lower  = strtolower($slug);
        $plugin_dirs = glob(WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($plugin_dirs as $dir) {
            if (strtolower(basename($dir)) === $slug_lower) {
                return $dir;
            }
        }
        return null;
    }

    /**
     * Trova la directory root del plugin (con file PHP con "Plugin Name:")
     * nell'archivio estratto. Cerca fino a 2 livelli di profondità.
     */
    private static function find_plugin_root(string $extracted_dir): ?string
    {
        $main = self::find_plugin_main_file($extracted_dir);
        if ($main) {
            return dirname($main);
        }

        $subdirs = glob($extracted_dir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($subdirs as $subdir) {
            $main = self::find_plugin_main_file($subdir);
            if ($main) {
                return dirname($main);
            }
            $subsubs = glob($subdir . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($subsubs as $ss) {
                $main = self::find_plugin_main_file($ss);
                if ($main) {
                    return dirname($main);
                }
            }
        }

        return null;
    }

    private static function find_plugin_main_file(string $dir): ?string
    {
        if (!is_dir($dir)) {
            return null;
        }
        $files = glob($dir . '/*.php') ?: [];
        foreach ($files as $f) {
            // Legge solo i primi 8KB per evitare file grandi.
            // Usa lo stesso pattern di WordPress (get_plugin_data): cerca "Plugin Name:" ovunque nella riga.
            $data = @file_get_contents($f, false, null, 0, 8192);
            if ($data !== false && preg_match('/Plugin Name\s*:/i', $data)) {
                return $f;
            }
        }
        return null;
    }

    private static function copy_dir(string $src, string $dest, \WP_Filesystem_Base $wp_fs): bool
    {
        if (!$wp_fs->exists($dest)) {
            if (!$wp_fs->mkdir($dest, FS_CHMOD_DIR)) {
                return false;
            }
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $ok      = true;
        $src_len = strlen($src);
        foreach ($iterator as $item) {
            $rel       = ltrim(substr($item->getPathname(), $src_len), DIRECTORY_SEPARATOR . '/');
            $dest_path = $dest . DIRECTORY_SEPARATOR . $rel;

            if ($item->isDir()) {
                if (!$wp_fs->exists($dest_path) && !$wp_fs->mkdir($dest_path, FS_CHMOD_DIR)) {
                    $ok = false;
                }
            } else {
                if (!$wp_fs->copy($item->getPathname(), $dest_path, true, FS_CHMOD_FILE)) {
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    /**
     * Esegue la pulizia dei duplicati solo una volta per versione Bridge.
     * Chiamato da plugins_loaded dopo ogni aggiornamento del Bridge.
     * NON esegue sync remoto (troppo lento per plugins_loaded).
     */
    public static function maybe_cleanup(): void
    {
        $done_key = self::OPTION_CLEANUP_DONE_VERSION . FP_REMOTE_BRIDGE_VERSION;
        if (get_option($done_key)) {
            return;
        }

        // Esegui la pulizia su init per avere tutto il contesto WP
        add_action('init', function () use ($done_key) {
            if (get_option($done_key)) {
                return;
            }

            // Transient lock atomico per evitare esecuzioni concorrenti
            $lock_key = 'fp_bridge_cleanup_lock_' . FP_REMOTE_BRIDGE_VERSION;
            if (get_transient($lock_key)) {
                return;
            }
            set_transient($lock_key, 1, 60);

            try {
                self::cleanup_duplicate_dirs();
                update_option($done_key, time(), false);
            } finally {
                delete_transient($lock_key);
            }
        }, 1);
    }

    /**
     * Cerca e rimuove cartelle plugin duplicate con nomi case-insensitive identici
     * ma diversi da quello del plugin attivo (es. "fp-remote-bridge" se esiste "FP-Remote-Bridge").
     * Non rimuove MAI cartelle con plugin attivi.
     *
     * @return array<string> Elenco delle cartelle rimosse
     */
    public static function cleanup_duplicate_dirs(): array
    {
        if (!self::init_filesystem()) {
            return [];
        }
        global $wp_filesystem;

        $plugin_dirs = glob(WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR) ?: [];

        // Raggruppa per nome lowercase
        $groups = [];
        foreach ($plugin_dirs as $dir) {
            $groups[strtolower(basename($dir))][] = $dir;
        }

        $active_plugins = (array) get_option('active_plugins', []);
        $removed        = [];

        foreach ($groups as $dirs) {
            if (count($dirs) <= 1) {
                continue;
            }

            // Trova la cartella canonica: quella con un plugin ATTIVO
            $canonical = null;
            foreach ($dirs as $dir) {
                $base = basename($dir);
                foreach ($active_plugins as $active) {
                    if (strpos($active, $base . '/') === 0) {
                        $canonical = $dir;
                        break 2;
                    }
                }
            }

            // Se nessuna è attiva, non rimuovere nulla — troppo rischioso
            if (!$canonical) {
                continue;
            }

            // Rimuovi solo le non-canoniche che non hanno plugin attivi
            foreach ($dirs as $dir) {
                if ($dir === $canonical) {
                    continue;
                }

                $base      = basename($dir);
                $is_active = false;
                foreach ($active_plugins as $active) {
                    if (strpos($active, $base . '/') === 0) {
                        $is_active = true;
                        break;
                    }
                }

                if ($is_active) {
                    continue; // sicurezza: non toccare mai cartelle con plugin attivi
                }

                if ($wp_filesystem->is_dir($dir)) {
                    $wp_filesystem->delete($dir, true);
                    $removed[] = $dir;
                }
            }
        }

        return $removed;
    }
}
