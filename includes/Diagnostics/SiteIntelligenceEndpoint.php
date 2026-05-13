<?php
/**
 * Endpoint REST site-intelligence per diagnostica remota (Cursor / MCP).
 *
 * @package FP\RemoteBridge\Diagnostics
 */

declare(strict_types=1);

namespace FP\RemoteBridge\Diagnostics;

use FP\RemoteBridge\SyncEndpoint;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registra la route read-only aggregata per analisi sito remoto.
 */
final class SiteIntelligenceEndpoint
{
    /**
     * @return void
     */
    public static function register(): void
    {
        register_rest_route('fp-remote-bridge/v1', '/site-intelligence', [
            'methods' => 'GET',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [SyncEndpoint::class, 'permission_check'],
            'args' => [
                'sections' => [
                    'type' => 'string',
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'client_errors_limit' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => SiteIntelligenceService::DEFAULT_CLIENT_ERROR_LIMIT,
                    'validate_callback' => static function ($value): bool {
                        $limit = (int) $value;
                        return $limit >= 1 && $limit <= 200;
                    },
                ],
            ],
        ]);
    }

    /**
     * @param WP_REST_Request $request Richiesta REST.
     * @return WP_REST_Response
     */
    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $sectionsParam = (string) $request->get_param('sections');
        $sections = [];
        if ($sectionsParam !== '') {
            $sections = array_values(array_filter(array_map('sanitize_key', explode(',', $sectionsParam))));
        }

        $payload = SiteIntelligenceService::build($sections);

        $limit = (int) $request->get_param('client_errors_limit');
        if ($limit > 0 && isset($payload['client_js']) && is_array($payload['client_js'])) {
            $payload['client_js']['recent'] = ClientErrorStore::get_recent($limit);
        }

        return new WP_REST_Response($payload, 200);
    }
}
