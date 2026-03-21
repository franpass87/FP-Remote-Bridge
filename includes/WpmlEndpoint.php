<?php

declare(strict_types=1);

/**
 * Endpoint REST per collegare traduzioni WPML da remoto.
 *
 * FP-Publisher chiama questo endpoint dopo aver creato un post traduzione via REST API,
 * per collegarlo all'originale tramite wpml_set_element_language_details.
 *
 * @package FP\RemoteBridge
 */

namespace FP\RemoteBridge;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

class WpmlEndpoint
{
    /**
     * Registra l'endpoint REST.
     */
    public static function register(): void
    {
        register_rest_route('fp-publisher/v1', '/wpml-link-translation', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_request'],
            'permission_callback' => [self::class, 'permission_check'],
            'args'                => [
                'original_id'   => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'translation_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'language_code'  => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'post_type'      => [
                    'required' => false,
                    'type'     => 'string',
                    'default'  => 'post',
                ],
            ],
        ]);
    }

    /**
     * Verifica permessi: utente autenticato con edit_posts (come RestEndpoint SEO).
     */
    public static function permission_check(): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * Collega il post traduzione all'originale tramite WPML.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_request(WP_REST_Request $request): WP_REST_Response
    {
        if (!function_exists('apply_filters') || !function_exists('do_action')) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'WPML hooks not available',
            ], 500);
        }

        $originalId   = (int) $request->get_param('original_id');
        $translationId = (int) $request->get_param('translation_id');
        $languageCode  = sanitize_text_field($request->get_param('language_code'));
        $postType      = sanitize_key($request->get_param('post_type') ?: 'post');

        if ($originalId <= 0 || $translationId <= 0 || $languageCode === '') {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'original_id, translation_id and language_code are required',
            ], 400);
        }

        $originalPost = get_post($originalId);
        $translationPost = get_post($translationId);

        if (!$originalPost || !$translationPost) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'Original or translation post not found',
            ], 404);
        }

        if (!current_user_can('edit_post', $originalId) || !current_user_can('edit_post', $translationId)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'Permission denied',
            ], 403);
        }

        $elementType = apply_filters('wpml_element_type', $postType);
        if (!$elementType || !is_string($elementType)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'WPML not active or element type not supported',
            ], 501);
        }

        $originalLangInfo = apply_filters(
            'wpml_element_language_details',
            null,
            ['element_id' => $originalId, 'element_type' => $postType]
        );

        if (!$originalLangInfo || !isset($originalLangInfo->trid)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'Could not retrieve original post language details (WPML may not have assigned it yet)',
            ], 422);
        }

        $setArgs = [
            'element_id'           => $translationId,
            'element_type'         => $elementType,
            'trid'                 => $originalLangInfo->trid,
            'language_code'        => $languageCode,
            'source_language_code' => $originalLangInfo->language_code ?? null,
            'check_duplicates'     => false,
        ];

        do_action('wpml_set_element_language_details', $setArgs);

        return new WP_REST_Response([
            'success'        => true,
            'original_id'    => $originalId,
            'translation_id' => $translationId,
            'language_code'  => $languageCode,
            'trid'           => $originalLangInfo->trid,
        ], 200);
    }
}
