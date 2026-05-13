<?php
/**
 * Aggregatore diagnostica sito per Cursor / site-intelligence.
 *
 * @package FP\RemoteBridge\Diagnostics
 */

declare(strict_types=1);

namespace FP\RemoteBridge\Diagnostics;

use FP\RemoteBridge\MasterSync;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Costruisce lo snapshot read-only esposto via REST.
 */
final class SiteIntelligenceService
{
    public const DEFAULT_CLIENT_ERROR_LIMIT = 50;
    public const DEFAULT_LOG_LINES = 40;

    /**
     * @param array<int, string> $sections Sezioni richieste; vuoto = tutte.
     * @return array<string, mixed>
     */
    public static function build(array $sections = []): array
    {
        $available = ['general', 'errors', 'performance', 'seo', 'client_js'];
        $requested = $sections === [] ? $available : array_values(array_intersect($sections, $available));
        if ($requested === []) {
            $requested = $available;
        }

        $payload = [
            'success' => true,
            'generated_at' => current_time('mysql'),
            'generated_at_gmt' => gmdate('Y-m-d H:i:s'),
            'site_url' => site_url(),
            'site_name' => get_bloginfo('name', 'display') ?: parse_url(site_url(), PHP_URL_HOST) ?: '',
            'bridge_version' => defined('FP_REMOTE_BRIDGE_VERSION') ? FP_REMOTE_BRIDGE_VERSION : '',
            'sections' => $requested,
        ];

        foreach ($requested as $section) {
            switch ($section) {
                case 'general':
                    $payload['general'] = self::build_general_section();
                    break;
                case 'errors':
                    $payload['errors'] = self::build_errors_section();
                    break;
                case 'performance':
                    $payload['performance'] = self::build_performance_section();
                    break;
                case 'seo':
                    $payload['seo'] = self::build_seo_section();
                    break;
                case 'client_js':
                    $payload['client_js'] = self::build_client_js_section(self::DEFAULT_CLIENT_ERROR_LIMIT);
                    break;
            }
        }

        return apply_filters('fp_remote_bridge_site_intelligence', $payload, $requested);
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_general_section(): array
    {
        global $wp_version;

        $plugins = MasterSync::get_installed_plugin_slugs();
        $activePlugins = (array) get_option('active_plugins', []);
        $theme = wp_get_theme();

        return [
            'wordpress_version' => is_string($wp_version ?? null) ? $wp_version : '',
            'php_version' => PHP_VERSION,
            'is_multisite' => is_multisite(),
            'locale' => get_locale(),
            'timezone' => wp_timezone_string(),
            'active_theme' => [
                'name' => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
                'stylesheet' => (string) $theme->get_stylesheet(),
            ],
            'active_plugins_count' => count($activePlugins),
            'installed_fp_plugins' => self::filter_fp_plugins($plugins),
            'site_health' => self::get_site_health_summary(),
            'debug' => [
                'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
                'wp_debug_log' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
                'wp_debug_display' => defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_errors_section(): array
    {
        return [
            'debug_log' => self::tail_log_file(WP_CONTENT_DIR . '/debug.log', self::DEFAULT_LOG_LINES),
            'php_error_log' => self::tail_php_error_log(self::DEFAULT_LOG_LINES),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_performance_section(): array
    {
        $section = [
            'fp_performance_active' => defined('FP_PERF_SUITE_VERSION'),
            'fp_performance_version' => defined('FP_PERF_SUITE_VERSION') ? (string) FP_PERF_SUITE_VERSION : '',
            'signals' => [],
            'signals_source' => 'normalized_options',
        ];

        if (!defined('FP_PERF_SUITE_VERSION')) {
            return $section;
        }

        $section['signals'] = FpPerformanceSignals::collect();

        $detectedScripts = get_transient('fp_ps_detected_scripts');
        if (is_array($detectedScripts)) {
            $section['detected_scripts_count'] = count($detectedScripts);
        }

        return $section;
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_seo_section(): array
    {
        $section = [
            'fp_seo_active' => defined('FP_SEO_PERFORMANCE_FILE'),
            'fp_seo_version' => self::resolve_fp_seo_version(),
            'homepage' => self::probe_homepage_seo_signals(),
            'monitoring' => [],
        ];

        $log404 = get_option('fp_seo_monitor_404_log', []);
        if (is_array($log404)) {
            $section['monitoring']['recent_404_count'] = count($log404);
            $section['monitoring']['recent_404_samples'] = array_slice($log404, 0, 5);
        }

        $brokenLinks = get_option('fp_seo_monitor_broken_links', []);
        if (is_array($brokenLinks)) {
            $section['monitoring']['broken_links_count'] = count($brokenLinks);
        }

        $seoOptions = get_option('fp_seo_perf_options', []);
        if (is_array($seoOptions)) {
            $section['settings'] = [
                'psi_enabled' => !empty($seoOptions['enable_psi']),
                'analyzer_enabled' => !empty($seoOptions['enable_analyzer']),
            ];
        }

        return $section;
    }

    /**
     * @param int $limit Numero massimo eventi client.
     * @return array<string, mixed>
     */
    private static function build_client_js_section(int $limit): array
    {
        return [
            'collection_enabled' => DiagnosticsSettings::is_client_error_collection_enabled(),
            'capture_console' => DiagnosticsSettings::is_console_capture_enabled(),
            'summary' => ClientErrorStore::get_summary(),
            'recent' => ClientErrorStore::get_recent($limit),
        ];
    }

    /**
     * @param array<int, string> $plugins Elenco slug:versione.
     * @return array<string, string>
     */
    private static function filter_fp_plugins(array $plugins): array
    {
        $filtered = [];
        foreach ($plugins as $entry) {
            if (!is_string($entry) || strpos($entry, ':') === false) {
                continue;
            }

            [$slug, $version] = explode(':', $entry, 2);
            if (strpos($slug, 'fp-') === 0 || strpos($slug, 'FP-') === 0) {
                $filtered[$slug] = $version;
            }
        }

        ksort($filtered);

        return $filtered;
    }

    /**
     * @return array<string, mixed>
     */
    private static function get_site_health_summary(): array
    {
        $summary = [
            'status' => 'unknown',
            'critical' => 0,
            'recommended' => 0,
            'good' => 0,
        ];

        $cached = get_transient('health-check-site-status-result');
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                $summary['status'] = 'cached';
                $summary['critical'] = (int) ($decoded['critical'] ?? 0);
                $summary['recommended'] = (int) ($decoded['recommended'] ?? 0);
                $summary['good'] = (int) ($decoded['good'] ?? 0);
            }
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private static function probe_homepage_seo_signals(): array
    {
        $homeUrl = home_url('/');
        $response = wp_remote_get($homeUrl, [
            'timeout' => 10,
            'redirection' => 3,
            'user-agent' => 'FP-Remote-Bridge-Site-Intelligence/1.0',
        ]);

        if (is_wp_error($response)) {
            return [
                'url' => $homeUrl,
                'reachable' => false,
                'error' => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $title = '';
        if ($body !== '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $matches) === 1) {
            $title = trim(wp_strip_all_tags($matches[1]));
        }

        $hasMetaDescription = stripos($body, 'name="description"') !== false || stripos($body, "name='description'") !== false;
        $hasCanonical = stripos($body, 'rel="canonical"') !== false || stripos($body, "rel='canonical'") !== false;

        return [
            'url' => $homeUrl,
            'reachable' => $code >= 200 && $code < 400,
            'http_status' => $code,
            'title' => $title,
            'has_meta_description' => $hasMetaDescription,
            'has_canonical' => $hasCanonical,
        ];
    }

    /**
     * @param string $path Percorso file log.
     * @param int $lines Numero righe finali.
     * @return array<string, mixed>
     */
    private static function tail_log_file(string $path, int $lines): array
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_readable($realPath) || !is_file($realPath)) {
            return [
                'available' => false,
                'path' => basename($path),
                'lines' => [],
            ];
        }

        $content = @file($realPath, FILE_IGNORE_NEW_LINES);
        if (!is_array($content)) {
            return [
                'available' => false,
                'path' => basename($path),
                'lines' => [],
            ];
        }

        $tail = array_slice($content, -1 * max(1, $lines));
        $sanitized = [];
        foreach ($tail as $line) {
            $sanitized[] = self::sanitize_log_line((string) $line);
        }

        return [
            'available' => true,
            'path' => basename($path),
            'lines' => $sanitized,
        ];
    }

    /**
     * @param int $lines Numero righe finali.
     * @return array<string, mixed>
     */
    private static function tail_php_error_log(int $lines): array
    {
        $iniPath = ini_get('error_log');
        if (!is_string($iniPath) || $iniPath === '' || stripos($iniPath, 'syslog') !== false) {
            return [
                'available' => false,
                'path' => '',
                'lines' => [],
            ];
        }

        return self::tail_log_file($iniPath, $lines);
    }

    /**
     * @return string
     */
    private static function resolve_fp_seo_version(): string
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        foreach ($plugins as $pluginFile => $data) {
            if (!is_array($data) || stripos($pluginFile, 'fp-seo') === false) {
                continue;
            }

            return isset($data['Version']) ? (string) $data['Version'] : '';
        }

        return '';
    }

    /**
     * @param string $line Riga di log grezza.
     * @return string Riga sanitizzata.
     */
    private static function sanitize_log_line(string $line): string
    {
        $line = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $line) ?? $line;
        $line = preg_replace('/https?:\/\/[^\s]+/i', '[url]', $line) ?? $line;

        return substr($line, 0, 1000);
    }
}
