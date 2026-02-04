<?php
/**
 * Abilita i meta SEO nel REST API per Yoast e FP SEO Manager
 *
 * @package FP\RemoteBridge
 */

namespace FP\RemoteBridge;

if (!defined('ABSPATH')) {
    exit;
}

class SeoRest
{
    /**
     * Meta keys per FP SEO Manager e Yoast SEO
     *
     * @var array<string, array<string, mixed>>
     */
    private static $seo_meta_keys = [
        '_fp_seo_title' => [
            'type' => 'string',
            'description' => 'SEO Title (FP SEO Manager)',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback' => [self::class, 'auth_edit_posts'],
        ],
        '_fp_seo_meta_description' => [
            'type' => 'string',
            'description' => 'Meta Description (FP SEO Manager)',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback' => [self::class, 'auth_edit_posts'],
        ],
        '_fp_seo_focus_keyword' => [
            'type' => 'string',
            'description' => 'Focus Keyword (FP SEO Manager)',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback' => [self::class, 'auth_edit_posts'],
        ],
        '_fp_seo_meta_canonical' => [
            'type' => 'string',
            'description' => 'Canonical URL (FP SEO Manager)',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => 'esc_url_raw',
            'auth_callback' => [self::class, 'auth_edit_posts'],
        ],
        '_fp_seo_meta_robots' => [
            'type' => 'string',
            'description' => 'Robots Meta (FP SEO Manager)',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback' => [self::class, 'auth_edit_posts'],
        ],
        '_fp_seo_geo_claims' => [
            'type' => 'string',
            'description' => 'Geo claims (FP SEO Manager)',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback' => [self::class, 'auth_edit_posts'],
        ],
        '_fp_seo_geo_expose' => [
            'type' => 'string',
            'description' => 'Geo expose (FP SEO Manager)',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback' => [self::class, 'auth_edit_posts'],
        ],
        '_fp_seo_geo_no_ai_reuse' => [
            'type' => 'string',
            'description' => 'Geo no AI reuse (FP SEO Manager)',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback' => [self::class, 'auth_edit_posts'],
        ],
        '_yoast_wpseo_title' => [
            'type' => 'string',
            'description' => 'SEO Title (Yoast)',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback' => [self::class, 'auth_edit_posts'],
        ],
        '_yoast_wpseo_metadesc' => [
            'type' => 'string',
            'description' => 'Meta Description (Yoast)',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback' => [self::class, 'auth_edit_posts'],
        ],
        '_yoast_wpseo_focuskw' => [
            'type' => 'string',
            'description' => 'Focus Keyword (Yoast)',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback' => [self::class, 'auth_edit_posts'],
        ],
    ];

    /**
     * Inizializza la registrazione dei meta SEO nel REST API
     */
    public static function init(): void
    {
        add_action('init', [self::class, 'register_seo_meta'], 99);
        add_filter('register_post_meta_args', [self::class, 'yoast_show_in_rest'], 10, 4);
    }

    /**
     * Auth callback per edit_posts
     *
     * @return bool
     */
    public static function auth_edit_posts(): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * Registra i meta SEO per post e page
     */
    public static function register_seo_meta(): void
    {
        foreach (self::$seo_meta_keys as $meta_key => $args) {
            register_post_meta('post', $meta_key, $args);
            register_post_meta('page', $meta_key, $args);
        }
    }

    /**
     * Filtro per abilitare show_in_rest sui meta Yoast già registrati
     *
     * @param array<string, mixed> $args
     * @param string $default
     * @param string $object_type
     * @param string $meta_key
     * @return array<string, mixed>
     */
    public static function yoast_show_in_rest($args, $default, $object_type, $meta_key)
    {
        $yoast_keys = ['_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw'];
        if (in_array($meta_key, $yoast_keys, true)) {
            $args['show_in_rest'] = true;
            if (!isset($args['auth_callback'])) {
                $args['auth_callback'] = [self::class, 'auth_edit_posts'];
            }
        }
        return $args;
    }
}
