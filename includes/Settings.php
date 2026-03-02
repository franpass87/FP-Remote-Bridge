<?php
/**
 * Pagina impostazioni FP Remote Bridge
 *
 * - Comunicazione con Master (sito con FP Updater): polling per aggiornamenti
 * - Ricezione POST da hub esterno (opzionale): secret per endpoint update-plugins
 *
 * @package FP\RemoteBridge
 */

namespace FP\RemoteBridge;

if (!defined('ABSPATH')) {
    exit;
}

class Settings
{
    /**
     * Registra menu e impostazioni
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_init', [self::class, 'handle_manual_sync']);
        add_action('admin_init', [self::class, 'handle_manual_backup']);
    }

    public static function add_menu(): void
    {
        add_options_page(
            __('FP Remote Bridge', 'fp-remote-bridge'),
            __('FP Remote Bridge', 'fp-remote-bridge'),
            'manage_options',
            'fp-remote-bridge',
            [self::class, 'render_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting('fp_remote_bridge_settings', PluginUpdateEndpoint::OPTION_SECRET, [
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return is_string($value) ? trim($value) : '';
            },
        ]);
        register_setting('fp_remote_bridge_settings', MasterSync::OPTION_MASTER_URL, [
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                $v = is_string($value) ? trim(esc_url_raw($value)) : '';
                return $v ?: '';
            },
        ]);
        register_setting('fp_remote_bridge_settings', MasterSync::OPTION_MASTER_SECRET, [
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return is_string($value) ? trim($value) : '';
            },
        ]);
        register_setting('fp_remote_bridge_settings', PluginInstaller::OPTION_GITHUB_TOKEN, [
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return is_string($value) ? trim($value) : '';
            },
        ]);
        register_setting('fp_remote_bridge_settings', MasterSync::OPTION_SYNC_INTERVAL, [
            'type' => 'string',
            'default' => 'hourly',
            'sanitize_callback' => function ($value) {
                $allowed = ['hourly', 'twicedaily', 'daily'];
                return in_array($value, $allowed, true) ? $value : 'hourly';
            },
        ]);
        register_setting('fp_remote_bridge_settings', BackupSync::OPTION_BACKUP_ENABLED, [
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => function ($value) {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            },
        ]);
        register_setting('fp_remote_bridge_settings', BackupSync::OPTION_BACKUP_INTERVAL, [
            'type' => 'string',
            'default' => 'daily',
            'sanitize_callback' => function ($value) {
                $allowed = ['hourly', 'twicedaily', 'daily'];
                return in_array($value, $allowed, true) ? $value : 'daily';
            },
        ]);
        register_setting('fp_remote_bridge_settings', BackupSync::OPTION_BACKUP_CLIENT_ID, [
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return is_string($value) ? sanitize_file_name(trim($value)) : '';
            },
        ]);
        register_setting('fp_remote_bridge_settings', BackupService::OPTION_INCLUDE_UPLOADS, [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);
    }

    public static function handle_manual_sync(): void
    {
        if (!isset($_GET['fp_bridge_sync']) || !current_user_can('manage_options')) {
            return;
        }
        check_admin_referer('fp_bridge_manual_sync');
        $result = MasterSync::run_manual_sync();
        $redirect = add_query_arg([
            'fp_bridge_sync_result' => $result['success'] ? 'ok' : 'error',
            'fp_bridge_updates' => $result['updates_available'] ?? false ? '1' : '0',
        ], admin_url('options-general.php?page=fp-remote-bridge'));
        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_manual_backup(): void
    {
        if (!isset($_GET['fp_bridge_backup']) || !current_user_can('manage_options')) {
            return;
        }
        check_admin_referer('fp_bridge_manual_backup');
        $result = BackupSync::create_and_upload();
        if (!empty($result['path'])) {
            BackupService::cleanup_temp();
        }
        $redirect = add_query_arg([
            'fp_bridge_backup_result' => $result['success'] ? 'ok' : 'error',
            'fp_bridge_backup_error' => isset($result['error']) ? urlencode($result['error']) : '',
        ], admin_url('options-general.php?page=fp-remote-bridge'));
        wp_safe_redirect($redirect);
        exit;
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $master_url = get_option(MasterSync::OPTION_MASTER_URL, '');
        $master_secret = get_option(MasterSync::OPTION_MASTER_SECRET, '');
        $sync_interval = get_option(MasterSync::OPTION_SYNC_INTERVAL, 'hourly');
        $secret = get_option(PluginUpdateEndpoint::OPTION_SECRET, '');
        $endpoint_url = PluginUpdateEndpoint::get_endpoint_url();

        $sync_result = isset($_GET['fp_bridge_sync_result']) ? sanitize_text_field($_GET['fp_bridge_sync_result']) : '';
        $updates_done = isset($_GET['fp_bridge_updates']) && $_GET['fp_bridge_updates'] === '1';
        $backup_result = isset($_GET['fp_bridge_backup_result']) ? sanitize_text_field($_GET['fp_bridge_backup_result']) : '';
        $backup_error = isset($_GET['fp_bridge_backup_error']) ? sanitize_text_field(urldecode($_GET['fp_bridge_backup_error'])) : '';
        $backup_enabled = get_option(BackupSync::OPTION_BACKUP_ENABLED, false);
        $backup_interval = get_option(BackupSync::OPTION_BACKUP_INTERVAL, 'daily');
        $backup_client_id = get_option(BackupSync::OPTION_BACKUP_CLIENT_ID, '');
        $include_uploads = get_option(BackupService::OPTION_INCLUDE_UPLOADS, true);
        ?>
        <div class="wrap fp-bridge-wrap">
            <div class="fp-bridge-header">
                <h1 class="fp-bridge-title">
                    <span class="dashicons dashicons-admin-links"></span>
                    <?php echo esc_html(get_admin_page_title()); ?>
                </h1>
                <p class="fp-bridge-subtitle"><?php esc_html_e('Collega questo sito al Master per ricevere aggiornamenti e inviare backup.', 'fp-remote-bridge'); ?></p>
            </div>

            <?php if ($sync_result === 'ok') : ?>
                <div class="notice notice-success"><p>
                    <?php echo $updates_done
                        ? esc_html__('Sincronizzazione completata. Aggiornamenti installati.', 'fp-remote-bridge')
                        : esc_html__('Sincronizzazione completata. Nessun aggiornamento disponibile.', 'fp-remote-bridge'); ?>
                </p></div>
            <?php elseif ($sync_result === 'error') : ?>
                <div class="notice notice-error"><p><?php esc_html_e('Errore durante la sincronizzazione. Verifica URL Master e secret.', 'fp-remote-bridge'); ?></p></div>
            <?php endif; ?>

            <?php if ($backup_result === 'ok') : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Backup creato e inviato al Master.', 'fp-remote-bridge'); ?></p></div>
            <?php elseif ($backup_result === 'error') : ?>
                <div class="notice notice-error"><p><?php
                    echo esc_html__('Errore durante il backup.', 'fp-remote-bridge');
                    if ($backup_error) {
                        echo ' ' . esc_html($backup_error);
                    }
                ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('fp_remote_bridge_settings'); ?>

                <div class="fp-bridge-card">
                    <h2 class="fp-bridge-card-title">
                        <span class="dashicons dashicons-networking"></span>
                        <?php esc_html_e('Comunicazione con Master', 'fp-remote-bridge'); ?>
                    </h2>
                    <p class="fp-bridge-card-desc"><?php esc_html_e('Il sito contatta periodicamente il Master per verificare aggiornamenti e li installa in automatico. Non serve FP Updater sul client.', 'fp-remote-bridge'); ?></p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="fp_remote_bridge_master_url"><?php esc_html_e('URL Master', 'fp-remote-bridge'); ?></label>
                        </th>
                        <td>
                            <input type="url" id="fp_remote_bridge_master_url"
                                   name="<?php echo esc_attr(MasterSync::OPTION_MASTER_URL); ?>"
                                   value="<?php echo esc_attr($master_url); ?>"
                                   class="regular-text" placeholder="https://sito-master.it" />
                            <p class="description">
                                <?php esc_html_e('URL del sito Master (con FP Updater in modalità Master). Es: https://tuo-sito-master.it', 'fp-remote-bridge'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fp_remote_bridge_master_secret"><?php esc_html_e('Secret Client', 'fp-remote-bridge'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="fp_remote_bridge_master_secret"
                                   name="<?php echo esc_attr(MasterSync::OPTION_MASTER_SECRET); ?>"
                                   value="<?php echo esc_attr($master_secret); ?>"
                                   class="regular-text" autocomplete="off" />
                            <p class="description">
                                <?php esc_html_e('Lo stesso secret configurato in FP Updater → Modalità Master sul sito Master.', 'fp-remote-bridge'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fp_remote_bridge_github_token"><?php esc_html_e('Token GitHub (opzionale)', 'fp-remote-bridge'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="fp_remote_bridge_github_token"
                                   name="<?php echo esc_attr(PluginInstaller::OPTION_GITHUB_TOKEN); ?>"
                                   value="<?php echo esc_attr(get_option(PluginInstaller::OPTION_GITHUB_TOKEN, '')); ?>"
                                   class="regular-text" autocomplete="off" />
                            <p class="description">
                                <?php esc_html_e('Solo per repository GitHub privati. Lascia vuoto per repo pubblici.', 'fp-remote-bridge'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fp_remote_bridge_sync_interval"><?php esc_html_e('Frequenza sincronizzazione', 'fp-remote-bridge'); ?></label>
                        </th>
                        <td>
                            <select id="fp_remote_bridge_sync_interval" name="<?php echo esc_attr(MasterSync::OPTION_SYNC_INTERVAL); ?>">
                                <option value="hourly" <?php selected($sync_interval, 'hourly'); ?>><?php esc_html_e('Ogni ora', 'fp-remote-bridge'); ?></option>
                                <option value="twicedaily" <?php selected($sync_interval, 'twicedaily'); ?>><?php esc_html_e('Due volte al giorno', 'fp-remote-bridge'); ?></option>
                                <option value="daily" <?php selected($sync_interval, 'daily'); ?>><?php esc_html_e('Una volta al giorno', 'fp-remote-bridge'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                </div>

                <div class="fp-bridge-card">
                    <h2 class="fp-bridge-card-title">
                        <span class="dashicons dashicons-database-export"></span>
                        <?php esc_html_e('Backup verso Master', 'fp-remote-bridge'); ?>
                    </h2>
                    <p class="fp-bridge-card-desc"><?php esc_html_e('Backup completi (database + wp-content) inviati al Master. Richiede URL e Secret Master configurati sopra.', 'fp-remote-bridge'); ?></p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Abilita backup automatici', 'fp-remote-bridge'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr(BackupSync::OPTION_BACKUP_ENABLED); ?>" value="0" />
                                <input type="checkbox" id="fp_remote_bridge_backup_enabled"
                                       name="<?php echo esc_attr(BackupSync::OPTION_BACKUP_ENABLED); ?>"
                                       value="1" <?php checked($backup_enabled); ?> />
                                <?php esc_html_e('Invia backup al Master periodicamente', 'fp-remote-bridge'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fp_remote_bridge_backup_interval"><?php esc_html_e('Frequenza backup', 'fp-remote-bridge'); ?></label>
                        </th>
                        <td>
                            <select id="fp_remote_bridge_backup_interval" name="<?php echo esc_attr(BackupSync::OPTION_BACKUP_INTERVAL); ?>">
                                <option value="hourly" <?php selected($backup_interval, 'hourly'); ?>><?php esc_html_e('Ogni ora', 'fp-remote-bridge'); ?></option>
                                <option value="twicedaily" <?php selected($backup_interval, 'twicedaily'); ?>><?php esc_html_e('Due volte al giorno', 'fp-remote-bridge'); ?></option>
                                <option value="daily" <?php selected($backup_interval, 'daily'); ?>><?php esc_html_e('Una volta al giorno', 'fp-remote-bridge'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Includi uploads', 'fp-remote-bridge'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr(BackupService::OPTION_INCLUDE_UPLOADS); ?>" value="0" />
                                <input type="checkbox" name="<?php echo esc_attr(BackupService::OPTION_INCLUDE_UPLOADS); ?>"
                                       value="1" <?php checked($include_uploads); ?> />
                                <?php esc_html_e('Includi la cartella uploads nel backup (può essere molto grande)', 'fp-remote-bridge'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fp_remote_bridge_backup_client_id"><?php esc_html_e('Identificativo client', 'fp-remote-bridge'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="fp_remote_bridge_backup_client_id"
                                   name="<?php echo esc_attr(BackupSync::OPTION_BACKUP_CLIENT_ID); ?>"
                                   value="<?php echo esc_attr($backup_client_id); ?>"
                                   class="regular-text" placeholder="<?php echo esc_attr(parse_url(site_url(), PHP_URL_HOST) ?: 'client'); ?>" />
                            <p class="description">
                                <?php esc_html_e('Opzionale. Usato per organizzare i backup sul Master. Default: dominio del sito.', 'fp-remote-bridge'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                </div>

                <div class="fp-bridge-card">
                    <h2 class="fp-bridge-card-title">
                        <span class="dashicons dashicons-lock"></span>
                        <?php esc_html_e('Ricezione da hub esterno (opzionale)', 'fp-remote-bridge'); ?>
                    </h2>
                    <p class="fp-bridge-card-desc"><?php esc_html_e('Secret per consentire a script esterni di inviare POST e avviare aggiornamenti o backup.', 'fp-remote-bridge'); ?></p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="fp_remote_bridge_plugin_update_secret"><?php esc_html_e('Secret per POST esterno', 'fp-remote-bridge'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="fp_remote_bridge_plugin_update_secret"
                                   name="<?php echo esc_attr(PluginUpdateEndpoint::OPTION_SECRET); ?>"
                                   value="<?php echo esc_attr($secret); ?>"
                                   class="regular-text" autocomplete="off" />
                            <p class="description">
                                <?php esc_html_e('Header', 'fp-remote-bridge'); ?> <code>X-FP-Update-Secret</code>
                                <?php esc_html_e('oppure body', 'fp-remote-bridge'); ?> <code>secret</code>.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('URL endpoint', 'fp-remote-bridge'); ?></th>
                        <td>
                            <div class="fp-bridge-input-group">
                                <input type="text" id="fp-remote-bridge-endpoint-url" value="<?php echo esc_attr($endpoint_url); ?>" readonly class="regular-text fp-bridge-url-input">
                                <button type="button" class="button fp-bridge-btn-copy" data-copy-source="fp-remote-bridge-endpoint-url">
                                    <span class="fp-bridge-copy-text"><?php esc_html_e('Copia', 'fp-remote-bridge'); ?></span>
                                </button>
                            </div>
                        </td>
                    </tr>
                </table>
                </div>

                <?php submit_button(); ?>
            </form>

            <?php if (!empty($master_url) && !empty($master_secret)) : ?>
                <div class="fp-bridge-actions">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('options-general.php?page=fp-remote-bridge&fp_bridge_sync=1'), 'fp_bridge_manual_sync')); ?>"
                       class="button button-primary fp-bridge-btn-action">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Sincronizza ora', 'fp-remote-bridge'); ?>
                    </a>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('options-general.php?page=fp-remote-bridge&fp_bridge_backup=1'), 'fp_bridge_manual_backup')); ?>"
                       class="button fp-bridge-btn-action">
                        <span class="dashicons dashicons-database-export"></span>
                        <?php esc_html_e('Esegui backup ora', 'fp-remote-bridge'); ?>
                    </a>
                </div>
            <?php endif; ?>

            <?php if (!class_exists('FP\GitUpdater\Updater')) : ?>
                <div class="notice notice-info inline fp-bridge-notice">
                    <p><span class="dashicons dashicons-info"></span> <?php esc_html_e('FP Updater non è installato: il Bridge installerà gli aggiornamenti direttamente da GitHub.', 'fp-remote-bridge'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <style>
            .fp-bridge-wrap { max-width: 720px; }
            .fp-bridge-header { margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #dcdcde; }
            .fp-bridge-title { display: flex; align-items: center; gap: 10px; font-size: 23px; margin: 0 0 6px 0; }
            .fp-bridge-title .dashicons { color: #2271b1; }
            .fp-bridge-subtitle { margin: 0; color: #50575e; font-size: 14px; }
            .fp-bridge-card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 20px 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
            .fp-bridge-card-title { display: flex; align-items: center; gap: 8px; margin: 0 0 8px 0; font-size: 16px; font-weight: 600; }
            .fp-bridge-card-title .dashicons { color: #2271b1; font-size: 20px; width: 20px; height: 20px; }
            .fp-bridge-card-desc { margin: 0 0 16px 0; color: #50575e; font-size: 13px; line-height: 1.5; }
            .fp-bridge-card .form-table { margin: 0; }
            .fp-bridge-input-group { display: flex; gap: 8px; align-items: center; max-width: 100%; }
            .fp-bridge-input-group .fp-bridge-url-input { flex: 1; font-family: Consolas, Monaco, monospace; font-size: 13px; }
            .fp-bridge-btn-copy.fp-bridge-copied .fp-bridge-copy-text { color: #00a32a; }
            .fp-bridge-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 20px; }
            .fp-bridge-btn-action { display: inline-flex; align-items: center; gap: 6px; }
            .fp-bridge-btn-action .dashicons { font-size: 18px; width: 18px; height: 18px; }
            .fp-bridge-notice .dashicons { vertical-align: middle; margin-right: 4px; }
        </style>
        <script>
            (function() {
                var btn = document.querySelector('.fp-bridge-btn-copy');
                if (btn) {
                    btn.addEventListener('click', function() {
                        var el = document.getElementById(btn.getAttribute('data-copy-source'));
                        if (!el) return;
                        var url = el.value || el.textContent;
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(url).then(function() {
                                var t = btn.querySelector('.fp-bridge-copy-text');
                                if (t) { t.textContent = '<?php echo esc_js(__('Copiato!', 'fp-remote-bridge')); ?>'; btn.classList.add('fp-bridge-copied'); }
                                setTimeout(function() { if (t) t.textContent = '<?php echo esc_js(__('Copia', 'fp-remote-bridge')); ?>'; btn.classList.remove('fp-bridge-copied'); }, 2000);
                            });
                        } else { el.select(); document.execCommand('copy'); }
                    });
                }
            })();
        </script>
        <?php
    }
}
