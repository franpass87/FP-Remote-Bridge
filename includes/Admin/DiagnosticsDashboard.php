<?php
/**
 * Pagina admin panoramica diagnostica FP Remote Bridge.
 *
 * @package FP\RemoteBridge\Admin
 */

declare(strict_types=1);

namespace FP\RemoteBridge\Admin;

use FP\RemoteBridge\Diagnostics\SiteIntelligenceService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dashboard read-only per salute sito, log PHP e errori browser.
 */
final class DiagnosticsDashboard
{
    public const PAGE_SLUG = 'fp-remote-bridge-diagnostics';
    public const AJAX_ACTION = 'fp_remote_bridge_refresh_diagnostics';
    public const NONCE_ACTION = 'fp_remote_bridge_diagnostics';

    /**
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'handle_refresh']);
    }

    /**
     * @return void
     */
    public static function register_menu(): void
    {
        add_submenu_page(
            'options-general.php',
            __('FP Bridge Diagnostica', 'fp-remote-bridge'),
            __('FP Bridge Diagnostica', 'fp-remote-bridge'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    /**
     * @param string $hook Hook admin corrente.
     * @return void
     */
    public static function enqueue_assets(string $hook): void
    {
        if ($hook !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style(
            'fp-remote-bridge-admin',
            plugin_dir_url(FP_REMOTE_BRIDGE_FILE) . 'assets/css/admin.css',
            [],
            FP_REMOTE_BRIDGE_VERSION
        );

        wp_enqueue_script(
            'fp-remote-bridge-admin-diagnostics',
            plugin_dir_url(FP_REMOTE_BRIDGE_FILE) . 'assets/js/admin-diagnostics.js',
            [],
            FP_REMOTE_BRIDGE_VERSION,
            true
        );

        wp_localize_script('fp-remote-bridge-admin-diagnostics', 'fpRemoteBridgeDiagnostics', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX_ACTION,
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'i18n' => [
                'refreshing' => __('Aggiornamento in corso…', 'fp-remote-bridge'),
                'refresh' => __('Aggiorna panoramica', 'fp-remote-bridge'),
                'error' => __('Impossibile aggiornare la panoramica. Riprova.', 'fp-remote-bridge'),
                'updatedPrefix' => __('Ultimo aggiornamento:', 'fp-remote-bridge'),
            ],
        ]);
    }

    /**
     * @return void
     */
    public static function handle_refresh(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permessi insufficienti.', 'fp-remote-bridge')], 403);
        }

        wp_send_json_success([
            'html' => self::render_dashboard_html(),
            'generated_at' => current_time('mysql'),
        ]);
    }

    /**
     * @return string Markup dashboard diagnostica.
     */
    public static function render_dashboard_html(): string
    {
        ob_start();
        self::render_dashboard_markup(self::build_view_model());

        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    }

    /**
     * @return void
     */
    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $view = self::build_view_model();
        $settingsUrl = admin_url('options-general.php?page=fp-remote-bridge');
        ?>
        <div class="wrap fp-bridge-wrap fpbridge-admin-page fpbridge-diagnostics-page">
            <h1 class="screen-reader-text"><?php esc_html_e('FP Bridge Diagnostica', 'fp-remote-bridge'); ?></h1>
            <div class="fpbridge-page-header">
                <div class="fpbridge-page-header-content">
                    <h2 class="fpbridge-page-header-title" aria-hidden="true">
                        <span class="dashicons dashicons-chart-area"></span>
                        <?php esc_html_e('Panoramica diagnostica', 'fp-remote-bridge'); ?>
                    </h2>
                    <p><?php esc_html_e('Leggi in un colpo d’occhio salute sito, log PHP e errori JavaScript raccolti dal browser.', 'fp-remote-bridge'); ?></p>
                </div>
                <span class="fpbridge-page-header-badge">v<?php echo esc_html(FP_REMOTE_BRIDGE_VERSION); ?></span>
            </div>

            <div class="fpbridge-diagnostics-toolbar">
                <button type="button" class="button button-primary" id="fpbridge-diagnostics-refresh">
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e('Aggiorna panoramica', 'fp-remote-bridge'); ?>
                </button>
                <a class="button" href="<?php echo esc_url($settingsUrl); ?>">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <?php esc_html_e('Impostazioni Bridge', 'fp-remote-bridge'); ?>
                </a>
                <p class="fpbridge-diagnostics-updated">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: data/ora ultimo snapshot */
                            __('Ultimo aggiornamento: %s', 'fp-remote-bridge'),
                            $view['generated_at']
                        )
                    );
                    ?>
                </p>
            </div>

