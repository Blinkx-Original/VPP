<?php
/**
 * Plugin Name: Virtual Product Pages (TiDB + Algolia)
 * Description: Render virtual product pages at /p/{slug} from TiDB, with external CTAs. Includes Push to VPP, Push to Algolia, and an Edit Product tool that writes back to TiDB.
 * Version: 1.3.0
 * Author: ChatGPT (for Martin)
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

class VPP_Plugin {
    const OPT_KEY = 'vpp_settings';
    const NONCE_KEY = 'vpp_nonce';
    const QUERY_VAR = 'vpp_slug';
    const VERSION = '1.3.0';
    const SITEMAP_META_OPTION = 'vpp_sitemap_meta';
    const LOG_SUBDIR = 'vpp-logs';
    const LOG_FILENAME = 'vpp.log';

    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_rewrite']);
        add_action('template_redirect', [$this, 'maybe_render_vpp']);
        add_filter('query_vars', [$this, 'add_query_var']);
        register_activation_hook(__FILE__, ['VPP_Plugin', 'on_activate']);
        register_deactivation_hook(__FILE__, ['VPP_Plugin', 'on_deactivate']);

        if (is_admin()) {
            add_action('admin_menu', [$this, 'admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_post_vpp_save_settings', [$this, 'handle_save_settings']);
            add_action('admin_post_vpp_test_tidb', [$this, 'handle_test_tidb']);
            add_action('admin_post_vpp_test_algolia', [$this, 'handle_test_algolia']);
            add_action('admin_post_vpp_push_publish', [$this, 'handle_push_publish']);
            add_action('admin_post_vpp_push_algolia', [$this, 'handle_push_algolia']);
            add_action('admin_post_vpp_purge_cache', [$this, 'handle_purge_cache']);
            add_action('admin_post_vpp_rebuild_sitemaps', [$this, 'handle_rebuild_sitemaps']);
            add_action('admin_post_vpp_download_log', [$this, 'handle_download_log']);
            add_action('admin_post_vpp_clear_log', [$this, 'handle_clear_log']);
            add_action('admin_post_vpp_edit_load', [$this, 'handle_edit_load']);
            add_action('admin_post_vpp_edit_save', [$this, 'handle_edit_save']);
            add_action('admin_notices', [$this, 'maybe_admin_notice']);
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public static function on_activate() { self::instance()->register_rewrite(); flush_rewrite_rules(); }
    public static function on_deactivate() { flush_rewrite_rules(); }

    public function add_query_var($vars) { $vars[] = self::QUERY_VAR; return $vars; }

    public function register_rewrite() {
        add_rewrite_rule('^p/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_tag('%' . self::QUERY_VAR . '%', '([^&]+)');
    }

    public function enqueue_assets() {
        wp_register_style('vpp-styles', plugins_url('assets/vpp.css', __FILE__), [], self::VERSION);
        if (get_query_var(self::QUERY_VAR)) wp_enqueue_style('vpp-styles');
    }

    /* ========= SETTINGS ========= */

    public function admin_menu() {
        add_menu_page('Virtual Products', 'Virtual Products', 'manage_options', 'vpp_settings', [$this, 'render_settings_page'], 'dashicons-archive', 58);
        add_submenu_page('vpp_settings', 'Edit Product', 'Edit Product', 'manage_options', 'vpp_edit', [$this, 'render_edit_page']);
        add_submenu_page('vpp_settings', 'VPP Status', 'VPP Status', 'manage_options', 'vpp_status', [$this, 'render_status_page']);
    }

    public function register_settings() {
        register_setting(self::OPT_KEY, self::OPT_KEY, [$this, 'sanitize_settings']);
    }

    public function get_settings() {
        $defaults = [
            'tidb' => ['host'=>'','port'=>'4000','database'=>'','user'=>'','pass'=>'','table'=>'products','ssl_ca'=>'/etc/ssl/certs/ca-certificates.crt'],
            'algolia' => ['app_id'=>'','admin_key'=>'','index'=>''],
            'cloudflare' => ['api_token'=>'','zone_id'=>'','site_base'=>''],
        ];
        $opt = get_option(self::OPT_KEY);
        if (!is_array($opt)) $opt = [];
        $out = wp_parse_args($opt, $defaults);
        $out['tidb'] = wp_parse_args(is_array($out['tidb']) ? $out['tidb'] : [], $defaults['tidb']);
        $out['algolia'] = wp_parse_args(is_array($out['algolia']) ? $out['algolia'] : [], $defaults['algolia']);
        $out['cloudflare'] = wp_parse_args(is_array($out['cloudflare']) ? $out['cloudflare'] : [], $defaults['cloudflare']);
        return $out;
    }

    public function sanitize_settings($input) {
        $out = $this->get_settings();
        if (isset($input['tidb'])) {
            $out['tidb']['host'] = sanitize_text_field($input['tidb']['host'] ?? '');
            $out['tidb']['port'] = sanitize_text_field($input['tidb']['port'] ?? '4000');
            $out['tidb']['database'] = sanitize_text_field($input['tidb']['database'] ?? '');
            $out['tidb']['user'] = sanitize_text_field($input['tidb']['user'] ?? '');
            $out['tidb']['pass'] = $input['tidb']['pass'] ?? '';
            $out['tidb']['table'] = sanitize_text_field($input['tidb']['table'] ?? 'products');
            $out['tidb']['ssl_ca'] = sanitize_text_field($input['tidb']['ssl_ca'] ?? '/etc/ssl/certs/ca-certificates.crt');
        }
        if (isset($input['algolia'])) {
            $out['algolia']['app_id'] = sanitize_text_field($input['algolia']['app_id'] ?? '');
            $out['algolia']['admin_key'] = sanitize_text_field($input['algolia']['admin_key'] ?? '');
            $out['algolia']['index'] = sanitize_text_field($input['algolia']['index'] ?? '');
        }
        if (isset($input['cloudflare'])) {
            $out['cloudflare']['api_token'] = sanitize_text_field($input['cloudflare']['api_token'] ?? '');
            $out['cloudflare']['zone_id'] = sanitize_text_field($input['cloudflare']['zone_id'] ?? '');
            $out['cloudflare']['site_base'] = esc_url_raw($input['cloudflare']['site_base'] ?? '');
        }
        return $out;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = $this->get_settings();
        ?>
        <div class="wrap">
            <h1>Virtual Product Pages</h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="vpp_save_settings"/>
                <?php wp_nonce_field(self::NONCE_KEY); ?>
                <h2 class="title">Connections</h2>
                <table class="form-table"><tbody>
                    <tr><th scope="row">TiDB Host</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][host]" value="<?php echo esc_attr($s['tidb']['host']); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row">TiDB Port</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][port]" value="<?php echo esc_attr($s['tidb']['port']); ?>" class="small-text"></td></tr>
                    <tr><th scope="row">TiDB Database</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][database]" value="<?php echo esc_attr($s['tidb']['database']); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row">TiDB User</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][user]" value="<?php echo esc_attr($s['tidb']['user']); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row">TiDB Password</th><td><input type="password" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][pass]" value="<?php echo esc_attr($s['tidb']['pass']); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row">TiDB Table</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][table]" value="<?php echo esc_attr($s['tidb']['table']); ?>" class="regular-text"><p class="description">Expected columns: id, slug, title_h1, brand, model, sku, images_json, desc_html, short_summary, cta_lead_url, cta_stripe_url, cta_affiliate_url, cta_paypal_url, is_published, last_tidb_update_at</p></td></tr>
                    <tr><th scope="row">SSL CA Path</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][ssl_ca]" value="<?php echo esc_attr($s['tidb']['ssl_ca']); ?>" class="regular-text"><p class="description">TiDB Serverless requires TLS. Typical paths: <code>/etc/ssl/certs/ca-certificates.crt</code> (Debian/Ubuntu) or <code>/etc/pki/tls/certs/ca-bundle.crt</code> (CentOS). You may also upload a CA file and reference the absolute path.</p></td></tr>
                </tbody></table>

                <table class="form-table"><tbody>
                    <tr><th scope="row">Algolia App ID</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[algolia][app_id]" value="<?php echo esc_attr($s['algolia']['app_id']); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row">Algolia Admin API Key</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[algolia][admin_key]" value="<?php echo esc_attr($s['algolia']['admin_key']); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row">Algolia Index Name</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[algolia][index]" value="<?php echo esc_attr($s['algolia']['index']); ?>" class="regular-text"></td></tr>
                </tbody></table>

                <?php submit_button('Save Changes'); ?>
            </form>

            <h2 class="title">Test Connections</h2>
            <p>Verify credentials without publishing anything.</p>
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="vpp_test_tidb"/>
                    <?php wp_nonce_field(self::NONCE_KEY); ?>
                    <?php submit_button('Test TiDB Connection', 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="vpp_test_algolia"/>
                    <?php wp_nonce_field(self::NONCE_KEY); ?>
                    <?php submit_button('Test Algolia Connection', 'secondary', 'submit', false); ?>
                </form>
            </div>

            <h2 class="title" style="margin-top:24px;">Push Actions</h2>
            <p>Enter IDs or slugs (comma-separated).</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1rem;">
                <input type="hidden" name="action" value="vpp_push_publish"/>
                <?php wp_nonce_field(self::NONCE_KEY); ?>
                <textarea name="vpp_ids" rows="2" style="width:100%;" placeholder="e.g. 60001, siemens-s7-1200-60001"></textarea>
                <?php submit_button('Push to VPP (Publish)'); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="vpp_push_algolia"/>
                <?php wp_nonce_field(self::NONCE_KEY); ?>
                <textarea name="vpp_ids" rows="2" style="width:100%;" placeholder="e.g. 60001, siemens-s7-1200-60001"></textarea>
                <?php submit_button('Push to Algolia'); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1rem;">
                <input type="hidden" name="action" value="vpp_purge_cache"/>
                <?php wp_nonce_field(self::NONCE_KEY); ?>
                <?php submit_button('Purge Cache'); ?>
            </form>

            <h2 class="title" style="margin-top:24px;">SEO Tools</h2>
            <p>Rebuild sitemap files for published products.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="vpp_rebuild_sitemaps"/>
                <?php wp_nonce_field(self::NONCE_KEY); ?>
                <?php submit_button('Rebuild Sitemap'); ?>
            </form>
        </div>
        <?php
    }

    public function maybe_admin_notice() {
        if (!empty($_GET['vpp_msg'])) {
            $msg = sanitize_text_field(wp_unslash($_GET['vpp_msg']));
            echo '<div class="notice notice-success is-dismissible"><p>'.esc_html($msg).'</p></div>';
        }
        if (!empty($_GET['vpp_err'])) {
            $msg = sanitize_text_field(wp_unslash($_GET['vpp_err']));
            echo '<div class="notice notice-error is-dismissible"><p>'.esc_html($msg).'</p></div>';
        }
    }

    /* ========= STATUS & LOGS ========= */

    public function render_status_page() {
        if (!current_user_can('manage_options')) { return; }

        $published_err = null;
        $published_count = $this->count_published_products($published_err);

        $tidb_status = $published_count === null ? 'Error' : 'OK';
        $tidb_message = $published_err ?: ($tidb_status === 'OK' ? 'Connection OK.' : '');

        $sitemap_meta = $this->get_sitemap_meta();
        $last_total = isset($sitemap_meta['total']) ? (int)$sitemap_meta['total'] : null;
        $last_files = isset($sitemap_meta['file_count']) ? (int)$sitemap_meta['file_count'] : null;
        $last_generated = isset($sitemap_meta['generated_at']) ? (int)$sitemap_meta['generated_at'] : 0;
        $index_url = !empty($sitemap_meta['index_url']) ? $sitemap_meta['index_url'] : '';

        $algolia_message = '';
        $algolia_status = $this->check_algolia_status($algolia_message);

        $log_excerpt = $this->get_log_excerpt();

        ?>
        <div class="wrap">
            <h1>VPP Status</h1>

            <table class="widefat striped" style="max-width:720px; margin-top:1rem;">
                <tbody>
                    <tr>
                        <th scope="row">Published products</th>
                        <td>
                            <?php
                            if ($published_count !== null) {
                                echo esc_html(number_format_i18n($published_count));
                            } else {
                                echo '—';
                                if ($published_err) {
                                    echo ' <span style="color:#b32d2e;">' . esc_html($published_err) . '</span>';
                                }
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">URLs in last sitemap</th>
                        <td><?php echo $last_total !== null ? esc_html(number_format_i18n($last_total)) : '—'; ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Sitemap files</th>
                        <td><?php echo $last_files !== null ? esc_html(number_format_i18n($last_files)) : '—'; ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Last sitemap rebuild</th>
                        <td>
                            <?php
                            if ($last_generated) {
                                $format = get_option('date_format') . ' ' . get_option('time_format');
                                echo esc_html(date_i18n($format, $last_generated));
                            } else {
                                echo 'Never';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Sitemap index URL</th>
                        <td>
                            <?php if ($index_url) : ?>
                                <a href="<?php echo esc_url($index_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($index_url); ?></a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">TiDB connection</th>
                        <td>
                            <strong><?php echo esc_html($tidb_status); ?></strong>
                            <?php if ($tidb_message) : ?>
                                <span style="margin-left:0.5rem;"><?php echo esc_html($tidb_message); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Algolia connection</th>
                        <td>
                            <?php
                            if ($algolia_status === null) {
                                echo esc_html('Not configured.');
                            } else {
                                echo '<strong>' . esc_html($algolia_status ? 'OK' : 'Error') . '</strong>';
                                if ($algolia_message) {
                                    echo ' <span style="margin-left:0.5rem;">' . esc_html($algolia_message) . '</span>';
                                }
                            }
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h2 style="margin-top:2rem;">Logs</h2>
            <p>The plugin records errors to <code>/wp-content/uploads/<?php echo esc_html(self::LOG_SUBDIR . '/' . self::LOG_FILENAME); ?></code>.</p>
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="vpp_download_log"/>
                    <?php wp_nonce_field(self::NONCE_KEY); ?>
                    <?php submit_button('Download Log', 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Clear the VPP log?');">
                    <input type="hidden" name="action" value="vpp_clear_log"/>
                    <?php wp_nonce_field(self::NONCE_KEY); ?>
                    <?php submit_button('Clear Log', 'secondary', 'submit', false); ?>
                </form>
            </div>

            <?php if ($log_excerpt !== '') : ?>
                <textarea readonly rows="12" style="width:100%; margin-top:1rem; font-family:monospace;">
<?php echo esc_textarea($log_excerpt); ?>
                </textarea>
            <?php else : ?>
                <p style="margin-top:1rem;">No log entries recorded yet.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function get_log_file_path($ensure_dir = false) {
        $uploads = wp_upload_dir();
        if (empty($uploads['basedir'])) { return ''; }
        $dir = trailingslashit($uploads['basedir']) . self::LOG_SUBDIR;
        if ($ensure_dir && !wp_mkdir_p($dir)) { return ''; }
        return trailingslashit($dir) . self::LOG_FILENAME;
    }

    private function get_log_excerpt($max_bytes = 65536) {
        $path = $this->get_log_file_path();
        if (!$path || !file_exists($path)) { return ''; }
        $size = @filesize($path);
        if (!$size) { return ''; }
        $offset = max(0, $size - $max_bytes);
        $content = @file_get_contents($path, false, null, $offset);
        if ($content === false) { return ''; }
        if ($offset > 0) {
            $content = "(trimmed)\n" . ltrim($content);
        }
        return trim($content);
    }

    private function get_sitemap_meta() {
        $meta = get_option(self::SITEMAP_META_OPTION);
        if (!is_array($meta)) { $meta = []; }
        return $meta;
    }

    private function count_published_products(&$err = null) {
        $mysqli = $this->db_connect($err);
        if (!$mysqli) { return null; }
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $this->get_settings()['tidb']['table']);
        $sql = "SELECT COUNT(*) AS total FROM `{$table}` WHERE is_published = 1";
        $res = $mysqli->query($sql);
        if (!$res) {
            $err = 'DB query failed: ' . $mysqli->error;
            $this->log_error('db_query', $err);
            @$mysqli->close();
            return null;
        }
        $row = $res->fetch_assoc();
        $res->free();
        @$mysqli->close();
        return isset($row['total']) ? (int)$row['total'] : 0;
    }

    private function check_algolia_status(&$message = '') {
        $s = $this->get_settings();
        $app = trim($s['algolia']['app_id'] ?? '');
        $key = trim($s['algolia']['admin_key'] ?? '');
        $index = trim($s['algolia']['index'] ?? '');
        if (!$app || !$key || !$index) { $message = 'Not configured.'; return null; }

        $endpoint = "https://{$app}-dsn.algolia.net/1/indexes/" . rawurlencode($index);
        $resp = wp_remote_request($endpoint, [
            'method' => 'GET',
            'headers' => [
                'X-Algolia-API-Key' => $key,
                'X-Algolia-Application-Id' => $app,
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($resp)) {
            $message = $resp->get_error_message();
            $this->log_error('algolia', 'Status check failed: ' . $message);
            return false;
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code >= 200 && $code < 300) {
            $message = 'Connection OK.';
            return true;
        }
        $message = 'HTTP ' . $code;
        $this->log_error('algolia', 'Status check HTTP ' . $code);
        return false;
    }

    private function log_error($context, $message) {
        $path = $this->get_log_file_path(true);
        if (!$path) { return; }
        $time = current_time('mysql');
        $line = sprintf("[%s] [%s] %s\n", $time, strtoupper($context), trim((string)$message));
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /* ========= ADMIN: EDIT PRODUCT ========= */

    private function parse_lookup_key($key) {
        $key = trim($key);
        if (!$key) return [null, null];
        // If URL like https://domain.com/p/slug
        $home = home_url();
        if (stripos($key, $home) === 0) {
            $path = parse_url($key, PHP_URL_PATH);
            $parts = explode('/', trim($path, '/'));
            // expect p/{slug}
            if (isset($parts[0]) && $parts[0] === 'p' && isset($parts[1])) {
                return ['slug', sanitize_title($parts[1])];
            }
        }
        // numeric?
        if (ctype_digit($key)) return ['id', (int)$key];
        // else treat as slug
        return ['slug', sanitize_title($key)];
    }

    public function render_edit_page() {
        if (!current_user_can('manage_options')) return;
        $lookup_type = isset($_GET['lookup_type']) ? sanitize_text_field($_GET['lookup_type']) : '';
        $lookup_val  = isset($_GET['lookup_val']) ? sanitize_text_field($_GET['lookup_val']) : '';
        $row = null; $err = null;
        if ($lookup_type && $lookup_val !== '') {
            $row = ($lookup_type === 'id')
                ? $this->fetch_product_by_id((int)$lookup_val, $err)
                : $this->fetch_product_by_slug($lookup_val, $err);
        }
        $inline = isset($_GET['inline']) ? sanitize_text_field($_GET['inline']) : '';
        $inline_msg = isset($_GET['inline_msg']) ? sanitize_text_field($_GET['inline_msg']) : '';
        ?>
        <div class="wrap">
            <h1>Edit Product</h1>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1rem;">
                <input type="hidden" name="action" value="vpp_edit_load"/>
                <?php wp_nonce_field(self::NONCE_KEY); ?>
                <input type="text" name="vpp_key" class="regular-text" placeholder="Paste /p/slug, slug or ID" value="<?php echo esc_attr($lookup_val); ?>"/>
                <?php submit_button('Load', 'secondary', 'submit', false); ?>
            </form>

            <?php if ($row): ?>
                <h2 class="title">Editing: <?php echo esc_html($row['title_h1'] ?: $row['slug']); ?> <small style="font-weight:normal;">(ID <?php echo (int)$row['id']; ?>, slug <code><?php echo esc_html($row['slug']); ?></code>)</small></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="vpp_edit_save"/>
                    <?php wp_nonce_field(self::NONCE_KEY); ?>
                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>"/>
                    <input type="hidden" name="slug" value="<?php echo esc_attr($row['slug']); ?>"/>

                    <table class="form-table"><tbody>
                        <tr><th scope="row">Title (H1)</th><td><input type="text" name="title_h1" value="<?php echo esc_attr($row['title_h1']); ?>" class="regular-text"></td></tr>
                        <tr><th scope="row">Brand</th><td><input type="text" name="brand" value="<?php echo esc_attr($row['brand']); ?>" class="regular-text"></td></tr>
                        <tr><th scope="row">Model</th><td><input type="text" name="model" value="<?php echo esc_attr($row['model']); ?>" class="regular-text"></td></tr>
                        <tr><th scope="row">SKU</th><td><input type="text" name="sku" value="<?php echo esc_attr($row['sku']); ?>" class="regular-text"></td></tr>
                        <tr><th scope="row">Short summary (max 150 chars)</th><td><input type="text" maxlength="150" name="short_summary" value="<?php echo esc_attr($row['short_summary'] ?? ''); ?>" class="regular-text"><p class="description">Appears on the right of the hero, above the CTAs. No HTML.</p></td></tr>
                        <tr><th scope="row">Images</th><td>
                            <textarea name="images_json" rows="3" style="width:100%;" placeholder='Either JSON array ["https://...","https://..."] or one URL per line'><?php
                                $images_val = $row['images_json'];
                                echo esc_textarea($images_val);
                            ?></textarea>
                        </td></tr>
                        <tr><th scope="row">CTAs</th><td style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <input type="url" placeholder="Lead URL" name="cta_lead_url" value="<?php echo esc_attr($row['cta_lead_url']); ?>"/>
                            <input type="url" placeholder="Stripe URL" name="cta_stripe_url" value="<?php echo esc_attr($row['cta_stripe_url']); ?>"/>
                            <input type="url" placeholder="Affiliate URL" name="cta_affiliate_url" value="<?php echo esc_attr($row['cta_affiliate_url']); ?>"/>
                            <input type="url" placeholder="PayPal URL" name="cta_paypal_url" value="<?php echo esc_attr($row['cta_paypal_url']); ?>"/>
                        </td></tr>
                        <tr><th scope="row">Published</th><td><label><input type="checkbox" name="is_published" value="1" <?php checked(!empty($row['is_published'])); ?>> Mark as published</label></td></tr>
                    </tbody></table>

                    <h3>Long description (HTML)</h3>
                    <?php
                        $content = (string)($row['desc_html'] ?? '');
                        $editor_id = 'desc_html';
                        $settings = [
                            'textarea_name' => 'desc_html',
                            'textarea_rows' => 16,
                            'media_buttons' => false,
                            'teeny' => false,
                            'tinymce' => [
                                'toolbar1' => 'formatselect,bold,italic,underline,link,unlink,blockquote,bullist,numlist,code,undo,redo',
                                'block_formats' => 'Paragraph=p;Heading 2=h2;Heading 3=h3;Heading 4=h4',
                            ],
                            'quicktags' => true,
                        ];
                        wp_editor($content, $editor_id, $settings);
                    ?>

                    <p class="submit" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <?php submit_button('Save to TiDB', 'primary', 'do_save', false); ?>
                        <?php submit_button('Save & View', 'secondary', 'do_save_view', false); ?>
                        <?php submit_button('Save & Purge CF', 'secondary', 'do_save_purge', false); ?>
                        <?php submit_button('Push to Algolia', 'secondary', 'do_push_algolia', false); ?>
                        <?php if ($inline): ?>
                            <span class="vpp-inline <?php echo $inline === 'ok' ? 'ok' : 'err'; ?>"><?php echo esc_html($inline_msg); ?></span>
                        <?php endif; ?>
                    </p>
                </form>
            <?php elseif ($lookup_type || $lookup_val !== ''): ?>
                <p>Not found <?php echo $err ? esc_html('(' . $err . ')') : ''; ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_edit_load() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $key = isset($_POST['vpp_key']) ? sanitize_text_field(wp_unslash($_POST['vpp_key'])) : '';
        list($type, $val) = $this->parse_lookup_key($key);
        if (!$type) {
            $redirect = add_query_arg(['vpp_err'=> rawurlencode('Enter a valid URL, slug or ID.')], admin_url('admin.php?page=vpp_edit'));
        } else {
            $redirect = add_query_arg(['lookup_type'=> $type, 'lookup_val'=> $val], admin_url('admin.php?page=vpp_edit'));
        }
        wp_safe_redirect($redirect); exit;
    }

    private function normalize_images_input($raw) {
        $raw = trim((string)$raw);
        if ($raw === '') return '';
        // if it looks like JSON array, keep
        $t = ltrim($raw);
        if ($t && $t[0] === '[') return $raw;
        // otherwise treat as newline separated URLs
        $lines = preg_split('/\r?\n/', $raw);
        $urls = [];
        foreach ($lines as $ln) {
            $u = trim($ln);
            if ($u !== '') $urls[] = $u;
        }
        return wp_json_encode(array_values(array_unique($urls)));
    }

    private function column_exists($mysqli, $table, $col) {
        $table_esc = $mysqli->real_escape_string($table);
        $col_esc = $mysqli->real_escape_string($col);
        $sql = "SHOW COLUMNS FROM `{$table_esc}` LIKE '{$col_esc}'";
        $r = @$mysqli->query($sql);
        if (!$r) return false;
        $exists = (bool)$r->fetch_row();
        $r->close();
        return $exists;
    }

    public function handle_edit_save() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);

        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $slug = isset($_POST['slug']) ? sanitize_title(wp_unslash($_POST['slug'])) : '';
        $title_h1 = sanitize_text_field($_POST['title_h1'] ?? '');
        $brand = sanitize_text_field($_POST['brand'] ?? '');
        $model = sanitize_text_field($_POST['model'] ?? '');
        $sku = sanitize_text_field($_POST['sku'] ?? '');
        $short_summary = sanitize_text_field($_POST['short_summary'] ?? '');
        if (strlen($short_summary) > 150) $short_summary = substr($short_summary, 0, 150);
        $images_json = $this->normalize_images_input($_POST['images_json'] ?? '');
        $cta_lead_url = esc_url_raw($_POST['cta_lead_url'] ?? '');
        $cta_stripe_url = esc_url_raw($_POST['cta_stripe_url'] ?? '');
        $cta_affiliate_url = esc_url_raw($_POST['cta_affiliate_url'] ?? '');
        $cta_paypal_url = esc_url_raw($_POST['cta_paypal_url'] ?? '');
        $desc_html = wp_kses_post($_POST['desc_html'] ?? ''); // store safe HTML
        $is_published = !empty($_POST['is_published']) ? 1 : 0;

        $err = null;
        $s = $this->get_settings();
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $s['tidb']['table']);
        $mysqli = $this->db_connect($err);
        if (!$mysqli) {
            $redirect = add_query_arg(['vpp_err'=> rawurlencode('DB error: ' . $err)], admin_url('admin.php?page=vpp_edit&lookup_type=id&lookup_val='.$id));
            wp_safe_redirect($redirect); exit;
        }

        // Build dynamic update only for columns that exist
        $cols = [
            'title_h1' => $title_h1,
            'brand' => $brand,
            'model' => $model,
            'sku' => $sku,
            'short_summary' => $short_summary,
            'images_json' => $images_json,
            'cta_lead_url' => $cta_lead_url,
            'cta_stripe_url' => $cta_stripe_url,
            'cta_affiliate_url' => $cta_affiliate_url,
            'cta_paypal_url' => $cta_paypal_url,
            'desc_html' => $desc_html,
            'is_published' => $is_published,
        ];
        $set_parts = [];
        $bind_types = '';
        $bind_values = [];
        foreach ($cols as $col => $val) {
            if ($this->column_exists($mysqli, $table, $col)) {
                $set_parts[] = "`{$col}` = ?";
                $bind_types .= ($col === 'is_published') ? 'i' : 's';
                $bind_values[] = $val;
            }
        }
        // add last_tidb_update_at if exists
        if ($this->column_exists($mysqli, $table, 'last_tidb_update_at')) {
            $set_parts[] = "last_tidb_update_at = NOW()";
        }

        if (empty($set_parts)) {
            $mysqli->close();
            $redirect = add_query_arg(['vpp_err'=> rawurlencode('No editable columns found in table.')], admin_url('admin.php?page=vpp_edit&lookup_type=id&lookup_val='.$id));
            wp_safe_redirect($redirect); exit;
        }

        $sql = "UPDATE `{$table}` SET " . implode(', ', $set_parts) . " WHERE id = ?";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            $this->log_error('db_query', 'DB prepare failed: ' . $mysqli->error);
            $mysqli->close();
            $redirect = add_query_arg(['vpp_err'=> rawurlencode('DB prepare failed: ' . $mysqli->error)], admin_url('admin.php?page=vpp_edit&lookup_type=id&lookup_val='.$id));
            wp_safe_redirect($redirect); exit;
        }

        $bind_types .= 'i'; // for id
        $bind_values[] = $id;

        $bind_params = array_merge([$bind_types], $bind_values);
        $refs = [];
        foreach ($bind_params as $k => $v) { $refs[$k] = &$bind_params[$k]; }
        call_user_func_array([$stmt, 'bind_param'], $refs);

        $ok = $stmt->execute();
        $stmt_error = $stmt->error;
        $stmt->close();
        @$mysqli->close();

        $inline = 'ok'; $inline_msg = 'Saved';
        $top_msg = 'Saved.';
        if (!$ok) {
            $this->log_error('db_query', 'Product update failed: ' . $stmt_error);
            $inline = 'err'; $inline_msg = 'Error saving'; $top_msg = 'Save failed.';
        }

        // Handle post-actions
        if ($ok && isset($_POST['do_save_purge'])) {
            $site = $this->get_settings()['cloudflare']['site_base'];
            $url = $site ? rtrim($site,'/') . '/p/' . $slug : home_url('/p/' . $slug);
            $this->purge_cloudflare_url($url);
            $inline = 'ok'; $inline_msg = 'Saved & purged'; $top_msg = 'Saved and purged Cloudflare.';
        }
        if ($ok && isset($_POST['do_push_algolia'])) {
            $p = $this->fetch_product_by_id($id, $tmp);
            if ($p && $this->push_algolia($p, $tmp2)) {
                $inline = 'ok'; $inline_msg = 'Pushed to Algolia'; $top_msg = 'Saved and pushed to Algolia.';
            } else {
                $inline = 'err'; $inline_msg = 'Algolia: ' . ($tmp2 ?: 'error'); $top_msg = 'Saved, but Algolia push failed.';
            }
        }

        $args = ['lookup_type'=>'id','lookup_val'=>$id,'vpp_msg'=>rawurlencode($top_msg),'inline'=>$inline,'inline_msg'=>rawurlencode($inline_msg)];
        $redirect = add_query_arg($args, admin_url('admin.php?page=vpp_edit'));

        if (isset($_POST['do_save_view']) && $ok) {
            $redirect = add_query_arg(['vpp_msg'=> rawurlencode($top_msg . ' Open: ' . home_url('/p/' . $slug))] + $args, admin_url('admin.php?page=vpp_edit'));
        }

        wp_safe_redirect($redirect); exit;
    }

    /* ========= ADMIN HANDLERS (existing) ========= */

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $input = isset($_POST[self::OPT_KEY]) ? (array) $_POST[self::OPT_KEY] : [];
        $clean = $this->sanitize_settings($input);
        update_option(self::OPT_KEY, $clean, false); // persistent
        $redirect = add_query_arg(['vpp_msg'=> rawurlencode('Settings saved.')], admin_url('admin.php?page=vpp_settings'));
        wp_safe_redirect($redirect); exit;
    }

    public function handle_test_tidb() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $err = null;
        $conn = $this->db_connect($err);
        if ($conn) { @$conn->close(); $ok = true; } else { $ok = false; }
        $redirect = add_query_arg([$ok ? 'vpp_msg' : 'vpp_err' => rawurlencode($ok ? 'TiDB connection OK.' : ('TiDB connection failed: ' . $err))], admin_url('admin.php?page=vpp_settings'));
        wp_safe_redirect($redirect); exit;
    }

    public function handle_test_algolia() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $s = $this->get_settings();
        $app = trim($s['algolia']['app_id'] ?? '');
        $key = trim($s['algolia']['admin_key'] ?? '');
        $index = trim($s['algolia']['index'] ?? '');
        if (!$app || !$key || !$index) {
            $redirect = add_query_arg(['vpp_err'=> rawurlencode('Algolia not configured (app_id, admin_key, index required).')], admin_url('admin.php?page=vpp_settings'));
            wp_safe_redirect($redirect); exit;
        }
        $endpoint = "https://{$app}-dsn.algolia.net/1/indexes/" . rawurlencode($index);
        $resp = wp_remote_request($endpoint, [
            'method' => 'GET',
            'headers' => [
                'X-Algolia-API-Key' => $key,
                'X-Algolia-Application-Id' => $app
            ],
            'timeout' => 10,
        ]);
        if (is_wp_error($resp)) {
            $msg = 'Algolia request failed: ' . $resp->get_error_message();
            $this->log_error('algolia', $msg);
            $redirect = add_query_arg(['vpp_err'=> rawurlencode($msg)], admin_url('admin.php?page=vpp_settings'));
        } else {
            $code = wp_remote_retrieve_response_code($resp);
            if ($code >= 200 && $code < 300) {
                $redirect = add_query_arg(['vpp_msg'=> rawurlencode('Algolia connection OK.')], admin_url('admin.php?page=vpp_settings'));
            } else {
                $msg = 'Algolia HTTP ' . $code;
                $this->log_error('algolia', 'Test failed: ' . $msg);
                $redirect = add_query_arg(['vpp_err'=> rawurlencode($msg)], admin_url('admin.php?page=vpp_settings'));
            }
        }
        wp_safe_redirect($redirect); exit;
    }

    /* ========= DATA ACCESS ========= */

    private function db_connect(&$err = null) {
        $s = $this->get_settings();
        $host = $s['tidb']['host']; $port = $s['tidb']['port']; $db = $s['tidb']['database']; $user = $s['tidb']['user']; $pass = $s['tidb']['pass'];
        $ssl_ca = trim($s['tidb']['ssl_ca']);
        if (!$host || !$db || !$user) { $err = 'TiDB connection is not configured.'; $this->log_error('db_connect', $err); return null; }
        $mysqli = @mysqli_init();
        if (!$mysqli) { $err = 'mysqli_init() failed'; $this->log_error('db_connect', $err); return null; }

        // Enforce TLS for TiDB Serverless
        if ($ssl_ca && @file_exists($ssl_ca)) {
            @mysqli_ssl_set($mysqli, NULL, NULL, $ssl_ca, NULL, NULL);
        }
        if (defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
            @mysqli_options($mysqli, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, ($ssl_ca && @file_exists($ssl_ca)));
        }
        if (defined('MYSQLI_OPT_SSL_MODE') && defined('MYSQLI_SSL_MODE_REQUIRED')) {
            @mysqli_options($mysqli, MYSQLI_OPT_SSL_MODE, MYSQLI_SSL_MODE_REQUIRED);
        }

        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 8);
        @$mysqli->real_connect($host, $user, $pass, $db, (int)$port, null, defined('MYSQLI_CLIENT_SSL') ? MYSQLI_CLIENT_SSL : 0);
        if ($mysqli->connect_errno) {
            $msg = $mysqli->connect_error;
            if (stripos($msg, 'insecure transport') !== false) {
                $msg .= ' (Tip: set a valid SSL CA path in the plugin settings.)';
            }
            $err = 'DB connect error: ' . $msg;
            $this->log_error('db_connect', $err);
            return null;
        }
        @$mysqli->set_charset('utf8mb4');
        return $mysqli;
    }

    private function fetch_product_by_slug($slug, &$err = null) {
        $s = $this->get_settings();
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $s['tidb']['table']);
        $mysqli = $this->db_connect($err);
        if (!$mysqli) return null;
        $sql = "SELECT id, slug, title_h1, brand, model, sku, images_json, desc_html,
                       short_summary,
                       cta_lead_url, cta_stripe_url, cta_affiliate_url, cta_paypal_url,
                       is_published, last_tidb_update_at
                FROM `{$table}` WHERE slug = ? LIMIT 2";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) { $err = 'DB prepare failed: ' . $mysqli->error; $this->log_error('db_query', $err); return null; }
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        @$mysqli->close();
        if (!$row) { $err = 'Product not found.'; return null; }
        return $row;
    }

    private function fetch_product_by_id($id, &$err = null) {
        $s = $this->get_settings();
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $s['tidb']['table']);
        $mysqli = $this->db_connect($err);
        if (!$mysqli) return null;
        $sql = "SELECT id, slug, title_h1, brand, model, sku, images_json, desc_html,
                       short_summary,
                       cta_lead_url, cta_stripe_url, cta_affiliate_url, cta_paypal_url,
                       is_published, last_tidb_update_at
                FROM `{$table}` WHERE id = ? LIMIT 1";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) { $err = 'DB prepare failed: ' . $mysqli->error; $this->log_error('db_query', $err); return null; }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        @$mysqli->close();
        if (!$row) { $err = 'Product not found.'; return null; }
        return $row;
    }

    private function publish_product($id_or_slug, &$err = null) {
        $s = $this->get_settings();
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $s['tidb']['table']);
        $mysqli = $this->db_connect($err);
        if (!$mysqli) return false;
        $is_num = ctype_digit((string)$id_or_slug);
        if ($is_num) {
            $sql = "UPDATE `{$table}` SET is_published = 1, last_tidb_update_at = NOW() WHERE id = ?";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) { $err = 'DB prepare failed.'; $this->log_error('db_query', $err); return false; }
            $id = (int)$id_or_slug;
            $stmt->bind_param('i', $id);
        } else {
            $sql = "UPDATE `{$table}` SET is_published = 1, last_tidb_update_at = NOW() WHERE slug = ?";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) { $err = 'DB prepare failed.'; $this->log_error('db_query', $err); return false; }
            $slug = $id_or_slug;
            $stmt->bind_param('s', $slug);
        }
        $ok = $stmt->execute();
        $stmt->close();
        @$mysqli->close();
        if (!$ok) { $err = 'Publish failed.'; $this->log_error('db_query', $err); return false; }
        return true;
    }

    private function purge_cloudflare(array $urls = [], &$err = null) {
        $s = $this->get_settings();
        $token = trim($s['cloudflare']['api_token'] ?? '');
        $zone  = trim($s['cloudflare']['zone_id'] ?? '');
        if (!$token || !$zone) { $err = 'Cloudflare credentials not configured.'; $this->log_error('cloudflare', $err); return false; }

        $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zone}/purge_cache";
        $payload = empty($urls)
            ? ['purge_everything' => true]
            : ['files' => array_values(array_filter($urls))];
        if (empty($payload['purge_everything']) && empty($payload['files'])) {
            $err = 'No URLs provided for purge.';
            $this->log_error('cloudflare', $err);
            return false;
        }

        $args = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 15,
        ];
        $resp = wp_remote_post($endpoint, $args);
        if (is_wp_error($resp)) { $err = 'Cloudflare request failed: ' . $resp->get_error_message(); $this->log_error('cloudflare', $err); return false; }
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code < 200 || $code >= 300 || (is_array($body) && isset($body['success']) && !$body['success'])) {
            $msg = is_array($body) && !empty($body['errors'][0]['message']) ? $body['errors'][0]['message'] : ('HTTP ' . $code);
            $err = 'Cloudflare purge failed: ' . $msg;
            $this->log_error('cloudflare', $err);
            return false;
        }
        return true;
    }

    private function purge_cloudflare_url($url) {
        if (!$url) { return false; }
        return $this->purge_cloudflare([$url]);
    }

    private function rebuild_sitemaps(&$summary = '', &$err = null, &$meta = null) {
        $mysqli = $this->db_connect($err);
        if (!$mysqli) { return false; }

        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $this->get_settings()['tidb']['table']);
        $batch_size = 2000;
        $chunk_size = 50000;
        $offset = 0;
        $total = 0;
        $chunk_index = 0;
        $chunk_entries = [];
        $chunk_lastmod = 0;
        $files = [];

        $uploads = wp_upload_dir();
        if (empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            @$mysqli->close();
            $err = 'Uploads directory is not writable.';
            $this->log_error('sitemap', $err);
            return false;
        }
        $dir = trailingslashit($uploads['basedir']) . 'vpp-sitemaps';
        if (!wp_mkdir_p($dir)) {
            @$mysqli->close();
            $err = 'Failed to create sitemap directory.';
            $this->log_error('sitemap', $err);
            return false;
        }
        $dir = trailingslashit($dir);
        $base_url = trailingslashit($uploads['baseurl']) . 'vpp-sitemaps/';

        // Clean previous sitemap files.
        foreach (glob($dir . 'sitemap-*.xml') ?: [] as $old) { @unlink($old); }
        @unlink($dir . 'sitemap-index.xml');

        $write_chunk = function(array $entries, $lastmod_ts, $index) use (&$files, $dir, $base_url, &$err) {
            if (empty($entries)) { return true; }
            $filename = sprintf('sitemap-products-%d.xml', $index);
            $path = $dir . $filename;
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($entries as $entry) {
                $loc = home_url('/p/' . $entry['slug']);
                $xml .= "  <url>\n";
                $xml .= '    <loc>' . $this->xml_escape($loc) . '</loc>' . "\n";
                $xml .= '    <lastmod>' . gmdate('c', $entry['lastmod']) . '</lastmod>' . "\n";
                $xml .= "  </url>\n";
            }
            $xml .= '</urlset>';
            if (@file_put_contents($path, $xml) === false) {
                $err = 'Failed to write ' . $filename;
                $this->log_error('sitemap', $err);
                return false;
            }
            $files[] = [
                'loc' => $base_url . $filename,
                'lastmod' => gmdate('c', $lastmod_ts ?: time()),
            ];
            return true;
        };

        while (true) {
            $sql = sprintf(
                "SELECT slug, last_tidb_update_at FROM `%s` WHERE is_published = 1 ORDER BY id LIMIT %d OFFSET %d",
                $table,
                $batch_size,
                $offset
            );
            $res = $mysqli->query($sql);
            if (!$res) {
                @$mysqli->close();
                $err = 'DB query failed: ' . $mysqli->error;
                $this->log_error('sitemap', $err);
                return false;
            }

            $row_count = 0;
            while ($row = $res->fetch_assoc()) {
                $row_count++;
                $slug = trim($row['slug'] ?? '');
                if ($slug === '') { continue; }
                $lastmod = !empty($row['last_tidb_update_at']) ? strtotime($row['last_tidb_update_at']) : time();
                if (!$lastmod) { $lastmod = time(); }
                $chunk_entries[] = ['slug' => $slug, 'lastmod' => $lastmod];
                $chunk_lastmod = max($chunk_lastmod, $lastmod);
                $total++;
                if (count($chunk_entries) >= $chunk_size) {
                    $chunk_index++;
                    if (!$write_chunk($chunk_entries, $chunk_lastmod, $chunk_index)) {
                        $res->free();
                        @$mysqli->close();
                        return false;
                    }
                    $chunk_entries = [];
                    $chunk_lastmod = 0;
                }
            }
            $res->free();

            if ($row_count < $batch_size) { break; }
            $offset += $batch_size;
        }

        if (!empty($chunk_entries)) {
            $chunk_index++;
            if (!$write_chunk($chunk_entries, $chunk_lastmod, $chunk_index)) {
                @$mysqli->close();
                return false;
            }
        }

        @$mysqli->close();

        $index_path = $dir . 'sitemap-index.xml';
        $index_xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $index_xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($files as $file) {
            $index_xml .= "  <sitemap>\n";
            $index_xml .= '    <loc>' . $this->xml_escape($file['loc']) . '</loc>' . "\n";
            $index_xml .= '    <lastmod>' . $file['lastmod'] . '</lastmod>' . "\n";
            $index_xml .= "  </sitemap>\n";
        }
        $index_xml .= '</sitemapindex>';
        if (@file_put_contents($index_path, $index_xml) === false) {
            $err = 'Failed to write sitemap-index.xml';
            $this->log_error('sitemap', $err);
            return false;
        }

        $summary = sprintf(
            'Generated %d sitemap file(s) covering %d product(s).',
            count($files),
            $total
        );

        $meta = [
            'file_count' => count($files),
            'total' => $total,
            'generated_at' => current_time('timestamp'),
            'index_url' => $base_url . 'sitemap-index.xml',
        ];
        update_option(self::SITEMAP_META_OPTION, $meta, false);

        return true;
    }

    private function ping_search_engines($index_url, &$message = '') {
        if (!$index_url) {
            $message = 'No sitemap URL available for ping.';
            $this->log_error('sitemap_ping', $message);
            return false;
        }

        $endpoints = [
            'Google' => 'https://www.google.com/ping?sitemap=%s',
            'Bing'   => 'https://www.bing.com/ping?sitemap=%s',
        ];

        $messages = [];
        $all_ok = true;
        foreach ($endpoints as $label => $pattern) {
            $url = sprintf($pattern, rawurlencode($index_url));
            $resp = wp_remote_get($url, ['timeout' => 10]);
            if (is_wp_error($resp)) {
                $err = $resp->get_error_message();
                $messages[] = sprintf('%s ping failed: %s.', $label, $err);
                $this->log_error('sitemap_ping', $label . ': ' . $err);
                $all_ok = false;
                continue;
            }
            $code = wp_remote_retrieve_response_code($resp);
            if ($code >= 200 && $code < 300) {
                $messages[] = sprintf('%s ping OK.', $label);
            } else {
                $messages[] = sprintf('%s ping failed: HTTP %d.', $label, $code);
                $this->log_error('sitemap_ping', $label . ' HTTP ' . $code);
                $all_ok = false;
            }
        }

        $message = implode(' ', $messages);
        return $all_ok;
    }

    private function push_algolia($product, &$err = null) {
        $s = $this->get_settings();
        $app = $s['algolia']['app_id'];
        $key = $s['algolia']['admin_key'];
        $index = $s['algolia']['index'];
        if (!$app || !$key || !$index) { $err = 'Algolia not configured.'; return false; }

        $record = [
            'objectID' => (string)$product['id'],
            'title' => (string)$product['title_h1'],
            'slug'  => (string)$product['slug'],
            'url'   => home_url('/p/' . $product['slug']),
            'brand' => $product['brand'] ?? '',
            'model' => $product['model'] ?? '',
            'sku'   => $product['sku'] ?? '',
            'cta_available_types' => [],
            'last_updated' => $product['last_tidb_update_at'] ?? '',
        ];

        $img = '';
        if (!empty($product['images_json'])) {
            $arr = json_decode($product['images_json'], true);
            if (is_array($arr) && !empty($arr)) { $img = $arr[0]; }
        }
        if ($img) { $record['image_thumb'] = $img; }

        // snippet: short_summary if present, else trimmed desc_html
        $snippet = '';
        if (!empty($product['short_summary'])) {
            $snippet = $product['short_summary'];
        } elseif (!empty($product['desc_html'])) {
            $snippet = wp_strip_all_tags($product['desc_html']);
            if (function_exists('mb_substr')) { $snippet = mb_substr($snippet, 0, 180); } else { $snippet = substr($snippet, 0, 180); }
        } else {
            $parts = array_filter([$product['brand'] ?? '', $product['model'] ?? '', $product['sku'] ?? '']);
            $snippet = trim(implode(' • ', $parts));
        }
        if ($snippet) $record['snippet'] = $snippet;

        $primary = '';
        if (!empty($product['cta_lead_url'])) { $primary = 'lead'; $record['cta_available_types'][] = 'lead'; }
        if (!empty($product['cta_stripe_url'])) { if (!$primary) $primary='stripe'; $record['cta_available_types'][] = 'stripe'; }
        if (!empty($product['cta_affiliate_url'])) { if (!$primary) $primary='affiliate'; $record['cta_available_types'][] = 'affiliate'; }
        if (!empty($product['cta_paypal_url'])) { if (!$primary) $primary='paypal'; $record['cta_available_types'][] = 'paypal'; }
        if ($primary) $record['cta_primary_type'] = $primary;

        $endpoint = "https://{$app}-dsn.algolia.net/1/indexes/" . rawurlencode($index) . "/" . rawurlencode($record['objectID']);
        $args = [
            'method' => 'PUT',
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Algolia-API-Key' => $key,
                'X-Algolia-Application-Id' => $app
            ],
            'body' => wp_json_encode($record),
            'timeout' => 15,
        ];
        $resp = wp_remote_request($endpoint, $args);
        if (is_wp_error($resp)) { $err = $resp->get_error_message(); $this->log_error('algolia', 'Push failed: ' . $err); return false; }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) { $err = 'Algolia HTTP ' . $code; $this->log_error('algolia', 'Push failed: ' . $err); return false; }
        return true;
    }

    /* ========= ADMIN ACTIONS ========= */

    public function handle_push_publish() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $ids = isset($_POST['vpp_ids']) ? wp_unslash($_POST['vpp_ids']) : '';
        $list = array_filter(array_map('trim', explode(',', $ids)));
        $ok = 0; $fail = 0; $msg = '';
        foreach ($list as $id_or_slug) {
            $err = null;
            if ($this->publish_product($id_or_slug, $err)) {
                $slug = ctype_digit((string)$id_or_slug) ? null : $id_or_slug;
                if (!$slug) {
                    $p = $this->fetch_product_by_id((int)$id_or_slug, $tmp);
                    if ($p && !empty($p['slug'])) $slug = $p['slug'];
                }
                if ($slug) {
                    $site = $this->get_settings()['cloudflare']['site_base'];
                    $url = $site ? rtrim($site,'/') . '/p/' . $slug : home_url('/p/' . $slug);
                    $this->purge_cloudflare_url($url);
                }
                $ok++;
            } else { $fail++; $msg = $err ?: 'Unknown error'; }
        }
        $redirect = add_query_arg($fail ? ['vpp_err'=> rawurlencode("Published {$ok}, failed {$fail}. {$msg}")] : ['vpp_msg'=> rawurlencode("Published {$ok}, failed {$fail}.")], admin_url('admin.php?page=vpp_settings'));
        wp_safe_redirect($redirect); exit;
    }

    public function handle_push_algolia() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $ids = isset($_POST['vpp_ids']) ? wp_unslash($_POST['vpp_ids']) : '';
        $list = array_filter(array_map('trim', explode(',', $ids)));
        $ok = 0; $fail = 0; $msg = '';
        foreach ($list as $id_or_slug) {
            $err = null;
            $product = ctype_digit((string)$id_or_slug) ? $this->fetch_product_by_id((int)$id_or_slug, $err) : $this->fetch_product_by_slug($id_or_slug, $err);
            if ($product && $this->push_algolia($product, $err)) { $ok++; }
            else { $fail++; $msg = $err ?: 'Unknown error'; }
        }
        $redirect = add_query_arg($fail ? ['vpp_err'=> rawurlencode("Algolia push: {$ok} ok, {$fail} failed. {$msg}")] : ['vpp_msg'=> rawurlencode("Algolia push: {$ok} ok, {$fail} failed.")], admin_url('admin.php?page=vpp_settings'));
        wp_safe_redirect($redirect); exit;
    }

    public function handle_purge_cache() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $err = null;
        $ok = $this->purge_cloudflare([], $err);
        if ($ok) {
            $redirect = add_query_arg(['vpp_msg' => rawurlencode('Cloudflare cache purged.')], admin_url('admin.php?page=vpp_settings'));
        } else {
            $redirect = add_query_arg(['vpp_err' => rawurlencode($err ?: 'Cloudflare purge failed.')], admin_url('admin.php?page=vpp_settings'));
        }
        wp_safe_redirect($redirect); exit;
    }

    public function handle_rebuild_sitemaps() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $summary = '';
        $err = null;
        $meta = null;
        $ok = $this->rebuild_sitemaps($summary, $err, $meta);
        if ($ok) {
            $ping_msg = '';
            $ping_ok = $this->ping_search_engines($meta['index_url'] ?? '', $ping_msg);
            $message = trim(implode(' ', array_filter([$summary, $ping_msg])));
            if (!$message) { $message = $summary ?: 'Sitemap rebuilt.'; }
            $key = $ping_ok ? 'vpp_msg' : 'vpp_err';
            $redirect = add_query_arg([$key => rawurlencode($message)], admin_url('admin.php?page=vpp_settings'));
        } else {
            $redirect = add_query_arg(['vpp_err' => rawurlencode($err ?: 'Sitemap rebuild failed.')], admin_url('admin.php?page=vpp_settings'));
        }
        wp_safe_redirect($redirect); exit;
    }

    public function handle_download_log() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $path = $this->get_log_file_path();
        if (!$path || !file_exists($path)) {
            $redirect = add_query_arg(['vpp_err' => rawurlencode('Log file not found.')], admin_url('admin.php?page=vpp_status'));
            wp_safe_redirect($redirect); exit;
        }
        @nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . self::LOG_FILENAME . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public function handle_clear_log() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $path = $this->get_log_file_path();
        if ($path && file_exists($path)) {
            if (@file_put_contents($path, '') === false) {
                $this->log_error('logs', 'Failed to clear log file.');
                $redirect = add_query_arg(['vpp_err' => rawurlencode('Failed to clear log file.')], admin_url('admin.php?page=vpp_status'));
                wp_safe_redirect($redirect); exit;
            }
        }
        $redirect = add_query_arg(['vpp_msg' => rawurlencode('Log cleared.')], admin_url('admin.php?page=vpp_status'));
        wp_safe_redirect($redirect); exit;
    }

    /* ========= FRONT RENDER ========= */

    public function maybe_render_vpp() {
        $slug = get_query_var(self::QUERY_VAR);
        if (!$slug) return;
        $err = null;
        $product = $this->fetch_product_by_slug($slug, $err);
        if (!$product || empty($product['is_published'])) {
            status_header(404);
            nocache_headers();
            echo '<!doctype html><html><head><meta charset="utf-8"><title>Not found</title></head><body><h1>404</h1><p>Product not found.</p></body></html>';
            exit;
        }
        $this->render_product($product);
        exit;
    }

    private function allowed_html() {
        return [
            'h2'=>['id'=>[]], 'h3'=>['id'=>[]], 'h4'=>['id'=>[]],
            'p'=>['class'=>[]], 'br'=>[], 'hr'=>[],
            'ul'=>['class'=>[]], 'ol'=>['class'=>[]], 'li'=>['class'=>[]],
            'strong'=>[], 'em'=>[], 'u'=>[], 'span'=>['class'=>[]],
            'a'=>['href'=>[], 'title'=>[], 'rel'=>[], 'target'=>[]],
            'table'=>['class'=>[]], 'thead'=>[], 'tbody'=>[], 'tr'=>[], 'th'=>[], 'td'=>['colspan'=>[], 'rowspan'=>[]],
            'code'=>[], 'pre'=>[],
        ];
    }

    private function xml_escape($value) {
        return htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function pick_primary_cta($p) {
        if (!empty($p['cta_lead_url'])) return ['type'=>'lead','url'=>$p['cta_lead_url'],'rel'=>'nofollow'];
        if (!empty($p['cta_stripe_url'])) return ['type'=>'stripe','url'=>$p['cta_stripe_url'],'rel'=>'nofollow'];
        if (!empty($p['cta_affiliate_url'])) return ['type'=>'affiliate','url'=>$p['cta_affiliate_url'],'rel'=>'sponsored nofollow'];
        if (!empty($p['cta_paypal_url'])) return ['type'=>'paypal','url'=>$p['cta_paypal_url'],'rel'=>'nofollow'];
        return null;
    }

    private function secondary_ctas($p) {
        $out = [];
        if (!empty($p['cta_lead_url'])) $out[] = ['type'=>'lead','url'=>$p['cta_lead_url'],'rel'=>'nofollow'];
        if (!empty($p['cta_stripe_url'])) $out[] = ['type'=>'stripe','url'=>$p['cta_stripe_url'],'rel'=>'nofollow'];
        if (!empty($p['cta_affiliate_url'])) $out[] = ['type'=>'affiliate','url'=>$p['cta_affiliate_url'],'rel'=>'sponsored nofollow'];
        if (!empty($p['cta_paypal_url'])) $out[] = ['type'=>'paypal','url'=>$p['cta_paypal_url'],'rel'=>'nofollow'];
        return $out;
    }

    private function render_product($p) {
        $title = $p['title_h1'] ?: $p['slug'];
        $brand = $p['brand'] ?? '';
        $model = $p['model'] ?? '';
        $sku   = $p['sku'] ?? '';
        $images = [];
        if (!empty($p['images_json'])) {
            $arr = json_decode($p['images_json'], true);
            if (is_array($arr)) $images = $arr;
        }
        $primary = $this->pick_primary_cta($p);
        $secondary = $this->secondary_ctas($p);
        $allowed = $this->allowed_html();
        $meta_line = trim(implode(' • ', array_filter([$brand, $model, $sku])));

        $short_summary = isset($p['short_summary']) ? trim((string)$p['short_summary']) : '';
        if (strlen($short_summary) > 150) { $short_summary = substr($short_summary, 0, 150); }

        @header('Content-Type: text/html; charset=utf-8');
        @header('Cache-Control: public, max-age=300');
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($title); ?></title>
<link rel="canonical" href="<?php echo esc_url(home_url('/p/' . $p['slug'])); ?>">
<?php wp_head(); ?>
</head>
<body class="vpp-body">
<main class="vpp-container">
  <article class="vpp">
    <section class="vpp-hero card-elevated">
      <div class="vpp-grid">
        <div class="vpp-media">
          <?php if (!empty($images)): ?>
              <img src="<?php echo esc_url($images[0]); ?>" alt="<?php echo esc_attr($title); ?>" loading="eager" decoding="async" class="vpp-main-image"/>
              <?php if (count($images) > 1): ?>
                <div class="vpp-thumbs">
                  <?php foreach (array_slice($images, 1, 6) as $thumb): ?>
                    <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy" decoding="async" class="vpp-thumb"/>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
          <?php else: ?>
              <div class="vpp-placeholder">
                <div class="vpp-ph-img"></div>
              </div>
          <?php endif; ?>
        </div>
        <div class="vpp-summary">
          <h1 class="vpp-title"><?php echo esc_html($title); ?></h1>
          <?php if ($meta_line): ?><p class="vpp-meta"><?php echo esc_html($meta_line); ?></p><?php endif; ?>
          <?php if ($short_summary): ?><p class="vpp-short"><?php echo esc_html($short_summary); ?></p><?php endif; ?>

          <?php if ($primary): ?>
            <div class="vpp-cta-block">
              <a class="vpp-cta-primary glow" href="<?php echo esc_url($primary['url']); ?>" rel="<?php echo esc_attr($primary['rel']); ?>">
                <?php
                  $labels = ['lead'=>'Request a Quote','stripe'=>'Buy via Stripe','affiliate'=>'Buy Now','paypal'=>'Pay with PayPal'];
                  echo esc_html($labels[$primary['type']]);
                ?>
              </a>
              <div class="vpp-cta-secondary">
                <?php
                foreach ($secondary as $btn) {
                    if ($primary && $btn['type'] === $primary['type'] && $btn['url'] === $primary['url']) continue;
                    $labels = ['lead'=>'Request a Quote','stripe'=>'Buy via Stripe','affiliate'=>'Buy Now','paypal'=>'Pay with PayPal'];
                    echo '<a class="vpp-cta-secondary-btn" href="'.esc_url($btn['url']).'" rel="'.esc_attr($btn['rel']).'">'.esc_html($labels[$btn['type']]).'</a>';
                }
                ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="vpp-content card">
      <?php
        $html = $p['desc_html'] ?? '';
        if ($html) {
            echo wp_kses($html, $allowed);
        } else {
            echo '<p>No description available yet.</p>';
        }
      ?>
    </section>
  </article>
</main>
<?php wp_footer(); ?>
</body>
</html>
        <?php
    }
}

VPP_Plugin::instance();
