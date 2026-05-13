<?php
/**
 * Impostazioni diagnostica remota (Cursor / site-intelligence).
 *
 * @package FP\RemoteBridge\Diagnostics
 */

declare(strict_types=1);

namespace FP\RemoteBridge\Diagnostics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Opzioni per la raccolta errori client e l'esposizione diagnostica.
 */
final class DiagnosticsSettings
{
    public const OPTION_CLIENT_ERRORS = 'fp_remote_bridge_diag_client_errors_enabled';
    public const OPTION_CAPTURE_CONSOLE = 'fp_remote_bridge_diag_capture_console';

    /**
     * @return bool
     */
    public static function is_client_error_collection_enabled(): bool
    {
        $value = get_option(self::OPTION_CLIENT_ERRORS, true);
        if ($value === '' || $value === null) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return bool
     */
    public static function is_console_capture_enabled(): bool
    {
        if (!self::is_client_error_collection_enabled()) {
            return false;
        }

        $value = get_option(self::OPTION_CAPTURE_CONSOLE, true);
        if ($value === '' || $value === null) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
