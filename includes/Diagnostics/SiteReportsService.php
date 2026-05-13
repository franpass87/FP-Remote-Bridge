<?php
/**
 * Report read-only mirati (SEO, WPML e FP Multilanguage) per siti remoti.
 *
 * @package FP\RemoteBridge\Diagnostics
 */

declare(strict_types=1);

namespace FP\RemoteBridge\Diagnostics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Esegue query di sola lettura su contenuti pubblicati.
 */
final class SiteReportsService
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    /**
     * @var list<string>
     */
    private const SEO_DESCRIPTION_KEYS = [
        '_fp_seo_meta_description',
        '_yoast_wpseo_metadesc',
    ];

    /**
     * @var list<string>
     */
    private const SEO_TITLE_KEYS = [
        '_fp_seo_title',
        '_yoast_wpseo_title',
    ];

    /**
     * @var list<string>
     */
    private const DEFAULT_POST_TYPES = [
        'post',
        'page',
        'product',
    ];

    /**
     * @param array<int, string> $reports Report richiesti.
     * @param int $limit Numero massimo righe per report.
     * @return array<string, mixed>
     */
    public static function build(array $reports, int $limit): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $available = ['seo_gaps', 'wpml_gaps', 'fp_ml_gaps'];
        $requested = $reports === [] ? $available : array_values(array_intersect($reports, $available));
        if ($requested === []) {
            $requested = $available;
        }

        $payload = [
            'success' => true,
            'generated_at' => current_time('mysql'),
            'generated_at_gmt' => gmdate('Y-m-d H:i:s'),
            'site_url' => site_url(),
            'reports' => $requested,
            'limit' => $limit,
        ];

        foreach ($requested as $report) {
            switch ($report) {
                case 'seo_gaps':
                    $payload['seo_gaps'] = self::build_seo_gaps_report($limit);
                    break;
                case 'wpml_gaps':
                    $payload['wpml_gaps'] = self::build_wpml_gaps_report($limit);
                    break;
                case 'fp_ml_gaps':
                    $payload['fp_ml_gaps'] = self::build_fp_ml_gaps_report($limit);
                    break;
            }
        }

        return apply_filters('fp_remote_bridge_site_reports', $payload, $requested, $limit);
    }

    /**
     * @param int $limit Numero massimo righe.
     * @return array<string, mixed>
     */
    private static function build_seo_gaps_report(int $limit): array
    {
        global $wpdb;

        $postTypes = self::get_scannable_post_types();
        if ($postTypes === []) {
            return [
                'post_types' => [],
                'meta_keys_checked' => array_merge(self::SEO_DESCRIPTION_KEYS, self::SEO_TITLE_KEYS),
                'summary' => [
                    'published_total' => 0,
                    'missing_description' => 0,
                    'missing_title' => 0,
                    'missing_any' => 0,
                ],
                'items' => [],
            ];
        }

        $placeholders = implode(', ', array_fill(0, count($postTypes), '%s'));
        $sql = "
            SELECT p.ID, p.post_title, p.post_type
            FROM {$wpdb->posts} p
            WHERE p.post_status = 'publish'
              AND p.post_type IN ($placeholders)
            ORDER BY p.post_modified_gmt DESC
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$postTypes), ARRAY_A);
        if (!is_array($rows)) {
            $rows = [];
        }

        $items = [];
        $missingDescription = 0;
        $missingTitle = 0;
        $missingAny = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $postId = (int) ($row['ID'] ?? 0);
            if ($postId <= 0) {
                continue;
            }

            $missingFields = self::get_missing_seo_fields($postId);
            if ($missingFields === []) {
                continue;
            }

            if (in_array('description', $missingFields, true)) {
                ++$missingDescription;
            }
            if (in_array('title', $missingFields, true)) {
                ++$missingTitle;
            }
            ++$missingAny;

            if (count($items) >= $limit) {
                continue;
            }

            $items[] = [
                'id' => $postId,
                'title' => (string) ($row['post_title'] ?? ''),
                'post_type' => (string) ($row['post_type'] ?? ''),
                'permalink' => get_permalink($postId) ?: '',
                'edit_url' => get_edit_post_link($postId, 'raw') ?: '',
                'missing' => $missingFields,
            ];
        }

        return [
            'post_types' => $postTypes,
            'meta_keys_checked' => array_merge(self::SEO_DESCRIPTION_KEYS, self::SEO_TITLE_KEYS),
            'summary' => [
                'published_total' => count($rows),
                'missing_description' => $missingDescription,
                'missing_title' => $missingTitle,
                'missing_any' => $missingAny,
            ],
            'items' => $items,
        ];
    }

    /**
     * @param int $limit Numero massimo righe.
     * @return array<string, mixed>
     */
    private static function build_wpml_gaps_report(int $limit): array
    {
        global $wpdb;

        if (!self::is_wpml_active()) {
            return [
                'wpml_active' => false,
                'source_language' => '',
                'target_language' => 'en',
                'summary' => [
                    'missing_translation' => 0,
                ],
                'items' => [],
            ];
        }

        $sourceLanguage = self::get_wpml_default_language();
        $targetLanguage = 'en';
        $postTypes = self::get_scannable_post_types();
        if ($postTypes === []) {
            return [
                'wpml_active' => true,
                'source_language' => $sourceLanguage,
                'target_language' => $targetLanguage,
                'summary' => [
                    'missing_translation' => 0,
                ],
                'items' => [],
            ];
        }

        $translationsTable = $wpdb->prefix . 'icl_translations';
        $items = [];
        $missingCount = 0;

        foreach ($postTypes as $postType) {
            $elementType = 'post_' . $postType;
            $sql = "
                SELECT p.ID, p.post_title, p.post_type
                FROM {$wpdb->posts} p
                INNER JOIN {$translationsTable} src
                    ON src.element_id = p.ID
                    AND src.element_type = %s
                WHERE p.post_status = 'publish'
                  AND p.post_type = %s
                  AND src.language_code = %s
                  AND NOT EXISTS (
                      SELECT 1
                      FROM {$translationsTable} tgt
                      WHERE tgt.trid = src.trid
                        AND tgt.language_code = %s
                  )
                ORDER BY p.post_modified_gmt DESC
                LIMIT %d
            ";

            $rows = $wpdb->get_results(
                $wpdb->prepare($sql, $elementType, $postType, $sourceLanguage, $targetLanguage, $limit),
                ARRAY_A
            );

            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                ++$missingCount;
                if (count($items) >= $limit) {
                    continue;
                }

                $postId = (int) ($row['ID'] ?? 0);
                $items[] = [
                    'id' => $postId,
                    'title' => (string) ($row['post_title'] ?? ''),
                    'post_type' => (string) ($row['post_type'] ?? ''),
                    'permalink' => get_permalink($postId) ?: '',
                    'edit_url' => get_edit_post_link($postId, 'raw') ?: '',
                    'source_language' => $sourceLanguage,
                    'target_language' => $targetLanguage,
                ];
            }
        }

        return [
            'wpml_active' => true,
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'summary' => [
                'missing_translation' => $missingCount,
            ],
            'items' => $items,
        ];
    }

    /**
     * @param int $limit Numero massimo righe.
     * @return array<string, mixed>
     */
    private static function build_fp_ml_gaps_report(int $limit): array
    {
        global $wpdb;

        if (!self::is_fp_multilanguage_active()) {
            return [
                'fp_multilanguage_active' => false,
                'target_language' => 'en',
                'summary' => [
                    'missing_translation' => 0,
                ],
                'items' => [],
            ];
        }

        $targetLanguage = 'en';
        $postTypes = self::get_scannable_post_types();
        if ($postTypes === []) {
            return [
                'fp_multilanguage_active' => true,
                'target_language' => $targetLanguage,
                'summary' => [
                    'missing_translation' => 0,
                ],
                'items' => [],
            ];
        }

        $placeholders = implode(', ', array_fill(0, count($postTypes), '%s'));
        $sql = "
            SELECT p.ID, p.post_title, p.post_type
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} is_translation
                ON is_translation.post_id = p.ID
                AND is_translation.meta_key = '_fpml_is_translation'
            LEFT JOIN {$wpdb->postmeta} pair_lang
                ON pair_lang.post_id = p.ID
                AND pair_lang.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} pair_legacy
                ON pair_legacy.post_id = p.ID
                AND pair_legacy.meta_key = '_fpml_pair_id'
            WHERE p.post_status = 'publish'
              AND p.post_type IN ($placeholders)
              AND (is_translation.meta_value IS NULL OR is_translation.meta_value <> '1')
              AND (
                    pair_lang.meta_id IS NULL
                    OR pair_lang.meta_value = ''
                    OR pair_lang.meta_value = '0'
              )
              AND (
                    pair_legacy.meta_id IS NULL
                    OR pair_legacy.meta_value = ''
                    OR pair_legacy.meta_value = '0'
              )
            ORDER BY p.post_modified_gmt DESC
        ";

        $pairMetaKey = '_fpml_pair_id_' . $targetLanguage;
        $params = array_merge([$pairMetaKey], $postTypes);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (!is_array($rows)) {
            $rows = [];
        }

        $items = [];
        $missingCount = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $postId = (int) ($row['ID'] ?? 0);
            if ($postId <= 0) {
                continue;
            }

            if (function_exists('fpml_get_translation_id')) {
                $translationId = (int) fpml_get_translation_id($postId, $targetLanguage);
                if ($translationId > 0) {
                    continue;
                }
            }

            ++$missingCount;
            if (count($items) >= $limit) {
                continue;
            }

            $items[] = [
                'id' => $postId,
                'title' => (string) ($row['post_title'] ?? ''),
                'post_type' => (string) ($row['post_type'] ?? ''),
                'permalink' => get_permalink($postId) ?: '',
                'edit_url' => get_edit_post_link($postId, 'raw') ?: '',
                'target_language' => $targetLanguage,
            ];
        }

        return [
            'fp_multilanguage_active' => true,
            'target_language' => $targetLanguage,
            'summary' => [
                'missing_translation' => $missingCount,
            ],
            'items' => $items,
        ];
    }

    /**
     * @return list<string>
     */
    private static function get_scannable_post_types(): array
    {
        $types = [];
        foreach (get_post_types(['public' => true], 'names') as $postType) {
            if (!is_string($postType) || $postType === 'attachment') {
                continue;
            }

            if (in_array($postType, ['revision', 'nav_menu_item'], true)) {
                continue;
            }

            $types[] = $postType;
        }

        if ($types === []) {
            return self::DEFAULT_POST_TYPES;
        }

        sort($types);

        return array_values($types);
    }

    /**
     * @param int $postId ID contenuto.
     * @return list<string>
     */
    private static function get_missing_seo_fields(int $postId): array
    {
        $missing = [];

        if (!self::has_meta_value($postId, self::SEO_DESCRIPTION_KEYS)) {
            $missing[] = 'description';
        }

        if (!self::has_meta_value($postId, self::SEO_TITLE_KEYS)) {
            $missing[] = 'title';
        }

        return $missing;
    }

    /**
     * @param int $postId ID contenuto.
     * @param list<string> $metaKeys Chiavi meta da verificare.
     * @return bool
     */
    private static function has_meta_value(int $postId, array $metaKeys): bool
    {
        foreach ($metaKeys as $metaKey) {
            $value = get_post_meta($postId, $metaKey, true);
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    private static function is_fp_multilanguage_active(): bool
    {
        if (defined('FPML_RUNTIME_BOOTSTRAPPED')) {
            return true;
        }

        if (function_exists('fpml_get_translation_id') || function_exists('fpml_get_enabled_languages')) {
            return true;
        }

        return class_exists('FP\\Multilanguage\\Core\\Plugin');
    }

    /**
     * @return bool
     */
    private static function is_wpml_active(): bool
    {
        global $wpdb;

        if (class_exists('SitePress') || defined('ICL_SITEPRESS_VERSION')) {
            return true;
        }

        $table = $wpdb->prefix . 'icl_translations';

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * @return string
     */
    private static function get_wpml_default_language(): string
    {
        $settings = get_option('icl_sitepress_settings', []);
        if (is_array($settings) && !empty($settings['default_language']) && is_string($settings['default_language'])) {
            return $settings['default_language'];
        }

        $locale = get_locale();
        if (strpos($locale, '_') !== false) {
            return strtolower(substr($locale, 0, (int) strpos($locale, '_')));
        }

        return strtolower($locale);
    }
}
