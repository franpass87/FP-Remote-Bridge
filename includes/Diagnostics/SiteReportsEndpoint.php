<?php
/**
 * Endpoint REST per report read-only mirati (SEO / WPML).
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
 * Espone report di sola lettura verso Cursor / MCP.
 */
final class SiteReportsEndpoint
{
    /**
     * @return void
     */
    public static function register(): void
    {
        register_rest_route('fp-remote-bridge/v1', '/site-reports', [
            'methods' => 'GET',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [SyncEndpoint::class, 'permission_check'],
            'args' => [
                'reports' => [
                    'type' => 'string',
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'limit' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => SiteReportsService::DEFAULT_LIMIT,
                    'validate_callback' => static function ($value): bool {
                        $limit = (int) $value;
                        return $limit >= 1 && $limit <= SiteReportsService::MAX_LIMIT;
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
        $reportsParam = (string) $request->get_param('reports');
        $reports = [];
        if ($reportsParam !== '') {
            $reports = array_values(array_filter(array_map('sanitize_key', explode(',', $reportsParam))));
        }

        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = SiteReportsService::DEFAULT_LIMIT;
        }

        return new WP_REST_Response(SiteReportsService::build($reports, $limit), 200);
    }
}
