<?php
/**
 * Classe principale del plugin FP Remote Bridge
 *
 * @package FP\RemoteBridge
 */

namespace FP\RemoteBridge;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin
{
    /** @var self|null */
    private static $instance = null;

    /**
     * Singleton instance
     *
     * @return self
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        SeoRest::init();
        add_action('rest_api_init', [RestEndpoint::class, 'register']);
        add_action('rest_api_init', [PluginUpdateEndpoint::class, 'register']);
        Settings::init();
        MasterSync::init();
    }

    /**
     * Previene clonazione
     */
    private function __clone()
    {
    }

    /**
     * Previeni deserializzazione
     */
    public function __wakeup()
    {
        _doing_it_wrong(
            __FUNCTION__,
            __('Deserializzazione non permessa.', 'fp-remote-bridge'),
            FP_REMOTE_BRIDGE_VERSION
        );
    }
}
