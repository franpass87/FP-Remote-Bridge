<?php
/**
 * Normalizza i segnali FP Performance per la diagnostica remota.
 *
 * @package FP\RemoteBridge\Diagnostics
 */

declare(strict_types=1);

namespace FP\RemoteBridge\Diagnostics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Legge le opzioni correnti di FP Performance con fallback legacy.
 */
final class FpPerformanceSignals
{
    /**
     * @return array<string, bool>
     */
    public static function collect(): array
    {
        if (!defined('FP_PERF_SUITE_VERSION')) {
            return [];
        }

        $assets = self::getArrayOption('fp_ps_assets');

        return [
            'page_cache_enabled' => self::isEnabled('fp_ps_page_cache_settings')
                || self::isEnabled('fp_ps_page_cache')
                || self::isLegacyFlagEnabled('fp_ps_page_cache_enabled'),
            'browser_cache_enabled' => self::isEnabled('fp_ps_browser_cache'),
            'object_cache_enabled' => self::isEnabled('fp_ps_object_cache')
                || self::isLegacyFlagEnabled('fp_ps_object_cache_enabled'),
            'lazy_load_enabled' => self::isEnabled('fp_ps_lazy_load')
                || self::isLegacyFlagEnabled('fp_ps_lazy_load_enabled')
                || self::isLegacyFlagEnabled('fp_ps_lazy_loading_enabled'),
            'asset_optimizer_enabled' => !empty($assets['enabled'])
                || self::isLegacyFlagEnabled('fp_ps_asset_optimization_enabled'),
            'minify_css_enabled' => !empty($assets['minify_css'])
                || self::isLegacyFlagEnabled('fp_ps_minify_css'),
            'minify_js_enabled' => !empty($assets['minify_js'])
                || self::isLegacyFlagEnabled('fp_ps_minify_js'),
            'defer_js_enabled' => !empty($assets['defer_js']),
            'predictive_prefetch_enabled' => self::isEnabled('fp_ps_predictive_prefetch'),
            'external_cache_enabled' => self::isEnabled('fp_ps_external_cache'),
            'edge_cache_enabled' => self::isEnabled('fp_ps_edge_cache_settings')
                || self::isLegacyFlagEnabled('fp_ps_edge_cache_enabled'),
            'compression_enabled' => self::isLegacyFlagEnabled('fp_ps_compression_enabled')
                || self::isLegacyFlagEnabled('fp_ps_compression_deflate_enabled')
                || self::isLegacyFlagEnabled('fp_ps_compression_brotli_enabled'),
            'cdn_enabled' => self::isEnabled('fp_ps_cdn')
                || self::isEnabled('fp_ps_cdn_settings'),
        ];
    }

    /**
     * @param string $optionName Nome opzione WordPress.
     * @param string $key Chiave booleana nel payload serializzato.
     * @return bool
     */
    private static function isEnabled(string $optionName, string $key = 'enabled'): bool
    {
        $option = self::getArrayOption($optionName);

        return !empty($option[$key]);
    }

    /**
     * @param string $optionName Nome opzione legacy booleana.
     * @return bool
     */
    private static function isLegacyFlagEnabled(string $optionName): bool
    {
        $value = get_option($optionName, false);

        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * @param string $optionName Nome opzione WordPress.
     * @return array<string, mixed>
     */
    private static function getArrayOption(string $optionName): array
    {
        $option = get_option($optionName, []);

        return is_array($option) ? $option : [];
    }
}
