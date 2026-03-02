<?php
/**
 * Servizio di backup per siti client FP Remote Bridge
 *
 * Crea backup completi (database + wp-content) da inviare al Master.
 *
 * @package FP\RemoteBridge
 */

namespace FP\RemoteBridge;

if (!defined('ABSPATH')) {
    exit;
}

class BackupService
{
    public const OPTION_INCLUDE_UPLOADS = 'fp_remote_bridge_backup_include_uploads';

    /** @var string[] Pattern da escludere da wp-content */
    private const EXCLUDE_DIRS = ['fp-backups', 'fp-bridge-backup-temp', 'upgrade', 'cache'];

    /** @var string[] Suffissi file da escludere */
    private const EXCLUDE_EXTENSIONS = ['log'];

    /**
     * Crea un backup completo (DB + wp-content)
     *
     * @param array{include_uploads?: bool} $options
     * @return array{path?: string, size?: int, error?: string}
     */
    public static function create_backup(array $options = []): array
    {
        global $wpdb;

        $include_uploads = $options['include_uploads'] ?? get_option(self::OPTION_INCLUDE_UPLOADS, true);

        $temp_dir = WP_CONTENT_DIR . '/fp-bridge-backup-temp';
        if (!is_dir($temp_dir)) {
            if (!wp_mkdir_p($temp_dir)) {
                return ['error' => __('Impossibile creare la cartella temporanea.', 'fp-remote-bridge')];
            }
        }

        $timestamp = gmdate('Y-m-d-His');
        $zip_path = $temp_dir . '/backup-' . $timestamp . '.zip';

        if (!class_exists('ZipArchive')) {
            return ['error' => __('Estensione ZipArchive non disponibile.', 'fp-remote-bridge')];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ['error' => __('Impossibile creare il file ZIP.', 'fp-remote-bridge')];
        }

        $tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);

        if (empty($tables)) {
            $zip->addFromString('db.sql', '-- No tables found');
        } else {
            $sql = "-- WordPress Database Backup\n";
            $sql .= "-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n";
            $sql .= "-- Site: " . site_url() . "\n\n";

            foreach ($tables as $row) {
                $table = $row[0];
                $create = $wpdb->get_row('SHOW CREATE TABLE `' . esc_sql($table) . '`', ARRAY_N);
                if ($create) {
                    $sql .= "\nDROP TABLE IF EXISTS `{$table}`;\n";
                    $sql .= $create[1] . ";\n\n";

                    $results = $wpdb->get_results('SELECT * FROM `' . esc_sql($table) . '`', ARRAY_A);
                    if (!empty($results)) {
                        $columns = array_keys($results[0]);
                        $col_list = '`' . implode('`, `', array_map('esc_sql', $columns)) . '`';

                        foreach ($results as $data) {
                            $values = array_map(function ($v) use ($wpdb) {
                                if ($v === null) {
                                    return 'NULL';
                                }
                                return $wpdb->prepare('%s', $v);
                            }, array_values($data));
                            $sql .= "INSERT INTO `{$table}` ({$col_list}) VALUES (" . implode(', ', $values) . ");\n";
                        }
                        $sql .= "\n";
                    }
                }
            }

            $zip->addFromString('db.sql', $sql);
        }

        $wp_content = realpath(WP_CONTENT_DIR);
        if ($wp_content && is_dir($wp_content)) {
            self::add_dir_to_zip($zip, $wp_content, '', $include_uploads);
        }

        $zip->close();

        if (!file_exists($zip_path)) {
            return ['error' => __('Backup non creato correttamente.', 'fp-remote-bridge')];
        }

        $size = (int) filesize($zip_path);

        return [
            'path' => $zip_path,
            'size' => $size,
            'error' => null,
        ];
    }

    /**
     * Aggiunge ricorsivamente una directory allo ZIP
     */
    private static function add_dir_to_zip(\ZipArchive $zip, string $dir, string $relative, bool $include_uploads): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full_path = $dir . '/' . $entry;
            $rel_path = $relative ? $relative . '/' . $entry : $entry;

            if (in_array($entry, self::EXCLUDE_DIRS, true)) {
                continue;
            }

            if ($entry === 'uploads' && !$include_uploads) {
                continue;
            }

            if (is_dir($full_path)) {
                self::add_dir_to_zip($zip, $full_path, $rel_path, $include_uploads);
            } else {
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($ext, self::EXCLUDE_EXTENSIONS, true)) {
                    continue;
                }
                $zip->addFile($full_path, 'wp-content/' . $rel_path);
            }
        }
    }

    /**
     * Elimina file temporanei di backup
     */
    public static function cleanup_temp(): void
    {
        $temp_dir = WP_CONTENT_DIR . '/fp-bridge-backup-temp';
        if (!is_dir($temp_dir)) {
            return;
        }

        $files = glob($temp_dir . '/backup-*.zip');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
}
