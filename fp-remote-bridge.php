<?php
/**
 * Plugin Name: FP Remote Bridge
 * Plugin URI: https://github.com/franpass87/FP-Remote-Bridge
 * Description: Connettore per siti remoti che ricevono pubblicazioni e dati SEO da FP Publisher e altri prodotti FP.
 * Version: 1.0.0
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

define('FP_REMOTE_BRIDGE_VERSION', '1.0.0');
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

add_action('plugins_loaded', function() {
    load_plugin_textdomain('fp-remote-bridge', false, dirname(FP_REMOTE_BRIDGE_BASENAME) . '/languages');
    Plugin::get_instance();
}, 10);
