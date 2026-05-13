<?php
/**
 * Script browser per catturare errori JS, promise rejection e console.error.
 *
 * @package FP\RemoteBridge\Diagnostics
 */

declare(strict_types=1);

namespace FP\RemoteBridge\Diagnostics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueue del collector lato frontend e admin.
 */
final class FrontendErrorCollector
{
    /**
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend'], 99);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin'], 99);
    }

    /**
     * @return void
     */
    public static function enqueue_frontend(): void
    {
        if (is_admin() || !DiagnosticsSettings::is_client_error_collection_enabled()) {
            return;
        }

        self::enqueue_script('frontend');
    }

    /**
     * @param string $hook Hook admin corrente.
     * @return void
     */
    public static function enqueue_admin(string $hook): void
    {
        unset($hook);

        if (!DiagnosticsSettings::is_client_error_collection_enabled()) {
            return;
        }

        self::enqueue_script('admin');
    }

    /**
     * @param string $context Contesto di cattura (frontend|admin).
     * @return void
     */
    private static function enqueue_script(string $context): void
    {
        $handle = 'fp-remote-bridge-client-errors';
        $scriptUrl = plugin_dir_url(FP_REMOTE_BRIDGE_FILE) . 'assets/js/client-error-collector.js';

        wp_enqueue_script(
            $handle,
            $scriptUrl,
            [],
            FP_REMOTE_BRIDGE_VERSION,
            true
        );

        wp_localize_script($handle, 'fpRemoteBridgeDiag', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => ClientErrorIngest::AJAX_ACTION,
            'nonce' => wp_create_nonce(ClientErrorIngest::NONCE_ACTION),
            'context' => $context,
            'captureConsole' => DiagnosticsSettings::is_console_capture_enabled(),
            'batchSize' => 5,
            'flushMs' => 4000,
        ]);
    }
}