            <div id="fpbridge-diagnostics-root">
                <?php self::render_dashboard_markup($view); ?>
            </div>
        </div>
        <?php
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_view_model(): array
    {
        $snapshot = SiteIntelligenceService::build();

        return [
            'generated_at' => isset($snapshot['generated_at']) ? (string) $snapshot['generated_at'] : current_time('mysql'),
            'site_name' => isset($snapshot['site_name']) ? (string) $snapshot['site_name'] : '',
            'site_url' => isset($snapshot['site_url']) ? (string) $snapshot['site_url'] : site_url(),
            'kpis' => self::build_kpis($snapshot),
            'client_js' => is_array($snapshot['client_js'] ?? null) ? $snapshot['client_js'] : [],
            'errors' => is_array($snapshot['errors'] ?? null) ? $snapshot['errors'] : [],
            'seo' => is_array($snapshot['seo'] ?? null) ? $snapshot['seo'] : [],
            'performance' => is_array($snapshot['performance'] ?? null) ? $snapshot['performance'] : [],
            'general' => is_array($snapshot['general'] ?? null) ? $snapshot['general'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot Snapshot site-intelligence.
     * @return array<int, array<string, string>>
     */
    private static function build_kpis(array $snapshot): array
    {
        $general = is_array($snapshot['general'] ?? null) ? $snapshot['general'] : [];
        $health = is_array($general['site_health'] ?? null) ? $general['site_health'] : [];
        $clientJs = is_array($snapshot['client_js'] ?? null) ? $snapshot['client_js'] : [];
        $summary = is_array($clientJs['summary'] ?? null) ? $clientJs['summary'] : [];
        $seo = is_array($snapshot['seo'] ?? null) ? $snapshot['seo'] : [];
        $homepage = is_array($seo['homepage'] ?? null) ? $seo['homepage'] : [];

        $critical = (int) ($health['critical'] ?? 0);
        $healthTone = $critical > 0 ? 'danger' : 'success';

        $jsTotal = (int) ($summary['total'] ?? 0);
        $jsTone = $jsTotal > 0 ? 'warning' : 'success';

        $homepageReachable = !empty($homepage['reachable']);
        $homepageTone = $homepageReachable ? 'success' : 'danger';

        return [
            [
                'label' => __('Errori browser', 'fp-remote-bridge'),
                'value' => (string) $jsTotal,
                'hint' => __('Eventi JS, promise e console.error raccolti', 'fp-remote-bridge'),
                'tone' => $jsTone,
            ],
            [
                'label' => __('Site Health critici', 'fp-remote-bridge'),
                'value' => (string) $critical,
                'hint' => __('Conteggio test critici in cache WordPress', 'fp-remote-bridge'),
                'tone' => $healthTone,
            ],
            [
                'label' => __('Homepage', 'fp-remote-bridge'),
                'value' => $homepageReachable ? __('Raggiungibile', 'fp-remote-bridge') : __('Non raggiungibile', 'fp-remote-bridge'),
                'hint' => isset($homepage['http_status']) ? sprintf('HTTP %d', (int) $homepage['http_status']) : __('Verifica SEO base', 'fp-remote-bridge'),
                'tone' => $homepageTone,
            ],
            [
                'label' => __('Stack', 'fp-remote-bridge'),
                'value' => sprintf('WP %s / PHP %s', (string) ($general['wordpress_version'] ?? ''), (string) ($general['php_version'] ?? PHP_VERSION)),
                'hint' => __('Versioni runtime del sito', 'fp-remote-bridge'),
                'tone' => 'neutral',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $view Modello vista.
     * @return void
     */
    private static function render_dashboard_markup(array $view): void
    {
        ?>
        <div class="fpbridge-diagnostics-grid">
            <?php foreach ($view['kpis'] as $kpi) : ?>
                <div class="fpbridge-diagnostics-kpi fpbridge-tone-<?php echo esc_attr((string) ($kpi['tone'] ?? 'neutral')); ?>">
                    <p class="fpbridge-diagnostics-kpi-label"><?php echo esc_html((string) ($kpi['label'] ?? '')); ?></p>
                    <p class="fpbridge-diagnostics-kpi-value"><?php echo esc_html((string) ($kpi['value'] ?? '')); ?></p>
                    <p class="fpbridge-diagnostics-kpi-hint"><?php echo esc_html((string) ($kpi['hint'] ?? '')); ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="fpbridge-diagnostics-columns">
            <div class="fp-bridge-card fpbridge-diagnostics-card">
                <h2 class="fp-bridge-card-title">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e('Errori JavaScript e console', 'fp-remote-bridge'); ?>
                </h2>
                <?php self::render_client_errors_table($view['client_js']); ?>
            </div>

            <div class="fp-bridge-card fpbridge-diagnostics-card">
                <h2 class="fp-bridge-card-title">
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e('Segnali SEO homepage', 'fp-remote-bridge'); ?>
                </h2>
                <?php self::render_seo_panel(is_array($view['seo']) ? $view['seo'] : []); ?>
            </div>
        </div>

        <div class="fpbridge-diagnostics-columns">
            <div class="fp-bridge-card fpbridge-diagnostics-card">
                <h2 class="fp-bridge-card-title">
                    <span class="dashicons dashicons-performance"></span>
                    <?php esc_html_e('Performance FP', 'fp-remote-bridge'); ?>
                </h2>
                <?php self::render_performance_panel(is_array($view['performance']) ? $view['performance'] : []); ?>
            </div>

            <div class="fp-bridge-card fpbridge-diagnostics-card">
                <h2 class="fp-bridge-card-title">
                    <span class="dashicons dashicons-media-code"></span>
                    <?php esc_html_e('Log PHP recenti', 'fp-remote-bridge'); ?>
                </h2>
                <?php self::render_log_panel(is_array($view['errors']) ? $view['errors'] : []); ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $clientJs Sezione client_js.
     * @return void
     */
    private static function render_client_errors_table(array $clientJs): void
    {
        $summary = is_array($clientJs['summary'] ?? null) ? $clientJs['summary'] : [];
        $recent = is_array($clientJs['recent'] ?? null) ? $clientJs['recent'] : [];
        ?>
        <p class="fp-bridge-card-desc">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: 1: total events, 2: console events, 3: frontend events, 4: admin events */
                    __('Totale %1$d · console %2$d · frontend %3$d · admin %4$d', 'fp-remote-bridge'),
                    (int) ($summary['total'] ?? 0),
                    (int) ($summary['console'] ?? 0),
                    (int) ($summary['frontend'] ?? 0),
                    (int) ($summary['admin'] ?? 0)
                )
            );
            ?>
        </p>
        <?php if ($recent === []) : ?>
            <p class="fpbridge-diagnostics-empty"><?php esc_html_e('Nessun errore browser registrato finora. Visita il frontend o l’admin per popolare il buffer.', 'fp-remote-bridge'); ?></p>
        <?php else : ?>
            <table class="widefat striped fpbridge-diagnostics-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Tipo', 'fp-remote-bridge'); ?></th>
                        <th><?php esc_html_e('Messaggio', 'fp-remote-bridge'); ?></th>
                        <th><?php esc_html_e('Contesto', 'fp-remote-bridge'); ?></th>
                        <th><?php esc_html_e('Pagina', 'fp-remote-bridge'); ?></th>
                        <th><?php esc_html_e('Data', 'fp-remote-bridge'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $entry) : ?>
                        <?php if (!is_array($entry)) { continue; } ?>
                        <tr>
                            <td><span class="fpbridge-badge"><?php echo esc_html((string) ($entry['type'] ?? '')); ?></span></td>
                            <td>
                                <strong><?php echo esc_html((string) ($entry['message'] ?? '')); ?></strong>
                                <?php if (!empty($entry['source'])) : ?>
                                    <br><code><?php echo esc_html((string) $entry['source']); ?><?php echo !empty($entry['line']) ? ':' . esc_html((string) $entry['line']) : ''; ?></code>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html((string) ($entry['context'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($entry['page_url'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($entry['captured_at'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    /**
     * @param array<string, mixed> $seo Sezione SEO.
     * @return void
     */
    private static function render_seo_panel(array $seo): void
    {
        $homepage = is_array($seo['homepage'] ?? null) ? $seo['homepage'] : [];
        ?>
        <ul class="fpbridge-diagnostics-list">
            <li><strong><?php esc_html_e('FP SEO attivo', 'fp-remote-bridge'); ?>:</strong> <?php echo !empty($seo['fp_seo_active']) ? esc_html__('Sì', 'fp-remote-bridge') : esc_html__('No', 'fp-remote-bridge'); ?></li>
            <li><strong><?php esc_html_e('Titolo homepage', 'fp-remote-bridge'); ?>:</strong> <?php echo esc_html((string) ($homepage['title'] ?? '—')); ?></li>
            <li><strong><?php esc_html_e('Meta description', 'fp-remote-bridge'); ?>:</strong> <?php echo !empty($homepage['has_meta_description']) ? esc_html__('Presente', 'fp-remote-bridge') : esc_html__('Assente', 'fp-remote-bridge'); ?></li>
            <li><strong><?php esc_html_e('Canonical', 'fp-remote-bridge'); ?>:</strong> <?php echo !empty($homepage['has_canonical']) ? esc_html__('Presente', 'fp-remote-bridge') : esc_html__('Assente', 'fp-remote-bridge'); ?></li>
            <li><strong><?php esc_html_e('404 monitorati', 'fp-remote-bridge'); ?>:</strong> <?php echo esc_html((string) ($seo['monitoring']['recent_404_count'] ?? 0)); ?></li>
        </ul>
        <?php
    }

    /**
     * @param array<string, mixed> $performance Sezione performance.
     * @return void
     */
    private static function render_performance_panel(array $performance): void
    {
        if (empty($performance['fp_performance_active'])) {
            echo '<p class="fpbridge-diagnostics-empty">' . esc_html__('FP Performance non risulta attivo su questo sito.', 'fp-remote-bridge') . '</p>';
            return;
        }

        $signals = is_array($performance['signals'] ?? null) ? $performance['signals'] : [];
        ?>
        <p class="fp-bridge-card-desc">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %s: plugin version */
                    __('Versione FP Performance: %s', 'fp-remote-bridge'),
                    (string) ($performance['fp_performance_version'] ?? '')
                )
            );
            ?>
        </p>
        <ul class="fpbridge-diagnostics-list">
            <?php foreach ($signals as $key => $enabled) : ?>
                <li>
                    <strong><?php echo esc_html(self::humanize_key((string) $key)); ?>:</strong>
                    <?php echo !empty($enabled) ? esc_html__('Attivo', 'fp-remote-bridge') : esc_html__('Disattivo', 'fp-remote-bridge'); ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    /**
     * @param array<string, mixed> $errors Sezione errori server.
     * @return void
     */
    private static function render_log_panel(array $errors): void
    {
        $debugLog = is_array($errors['debug_log'] ?? null) ? $errors['debug_log'] : [];
        $phpLog = is_array($errors['php_error_log'] ?? null) ? $errors['php_error_log'] : [];
        $lines = array_merge(
            is_array($debugLog['lines'] ?? null) ? $debugLog['lines'] : [],
            is_array($phpLog['lines'] ?? null) ? $phpLog['lines'] : []
        );
        $lines = array_slice($lines, -20);

        if ($lines === []) {
            echo '<p class="fpbridge-diagnostics-empty">' . esc_html__('Nessuna riga di log leggibile (debug.log o error_log PHP).', 'fp-remote-bridge') . '</p>';
            return;
        }

        echo '<pre class="fpbridge-log-tail">';
        foreach ($lines as $line) {
            echo esc_html((string) $line) . "\n";
        }
        echo '</pre>';
    }

    /**
     * @param string $key Chiave tecnica.
     * @return string Etichetta leggibile.
     */
    private static function humanize_key(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }
}
