<?php
/*
Plugin Name: FP Remote Bridge
*/
/**
 * Plugin Name: FP Remote Bridge
 * Plugin URI: https://github.com/franpass87/FP-Remote-Bridge
 * Description: Connettore per siti remoti che ricevono pubblicazioni e dati SEO da FP Publisher e altri prodotti FP.
 * Version: 1.2.8
 * Author: Francesco Passeri
 * Author URI: https://www.francescopasseri.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fp-remote-bridge
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * GitHub Plugin URI: franpass87/FP-Remote-Bridge
 * GitHub Branch: main
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FP_REMOTE_BRIDGE_VERSION', '1.2.8');
define('FP_REMOTE_BRIDGE_FILE', __FILE__);
define('FP_REMOTE_BRIDGE_DIR', plugin_dir_path(__FILE__));
define('FP_REMOTE_BRIDGE_BASENAME', plugin_basename(__FILE__));

$autoload_file = FP_REMOTE_BRIDGE_DIR . 'vendor/autoload.php';

if (file_exists($autoload_file)) {
    require_once $autoload_file;
} else {
    add_action('admin_notices', function() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>';
        echo '<strong>' . esc_html__('FP Remote Bridge:', 'fp-remote-bridge') . '</strong> ';
        echo esc_html__('Esegui', 'fp-remote-bridge') . ' <code>composer install --no-dev</code> ';
        echo esc_html__('nella cartella del plugin.', 'fp-remote-bridge');
        echo '</p></div>';
    });
    return;
}

use FP\RemoteBridge\Plugin;
use FP\RemoteBridge\MasterSync;
use FP\RemoteBridge\BackupSync;

register_deactivation_hook(__FILE__, function () {
    MasterSync::unschedule_cron();
    BackupSync::unschedule_cron();
});

// Se questo file viene caricato da una cartella diversa da quella in active_plugins
// (es. "fp-remote-bridge-new" mentre active_plugins ha ancora "fp-remote-bridge"),
// aggiorna active_plugins per puntare a questo file e disattiva la versione vecchia.
// Questo viene eseguito PRIMA di plugins_loaded, quindi funziona anche se il vecchio
// Bridge è ancora in active_plugins.
add_action('muplugins_loaded', function () {
    $my_basename = plugin_basename(__FILE__);
    $active      = (array) get_option('active_plugins', []);

    $found_exact  = in_array($my_basename, $active, true);
    $found_others = array_filter($active, function ($a) use ($my_basename) {
        return $a !== $my_basename && stripos($a, 'fp-remote-bridge') !== false;
    });

    if (!$found_exact && !empty($found_others)) {
        // Sostituisci il vecchio entry con questo
        $updated = array_map(function ($a) use ($my_basename, $found_others) {
            return in_array($a, $found_others, true) ? $my_basename : $a;
        }, $active);
        update_option('active_plugins', array_values(array_unique($updated)));
    }
}, 0);

add_action('plugins_loaded', function() {
    load_plugin_textdomain('fp-remote-bridge', false, dirname(FP_REMOTE_BRIDGE_BASENAME) . '/languages');
    Plugin::get_instance();
    // Pulizia automatica cartelle duplicate (eseguita una sola volta per versione)
    \FP\RemoteBridge\PluginInstaller::maybe_cleanup();
}, 10);

// Invalida opcache per i file del Bridge ad ogni richiesta.
// Necessario su hosting con opcache.validate_timestamps=0 per garantire che
// dopo un aggiornamento del Bridge il nuovo codice venga caricato immediatamente.
if (function_exists('opcache_invalidate') && defined('FP_REMOTE_BRIDGE_DIR')) {
    $bridge_dir = FP_REMOTE_BRIDGE_DIR;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($bridge_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if ($file->getExtension() === 'php') {
            @opcache_invalidate($file->getPathname(), false);
        }
    }
}
