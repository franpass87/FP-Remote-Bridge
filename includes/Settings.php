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
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if ($sync_result === 'ok') : ?>
                <div class="notice notice-success"><p>
                    <?php echo $updates_done
                        ? esc_html__('Sincronizzazione completata. Aggiornamenti installati.', 'fp-remote-bridge')
                        : esc_html__('Sincronizzazione completata. Nessun aggiornamento disponibile.', 'fp-remote-bridge'); ?>
                </p></div>
            <?php elseif ($sync_result === 'error') : ?>
                <div class="notice notice-error"><p><?php esc_html_e('Errore durante la sincronizzazione. Verifica URL Master e secret.', 'fp-remote-bridge'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('fp_remote_bridge_settings'); ?>

                <h2 class="title"><?php esc_html_e('Comunicazione con Master (sito centrale)', 'fp-remote-bridge'); ?></h2>
                <p><?php esc_html_e('Questo sito client contatta periodicamente il Master per verificare se ci sono aggiornamenti dei plugin FP. Se sì, li installa localmente. Non serve FP Updater sul client: il Bridge gestisce tutto.', 'fp-remote-bridge'); ?></p>

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

                <hr />

                <h2 class="title"><?php esc_html_e('Ricezione da hub esterno (opzionale)', 'fp-remote-bridge'); ?></h2>
                <p><?php esc_html_e('Se un altro sistema (es. script) deve inviare POST a questo sito per avviare gli aggiornamenti, configura il secret qui sotto.', 'fp-remote-bridge'); ?></p>

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
                            <code id="fp-remote-bridge-endpoint-url"><?php echo esc_html($endpoint_url); ?></code>
                            <button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('fp-remote-bridge-endpoint-url').textContent); this.textContent='<?php echo esc_js(__('Copiato!', 'fp-remote-bridge')); ?>';">
                                <?php esc_html_e('Copia', 'fp-remote-bridge'); ?>
                            </button>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <?php if (!empty($master_url) && !empty($master_secret)) : ?>
                <p>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('options-general.php?page=fp-remote-bridge&fp_bridge_sync=1'), 'fp_bridge_manual_sync')); ?>"
                       class="button"><?php esc_html_e('Sincronizza ora', 'fp-remote-bridge'); ?></a>
                </p>
            <?php endif; ?>

            <?php if (!class_exists('FP\GitUpdater\Updater')) : ?>
                <div class="notice notice-info inline">
                    <p><?php esc_html_e('FP Updater non è installato: il Bridge installerà gli aggiornamenti direttamente da GitHub.', 'fp-remote-bridge'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
