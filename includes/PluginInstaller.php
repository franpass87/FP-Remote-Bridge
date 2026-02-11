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
    public const OPTION_GITHUB_TOKEN = 'fp_remote_bridge_github_token';

    /**
     * Installa o aggiorna un plugin dalla risposta Master
     *
     * @param array $plugin Dati da Master: slug, github_repo?, branch?, zip_url?
     * @return bool|array true se ok, array con 'error' se fallito
     */
    public static function install_from_master(array $plugin): bool|array
    {
        $slug = $plugin['slug'] ?? '';
        $zip_url = $plugin['zip_url'] ?? '';
        $github_repo = $plugin['github_repo'] ?? '';
        $branch = $plugin['branch'] ?? 'main';

        if (empty($slug)) {
            if (!empty($github_repo)) {
                $parts = explode('/', $github_repo);
                $slug = strtolower(end($parts));
            } else {
                return ['error' => 'Slug o github_repo richiesti'];
            }
        }

        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '-', trim($slug, '-'));

        if (!empty($zip_url) && filter_var($zip_url, FILTER_VALIDATE_URL)) {
            $download_url = $zip_url;
            $args = [
                'timeout' => 300,
                'headers' => ['User-Agent' => 'FP-Remote-Bridge/' . (defined('FP_REMOTE_BRIDGE_VERSION') ? FP_REMOTE_BRIDGE_VERSION : '1.0')],
            ];
        } elseif (!empty($github_repo)) {
            $token = get_option(self::OPTION_GITHUB_TOKEN, '');
            if (!empty($token)) {
                $download_url = "https://api.github.com/repos/{$github_repo}/zipball/{$branch}";
                $args = [
                    'timeout' => 300,
                    'headers' => [
                        'User-Agent' => 'FP-Remote-Bridge/1.0',
                        'Accept' => 'application/vnd.github.v3+json',
                        'Authorization' => 'token ' . $token,
                    ],
                ];
            } else {
                $download_url = "https://github.com/{$github_repo}/archive/refs/heads/{$branch}.zip";
                $args = [
                    'timeout' => 300,
                    'headers' => ['User-Agent' => 'FP-Remote-Bridge/1.0'],
                ];
            }
        } else {
            return ['error' => 'zip_url o github_repo richiesti'];
        }

        $upgrade_dir = WP_CONTENT_DIR . '/upgrade';
        if (!file_exists($upgrade_dir)) {
            wp_mkdir_p($upgrade_dir);
        }

        $temp_file = $upgrade_dir . '/fp-bridge-download-' . time() . '-' . uniqid() . '.zip';

        if (!empty($args['headers']['Authorization'])) {
            $args['stream'] = true;
            $args['filename'] = $temp_file;
            $response = wp_remote_get($download_url, $args);
            if (is_wp_error($response)) {
                return ['error' => $response->get_error_message()];
            }
            if (wp_remote_retrieve_response_code($response) !== 200) {
                @unlink($temp_file);
                return ['error' => 'HTTP ' . wp_remote_retrieve_response_code($response)];
            }
        } else {
            $downloaded = download_url($download_url, 300);
            if (is_wp_error($downloaded)) {
                return ['error' => $downloaded->get_error_message()];
            }
            if (!@rename($downloaded, $temp_file)) {
                @copy($downloaded, $temp_file);
                @unlink($downloaded);
            }
        }

        if (!file_exists($temp_file) || filesize($temp_file) === 0) {
            @unlink($temp_file);
            return ['error' => 'File scaricato vuoto o mancante'];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        $temp_extract = $upgrade_dir . '/fp-bridge-extract-' . time();
        $unzip = unzip_file($temp_file, $temp_extract);
        @unlink($temp_file);

        if (is_wp_error($unzip)) {
            return ['error' => 'Estrazione: ' . $unzip->get_error_message()];
        }

        $source_dir = self::find_plugin_root($temp_extract);
        if (!$source_dir || !is_dir($source_dir)) {
            $wp_filesystem->delete($temp_extract, true);
            return ['error' => 'Struttura plugin non riconosciuta nello zip'];
        }

        $target_dir = WP_PLUGIN_DIR . '/' . $slug;
        $backup_dir = null;

        if (file_exists($target_dir) && is_dir($target_dir)) {
            $backup_dir = $upgrade_dir . '/fp-bridge-backup-' . $slug . '-' . time();
            if (!@rename($target_dir, $backup_dir)) {
                $wp_filesystem->delete($temp_extract, true);
                return ['error' => 'Impossibile creare backup della cartella esistente'];
            }
        }

        $move_ok = @rename($source_dir, $target_dir);
        if (!$move_ok) {
            $copy_ok = self::copy_dir($source_dir, $target_dir, $wp_filesystem);
            if (!$copy_ok) {
                if ($backup_dir && file_exists($backup_dir)) {
                    @rename($backup_dir, $target_dir);
                }
                $wp_filesystem->delete($temp_extract, true);
                return ['error' => 'Impossibile copiare il plugin'];
            }
        }

        $wp_filesystem->delete($temp_extract, true);

        if ($backup_dir && file_exists($backup_dir)) {
            $wp_filesystem->delete($backup_dir, true);
        }

        return true;
    }

    /**
     * Trova la directory root del plugin (con file PHP con "Plugin Name:")
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
            $data = @file_get_contents($f, false, null, 0, 8192);
            if ($data && preg_match('/Plugin Name:\s*.+/i', $data)) {
                return $f;
            }
        }
        return null;
    }

    private static function copy_dir(string $src, string $dest, $wp_fs): bool
    {
        if (!$wp_fs->exists($dest)) {
            $wp_fs->mkdir($dest, FS_CHMOD_DIR);
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $rel = str_replace($src . DIRECTORY_SEPARATOR, '', $item->getPathname());
            $dest_path = $dest . DIRECTORY_SEPARATOR . $rel;
            if ($item->isDir()) {
                if (!$wp_fs->exists($dest_path)) {
                    $wp_fs->mkdir($dest_path, FS_CHMOD_DIR);
                }
            } else {
                $wp_fs->copy($item->getPathname(), $dest_path, true, FS_CHMOD_FILE);
            }
        }
        return true;
    }
}
