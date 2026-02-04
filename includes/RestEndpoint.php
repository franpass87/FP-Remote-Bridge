<?php
/**
 * Endpoint REST per aggiornamento meta SEO da remoto
 *
 * @package FP\RemoteBridge
 */

namespace FP\RemoteBridge;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

class RestEndpoint
{
    /**
     * Meta keys consentiti (whitelist)
     *
     * @var list<string>
     */
    private static $allowed_keys = [
        '_fp_seo_title',
        '_fp_seo_meta_description',
        '_fp_seo_focus_keyword',
        '_fp_seo_meta_canonical',
        '_fp_seo_meta_robots',
        '_fp_seo_geo_claims',
        '_fp_seo_geo_expose',
        '_fp_seo_geo_no_ai_reuse',
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_focuskw',
    ];

    /**
     * Registra l'endpoint REST
     */
    public static function register(): void
    {
        register_rest_route('fp-publisher/v1', '/update-seo-meta', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle_request'],
            'permission_callback' => [self::class, 'permission_check'],
            'args' => [
                'post_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'meta' => [
                    'required' => true,
                    'type' => 'object',
                ],
            ],
        ]);
    }

    /**
     * Verifica permessi
     *
     * @return bool
     */
    public static function permission_check(): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * Gestisce la richiesta POST
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_request(WP_REST_Request $request): WP_REST_Response
    {
        $post_id = (int) $request->get_param('post_id');
        $meta = $request->get_param('meta');

        $post = get_post($post_id);
        if (!$post) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Post not found',
            ], 404);
        }

        if (!current_user_can('edit_post', $post_id)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Permission denied',
            ], 403);
        }

        $saved = [];
        $errors = [];

        if (is_array($meta)) {
            foreach ($meta as $key => $value) {
                if (!in_array($key, self::$allowed_keys, true)) {
                    $errors[] = "Meta key not allowed: {$key}";
                    continue;
                }

                if (is_array($value)) {
                    $sanitized = array_map('sanitize_text_field', $value);
                } elseif (strpos($key, 'canonical') !== false) {
                    $sanitized = esc_url_raw($value);
                } else {
                    $sanitized = sanitize_text_field($value);
                }

                $result = update_post_meta($post_id, $key, $sanitized);
                if ($result !== false) {
                    $saved[$key] = true;
                } else {
                    $errors[] = "Failed to save: {$key}";
                }
            }
        }

        $status_code = count($errors) === 0 ? 200 : 207;

        return new WP_REST_Response([
            'success' => count($errors) === 0,
            'post_id' => $post_id,
            'saved' => $saved,
            'errors' => $errors,
        ], $status_code);
    }
}
