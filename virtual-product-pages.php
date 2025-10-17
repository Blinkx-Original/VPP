<?php
/**
 * Plugin Name: Virtual Product Pages (TiDB + Algolia)
 * Description: Render virtual product pages at /p/{slug} from TiDB, with external CTAs. Includes Push to VPP, Push to Algolia, Edit Product, sitemap rebuild, and Cloudflare purge.
 * Version: 1.4.9
 * Author: ChatGPT (for Martin)
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

class VPP_Plugin {
    const OPT_KEY = 'vpp_settings';
    const NONCE_KEY = 'vpp_nonce';
    const QUERY_VAR = 'vpp_slug';
    const SITEMAP_QUERY_VAR = 'vpp_sitemap';
    const SITEMAP_FILE_QUERY_VAR = 'vpp_sitemap_file';
    const VERSION = '1.4.9';
    const CSS_FALLBACK = <<<CSS
/* Minimal Vercel-like look */
body.vpp-body {
  background: #f9fafb;
  color: #0f172a;
  color-scheme: light;
  margin: 0;
  min-height: 100vh;
  font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  line-height: 1.5;
}
.vpp-container { max-width: 1100px; margin: 0 auto; padding: 2rem 1rem; }
.vpp .card,
.vpp .card-elevated {
  background: linear-gradient(180deg, rgba(248,250,252,0.85), #ffffff 45%);
  border-radius: 20px;
  padding: 1.35rem;
  box-shadow: 0 20px 60px rgba(15,23,42,0.08);
  border: 1px solid rgba(148,163,184,0.15);
}
.vpp .card-elevated {
  box-shadow: 0 28px 80px rgba(15,23,42,0.12), 0 1px 0 rgba(148,163,184,0.25);
}
.vpp-hero {
  margin-bottom: 1.25rem;
  background: linear-gradient(160deg, rgba(226,232,240,0.35), rgba(255,255,255,0.95));
}
.vpp-grid { display: grid; grid-template-columns: 1.1fr 1fr; gap: 2rem; }
@media (max-width: 900px) { .vpp-grid { grid-template-columns: 1fr; } }

.vpp-media { display:flex; flex-direction:column; gap: .75rem; }
.vpp-main-image {
  width: 100%;
  height: auto;
  border-radius: 16px;
  background: #f1f5f9;
  box-shadow: 0 18px 40px rgba(15,23,42,0.12);
}
.vpp-thumbs { display:flex; gap: .5rem; flex-wrap: wrap; }
.vpp-thumb {
  width: 88px;
  height: 64px;
  object-fit: cover;
  border-radius: 12px;
  background: #f1f5f9;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.9), 0 10px 24px rgba(15,23,42,0.08);
}

.vpp-placeholder {
  display:flex;
  align-items:center;
  justify-content:center;
  height: 360px;
  background: linear-gradient(180deg,#f8fafc,#e2e8f0);
  border-radius: 16px;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.6), 0 20px 40px rgba(15,23,42,0.08);
}
.vpp-ph-img {
  width: 60%;
  height: 70%;
  border-radius: 16px;
  background: repeating-linear-gradient(45deg,#e2e8f0,#e2e8f0 12px,#f8fafc 12px,#f8fafc 24px);
}

.vpp-summary {
  display:flex;
  flex-direction:column;
  gap: .65rem;
}
.vpp-title { font-size: clamp(1.6rem, 2vw, 2.2rem); font-weight: 800; margin: 0; color: #111827; }
.vpp-meta { color: #6b7280; margin: 0; }
.vpp-short { color: #374151; font-size: .95rem; margin: .25rem 0 0; max-width: 50ch; }

        .vpp-cta-block { margin-top: .75rem; display:flex; flex-direction:column; gap: .75rem; }
        .vpp-cta-button {
          display:flex;
          align-items:center;
          justify-content:center;
          text-decoration:none;
          padding: .95rem 1.4rem;
          font-weight: 700;
          border-radius: 999px;
          background: linear-gradient(135deg,#2563eb,#3b82f6);
          color: #fff !important;
          position: relative;
          box-shadow: 0 16px 32px rgba(37,99,235,0.4);
          transition: transform .2s ease, box-shadow .2s ease;
          min-height: 56px;
          text-align: center;
        }
        .vpp-cta-button.glow::before {
          content:"";
          position:absolute;
          inset:-3px;
          border-radius: 999px;
          background: radial-gradient( 120% 120% at 50% 0%, rgba(59,130,246,0.65), rgba(59,130,246,0.15) 60%, transparent 70% );
          filter: blur(8px);
          z-index:-1;
        }
        .vpp-cta-button:hover {
          transform: translateY(-2px);
          box-shadow: 0 24px 48px rgba(37,99,235,0.55);
        }
        .vpp-cta-button:focus-visible {
          outline: 3px solid rgba(59,130,246,0.45);
          outline-offset: 2px;
        }

.vpp-content {
  margin-top: 1.5rem;
  background: linear-gradient(180deg, rgba(248,250,252,0.9), #ffffff 55%);
}
.vpp-content h2 { font-size: 1.25rem; margin: 1rem 0 .5rem; }
.vpp-content h3 { font-size: 1.05rem; margin: .75rem 0 .25rem; }
.vpp-content table { width:100%; border-collapse: collapse; margin: .75rem 0; }
.vpp-content th, .vpp-content td { border: 1px solid #e5e7eb; padding: .5rem .6rem; text-align:left; }
.vpp-content a { color: #2563eb; }

/* inline admin messages */
.vpp-inline { padding:.25rem .5rem; border-radius:8px; font-size:12px; }
.vpp-inline.ok { background:#e6ffed; color:#065f46; }
.vpp-inline.err { background:#fee2e2; color:#991b1b; }
CSS;
    const SITEMAP_META_OPTION = 'vpp_sitemap_meta';
    const LOG_SUBDIR = 'vpp-logs';
    const LOG_FILENAME = 'vpp.log';
    const PUBLISH_STATE_OPTION = 'vpp_publish_state';
    const PUBLISH_HISTORY_OPTION = 'vpp_publish_history';
    const PUBLISH_RESUME_OPTION = 'vpp_publish_resume';

    private static $instance = null;

    // runtime debug
    private $last_meta_source = '';
    private $last_meta_value = '';

    private $cached_inline_css = null;
    private $current_inline_css = '';
    private $current_product = null;
    private $current_product_slug = null;
    private $current_meta_description = '';
    private $current_canonical = '';
    private $table_columns_cache = [];

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_rewrite']);
        add_filter('query_vars', [$this, 'add_query_var']);
        add_action('template_redirect', [$this, 'maybe_output_sitemap'], 0);
        add_action('template_redirect', [$this, 'maybe_render_vpp']);
        register_activation_hook(__FILE__, ['VPP_Plugin', 'on_activate']);
        register_deactivation_hook(__FILE__, ['VPP_Plugin', 'on_deactivate']);

        add_action('wp_head', [$this, 'inject_inline_css_head'], 0);
        add_action('wp_head', [$this, 'output_meta_tags'], 1);
        add_filter('robots_txt', [$this, 'filter_robots_txt'], 10, 2);

        if ($this->uses_wpseo_sitemaps()) {
            add_filter('wpseo_sitemap_index', [$this, 'filter_wpseo_sitemap_index']);
        }

        if (is_admin()) {
            add_action('admin_menu', [$this, 'admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_post_vpp_save_settings', [$this, 'handle_save_settings']);
            add_action('admin_post_vpp_test_tidb', [$this, 'handle_test_tidb']);
            add_action('admin_post_vpp_test_algolia', [$this, 'handle_test_algolia']);
            add_action('admin_post_vpp_push_publish', [$this, 'handle_push_publish']);
            add_action('admin_post_vpp_push_algolia', [$this, 'handle_push_algolia']);
            add_action('admin_post_vpp_publish_preview', [$this, 'handle_publish_preview']);
            add_action('admin_post_vpp_publish_sitemap', [$this, 'handle_publish_sitemap']);
            add_action('admin_post_vpp_publish_algolia', [$this, 'handle_publish_algolia']);
            add_action('admin_post_vpp_publish_resume', [$this, 'handle_publish_resume']);
            add_action('admin_post_vpp_publish_rotate_now', [$this, 'handle_publish_rotate_now']);
            add_action('admin_post_vpp_publish_rebuild_index', [$this, 'handle_publish_rebuild_index']);
            add_action('admin_post_vpp_publish_validate', [$this, 'handle_publish_validate']);
            add_action('admin_post_vpp_purge_cache', [$this, 'handle_purge_cache']);
            add_action('admin_post_vpp_rebuild_sitemaps', [$this, 'handle_rebuild_sitemaps']);
            add_action('admin_post_vpp_download_log', [$this, 'handle_download_log']);
            add_action('admin_post_vpp_clear_log', [$this, 'handle_clear_log']);
            add_action('admin_post_vpp_edit_load', [$this, 'handle_edit_load']);
            add_action('admin_post_vpp_edit_save', [$this, 'handle_edit_save']);
            add_action('admin_notices', [$this, 'maybe_admin_notice']);
        }

        // Front assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('body_class', [$this, 'filter_body_class']);

        // Canonical filters (Yoast/core)
        add_filter('wpseo_canonical', [$this, 'filter_canonical'], 99);
        add_filter('rel_canonical', [$this, 'filter_canonical'], 99);

        // Title filter signature-safe
        add_filter('pre_get_document_title', [$this, 'filter_document_title'], 10, 1);

        // Yoast meta description filter (keep in sync), we still print manual fallback in the head
        add_filter('wpseo_metadesc', [$this, 'filter_yoast_metadesc'], 99, 1);
        if (defined('WPSEO_VERSION')) {
            add_filter('wpseo_frontend_presenter_classes', [$this, 'filter_yoast_presenters'], 20, 1);
        }
    }

    public static function on_activate() { self::instance()->register_rewrite(); flush_rewrite_rules(); }
    public static function on_deactivate() { flush_rewrite_rules(); }

    public function add_query_var($vars) {
        $vars[] = self::QUERY_VAR;
        $vars[] = self::SITEMAP_QUERY_VAR;
        $vars[] = self::SITEMAP_FILE_QUERY_VAR;
        return $vars;
    }

    public function register_rewrite() {
        add_rewrite_rule('^p/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_tag('%' . self::QUERY_VAR . '%', '([^&]+)');
        if (!$this->uses_wpseo_sitemaps()) {
            add_rewrite_rule('^sitemap_index\\.xml$', 'index.php?' . self::SITEMAP_QUERY_VAR . '=index', 'top');
            add_rewrite_rule('^sitemap-index\\.xml$', 'index.php?' . self::SITEMAP_QUERY_VAR . '=index', 'top');
        }
        add_rewrite_rule('^sitemaps/([^/]+\\.xml)$', 'index.php?' . self::SITEMAP_QUERY_VAR . '=file&' . self::SITEMAP_FILE_QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_tag('%' . self::SITEMAP_QUERY_VAR . '%', '([^&]+)');
        add_rewrite_tag('%' . self::SITEMAP_FILE_QUERY_VAR . '%', '([^&]+)');
    }

    private function uses_wpseo_sitemaps() {
        static $cache = null;
        if ($cache === null) {
            $cache = defined('WPSEO_VERSION') || class_exists('WPSEO_Sitemaps_Router');
        }
        return $cache;
    }

    public function filter_robots_txt($output, $public) {
        $storage = $this->get_sitemap_storage_paths();
        if (!$storage || empty($storage['index_url'])) {
            return $output;
        }
        $sitemap = $storage['index_url'];
        if ($sitemap && stripos($output, $sitemap) !== false) {
            return $output;
        }
        $line = 'Sitemap: ' . esc_url_raw($sitemap);
        if ($output !== '') {
            $output = rtrim($output) . "\n";
        }
        return $output . $line . "\n";
    }

    public function filter_wpseo_sitemap_index($content) {
        $storage = $this->get_sitemap_storage_paths();
        if (!$storage || empty($storage['index_url'])) {
            return $content;
        }
        $loc = $storage['index_url'];
        if (!$loc || strpos($content, $loc) !== false) {
            return $content;
        }
        $path = $storage['dir'] . 'sitemap_index.xml';
        if (!@is_readable($path)) {
            $err = null;
            $this->refresh_sitemap_index($storage, $err);
        }
        $lastmod_ts = @filemtime($path) ?: time();
        $entry  = "  <sitemap>\n";
        $entry .= '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</loc>' . "\n";
        $entry .= '    <lastmod>' . htmlspecialchars(gmdate('c', $lastmod_ts), ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</lastmod>' . "\n";
        $entry .= "  </sitemap>\n";
        if (strpos($content, '</sitemapindex>') !== false) {
            return str_replace('</sitemapindex>', $entry . '</sitemapindex>', $content);
        }
        return $content . $entry;
    }

    public function enqueue_assets() {
        wp_register_style('vpp-styles', plugins_url('assets/vpp.css', __FILE__), [], self::VERSION);
        if ($this->current_vpp_slug()) {
            wp_enqueue_style('vpp-styles');
            $inline = $this->load_css_contents();
            if ($inline !== '') {
                wp_add_inline_style('vpp-styles', $inline);
            }
        }
    }

    public function filter_body_class($classes) {
        if ($this->current_vpp_slug()) {
            $classes[] = 'vpp-body';
        }
        return $classes;
    }

    /* ========= SETTINGS ========= */

    public function admin_menu() {
        add_menu_page('Virtual Products', 'Virtual Products', 'manage_options', 'vpp_settings', [$this, 'render_connectivity_page'], 'dashicons-archive', 58);
        add_submenu_page('vpp_settings', 'Connectivity', 'Connectivity', 'manage_options', 'vpp_settings', [$this, 'render_connectivity_page']);
        add_submenu_page('vpp_settings', 'Publishing', 'Publishing', 'manage_options', 'vpp_publishing', [$this, 'render_publishing_page']);
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

    public function render_connectivity_page() {
        if (!current_user_can('manage_options')) return;
        $s = $this->get_settings();
        ?>
        <div class="wrap">
            <h1>Connectivity</h1>
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
                    <tr><th scope="row">TiDB Table</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][table]" value="<?php echo esc_attr($s['tidb']['table']); ?>" class="regular-text"><p class="description">Expected columns: id, slug, title_h1, brand, model, sku, images_json, schema_json, desc_html, short_summary, meta_description, cta_lead_url, cta_stripe_url, cta_affiliate_url, cta_paypal_url, is_published, last_tidb_update_at</p></td></tr>
                    <tr><th scope="row">SSL CA Path</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][ssl_ca]" value="<?php echo esc_attr($s['tidb']['ssl_ca']); ?>" class="regular-text"></td></tr>
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

    public function render_publishing_page() {
        if (!current_user_can('manage_options')) return;
        $state = $this->get_publish_state();
        $inputs = $state['inputs'];
        $from_val = $inputs['from'];
        $to_val = $inputs['to'];
        $batch_limit = (int)$inputs['batch_limit'];
        $delay_ms = (int)$inputs['delay_ms'];
        $range_text = $from_val && $to_val ? sprintf('Range: %s UTC → %s UTC', $from_val, $to_val) : 'Range: —';
        $message = $state['message'];
        $message_type = $state['message_type'];
        $result = $state['result'];
        $samples = $state['samples'];
        $total = $state['total'];
        $history = $this->get_publish_history();
        $validation = $state['validation'];
        $sitemap_urls = $this->get_sitemap_index_urls();
        $notice_class = 'success';
        if ($message_type === 'error') {
            $notice_class = 'error';
        } elseif ($message_type === 'warning') {
            $notice_class = 'warning';
        }

        $presets = $this->get_publishing_presets();
        $admin_post = admin_url('admin-post.php');
        $resume_entries = $this->get_publish_resume_data();
        $resume_notices = [];
        $resume_labels = [
            'sitemap' => 'Publish to Sitemap',
            'algolia' => 'Push to Algolia',
        ];
        foreach ($resume_labels as $key => $label) {
            if (empty($resume_entries[$key]) || !is_array($resume_entries[$key])) {
                continue;
            }
            $entry = $resume_entries[$key];
            $total_limit = isset($entry['limit']) ? (int)$entry['limit'] : (int)($entry['total'] ?? 0);
            $processed = isset($entry['processed']) ? (int)$entry['processed'] : 0;
            if ($total_limit > 0 && $processed >= $total_limit) {
                continue;
            }
            $resume_notices[$key] = [
                'label' => $label,
                'batch' => max(1, (int)($entry['batches_done'] ?? 0) + 1),
                'processed' => $processed,
                'limit' => $total_limit,
                'from' => $entry['inputs']['from'] ?? '',
                'to' => $entry['inputs']['to'] ?? '',
            ];
        }
        ?>
        <div class="wrap vpp-publishing">
            <h1>Publishing</h1>
            <p class="description">Push products from TiDB to Algolia and the sitemap within a UTC date range.</p>

            <?php if ($message): ?>
                <div class="notice notice-<?php echo esc_attr($notice_class); ?> is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>

            <?php if (!empty($resume_notices)): ?>
                <?php foreach ($resume_notices as $action => $notice): ?>
                    <div class="notice notice-warning">
                        <p>
                            <strong>Resume available:</strong>
                            A previous <?php echo esc_html($notice['label']); ?> run was interrupted for
                            <code><?php echo esc_html($notice['from']); ?></code> → <code><?php echo esc_html($notice['to']); ?></code>.
                            Resume from batch #<?php echo (int)$notice['batch']; ?>?
                        </p>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <form method="post" action="<?php echo esc_url($admin_post); ?>">
                                <?php wp_nonce_field(self::NONCE_KEY); ?>
                                <input type="hidden" name="action" value="vpp_publish_resume"/>
                                <input type="hidden" name="target" value="<?php echo esc_attr($action); ?>"/>
                                <input type="hidden" name="mode" value="resume"/>
                                <button type="submit" class="button button-primary">Resume <?php echo esc_html($notice['label']); ?></button>
                            </form>
                            <form method="post" action="<?php echo esc_url($admin_post); ?>">
                                <?php wp_nonce_field(self::NONCE_KEY); ?>
                                <input type="hidden" name="action" value="vpp_publish_resume"/>
                                <input type="hidden" name="target" value="<?php echo esc_attr($action); ?>"/>
                                <input type="hidden" name="mode" value="clear"/>
                                <button type="submit" class="button">Dismiss</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <form id="vpp-publishing-form" method="post" action="<?php echo esc_url($admin_post); ?>">
                <?php wp_nonce_field(self::NONCE_KEY); ?>
                <h2 class="title">Date Range</h2>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="vpp-publish-from">DateTime From (UTC)</label></th>
                            <td>
                                <input type="text" id="vpp-publish-from" name="publish_from" value="<?php echo esc_attr($from_val); ?>" class="regular-text" placeholder="YYYY-MM-DD HH:MM" autocomplete="off">
                                <p class="description">Input format: YYYY-MM-DD HH:MM (UTC)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="vpp-publish-to">DateTime To (UTC)</label></th>
                            <td>
                                <input type="text" id="vpp-publish-to" name="publish_to" value="<?php echo esc_attr($to_val); ?>" class="regular-text" placeholder="YYYY-MM-DD HH:MM" autocomplete="off">
                                <div class="vpp-preset-buttons" style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                                    <?php foreach ($presets as $preset): ?>
                                        <button type="button" class="button" data-from="<?php echo esc_attr($preset['from']); ?>" data-to="<?php echo esc_attr($preset['to']); ?>"><?php echo esc_html($preset['label']); ?></button>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description" id="vpp-range-text"><?php echo esc_html($range_text); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2 class="title" style="margin-top:24px;">Publishing Runs</h2>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="vpp-batch-limit">Batch Limit</label></th>
                            <td>
                                <input type="number" id="vpp-batch-limit" name="batch_limit" value="<?php echo esc_attr($batch_limit); ?>" min="1" step="1" style="width:120px;">
                                <p class="description">Maximum number of products processed per run.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="vpp-delay-ms">Delay (ms) between batches</label></th>
                            <td>
                                <input type="number" id="vpp-delay-ms" name="delay_ms" value="<?php echo esc_attr($delay_ms); ?>" min="0" step="50" style="width:120px;">
                                <p class="description">Soft rate limit applied after each batch (default 200 ms).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Sitemap Options</th>
                            <td>
                                <label><input type="checkbox" name="ping_google" value="1" <?php checked(!empty($inputs['ping_google'])); ?>> Ping Google after sitemap update</label><br>
                                <label><input type="checkbox" name="mark_published" value="1" <?php checked(!empty($inputs['mark_published'])); ?>> Mark records as published</label>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit" style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                    <button type="submit" class="button" formaction="<?php echo esc_url($admin_post . '?action=vpp_publish_preview'); ?>">Preview Range</button>
                    <button type="submit" class="button button-primary" formaction="<?php echo esc_url($admin_post . '?action=vpp_publish_sitemap'); ?>">Publish to Sitemap</button>
                    <button type="submit" class="button button-primary" formaction="<?php echo esc_url($admin_post . '?action=vpp_publish_algolia'); ?>">Push to Algolia</button>
                </p>
            </form>

            <div class="vpp-sitemap-maintenance card" style="margin-top:32px; padding:16px; max-width:940px;">
                <h2 class="title">Sitemap Maintenance</h2>
                <p>Manual tools to keep sitemap files healthy.</p>
                <?php if (!empty($sitemap_urls)): ?>
                    <p class="description" style="margin-top:4px;">
                        Sitemap index URL:
                        <a href="<?php echo esc_url($sitemap_urls['primary']); ?>" target="_blank" rel="noopener noreferrer">
                            <code><?php echo esc_html($sitemap_urls['primary']); ?></code>
                        </a>
                    </p>
                    <?php if (!empty($sitemap_urls['yoast'])): ?>
                        <p class="description" style="margin-top:4px;">
                            Listed in Yoast index:
                            <a href="<?php echo esc_url($sitemap_urls['yoast']); ?>" target="_blank" rel="noopener noreferrer">
                                <code><?php echo esc_html($sitemap_urls['yoast']); ?></code>
                            </a>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($sitemap_urls['legacy']) && $sitemap_urls['legacy'] !== $sitemap_urls['primary']): ?>
                        <p class="description" style="margin-top:4px;">
                            Legacy alias:
                            <a href="<?php echo esc_url($sitemap_urls['legacy']); ?>" target="_blank" rel="noopener noreferrer">
                                <code><?php echo esc_html($sitemap_urls['legacy']); ?></code>
                            </a>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
                <div style="display:flex; flex-wrap:wrap; gap:12px;">
                    <form method="post" action="<?php echo esc_url($admin_post); ?>" style="margin:0;">
                        <?php wp_nonce_field(self::NONCE_KEY); ?>
                        <input type="hidden" name="action" value="vpp_publish_rotate_now"/>
                        <button type="submit" class="button">Rotate Now (Start New Sitemap Set)</button>
                    </form>
                    <form method="post" action="<?php echo esc_url($admin_post); ?>" style="margin:0;">
                        <?php wp_nonce_field(self::NONCE_KEY); ?>
                        <input type="hidden" name="action" value="vpp_publish_rebuild_index"/>
                        <button type="submit" class="button">Rebuild sitemap_index.xml</button>
                    </form>
                    <form method="post" action="<?php echo esc_url($admin_post); ?>" style="margin:0;">
                        <?php wp_nonce_field(self::NONCE_KEY); ?>
                        <input type="hidden" name="action" value="vpp_publish_validate"/>
                        <button type="submit" class="button">Validate Sitemaps</button>
                    </form>
                </div>

                <?php if (!empty($validation)): ?>
                    <div style="margin-top:16px;">
                        <h3>Validation Summary</h3>
                        <table class="widefat striped" style="max-width:900px;">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>File</th>
                                    <th>URLs</th>
                                    <th>Size</th>
                                    <th>Last Modified (UTC)</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($validation as $row): ?>
                                    <tr>
                                        <td><?php echo esc_html($row['icon'] ?? ''); ?></td>
                                        <td><code><?php echo esc_html($row['file'] ?? ''); ?></code></td>
                                        <td><?php echo esc_html(number_format_i18n((int)($row['urls'] ?? 0))); ?></td>
                                        <td><?php echo esc_html($row['size_human'] ?? ''); ?></td>
                                        <td><?php echo esc_html($row['last_modified'] ?? ''); ?></td>
                                        <td><?php echo esc_html($row['note'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="vpp-publish-summary" style="margin-top:24px;">
                <h2>Latest Result</h2>
                <?php if ($result): ?>
                    <table class="widefat striped" style="max-width:720px;">
                        <tbody>
                            <tr><th scope="row">Action</th><td><?php echo esc_html(ucfirst($result['action'])); ?></td></tr>
                            <tr><th scope="row">Total in Range</th><td><?php echo esc_html(number_format_i18n((int)$total)); ?></td></tr>
                            <tr><th scope="row">Processed</th><td><?php echo esc_html(number_format_i18n((int)($result['processed'] ?? 0))); ?></td></tr>
                            <tr><th scope="row">Skipped</th><td><?php echo esc_html(number_format_i18n((int)($result['skipped'] ?? 0))); ?></td></tr>
                            <tr><th scope="row">Failed</th><td><?php echo esc_html(number_format_i18n((int)($result['failed'] ?? 0))); ?></td></tr>
                            <tr><th scope="row">Batches</th><td><?php echo esc_html(number_format_i18n((int)($result['batches'] ?? 0))); ?></td></tr>
                            <tr><th scope="row">Duration</th><td><?php echo esc_html($result['duration'] ?? '—'); ?></td></tr>
                            <?php if (!empty($result['notes'])): ?>
                                <tr><th scope="row">Notes</th><td><?php echo esc_html($result['notes']); ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No publishing actions executed yet.</p>
                <?php endif; ?>
            </div>

            <div class="vpp-publish-preview" style="margin-top:24px;">
                <h2>Preview Samples</h2>
                <?php if (!empty($samples)): ?>
                    <p>Showing up to 20 slugs ordered by updated_at ASC.</p>
                    <ol>
                        <?php foreach ($samples as $slug): ?>
                            <li><code><?php echo esc_html($slug); ?></code></li>
                        <?php endforeach; ?>
                    </ol>
                <?php else: ?>
                    <p>No samples available.</p>
                <?php endif; ?>
            </div>

            <div class="vpp-publish-history" style="margin-top:24px;">
                <h2>History (Last 10)</h2>
                <?php if (!empty($history)): ?>
                    <table class="widefat striped" style="max-width:920px;">
                        <thead>
                            <tr>
                                <th>Executed (UTC)</th>
                                <th>Action</th>
                                <th>Date Range</th>
                                <th>Processed</th>
                                <th>Duration</th>
                                <th>Batch Limit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $entry): ?>
                                <tr>
                                    <td><?php echo esc_html($entry['timestamp']); ?></td>
                                    <td><?php echo esc_html($entry['action']); ?></td>
                                    <td><code><?php echo esc_html($entry['from']); ?></code> → <code><?php echo esc_html($entry['to']); ?></code></td>
                                    <td><?php echo esc_html(number_format_i18n((int)$entry['processed'])); ?></td>
                                    <td><?php echo esc_html($entry['duration']); ?></td>
                                    <td><?php echo esc_html((int)$entry['batch_limit']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No executions logged yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <script>
        (function(){
            const form = document.getElementById('vpp-publishing-form');
            if (!form) { return; }
            const fromInput = document.getElementById('vpp-publish-from');
            const toInput = document.getElementById('vpp-publish-to');
            const rangeText = document.getElementById('vpp-range-text');
            const buttons = form.querySelectorAll('.vpp-preset-buttons button');
            buttons.forEach(btn => {
                btn.addEventListener('click', () => {
                    const from = btn.getAttribute('data-from');
                    const to = btn.getAttribute('data-to');
                    if (from && fromInput) { fromInput.value = from; }
                    if (to && toInput) { toInput.value = to; }
                    if (rangeText) {
                        if (from && to) {
                            rangeText.textContent = 'Range: ' + from + ' UTC → ' + to + ' UTC';
                        } else {
                            rangeText.textContent = 'Range: —';
                        }
                    }
                });
            });
        })();
        </script>
        <?php
    }

    private function get_publish_state() {
        $state = get_option(self::PUBLISH_STATE_OPTION);
        if (!is_array($state)) {
            $state = [];
        }
        $defaults = [
            'inputs' => [
                'from' => '',
                'to' => '',
                'batch_limit' => 1000,
                'delay_ms' => 200,
                'ping_google' => 0,
                'mark_published' => 1,
            ],
            'message' => '',
            'message_type' => 'success',
            'result' => null,
            'samples' => [],
            'total' => 0,
            'validation' => [],
        ];
        $inputs = isset($state['inputs']) && is_array($state['inputs']) ? $state['inputs'] : [];
        $state = array_merge($defaults, $state);
        $state['inputs'] = array_merge($defaults['inputs'], $inputs);
        $state['inputs']['batch_limit'] = max(1, (int)$state['inputs']['batch_limit']);
        $state['inputs']['delay_ms'] = max(0, (int)$state['inputs']['delay_ms']);
        $state['inputs']['from'] = is_string($state['inputs']['from']) ? $state['inputs']['from'] : '';
        $state['inputs']['to'] = is_string($state['inputs']['to']) ? $state['inputs']['to'] : '';
        $state['message'] = is_string($state['message']) ? $state['message'] : '';
        $state['message_type'] = $state['message_type'] === 'error' ? 'error' : 'success';
        $state['samples'] = is_array($state['samples']) ? $state['samples'] : [];
        $state['total'] = isset($state['total']) ? (int)$state['total'] : 0;
        $state['validation'] = is_array($state['validation']) ? $state['validation'] : [];
        return $state;
    }

    private function save_publish_state(array $state) {
        update_option(self::PUBLISH_STATE_OPTION, $state, false);
    }

    private function get_publish_history() {
        $history = get_option(self::PUBLISH_HISTORY_OPTION);
        if (!is_array($history)) {
            return [];
        }
        return $history;
    }

    private function add_publish_history_entry(array $entry) {
        $history = $this->get_publish_history();
        array_unshift($history, $entry);
        if (count($history) > 10) {
            $history = array_slice($history, 0, 10);
        }
        update_option(self::PUBLISH_HISTORY_OPTION, $history, false);
    }

    private function get_publish_resume_data() {
        $data = get_option(self::PUBLISH_RESUME_OPTION);
        if (!is_array($data)) {
            $data = [];
        }
        return $data;
    }

    private function get_publish_resume_entry($action) {
        $data = $this->get_publish_resume_data();
        if (isset($data[$action]) && is_array($data[$action])) {
            return $data[$action];
        }
        return null;
    }

    private function set_publish_resume_entry($action, $entry) {
        $data = $this->get_publish_resume_data();
        if ($entry === null) {
            unset($data[$action]);
        } else {
            $entry['action'] = $action;
            $data[$action] = $entry;
        }
        update_option(self::PUBLISH_RESUME_OPTION, $data, false);
    }

    private function get_sitemap_meta() {
        $meta = get_option(self::SITEMAP_META_OPTION);
        if (!is_array($meta)) {
            $meta = [];
        }
        if (!isset($meta['locks']) || !is_array($meta['locks'])) {
            $meta['locks'] = [];
        }
        if (!isset($meta['base_url'])) {
            $meta['base_url'] = '';
        } else {
            $meta['base_url'] = (string)$meta['base_url'];
        }
        return $meta;
    }

    private function save_sitemap_meta(array $meta) {
        if (!isset($meta['locks']) || !is_array($meta['locks'])) {
            $meta['locks'] = [];
        }
        if (!isset($meta['base_url'])) {
            $meta['base_url'] = '';
        } else {
            $meta['base_url'] = (string)$meta['base_url'];
        }
        update_option(self::SITEMAP_META_OPTION, $meta, false);
    }

    private function get_sitemap_storage_paths() {
        $uploads = wp_upload_dir();
        if (empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            return null;
        }
        $dir = trailingslashit($uploads['basedir']) . 'vpp-sitemaps/';
        $public_base = trailingslashit(home_url('/sitemaps'));
        $yoast_active = $this->uses_wpseo_sitemaps();
        $root_index = home_url('/sitemap_index.xml');
        $alias_index = home_url('/sitemaps/vpp-index.xml');
        $public_index = $yoast_active ? $alias_index : $root_index;
        $legacy_dir = trailingslashit($uploads['basedir']) . 'vpp/';
        $legacy_url = trailingslashit($uploads['baseurl']) . 'vpp/';
        return [
            'dir' => $dir,
            'url' => $public_base,
            'index_url' => $public_index,
            'alias_index_url' => $alias_index,
            'yoast_index_url' => $yoast_active ? $root_index : null,
            'root_index_url' => $root_index,
            'upload_url' => trailingslashit($uploads['baseurl']) . 'vpp-sitemaps/',
            'legacy_dir' => $legacy_dir,
            'legacy_url' => $legacy_url,
        ];
    }

    private function get_sitemap_index_urls() {
        $storage = $this->get_sitemap_storage_paths();
        if (!$storage) {
            return [];
        }
        $urls = [
            'primary' => $storage['index_url'],
        ];
        if (!empty($storage['yoast_index_url']) && $storage['yoast_index_url'] !== $storage['index_url']) {
            $urls['yoast'] = $storage['yoast_index_url'];
        }
        if (!empty($storage['legacy_url'])) {
            $urls['legacy'] = $storage['legacy_url'] . 'sitemap_index.xml';
        }
        return $urls;
    }

    private function get_publishing_presets() {
        $tz = new DateTimeZone('UTC');
        $now = new DateTime('now', $tz);
        $now->setTime((int)$now->format('H'), (int)$now->format('i'));
        $today_start = (clone $now);
        $today_start->setTime(0, 0);
        $tomorrow = (clone $today_start);
        $tomorrow->modify('+1 day');
        $yesterday = (clone $today_start);
        $yesterday->modify('-1 day');
        $last24 = (clone $now);
        $last24->modify('-24 hours');
        $last7 = (clone $now);
        $last7->modify('-7 days');
        return [
            [
                'label' => 'Today (UTC)',
                'from' => $today_start->format('Y-m-d H:i'),
                'to' => $tomorrow->format('Y-m-d H:i'),
            ],
            [
                'label' => 'Yesterday + Today (UTC)',
                'from' => $yesterday->format('Y-m-d H:i'),
                'to' => $tomorrow->format('Y-m-d H:i'),
            ],
            [
                'label' => 'Last 24 h (UTC)',
                'from' => $last24->format('Y-m-d H:i'),
                'to' => $now->format('Y-m-d H:i'),
            ],
            [
                'label' => 'Last 7 days (UTC)',
                'from' => $last7->format('Y-m-d H:i'),
                'to' => $now->format('Y-m-d H:i'),
            ],
        ];
    }

    private function format_publish_duration($seconds) {
        if ($seconds < 0) {
            $seconds = 0;
        }
        if ($seconds < 60) {
            return sprintf('%.2fs', $seconds);
        }
        $minutes = floor($seconds / 60);
        $remaining = $seconds - ($minutes * 60);
        if ($minutes >= 60) {
            $hours = floor($minutes / 60);
            $minutes = $minutes % 60;
            return sprintf('%dh %dm %.1fs', $hours, $minutes, $remaining);
        }
        return sprintf('%dm %.1fs', $minutes, $remaining);
    }

    private function format_bytes($bytes) {
        $bytes = (float)$bytes;
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int)floor(log($bytes, 1024));
        $power = max(0, min($power, count($units) - 1));
        $value = $bytes / pow(1024, $power);
        if ($value >= 100) {
            $formatted = number_format_i18n($value, 0);
        } elseif ($value >= 10) {
            $formatted = number_format_i18n($value, 1);
        } else {
            $formatted = number_format_i18n($value, 2);
        }
        return $formatted . ' ' . $units[$power];
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

    /* ========= STATUS ========= */

    private function count_published_products(&$err = null) {
        $mysqli = $this->db_connect($err);
        if (!$mysqli) {
            return null;
        }

        $settings = $this->get_settings();
        $table = $mysqli->real_escape_string($settings['tidb']['table']);
        $sql = "SELECT COUNT(*) AS cnt FROM `{$table}` WHERE is_published = 1";
        $res = @$mysqli->query($sql);
        if (!$res) {
            $err = 'Failed to count published products: ' . $mysqli->error;
            $this->log_error('status_count', $err);
            @$mysqli->close();
            return null;
        }

        $row = $res->fetch_assoc();
        $res->free();
        $count = isset($row['cnt']) ? (int)$row['cnt'] : 0;
        @$mysqli->close();
        return $count;
    }

    public function render_status_page() {
        if (!current_user_can('manage_options')) return;

        $published_err = null;
        $published_count = $this->count_published_products($published_err);

        $meta_source = $this->last_meta_source ?: '—';
        $meta_value = $this->last_meta_value ? mb_substr($this->last_meta_value, 0, 120) : '—';

        ?>
        <div class="wrap">
            <h1>VPP Status</h1>
            <table class="widefat striped" style="max-width:820px; margin-top:1rem;">
                <tbody>
                    <tr><th>Published products</th><td><?php echo $published_count !== null ? esc_html(number_format_i18n($published_count)) : '—'; ?> <?php if ($published_err) echo ' <span style="color:#b32d2e;">'.esc_html($published_err).'</span>'; ?></td></tr>
                    <tr><th>Last meta description source</th><td><?php echo esc_html($meta_source); ?></td></tr>
                    <tr><th>Last meta description value</th><td><code><?php echo esc_html($meta_value); ?></code></td></tr>
                </tbody>
            </table>
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

    private function log_error($context, $message) {
        $path = $this->get_log_file_path(true);
        if (!$path) { return; }
        $time = current_time('mysql');
        $line = sprintf("[%s] [%s] %s\n", $time, strtoupper($context), trim((string)$message));
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /* ========= PUBLISHING HELPERS ========= */

    private function parse_publish_inputs(array $source, &$err = null) {
        $from_raw = isset($source['publish_from']) ? sanitize_text_field(wp_unslash($source['publish_from'])) : '';
        $to_raw = isset($source['publish_to']) ? sanitize_text_field(wp_unslash($source['publish_to'])) : '';
        if ($from_raw === '' || $to_raw === '') {
            $err = 'Enter both "From" and "To" timestamps in UTC (YYYY-MM-DD HH:MM).';
            return null;
        }
        $tz = new DateTimeZone('UTC');
        $from_dt = DateTime::createFromFormat('Y-m-d H:i', $from_raw, $tz);
        $from_errors = DateTime::getLastErrors();
        if (!$from_dt || ($from_errors['warning_count'] ?? 0) > 0 || ($from_errors['error_count'] ?? 0) > 0) {
            $err = 'Invalid "From" timestamp (use YYYY-MM-DD HH:MM in UTC).';
            return null;
        }
        $to_dt = DateTime::createFromFormat('Y-m-d H:i', $to_raw, $tz);
        $to_errors = DateTime::getLastErrors();
        if (!$to_dt || ($to_errors['warning_count'] ?? 0) > 0 || ($to_errors['error_count'] ?? 0) > 0) {
            $err = 'Invalid "To" timestamp (use YYYY-MM-DD HH:MM in UTC).';
            return null;
        }
        $from_ts = $from_dt->getTimestamp();
        $to_ts = $to_dt->getTimestamp();
        if ($to_ts <= $from_ts) {
            $err = 'The "To" timestamp must be greater than "From".';
            return null;
        }
        $batch_limit = isset($source['batch_limit']) ? (int)$source['batch_limit'] : 1000;
        if ($batch_limit < 1) { $batch_limit = 1; }
        if ($batch_limit > 50000) { $batch_limit = 50000; }
        $delay_ms = isset($source['delay_ms']) ? (int)$source['delay_ms'] : 200;
        if ($delay_ms < 0) { $delay_ms = 0; }
        if ($delay_ms > 60000) { $delay_ms = 60000; }
        $ping_google = !empty($source['ping_google']) ? 1 : 0;
        $mark_published = !empty($source['mark_published']) ? 1 : 0;
        return [
            'from' => $from_dt->format('Y-m-d H:i'),
            'to' => $to_dt->format('Y-m-d H:i'),
            'from_sql' => $from_dt->format('Y-m-d H:i:00'),
            'to_sql' => $to_dt->format('Y-m-d H:i:00'),
            'from_ts' => $from_ts,
            'to_ts' => $to_ts,
            'batch_limit' => $batch_limit,
            'delay_ms' => $delay_ms,
            'ping_google' => $ping_google,
            'mark_published' => $mark_published,
        ];
    }

    private function count_products_in_range($mysqli, $table, $from_sql, $to_sql, &$err = null, $only_unpublished = false, $has_mark_column = false) {
        $where = '`updated_at` >= ? AND `updated_at` < ?';
        if ($only_unpublished && $has_mark_column) {
            $where .= ' AND (`is_published` = 0 OR `is_published` IS NULL)';
        }
        $sql = "SELECT COUNT(*) AS cnt FROM `{$table}` WHERE {$where}";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            $err = 'DB prepare failed: ' . $mysqli->error;
            $this->log_error('db_query', $err);
            return null;
        }
        $stmt->bind_param('ss', $from_sql, $to_sql);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) { $res->free(); }
        $stmt->close();
        return isset($row['cnt']) ? (int)$row['cnt'] : 0;
    }

    private function build_publish_select_clause($mysqli, $table) {
        $cols = array_flip($this->get_table_columns($mysqli, $table));
        $select = ['`id`', '`slug`', '`updated_at`'];
        $select[] = isset($cols['sku']) ? '`sku`' : "'' AS sku";
        $select[] = isset($cols['brand']) ? '`brand`' : "'' AS brand";
        if (isset($cols['title_h1'])) {
            $select[] = '`title_h1`';
        } elseif (isset($cols['name'])) {
            $select[] = '`name` AS title_h1';
        } else {
            $select[] = '`slug` AS title_h1';
        }
        if (isset($cols['short_description'])) {
            $select[] = '`short_description`';
        } elseif (isset($cols['short_summary'])) {
            $select[] = '`short_summary` AS short_description';
        } else {
            $select[] = "'' AS short_description";
        }
        $select[] = isset($cols['price']) ? '`price`' : "'' AS price";
        $select[] = isset($cols['categories']) ? '`categories`' : "'' AS categories";
        if (isset($cols['image'])) {
            $select[] = '`image`';
        } else {
            $select[] = "'' AS image";
        }
        $select[] = isset($cols['images_json']) ? '`images_json`' : "'' AS images_json";
        $select[] = isset($cols['schema_json']) ? '`schema_json`' : "'' AS schema_json";
        if (isset($cols['is_published'])) {
            $select[] = '`is_published`';
        }
        return implode(', ', $select);
    }

    private function fetch_publish_batch($mysqli, $table, $select_clause, $from_sql, $to_sql, $limit, $offset, &$err = null, $only_unpublished = false, $has_mark_column = false) {
        $where = '`updated_at` >= ? AND `updated_at` < ?';
        if ($only_unpublished && $has_mark_column) {
            $where .= ' AND (`is_published` = 0 OR `is_published` IS NULL)';
        }
        $sql = "SELECT {$select_clause} FROM `{$table}` WHERE {$where} ORDER BY `updated_at` ASC, `id` ASC LIMIT ? OFFSET ?";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            $err = 'DB prepare failed: ' . $mysqli->error;
            $this->log_error('db_query', $err);
            return false;
        }
        $limit = (int)$limit;
        $offset = (int)$offset;
        $stmt->bind_param('ssii', $from_sql, $to_sql, $limit, $offset);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->free();
        }
        $stmt->close();
        return $rows;
    }

    private function mark_products_as_published($mysqli, $table, array $ids) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return 0;
        }
        $updated = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $id_list = implode(',', $chunk);
            $sql = "UPDATE `{$table}` SET `is_published` = 1 WHERE `id` IN ({$id_list})";
            $ok = @$mysqli->query($sql);
            if ($ok && $mysqli->affected_rows > 0) {
                $updated += $mysqli->affected_rows;
            }
        }
        return $updated;
    }

    private function run_publish_preview(array $inputs, &$total, &$samples, &$err = null) {
        $mysqli = $this->db_connect($err);
        if (!$mysqli) {
            return false;
        }
        $settings = $this->get_settings();
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $settings['tidb']['table']);
        if ($table === '') {
            $err = 'Invalid TiDB table name.';
            @$mysqli->close();
            return false;
        }
        if (!$this->column_exists($mysqli, $table, 'updated_at')) {
            $err = 'Table is missing updated_at column.';
            @$mysqli->close();
            return false;
        }
        $has_mark_column = $this->column_exists($mysqli, $table, 'is_published');
        $only_unpublished = !empty($inputs['mark_published']) && $has_mark_column;
        $total = $this->count_products_in_range($mysqli, $table, $inputs['from_sql'], $inputs['to_sql'], $err, $only_unpublished, $has_mark_column);
        if ($total === null) {
            @$mysqli->close();
            return false;
        }
        $samples = [];
        $where = '`updated_at` >= ? AND `updated_at` < ?';
        if ($only_unpublished && $has_mark_column) {
            $where .= ' AND (`is_published` = 0 OR `is_published` IS NULL)';
        }
        $sql = "SELECT `slug` FROM `{$table}` WHERE {$where} ORDER BY `updated_at` ASC, `id` ASC LIMIT 20";
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $inputs['from_sql'], $inputs['to_sql']);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    if (!empty($row['slug'])) {
                        $samples[] = (string)$row['slug'];
                    }
                }
                $res->free();
            }
            $stmt->close();
        }
        @$mysqli->close();
        return true;
    }

    private function perform_publish_sitemap(array $inputs, $resume_entry = null) {
        $start = microtime(true);
        $err = null;
        $mysqli = $this->db_connect($err);
        if (!$mysqli) {
            return [
                'message' => $err ?: 'Failed to connect to TiDB.',
                'message_type' => 'error',
                'result' => [
                    'action' => 'sitemap',
                    'processed' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'batches' => 0,
                    'duration' => $this->format_publish_duration(microtime(true) - $start),
                ],
                'total' => 0,
            ];
        }

        $settings = $this->get_settings();
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $settings['tidb']['table']);
        if ($table === '') {
            @$mysqli->close();
            return [
                'message' => 'Invalid TiDB table name.',
                'message_type' => 'error',
                'result' => [
                    'action' => 'sitemap',
                    'processed' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'batches' => 0,
                    'duration' => $this->format_publish_duration(microtime(true) - $start),
                ],
                'total' => 0,
            ];
        }

        if (!$this->column_exists($mysqli, $table, 'updated_at')) {
            @$mysqli->close();
            return [
                'message' => 'Table is missing updated_at column.',
                'message_type' => 'error',
                'result' => [
                    'action' => 'sitemap',
                    'processed' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'batches' => 0,
                    'duration' => $this->format_publish_duration(microtime(true) - $start),
                ],
                'total' => 0,
            ];
        }

        $select_clause = $this->build_publish_select_clause($mysqli, $table);
        $has_mark_column = $this->column_exists($mysqli, $table, 'is_published');
        $only_unpublished = !empty($inputs['mark_published']) && $has_mark_column;
        $total_in_range = $this->count_products_in_range($mysqli, $table, $inputs['from_sql'], $inputs['to_sql'], $err, $only_unpublished, $has_mark_column);
        if ($total_in_range === null) {
            @$mysqli->close();
            return [
                'message' => $err ?: 'Failed to count products.',
                'message_type' => 'error',
                'result' => [
                    'action' => 'sitemap',
                    'processed' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'batches' => 0,
                    'duration' => $this->format_publish_duration(microtime(true) - $start),
                ],
                'total' => 0,
            ];
        }

        $limit = min($inputs['batch_limit'], $total_in_range);
        $result = [
            'action' => 'sitemap',
            'processed' => 0,
            'skipped' => max(0, $total_in_range - $limit),
            'failed' => 0,
            'batches' => 0,
            'duration' => '',
        ];

        if ($limit <= 0) {
            @$mysqli->close();
            $this->set_publish_resume_entry('sitemap', null);
            $result['duration'] = $this->format_publish_duration(microtime(true) - $start);
            return [
                'message' => 'No products matched the selected range.',
                'message_type' => 'success',
                'result' => $result,
                'total' => $total_in_range,
            ];
        }

        $resume = $resume_entry && is_array($resume_entry) ? $resume_entry : null;
        if ($resume) {
            $resume['notes'] = isset($resume['notes']) && is_array($resume['notes']) ? $resume['notes'] : [];
        }

        if (!empty($inputs['mark_published']) && !$has_mark_column) {
            $notice = 'TiDB table missing is_published column; sitemap batches cannot skip previously published rows.';
        }

        $processed_rows = $resume ? max(0, (int)($resume['processed'] ?? 0)) : 0;
        $processed_valid = $resume ? max(0, (int)($resume['processed_valid'] ?? 0)) : 0;
        $failed_rows = $resume ? max(0, (int)($resume['failed'] ?? 0)) : 0;
        $next_offset = $resume ? max(0, (int)($resume['next_offset'] ?? $processed_rows)) : $processed_rows;
        $batches_done = $resume ? max(0, (int)($resume['batches_done'] ?? 0)) : 0;
        $notes_parts = $resume ? $resume['notes'] : [];
        if (isset($notice) && !in_array($notice, $notes_parts, true)) {
            $notes_parts[] = $notice;
        }

        if ($processed_rows >= $limit) {
            @$mysqli->close();
            $this->set_publish_resume_entry('sitemap', null);
            $result['processed'] = $processed_valid;
            $result['failed'] = $failed_rows;
            $result['batches'] = $batches_done;
            $result['duration'] = $this->format_publish_duration(microtime(true) - $start);
            return [
                'message' => 'Previous sitemap run already completed.',
                'message_type' => 'success',
                'result' => $result,
                'total' => $total_in_range,
            ];
        }

        $resume_payload = [
            'inputs' => $inputs,
            'limit' => $limit,
            'total' => $total_in_range,
            'processed' => $processed_rows,
            'processed_valid' => $processed_valid,
            'failed' => $failed_rows,
            'batches_done' => $batches_done,
            'next_offset' => $next_offset,
            'notes' => $notes_parts,
            'timestamp' => gmdate('c'),
        ];
        $this->set_publish_resume_entry('sitemap', $resume_payload);

        $completed = true;
        $last_error = '';

        while ($processed_rows < $limit) {
            $chunk = min(200, $limit - $processed_rows);
            $batch = $this->fetch_publish_batch($mysqli, $table, $select_clause, $inputs['from_sql'], $inputs['to_sql'], $chunk, $next_offset, $err, $only_unpublished, $has_mark_column);
            if ($batch === false) {
                $completed = false;
                $last_error = $err ?: 'Failed to fetch batch.';
                break;
            }
            if (empty($batch)) {
                break;
            }

            $batch_count = count($batch);
            $prepared = [];
            $batch_failed = 0;
            foreach ($batch as $row) {
                $slug = sanitize_title($row['slug'] ?? '');
                $updated = trim((string)($row['updated_at'] ?? ''));
                if ($slug === '' || $updated === '') {
                    $batch_failed++;
                    continue;
                }
                $ts = strtotime($updated . ' UTC');
                if ($ts === false) {
                    $ts = strtotime($updated);
                }
                if ($ts === false) {
                    $batch_failed++;
                    continue;
                }
                $prepared[] = [
                    'slug' => $slug,
                    'lastmod' => gmdate('c', $ts),
                ];
            }

            $should_ping = !empty($inputs['ping_google']) && ($processed_rows + $batch_count >= $limit);
            $batch_notes = '';
            $ok = empty($prepared) ? true : $this->update_sitemaps_with_products($prepared, $should_ping, $batch_notes, $err);
            if (!$ok) {
                $completed = false;
                $last_error = $err ?: 'Failed to update sitemap files.';
                break;
            }
            if ($batch_notes !== '') {
                $notes_parts[] = $batch_notes;
            }
            if (!empty($inputs['mark_published']) && $has_mark_column) {
                $this->mark_products_as_published($mysqli, $table, array_column($batch, 'id'));
            }

            $processed_rows += $batch_count;
            $processed_valid += count($prepared);
            $failed_rows += $batch_failed;
            $next_offset += $batch_count;
            $batches_done++;

            $resume_payload['processed'] = $processed_rows;
            $resume_payload['processed_valid'] = $processed_valid;
            $resume_payload['failed'] = $failed_rows;
            $resume_payload['batches_done'] = $batches_done;
            $resume_payload['next_offset'] = $next_offset;
            $resume_payload['notes'] = $notes_parts;
            $resume_payload['timestamp'] = gmdate('c');
            $this->set_publish_resume_entry('sitemap', $resume_payload);

            if (!empty($inputs['delay_ms']) && $processed_rows < $limit) {
                usleep((int)$inputs['delay_ms'] * 1000);
            }
        }

        @$mysqli->close();

        if (!$completed) {
            $result['processed'] = $processed_valid;
            $result['failed'] = $failed_rows;
            $result['batches'] = $batches_done;
            $result['duration'] = $this->format_publish_duration(microtime(true) - $start);
            $this->set_publish_resume_entry('sitemap', $resume_payload);
            return [
                'message' => $last_error,
                'message_type' => 'error',
                'result' => $result,
                'total' => $total_in_range,
            ];
        }

        $this->set_publish_resume_entry('sitemap', null);
        $result['processed'] = $processed_valid;
        $result['failed'] = $failed_rows;
        $result['batches'] = $batches_done;
        $result['duration'] = $this->format_publish_duration(microtime(true) - $start);
        $result['notes'] = empty($notes_parts) ? '' : implode(' | ', array_unique($notes_parts));
        $message = sprintf('Updated sitemap for %d product(s).', $processed_valid);
        if ($result['skipped'] > 0) {
            $message .= sprintf(' %d product(s) not processed due to batch limit.', $result['skipped']);
        }
        if ($failed_rows > 0) {
            $message .= sprintf(' %d product(s) skipped due to missing data.', $failed_rows);
        }

        return [
            'message' => $message,
            'message_type' => 'success',
            'result' => $result,
            'total' => $total_in_range,
            'history' => [
                'timestamp' => gmdate('Y-m-d H:i'),
                'action' => 'Sitemap',
                'from' => $inputs['from'],
                'to' => $inputs['to'],
                'processed' => $processed_valid,
                'duration' => $result['duration'],
                'batch_limit' => $inputs['batch_limit'],
            ],
        ];
    }

    private function perform_publish_algolia(array $inputs, $resume_entry = null) {
        $start = microtime(true);
        $err = null;
        $mysqli = $this->db_connect($err);
        if (!$mysqli) {
            return [
                'message' => $err ?: 'Failed to connect to TiDB.',
                'message_type' => 'error',
                'result' => [
                    'action' => 'algolia',
                    'processed' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'batches' => 0,
                    'duration' => $this->format_publish_duration(microtime(true) - $start),
                ],
                'total' => 0,
            ];
        }

        $settings = $this->get_settings();
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $settings['tidb']['table']);
        if ($table === '') {
            @$mysqli->close();
            return [
                'message' => 'Invalid TiDB table name.',
                'message_type' => 'error',
                'result' => [
                    'action' => 'algolia',
                    'processed' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'batches' => 0,
                    'duration' => $this->format_publish_duration(microtime(true) - $start),
                ],
                'total' => 0,
            ];
        }

        if (!$this->column_exists($mysqli, $table, 'updated_at')) {
            @$mysqli->close();
            return [
                'message' => 'Table is missing updated_at column.',
                'message_type' => 'error',
                'result' => [
                    'action' => 'algolia',
                    'processed' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'batches' => 0,
                    'duration' => $this->format_publish_duration(microtime(true) - $start),
                ],
                'total' => 0,
            ];
        }

        $select_clause = $this->build_publish_select_clause($mysqli, $table);
        $has_mark_column = $this->column_exists($mysqli, $table, 'is_published');
        $only_unpublished = !empty($inputs['mark_published']) && $has_mark_column;
        $total_in_range = $this->count_products_in_range($mysqli, $table, $inputs['from_sql'], $inputs['to_sql'], $err, $only_unpublished, $has_mark_column);
        if ($total_in_range === null) {
            @$mysqli->close();
            return [
                'message' => $err ?: 'Failed to count products.',
                'message_type' => 'error',
                'result' => [
                    'action' => 'algolia',
                    'processed' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'batches' => 0,
                    'duration' => $this->format_publish_duration(microtime(true) - $start),
                ],
                'total' => 0,
            ];
        }

        $limit = min($inputs['batch_limit'], $total_in_range);
        $result = [
            'action' => 'algolia',
            'processed' => 0,
            'skipped' => max(0, $total_in_range - $limit),
            'failed' => 0,
            'batches' => 0,
            'duration' => '',
        ];

        if ($limit <= 0) {
            @$mysqli->close();
            $this->set_publish_resume_entry('algolia', null);
            $result['duration'] = $this->format_publish_duration(microtime(true) - $start);
            return [
                'message' => 'No products matched the selected range.',
                'message_type' => 'success',
                'result' => $result,
                'total' => $total_in_range,
            ];
        }

        $resume = $resume_entry && is_array($resume_entry) ? $resume_entry : null;
        $processed_rows = $resume ? max(0, (int)($resume['processed'] ?? 0)) : 0;
        $processed_valid = $resume ? max(0, (int)($resume['processed_valid'] ?? 0)) : 0;
        $failed_rows = $resume ? max(0, (int)($resume['failed'] ?? 0)) : 0;
        $next_offset = $resume ? max(0, (int)($resume['next_offset'] ?? $processed_rows)) : $processed_rows;
        $batches_done = $resume ? max(0, (int)($resume['batches_done'] ?? 0)) : 0;
        $notes = '';

        if ($processed_rows >= $limit) {
            @$mysqli->close();
            $this->set_publish_resume_entry('algolia', null);
            $result['processed'] = $processed_valid;
            $result['failed'] = $failed_rows;
            $result['batches'] = $batches_done;
            $result['duration'] = $this->format_publish_duration(microtime(true) - $start);
            if ($notes !== '') {
                $result['notes'] = $notes;
            }
            return [
                'message' => 'Previous Algolia run already completed.',
                'message_type' => 'success',
                'result' => $result,
                'total' => $total_in_range,
            ];
        }

        $resume_payload = [
            'inputs' => $inputs,
            'limit' => $limit,
            'total' => $total_in_range,
            'processed' => $processed_rows,
            'processed_valid' => $processed_valid,
            'failed' => $failed_rows,
            'batches_done' => $batches_done,
            'next_offset' => $next_offset,
            'notes' => $notes,
            'timestamp' => gmdate('c'),
        ];
        $this->set_publish_resume_entry('algolia', $resume_payload);

        $completed = true;
        $last_error = '';

        while ($processed_rows < $limit) {
            $chunk = min(200, $limit - $processed_rows);
            $batch = $this->fetch_publish_batch($mysqli, $table, $select_clause, $inputs['from_sql'], $inputs['to_sql'], $chunk, $next_offset, $err, $only_unpublished, $has_mark_column);
            if ($batch === false) {
                $completed = false;
                $last_error = $err ?: 'Failed to fetch batch.';
                break;
            }
            if (empty($batch)) {
                break;
            }

            $batch_count = count($batch);
            $records = [];
            $batch_failed = 0;
            foreach ($batch as $row) {
                $record = $this->build_algolia_publish_record($row);
                if ($record === null) {
                    $batch_failed++;
                    continue;
                }
                $records[] = $record;
            }

            $sent = 0;
            $buffer = [];
            $batch_error = null;
            foreach ($records as $record) {
                $buffer[] = $record;
                if (count($buffer) >= 100) {
                    if ($this->send_algolia_batch($buffer, $batch_error)) {
                        $sent += count($buffer);
                        $buffer = [];
                    } else {
                        break;
                    }
                }
            }

            if ($batch_error === null && !empty($buffer)) {
                if ($this->send_algolia_batch($buffer, $batch_error)) {
                    $sent += count($buffer);
                }
            }

            if ($batch_error !== null) {
                $completed = false;
                $last_error = $batch_error;
                $notes = $batch_error;
                break;
            }

            $processed_rows += $batch_count;
            $processed_valid += $sent;
            $failed_rows += $batch_failed;
            $next_offset += $batch_count;
            $batches_done++;

            $resume_payload['processed'] = $processed_rows;
            $resume_payload['processed_valid'] = $processed_valid;
            $resume_payload['failed'] = $failed_rows;
            $resume_payload['batches_done'] = $batches_done;
            $resume_payload['next_offset'] = $next_offset;
            $resume_payload['notes'] = $notes;
            $resume_payload['timestamp'] = gmdate('c');
            $this->set_publish_resume_entry('algolia', $resume_payload);

            if (!empty($inputs['delay_ms']) && $processed_rows < $limit) {
                usleep((int)$inputs['delay_ms'] * 1000);
            }
        }

        @$mysqli->close();

        if (!$completed) {
            $result['processed'] = $processed_valid;
            $result['failed'] = $failed_rows;
            $result['batches'] = $batches_done;
            $result['duration'] = $this->format_publish_duration(microtime(true) - $start);
            $result['notes'] = $notes;
            $this->set_publish_resume_entry('algolia', $resume_payload);
            return [
                'message' => $last_error,
                'message_type' => 'error',
                'result' => $result,
                'total' => $total_in_range,
            ];
        }

        $this->set_publish_resume_entry('algolia', null);
        $result['processed'] = $processed_valid;
        $result['failed'] = $failed_rows;
        $result['batches'] = $batches_done;
        $result['duration'] = $this->format_publish_duration(microtime(true) - $start);
        if ($notes !== '') {
            $result['notes'] = $notes;
        }

        $message = sprintf('Pushed %d product(s) to Algolia.', $processed_valid);
        $skipped_due_to_limit = max(0, $total_in_range - $limit);
        if ($skipped_due_to_limit > 0) {
            $message .= sprintf(' %d product(s) remain due to batch limit.', $skipped_due_to_limit);
        }
        if ($failed_rows > 0) {
            $message .= sprintf(' %d product(s) skipped due to missing data.', $failed_rows);
        }

        return [
            'message' => $message,
            'message_type' => 'success',
            'result' => $result,
            'total' => $total_in_range,
            'history' => [
                'timestamp' => gmdate('Y-m-d H:i'),
                'action' => 'Algolia',
                'from' => $inputs['from'],
                'to' => $inputs['to'],
                'processed' => $processed_valid,
                'duration' => $result['duration'],
                'batch_limit' => $inputs['batch_limit'],
            ],
        ];
    }

    private function fetch_publish_rows(array $inputs, $mark_published, &$err = null) {
        $mysqli = $this->db_connect($err);
        if (!$mysqli) {
            return false;
        }
        $settings = $this->get_settings();
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $settings['tidb']['table']);
        if ($table === '') {
            $err = 'Invalid TiDB table name.';
            @$mysqli->close();
            return false;
        }
        if (!$this->column_exists($mysqli, $table, 'updated_at')) {
            $err = 'Table is missing updated_at column.';
            @$mysqli->close();
            return false;
        }
        $select_clause = $this->build_publish_select_clause($mysqli, $table);
        $has_mark_column = $this->column_exists($mysqli, $table, 'is_published');
        $only_unpublished = $mark_published && $has_mark_column;
        $total = $this->count_products_in_range($mysqli, $table, $inputs['from_sql'], $inputs['to_sql'], $err, $only_unpublished, $has_mark_column);
        if ($total === null) {
            @$mysqli->close();
            return false;
        }
        $limit = min($inputs['batch_limit'], $total);
        $rows = [];
        $offset = 0;
        $batches = 0;
        while ($limit > 0) {
            $chunk = min(200, $limit);
            $batch = $this->fetch_publish_batch($mysqli, $table, $select_clause, $inputs['from_sql'], $inputs['to_sql'], $chunk, $offset, $err, $only_unpublished, $has_mark_column);
            if ($batch === false) {
                @$mysqli->close();
                return false;
            }
            if (empty($batch)) {
                break;
            }
            $rows = array_merge($rows, $batch);
            $offset += count($batch);
            $limit -= count($batch);
            $batches++;
            if (count($batch) < $chunk) {
                break;
            }
        }
        if ($mark_published && $has_mark_column && !empty($rows)) {
            $this->mark_products_as_published($mysqli, $table, array_column($rows, 'id'));
        }
        @$mysqli->close();
        return [
            'total' => $total,
            'rows' => $rows,
            'batches' => $batches,
        ];
    }

    private function extract_slug_from_loc($loc) {
        if (!is_string($loc) || $loc === '') {
            return '';
        }
        $path = parse_url($loc, PHP_URL_PATH);
        if (!$path) {
            return '';
        }
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }
        $parts = explode('/', $path);
        $index = array_search('p', $parts, true);
        if ($index !== false && isset($parts[$index + 1])) {
            $slug = $parts[$index + 1];
        } else {
            $slug = end($parts);
        }
        return sanitize_title($slug);
    }

    private function read_sitemap_entries($path) {
        $entries = [];
        if (!@file_exists($path)) {
            return $entries;
        }
        $xml = @simplexml_load_file($path);
        if (!$xml) {
            return $entries;
        }
        foreach ($xml->url as $url) {
            $loc = (string)$url->loc;
            $slug = $this->extract_slug_from_loc($loc);
            if ($slug === '') {
                continue;
            }
            $lastmod = (string)$url->lastmod;
            if ($lastmod === '') {
                $lastmod = gmdate('c');
            }
            $entries[] = [
                'slug' => $slug,
                'lastmod' => $lastmod,
            ];
        }
        return $entries;
    }

    private function write_sitemap_file($path, array $entries) {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $entry) {
            $slug = sanitize_title($entry['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $loc = home_url('/p/' . $slug . '/');
            $lastmod = $entry['lastmod'] ?? gmdate('c');
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</loc>' . "\n";
            $xml .= '    <lastmod>' . htmlspecialchars($lastmod, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</lastmod>' . "\n";
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';
        if (strlen($xml) > 50 * 1024 * 1024) {
            return false;
        }
        return @file_put_contents($path, $xml) !== false;
    }

    private function refresh_sitemap_index(array $storage, &$err = null) {
        $dir = $storage['dir'];
        $base_url = $storage['url'];
        $files = glob($dir . '*.xml') ?: [];
        $meta = $this->get_sitemap_meta();
        $needs_rewrite = isset($meta['base_url']) ? ($meta['base_url'] !== $base_url) : true;
        if ($needs_rewrite) {
            foreach ($files as $file) {
                $name = basename($file);
                if ($name === 'sitemap_index.xml' || $name === 'sitemap-index.xml' || $name === 'vpp-index.xml') {
                    continue;
                }
                $entries = $this->read_sitemap_entries($file);
                if (!$this->write_sitemap_file($file, $entries)) {
                    $err = 'Failed to rewrite ' . basename($file);
                    return false;
                }
            }
            $meta['base_url'] = $base_url;
            $this->save_sitemap_meta($meta);
            $files = glob($dir . '*.xml') ?: [];
        }
        $entries = [];
        foreach ($files as $file) {
            $name = basename($file);
            if ($name === 'sitemap_index.xml' || $name === 'sitemap-index.xml' || $name === 'vpp-index.xml') {
                continue;
            }
            $entries[] = [
                'loc' => $base_url . $name,
                'lastmod' => gmdate('c', @filemtime($file) ?: time()),
            ];
        }
        usort($entries, function ($a, $b) {
            return strcmp($a['loc'], $b['loc']);
        });
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $entry) {
            $xml .= "  <sitemap>\n";
            $xml .= '    <loc>' . htmlspecialchars($entry['loc'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</loc>' . "\n";
            $xml .= '    <lastmod>' . htmlspecialchars($entry['lastmod'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</lastmod>' . "\n";
            $xml .= "  </sitemap>\n";
        }
        $xml .= '</sitemapindex>';
        $index_path = $dir . 'sitemap_index.xml';
        if (@file_put_contents($index_path, $xml) === false) {
            $err = 'Failed to write sitemap_index.xml';
            return false;
        }
        @file_put_contents($dir . 'sitemap-index.xml', $xml);
        @file_put_contents($dir . 'vpp-index.xml', $xml);
        if (!empty($storage['legacy_dir'])) {
            if (wp_mkdir_p($storage['legacy_dir'])) {
                @file_put_contents($storage['legacy_dir'] . 'sitemap_index.xml', $xml);
                @file_put_contents($storage['legacy_dir'] . 'sitemap-index.xml', $xml);
                @file_put_contents($storage['legacy_dir'] . 'vpp-index.xml', $xml);
            }
        }
        if (!$needs_rewrite && $meta['base_url'] !== $base_url) {
            $meta['base_url'] = $base_url;
            $this->save_sitemap_meta($meta);
        }
        return $storage['index_url'];
    }

    private function update_sitemaps_with_products(array $items, $ping_google, &$notes, &$err = null) {
        if (empty($items)) {
            $notes = 'No sitemap entries to update.';
            return true;
        }
        $storage = $this->get_sitemap_storage_paths();
        if (!$storage) {
            $err = 'Uploads directory is not writable.';
            return false;
        }
        $dir = $storage['dir'];
        if (!wp_mkdir_p($dir)) {
            $err = 'Failed to create sitemap directory.';
            return false;
        }
        $existing_files = glob($dir . '*.xml') ?: [];
        $meta = $this->get_sitemap_meta();
        $locks = isset($meta['locks']) && is_array($meta['locks']) ? $meta['locks'] : [];
        $items_map = [];
        foreach ($items as $item) {
            $slug = sanitize_title($item['slug']);
            if ($slug === '') {
                continue;
            }
            $items_map[$slug] = [
                'slug' => $slug,
                'lastmod' => $item['lastmod'],
            ];
        }
        if (empty($items_map)) {
            $notes = 'No valid sitemap entries found.';
            return true;
        }
        $files_touched = 0;
        $filtered_files = [];
        foreach ($existing_files as $file) {
            $name = basename($file);
            if ($name === 'sitemap_index.xml' || $name === 'sitemap-index.xml' || $name === 'vpp-index.xml') {
                continue;
            }
            $filtered_files[] = $file;
        }
        sort($filtered_files, SORT_NATURAL);
        foreach ($filtered_files as $file) {
            $entries = $this->read_sitemap_entries($file);
            $changed = false;
            foreach ($entries as &$entry) {
                $slug = $entry['slug'];
                if (isset($items_map[$slug])) {
                    $entry['lastmod'] = $items_map[$slug]['lastmod'];
                    unset($items_map[$slug]);
                    $changed = true;
                }
            }
            if ($changed) {
                if (!$this->write_sitemap_file($file, $entries)) {
                    $err = 'Failed to write ' . basename($file);
                    return false;
                }
                $files_touched++;
            }
        }
        $remaining = array_values($items_map);
        $date_prefix = gmdate('Ymd');
        $max_entries = 50000;
        if (!empty($remaining)) {
            foreach ($filtered_files as $file) {
                if (empty($remaining)) {
                    break;
                }
                $name = basename($file);
                if (!preg_match('/^products-' . $date_prefix . '-\\d+\\.xml$/', $name)) {
                    continue;
                }
                if (preg_match('/^products-(\d+)-(\d+)\.xml$/', $name, $match)) {
                    $prefix_lock = isset($locks[$match[1]]) ? (int)$locks[$match[1]] : null;
                    if ($prefix_lock !== null && (int)$match[2] <= $prefix_lock) {
                        continue;
                    }
                }
                $entries = $this->read_sitemap_entries($file);
                $count = count($entries);
                if ($count >= $max_entries) {
                    continue;
                }
                $space = $max_entries - $count;
                if ($space <= 0) {
                    continue;
                }
                $add = array_splice($remaining, 0, $space);
                foreach ($add as $item) {
                    $entries[] = $item;
                }
                if (!$this->write_sitemap_file($file, $entries)) {
                    $err = 'Failed to write ' . basename($file);
                    return false;
                }
                $files_touched++;
            }
        }
        if (!empty($remaining)) {
            $existing_indexes = [];
            foreach ($filtered_files as $file) {
                $name = basename($file);
                if (preg_match('/^products-' . $date_prefix . '-(\d+)\.xml$/', $name, $m)) {
                    $existing_indexes[] = (int)$m[1];
                }
            }
            $index = empty($existing_indexes) ? 0 : max($existing_indexes);
            while (!empty($remaining)) {
                $chunk = array_splice($remaining, 0, $max_entries);
                $index++;
                $filename = sprintf('products-%s-%d.xml', $date_prefix, $index);
                $path = $dir . $filename;
                if (!$this->write_sitemap_file($path, $chunk)) {
                    $err = 'Failed to write ' . $filename;
                    return false;
                }
                $files_touched++;
                $filtered_files[] = $path;
            }
        }
        $index_url = $this->refresh_sitemap_index($storage, $err);
        if ($index_url === false) {
            return false;
        }
        $notes = $files_touched ? sprintf('Updated %d sitemap file(s).', $files_touched) : 'Sitemap unchanged.';
        if ($ping_google && $index_url) {
            $resp = wp_remote_get('https://www.google.com/ping?sitemap=' . rawurlencode($index_url), ['timeout' => 10]);
            if (is_wp_error($resp)) {
                $notes .= ' Google ping failed.';
            } else {
                $code = wp_remote_retrieve_response_code($resp);
                if ($code >= 200 && $code < 300) {
                    $notes .= ' Google ping sent.';
                } else {
                    $notes .= ' Google ping HTTP ' . $code . '.';
                }
            }
        }
        return true;
    }

    private function normalize_publish_categories($raw) {
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $item) {
                $item = trim((string)$item);
                if ($item !== '') {
                    $out[] = $item;
                }
            }
            return empty($out) ? null : array_values(array_unique($out));
        }
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if ($raw[0] === '[' || $raw[0] === '{') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->normalize_publish_categories($decoded);
            }
        }
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        return empty($parts) ? null : array_values(array_unique($parts));
    }

    private function build_algolia_publish_record(array $row) {
        $slug = sanitize_title($row['slug'] ?? '');
        if ($slug === '') {
            return null;
        }
        $sku = trim((string)($row['sku'] ?? ''));
        if ($sku === '') {
            $sku = $slug;
        }
        $title = trim((string)($row['title_h1'] ?? ''));
        if ($title === '') {
            $title = $slug;
        }
        $record = [
            'objectID' => $sku,
            'sku' => $sku,
            'slug' => $slug,
            'brand' => (string)($row['brand'] ?? ''),
            'title_h1' => $title,
            'name' => $title,
            'short_description' => (string)($row['short_description'] ?? ''),
            'url' => home_url('/p/' . $slug . '/'),
        ];
        if (isset($row['price']) && $row['price'] !== '') {
            $record['price'] = is_numeric($row['price']) ? 0 + $row['price'] : (string)$row['price'];
        }
        $categories = $this->normalize_publish_categories($row['categories'] ?? null);
        if ($categories !== null) {
            $record['categories'] = $categories;
        }
        $image = '';
        if (!empty($row['image'])) {
            $image = (string)$row['image'];
        }
        if (!$image && !empty($row['images_json'])) {
            $decoded = json_decode($row['images_json'], true);
            if (is_array($decoded)) {
                $first = reset($decoded);
                if (is_string($first)) {
                    $image = $first;
                }
            }
        }
        if ($image) {
            $record['image'] = $image;
        }
        if (!empty($row['updated_at'])) {
            $ts = strtotime($row['updated_at'] . ' UTC');
            if ($ts === false) {
                $ts = strtotime($row['updated_at']);
            }
            if ($ts !== false) {
                $record['updated_at'] = gmdate('c', $ts);
            }
        }
        return $record;
    }

    private function send_algolia_batch(array $records, &$err = null) {
        if (empty($records)) {
            return true;
        }
        $settings = $this->get_settings();
        $app = trim($settings['algolia']['app_id'] ?? '');
        $key = trim($settings['algolia']['admin_key'] ?? '');
        $index = trim($settings['algolia']['index'] ?? '');
        if (!$app || !$key || !$index) {
            $err = 'Algolia not configured.';
            return false;
        }
        $endpoint = "https://{$app}-dsn.algolia.net/1/indexes/" . rawurlencode($index) . '/batch';
        $payload = ['requests' => []];
        foreach ($records as $record) {
            $payload['requests'][] = [
                'action' => 'updateObject',
                'body' => $record,
            ];
        }
        $args = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Algolia-API-Key' => $key,
                'X-Algolia-Application-Id' => $app,
            ],
            'timeout' => 20,
            'body' => wp_json_encode($payload),
        ];
        $resp = wp_remote_request($endpoint, $args);
        if (is_wp_error($resp)) {
            $err = 'Algolia request failed: ' . $resp->get_error_message();
            return false;
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            $body = wp_remote_retrieve_body($resp);
            $snippet = '';
            if ($body) {
                $snippet = function_exists('mb_substr') ? mb_substr($body, 0, 120) : substr($body, 0, 120);
                $snippet = trim((string)$snippet);
            }
            $err = 'Algolia HTTP ' . $code . ($snippet !== '' ? ': ' . $snippet : '');
            return false;
        }
        return true;
    }

    /* ========= DATA ========= */

    private function db_connect(&$err = null) {
        $s = $this->get_settings();
        $host = $s['tidb']['host']; $port = $s['tidb']['port']; $db = $s['tidb']['database']; $user = $s['tidb']['user']; $pass = $s['tidb']['pass'];
        $ssl_ca = trim($s['tidb']['ssl_ca']);
        if (!$host || !$db || !$user) { $err = 'TiDB connection is not configured.'; $this->log_error('db_connect', $err); return null; }
        $mysqli = @mysqli_init();
        if (!$mysqli) { $err = 'mysqli_init() failed'; $this->log_error('db_connect', $err); return null; }
        if ($ssl_ca && @file_exists($ssl_ca)) { @mysqli_ssl_set($mysqli, NULL, NULL, $ssl_ca, NULL, NULL); }
        if (defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) { @mysqli_options($mysqli, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, ($ssl_ca && @file_exists($ssl_ca))); }
        if (defined('MYSQLI_OPT_SSL_MODE') && defined('MYSQLI_SSL_MODE_REQUIRED')) { @mysqli_options($mysqli, MYSQLI_OPT_SSL_MODE, MYSQLI_SSL_MODE_REQUIRED); }
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 8);
        @$mysqli->real_connect($host, $user, $pass, $db, (int)$port, null, defined('MYSQLI_CLIENT_SSL') ? MYSQLI_CLIENT_SSL : 0);
        if ($mysqli->connect_errno) { $err = 'DB connect error: ' . $mysqli->connect_error; $this->log_error('db_connect', $err); return null; }
        @$mysqli->set_charset('utf8mb4');
        return $mysqli;
    }

    private function get_table_columns($mysqli, $table) {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
        if ($table === '') { return []; }
        if (isset($this->table_columns_cache[$table])) {
            return $this->table_columns_cache[$table];
        }

        $table_esc = $mysqli->real_escape_string($table);
        $sql = "SHOW COLUMNS FROM `{$table_esc}`";
        $r = @$mysqli->query($sql);
        $cols = [];
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                if (isset($row['Field'])) {
                    $cols[] = $row['Field'];
                }
            }
            $r->close();
        }
        $this->table_columns_cache[$table] = $cols;
        return $cols;
    }

    private function clear_table_columns_cache($table) {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
        unset($this->table_columns_cache[$table]);
    }

    private function column_exists($mysqli, $table, $col) {
        $cols = $this->get_table_columns($mysqli, $table);
        return in_array($col, $cols, true);
    }

    private function table_exists($mysqli, $table) {
        $table_clean = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
        if ($table_clean === '') {
            return false;
        }
        $pattern = str_replace(['_', '%'], ['\\_', '\\%'], $table_clean);
        $like = $mysqli->real_escape_string($pattern);
        $sql = "SHOW TABLES LIKE '{$like}'";
        $res = @$mysqli->query($sql);
        if (!$res) {
            return false;
        }
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }

    private function maybe_add_schema_column($mysqli, $table_clean) {
        if ($this->column_exists($mysqli, $table_clean, 'schema_json')) {
            return;
        }
        $table_esc = $mysqli->real_escape_string($table_clean);
        $sql = sprintf('ALTER TABLE `%s` ADD COLUMN `schema_json` TEXT NULL AFTER `images_json`', $table_esc);
        if (@$mysqli->query($sql)) {
            $this->clear_table_columns_cache($table_clean);
        } else {
            $this->log_error('db_schema', sprintf('Failed adding schema_json to %s: %s', $table_clean, $mysqli->error));
        }
    }

    private function ensure_schema_json_column($mysqli, $table) {
        $table_clean = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
        if ($table_clean === '') {
            return;
        }

        $this->maybe_add_schema_column($mysqli, $table_clean);

        $import_table = 'import_buffer';
        if ($this->table_exists($mysqli, $import_table)) {
            $this->maybe_add_schema_column($mysqli, $import_table);
        }
    }

    private function ensure_cta_label_columns($mysqli, $table) {
        $table_clean = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
        if ($table_clean === '') {
            return;
        }

        $definitions = [
            'cta_lead_label' => 'ALTER TABLE `%s` ADD COLUMN `cta_lead_label` VARCHAR(120) NOT NULL DEFAULT \'\' AFTER `cta_lead_url`',
            'cta_stripe_label' => 'ALTER TABLE `%s` ADD COLUMN `cta_stripe_label` VARCHAR(120) NOT NULL DEFAULT \'\' AFTER `cta_stripe_url`',
            'cta_affiliate_label' => 'ALTER TABLE `%s` ADD COLUMN `cta_affiliate_label` VARCHAR(120) NOT NULL DEFAULT \'\' AFTER `cta_affiliate_url`',
            'cta_paypal_label' => 'ALTER TABLE `%s` ADD COLUMN `cta_paypal_label` VARCHAR(120) NOT NULL DEFAULT \'\' AFTER `cta_paypal_url`',
        ];

        foreach ($definitions as $column => $sql_template) {
            if ($this->column_exists($mysqli, $table_clean, $column)) {
                continue;
            }
            $table_esc = $mysqli->real_escape_string($table_clean);
            $sql = sprintf($sql_template, $table_esc);
            if (@$mysqli->query($sql)) {
                $this->clear_table_columns_cache($table_clean);
            } else {
                $this->log_error('db_schema', sprintf('Failed adding %s: %s', $column, $mysqli->error));
            }
        }
    }

    private function build_cta_select_clause($mysqli, $table) {
        $columns = [
            'cta_lead_url',
            'cta_lead_label',
            'cta_stripe_url',
            'cta_stripe_label',
            'cta_affiliate_url',
            'cta_affiliate_label',
            'cta_paypal_url',
            'cta_paypal_label',
        ];
        $existing = array_flip($this->get_table_columns($mysqli, $table));
        $parts = [];
        foreach ($columns as $col) {
            if (isset($existing[$col])) {
                $parts[] = ", `{$col}`";
            } else {
                $parts[] = ", '' AS {$col}";
            }
        }
        return implode('', $parts);
    }

    private function sanitize_cta_label($value) {
        $value = sanitize_text_field($value ?? '');
        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, 80);
        } else {
            $value = substr($value, 0, 80);
        }
        return trim($value);
    }

    private function select_has_meta_column($mysqli, $table) {
        return $this->column_exists($mysqli, $table, 'meta_description');
    }

    private function select_has_schema_column($mysqli, $table) {
        return $this->column_exists($mysqli, $table, 'schema_json');
    }

    private function fetch_product_by_slug($slug, &$err = null) {
        $s = $this->get_settings();
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $s['tidb']['table']);
        $mysqli = $this->db_connect($err);
        if (!$mysqli) return null;
        $has_meta = $this->select_has_meta_column($mysqli, $table);
        $meta_sql = $has_meta ? ', meta_description' : ", '' AS meta_description";
        $has_schema = $this->select_has_schema_column($mysqli, $table);
        $schema_sql = $has_schema ? ', schema_json' : ", '' AS schema_json";
        $cta_sql = $this->build_cta_select_clause($mysqli, $table);
        $sql = "SELECT id, slug, title_h1, brand, model, sku, images_json{$schema_sql}, desc_html, short_summary, is_published, last_tidb_update_at {$meta_sql}{$cta_sql}
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
        $has_meta = $this->select_has_meta_column($mysqli, $table);
        $meta_sql = $has_meta ? ', meta_description' : ", '' AS meta_description";
        $has_schema = $this->select_has_schema_column($mysqli, $table);
        $schema_sql = $has_schema ? ', schema_json' : ", '' AS schema_json";
        $cta_sql = $this->build_cta_select_clause($mysqli, $table);
        $sql = "SELECT id, slug, title_h1, brand, model, sku, images_json{$schema_sql}, desc_html, short_summary, is_published, last_tidb_update_at {$meta_sql}{$cta_sql}
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

    private function normalize_images_input($raw) {
        $raw = trim((string)$raw);
        if ($raw === '') return '';
        $t = ltrim($raw);
        if ($t && $t[0] === '[') return $raw;
        $lines = preg_split('/\r?\n/', $raw);
        $urls = [];
        foreach ($lines as $ln) { $u = trim($ln); if ($u !== '') $urls[] = $u; }
        return wp_json_encode(array_values(array_unique($urls)));
    }

    /* ========= ADMIN: EDIT PRODUCT ========= */

    private function parse_lookup_key($key) {
        $key = trim($key);
        if (!$key) return [null, null];
        $home = home_url();
        if (stripos($key, $home) === 0) {
            $path = parse_url($key, PHP_URL_PATH);
            $parts = explode('/', trim($path, '/'));
            if (isset($parts[0]) && $parts[0] === 'p' && isset($parts[1])) { return ['slug', sanitize_title($parts[1])]; }
        }
        if (ctype_digit($key)) return ['id', (int)$key];
        return ['slug', sanitize_title($key)];
    }

    public function render_edit_page() {
        if (!current_user_can('manage_options')) return;
        $lookup_type = isset($_GET['lookup_type']) ? sanitize_text_field($_GET['lookup_type']) : '';
        $lookup_val  = isset($_GET['lookup_val']) ? sanitize_text_field($_GET['lookup_val']) : '';
        $row = null; $err = null;
        if ($lookup_type && $lookup_val !== '') {
            $row = ($lookup_type === 'id') ? $this->fetch_product_by_id((int)$lookup_val, $err)
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
                        <tr><th scope="row">Short summary (max 150 chars)</th><td><input type="text" maxlength="150" name="short_summary" value="<?php echo esc_attr($row['short_summary'] ?? ''); ?>" class="regular-text"></td></tr>
                        <tr><th scope="row">Meta description (max 160)</th><td><input type="text" maxlength="160" name="meta_description" value="<?php echo esc_attr($row['meta_description'] ?? ''); ?>" class="regular-text"></td></tr>
                        <tr><th scope="row">Images</th><td>
                            <textarea name="images_json" rows="3" style="width:100%;" placeholder='Either JSON array ["https://...","https://..."] or one URL per line'><?php
                                $images_val = $row['images_json'];
                                echo esc_textarea($images_val);
                            ?></textarea>
                        </td></tr>
                        <tr><th scope="row">CTAs</th><td style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;">
                            <input type="text" placeholder="Lead button label" name="cta_lead_label" value="<?php echo esc_attr($row['cta_lead_label'] ?? ''); ?>" aria-label="Lead button label"/>
                            <input type="url" placeholder="Lead URL" name="cta_lead_url" value="<?php echo esc_attr($row['cta_lead_url'] ?? ''); ?>" aria-label="Lead button URL"/>
                            <input type="text" placeholder="Stripe button label" name="cta_stripe_label" value="<?php echo esc_attr($row['cta_stripe_label'] ?? ''); ?>" aria-label="Stripe button label"/>
                            <input type="url" placeholder="Stripe URL" name="cta_stripe_url" value="<?php echo esc_attr($row['cta_stripe_url'] ?? ''); ?>" aria-label="Stripe button URL"/>
                            <input type="text" placeholder="Affiliate button label" name="cta_affiliate_label" value="<?php echo esc_attr($row['cta_affiliate_label'] ?? ''); ?>" aria-label="Affiliate button label"/>
                            <input type="url" placeholder="Affiliate URL" name="cta_affiliate_url" value="<?php echo esc_attr($row['cta_affiliate_url'] ?? ''); ?>" aria-label="Affiliate button URL"/>
                            <input type="text" placeholder="PayPal button label" name="cta_paypal_label" value="<?php echo esc_attr($row['cta_paypal_label'] ?? ''); ?>" aria-label="PayPal button label"/>
                            <input type="url" placeholder="PayPal URL" name="cta_paypal_url" value="<?php echo esc_attr($row['cta_paypal_url'] ?? ''); ?>" aria-label="PayPal button URL"/>
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

                    <?php $schema_json_val = (string)($row['schema_json'] ?? ''); ?>
                    <table class="form-table"><tbody>
                        <tr>
                          <th scope="row"><label for="schema_json">Schema JSON (Product)</label></th>
                          <td>
                            <textarea id="schema_json" name="schema_json" rows="10" class="large-text code"
                              placeholder='{"@context":"https://schema.org","@type":"Product","name":"..."}'><?php
                                echo esc_textarea($schema_json_val);
                            ?></textarea>
                            <p class="description">
                              Paste a valid JSON-LD <code>Product</code>. It will be injected as
                              <code>&lt;script type="application/ld+json"&gt;</code> on the product page.
                            </p>

                            <label>
                              <input type="checkbox" name="schema_json_clear" value="1">
                              Clear schema on save (set NULL)
                            </label>
                          </td>
                        </tr>
                    </tbody></table>

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
            $redirect = add_query_arg(['vpp_err'=> 'Enter a valid URL, slug or ID.'], admin_url('admin.php?page=vpp_edit'));
        } else {
            $redirect = add_query_arg(['lookup_type'=> $type, 'lookup_val'=> $val], admin_url('admin.php?page=vpp_edit'));
        }
        wp_safe_redirect($redirect); exit;
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
        $meta_description = sanitize_text_field($_POST['meta_description'] ?? '');
        if (strlen($meta_description) > 160) $meta_description = substr($meta_description, 0, 160);
        $images_json = $this->normalize_images_input($_POST['images_json'] ?? '');
        $cta_lead_label = $this->sanitize_cta_label($_POST['cta_lead_label'] ?? '');
        $cta_lead_url = esc_url_raw($_POST['cta_lead_url'] ?? '');
        $cta_stripe_label = $this->sanitize_cta_label($_POST['cta_stripe_label'] ?? '');
        $cta_stripe_url = esc_url_raw($_POST['cta_stripe_url'] ?? '');
        $cta_affiliate_label = $this->sanitize_cta_label($_POST['cta_affiliate_label'] ?? '');
        $cta_affiliate_url = esc_url_raw($_POST['cta_affiliate_url'] ?? '');
        $cta_paypal_label = $this->sanitize_cta_label($_POST['cta_paypal_label'] ?? '');
        $cta_paypal_url = esc_url_raw($_POST['cta_paypal_url'] ?? '');
        $desc_html = wp_kses_post($_POST['desc_html'] ?? '');
        $is_published = !empty($_POST['is_published']) ? 1 : 0;
        $schema_json_raw = isset($_POST['schema_json']) ? wp_unslash($_POST['schema_json']) : '';
        $schema_json_raw = trim($schema_json_raw);
        $clear_schema = !empty($_POST['schema_json_clear']);

        $err = null;
        $s = $this->get_settings();
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $s['tidb']['table']);
        $mysqli = $this->db_connect($err);
        if (!$mysqli) {
            $redirect = add_query_arg(['vpp_err'=> 'DB error: ' . $err], admin_url('admin.php?page=vpp_edit&lookup_type=id&lookup_val='.$id));
            wp_safe_redirect($redirect); exit;
        }

        $this->ensure_schema_json_column($mysqli, $table);
        $this->ensure_cta_label_columns($mysqli, $table);
        $existing_cols = array_flip($this->get_table_columns($mysqli, $table));

        $current_schema = null;
        if (isset($existing_cols['schema_json'])) {
            $schema_stmt = $mysqli->prepare("SELECT `schema_json` FROM `{$table}` WHERE id = ? LIMIT 1");
            if ($schema_stmt) {
                $schema_stmt->bind_param('i', $id);
                if ($schema_stmt->execute()) {
                    $schema_res = $schema_stmt->get_result();
                    if ($schema_res) {
                        $schema_row = $schema_res->fetch_assoc();
                        if ($schema_row && array_key_exists('schema_json', $schema_row)) {
                            $current_schema = $schema_row['schema_json'];
                        }
                        $schema_res->free();
                    }
                }
                $schema_stmt->close();
            }
        }

        if ($clear_schema) {
            $schema_json = null;
        } elseif ($schema_json_raw === '') {
            $schema_json = $current_schema;
        } else {
            $decoded = json_decode($schema_json_raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $schema_json = wp_json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                $schema_json = $current_schema;
            }
        }

        $cols = [
            'title_h1' => $title_h1,
            'brand' => $brand,
            'model' => $model,
            'sku' => $sku,
            'short_summary' => $short_summary,
            'meta_description' => $meta_description,
            'images_json' => $images_json,
            'schema_json' => $schema_json,
            'cta_lead_label' => $cta_lead_label,
            'cta_lead_url' => $cta_lead_url,
            'cta_stripe_label' => $cta_stripe_label,
            'cta_stripe_url' => $cta_stripe_url,
            'cta_affiliate_label' => $cta_affiliate_label,
            'cta_affiliate_url' => $cta_affiliate_url,
            'cta_paypal_label' => $cta_paypal_label,
            'cta_paypal_url' => $cta_paypal_url,
            'desc_html' => $desc_html,
            'is_published' => $is_published,
        ];
        $set_parts = [];
        $bind_types = '';
        $bind_values = [];
        foreach ($cols as $col => $val) {
            if (!isset($existing_cols[$col])) {
                if ($col === 'meta_description') {
                    continue;
                }
                continue;
            }
            $set_parts[] = "`{$col}` = ?";
            $bind_types .= ($col === 'is_published') ? 'i' : 's';
            $bind_values[] = $val;
        }
        if (isset($existing_cols['last_tidb_update_at'])) {
            $set_parts[] = "last_tidb_update_at = NOW()";
        }

        if (empty($set_parts)) {
            $mysqli->close();
            $redirect = add_query_arg(['vpp_err'=> 'No editable columns found in table.'], admin_url('admin.php?page=vpp_edit&lookup_type=id&lookup_val='.$id));
            wp_safe_redirect($redirect); exit;
        }

        $sql = "UPDATE `{$table}` SET " . implode(', ', $set_parts) . " WHERE id = ?";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            $this->log_error('db_query', 'DB prepare failed: ' . $mysqli->error);
            $mysqli->close();
            $redirect = add_query_arg(['vpp_err'=> 'DB prepare failed: ' . $mysqli->error], admin_url('admin.php?page=vpp_edit&lookup_type=id&lookup_val='.$id));
            wp_safe_redirect($redirect); exit;
        }

        $bind_types .= 'i';
        $bind_values[] = $id;

        $bind_params = array_merge([$bind_types], $bind_values);
        $refs = [];
        foreach ($bind_params as $k => $v) { $refs[$k] = &$bind_params[$k]; }
        call_user_func_array([$stmt, 'bind_param'], $refs);

        $ok = $stmt->execute();
        $stmt_error = $stmt->error;
        $stmt->close();
        @$mysqli->close();

        $inline = 'ok'; $inline_msg = 'Saved'; $top_msg = 'Saved.';
        if (!$ok) {
            $this->log_error('db_query', 'Product update failed: ' . $stmt_error);
            $inline = 'err'; $inline_msg = 'Error saving'; $top_msg = 'Save failed.';
        }

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

        $args = ['lookup_type'=>'id','lookup_val'=>$id,'vpp_msg'=>$top_msg,'inline'=>$inline,'inline_msg'=>$inline_msg];
        $redirect = add_query_arg($args, admin_url('admin.php?page=vpp_edit'));
        if (isset($_POST['do_save_view']) && $ok) {
            $redirect = add_query_arg(['vpp_msg'=> $top_msg . ' Open: ' . home_url('/p/' . $slug)] + $args, admin_url('admin.php?page=vpp_edit'));
        }
        wp_safe_redirect($redirect); exit;
    }

    /* ========= HANDLERS ========= */

    public function handle_publish_preview() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer(self::NONCE_KEY);
        $err = null;
        $inputs = $this->parse_publish_inputs($_POST, $err);
        $state = $this->get_publish_state();
        if (!$inputs) {
            $state['message'] = $err ?: 'Invalid date range.';
            $state['message_type'] = 'error';
            $state['inputs']['from'] = sanitize_text_field(wp_unslash($_POST['publish_from'] ?? ''));
            $state['inputs']['to'] = sanitize_text_field(wp_unslash($_POST['publish_to'] ?? ''));
            if (isset($_POST['batch_limit'])) {
                $state['inputs']['batch_limit'] = max(1, (int)$_POST['batch_limit']);
            }
            if (isset($_POST['delay_ms'])) {
                $state['inputs']['delay_ms'] = max(0, (int)$_POST['delay_ms']);
            }
            $state['inputs']['ping_google'] = !empty($_POST['ping_google']) ? 1 : 0;
            $state['inputs']['mark_published'] = !empty($_POST['mark_published']) ? 1 : 0;
            $state['result'] = null;
            $this->save_publish_state($state);
            wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
            exit;
        }
        $start = microtime(true);
        $total = 0;
        $samples = [];
        $ok = $this->run_publish_preview($inputs, $total, $samples, $err);
        $duration = $this->format_publish_duration(microtime(true) - $start);
        $state['inputs'] = [
            'from' => $inputs['from'],
            'to' => $inputs['to'],
            'batch_limit' => $inputs['batch_limit'],
            'delay_ms' => $inputs['delay_ms'],
            'ping_google' => $inputs['ping_google'],
            'mark_published' => $inputs['mark_published'],
        ];
        $state['total'] = $total;
        $state['samples'] = $samples;
        $state['result'] = [
            'action' => 'preview',
            'processed' => min($total, $inputs['batch_limit']),
            'skipped' => max(0, $total - $inputs['batch_limit']),
            'failed' => $ok ? 0 : ($total ?: 0),
            'batches' => 0,
            'duration' => $duration,
        ];
        if ($ok) {
            $state['message'] = sprintf('Preview found %d product(s).', $total);
            $state['message_type'] = 'success';
        } else {
            $state['message'] = $err ?: 'Preview failed.';
            $state['message_type'] = 'error';
        }
        $this->save_publish_state($state);
        wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
        exit;
    }

    public function handle_publish_sitemap() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer(self::NONCE_KEY);
        $err = null;
        $inputs = $this->parse_publish_inputs($_POST, $err);
        $state = $this->get_publish_state();
        if (!$inputs) {
            $state['message'] = $err ?: 'Invalid date range.';
            $state['message_type'] = 'error';
            $state['inputs']['from'] = sanitize_text_field(wp_unslash($_POST['publish_from'] ?? ''));
            $state['inputs']['to'] = sanitize_text_field(wp_unslash($_POST['publish_to'] ?? ''));
            if (isset($_POST['batch_limit'])) {
                $state['inputs']['batch_limit'] = max(1, (int)$_POST['batch_limit']);
            }
            if (isset($_POST['delay_ms'])) {
                $state['inputs']['delay_ms'] = max(0, (int)$_POST['delay_ms']);
            }
            $state['inputs']['ping_google'] = !empty($_POST['ping_google']) ? 1 : 0;
            $state['inputs']['mark_published'] = !empty($_POST['mark_published']) ? 1 : 0;
            $state['result'] = null;
            $this->save_publish_state($state);
            wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
            exit;
        }
        $outcome = $this->perform_publish_sitemap($inputs);
        $state['inputs'] = [
            'from' => $inputs['from'],
            'to' => $inputs['to'],
            'batch_limit' => $inputs['batch_limit'],
            'delay_ms' => $inputs['delay_ms'],
            'ping_google' => $inputs['ping_google'],
            'mark_published' => $inputs['mark_published'],
        ];
        $state['total'] = (int)($outcome['total'] ?? 0);
        $state['result'] = $outcome['result'] ?? null;
        $state['message'] = $outcome['message'] ?? '';
        $state['message_type'] = $outcome['message_type'] ?? 'success';
        if (!empty($outcome['history']) && is_array($outcome['history'])) {
            $this->add_publish_history_entry($outcome['history']);
        }
        $this->save_publish_state($state);
        wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
        exit;
    }

    public function handle_publish_algolia() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer(self::NONCE_KEY);
        $err = null;
        $inputs = $this->parse_publish_inputs($_POST, $err);
        $state = $this->get_publish_state();
        if (!$inputs) {
            $state['message'] = $err ?: 'Invalid date range.';
            $state['message_type'] = 'error';
            $state['inputs']['from'] = sanitize_text_field(wp_unslash($_POST['publish_from'] ?? ''));
            $state['inputs']['to'] = sanitize_text_field(wp_unslash($_POST['publish_to'] ?? ''));
            if (isset($_POST['batch_limit'])) {
                $state['inputs']['batch_limit'] = max(1, (int)$_POST['batch_limit']);
            }
            if (isset($_POST['delay_ms'])) {
                $state['inputs']['delay_ms'] = max(0, (int)$_POST['delay_ms']);
            }
            $state['inputs']['ping_google'] = !empty($_POST['ping_google']) ? 1 : 0;
            $state['inputs']['mark_published'] = !empty($_POST['mark_published']) ? 1 : 0;
            $state['result'] = null;
            $this->save_publish_state($state);
            wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
            exit;
        }
        $outcome = $this->perform_publish_algolia($inputs);
        $state['inputs'] = [
            'from' => $inputs['from'],
            'to' => $inputs['to'],
            'batch_limit' => $inputs['batch_limit'],
            'delay_ms' => $inputs['delay_ms'],
            'ping_google' => $inputs['ping_google'],
            'mark_published' => $inputs['mark_published'],
        ];
        $state['total'] = (int)($outcome['total'] ?? 0);
        $state['result'] = $outcome['result'] ?? null;
        $state['message'] = $outcome['message'] ?? '';
        $state['message_type'] = $outcome['message_type'] ?? 'success';
        if (!empty($outcome['history']) && is_array($outcome['history'])) {
            $this->add_publish_history_entry($outcome['history']);
        }
        $this->save_publish_state($state);
        wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
        exit;
    }

    public function handle_publish_resume() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer(self::NONCE_KEY);
        $target = isset($_POST['target']) ? sanitize_key($_POST['target']) : '';
        $mode = isset($_POST['mode']) ? sanitize_key($_POST['mode']) : '';
        $state = $this->get_publish_state();
        $valid_targets = ['sitemap', 'algolia'];

        if (!in_array($target, $valid_targets, true)) {
            $state['message'] = 'Unknown resume target.';
            $state['message_type'] = 'error';
            $this->save_publish_state($state);
            wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
            exit;
        }

        if ($mode === 'clear') {
            $this->set_publish_resume_entry($target, null);
            $state['message'] = ucfirst($target) . ' resume checkpoint cleared.';
            $state['message_type'] = 'success';
            $this->save_publish_state($state);
            wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
            exit;
        }

        if ($mode !== 'resume') {
            $state['message'] = 'Invalid resume action.';
            $state['message_type'] = 'error';
            $this->save_publish_state($state);
            wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
            exit;
        }

        $entry = $this->get_publish_resume_entry($target);
        $inputs = isset($entry['inputs']) && is_array($entry['inputs']) ? $entry['inputs'] : null;
        if (!$entry || !$inputs) {
            $state['message'] = 'No resume checkpoint available.';
            $state['message_type'] = 'error';
            $this->save_publish_state($state);
            wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
            exit;
        }

        $outcome = $target === 'sitemap'
            ? $this->perform_publish_sitemap($inputs, $entry)
            : $this->perform_publish_algolia($inputs, $entry);

        $state['inputs'] = [
            'from' => $inputs['from'] ?? '',
            'to' => $inputs['to'] ?? '',
            'batch_limit' => isset($inputs['batch_limit']) ? (int)$inputs['batch_limit'] : ($state['inputs']['batch_limit'] ?? 1000),
            'delay_ms' => isset($inputs['delay_ms']) ? (int)$inputs['delay_ms'] : ($state['inputs']['delay_ms'] ?? 200),
            'ping_google' => !empty($inputs['ping_google']) ? 1 : 0,
            'mark_published' => !empty($inputs['mark_published']) ? 1 : 0,
        ];
        $state['total'] = (int)($outcome['total'] ?? 0);
        $state['result'] = $outcome['result'] ?? null;
        $state['message'] = $outcome['message'] ?? '';
        $state['message_type'] = $outcome['message_type'] ?? 'success';
        if (!empty($outcome['history']) && is_array($outcome['history'])) {
            $this->add_publish_history_entry($outcome['history']);
        }
        $this->save_publish_state($state);
        wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
        exit;
    }

    public function handle_publish_rotate_now() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer(self::NONCE_KEY);
        $state = $this->get_publish_state();
        $storage = $this->get_sitemap_storage_paths();
        if (!$storage) {
            $state['message'] = 'Uploads directory is not writable.';
            $state['message_type'] = 'error';
            $this->save_publish_state($state);
            wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
            exit;
        }
        $dir = $storage['dir'];
        if (!wp_mkdir_p($dir)) {
            $state['message'] = 'Failed to create sitemap directory.';
            $state['message_type'] = 'error';
            $this->save_publish_state($state);
            wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
            exit;
        }
        $prefix = gmdate('Ymd');
        $existing = glob($dir . 'products-' . $prefix . '-*.xml') ?: [];
        $indexes = [];
        foreach ($existing as $file) {
            if (preg_match('/^products-' . $prefix . '-(\d+)\.xml$/', basename($file), $m)) {
                $indexes[] = (int)$m[1];
            }
        }
        $next_index = empty($indexes) ? 1 : max($indexes) + 1;
        $filename = sprintf('products-%s-%d.xml', $prefix, $next_index);
        $path = $dir . $filename;
        if (!$this->write_sitemap_file($path, [])) {
            $state['message'] = 'Failed to start new sitemap file.';
            $state['message_type'] = 'error';
            $this->save_publish_state($state);
            wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
            exit;
        }

        $meta = $this->get_sitemap_meta();
        $meta['locks'][$prefix] = $next_index - 1;
        $this->save_sitemap_meta($meta);

        $refresh_err = null;
        $this->refresh_sitemap_index($storage, $refresh_err);
        if ($refresh_err) {
            $state['message'] = $refresh_err;
            $state['message_type'] = 'error';
        } else {
            $state['message'] = sprintf('Rotation complete. Next sitemap file: %s', $filename);
            $state['message_type'] = 'success';
        }
        $this->save_publish_state($state);
        wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
        exit;
    }

    public function handle_publish_rebuild_index() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer(self::NONCE_KEY);
        $state = $this->get_publish_state();
        $storage = $this->get_sitemap_storage_paths();
        if (!$storage) {
            $state['message'] = 'Uploads directory is not writable.';
            $state['message_type'] = 'error';
            $this->save_publish_state($state);
            wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
            exit;
        }
        $dir = $storage['dir'];
        if (!wp_mkdir_p($dir)) {
            $state['message'] = 'Failed to create sitemap directory.';
            $state['message_type'] = 'error';
            $this->save_publish_state($state);
            wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
            exit;
        }
        $published_err = null;
        $published_count = $this->count_published_products($published_err);
        if ($published_err) {
            $state['message'] = $published_err;
            $state['message_type'] = 'error';
            $state['validation'] = [];
            $this->save_publish_state($state);
            wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
            exit;
        }

        if ((int)$published_count === 0) {
            foreach (glob($dir . 'products-*.xml') ?: [] as $old) { @unlink($old); }
            foreach (glob($dir . 'sitemap-*.xml') ?: [] as $old) { @unlink($old); }
            if (!empty($storage['legacy_dir']) && is_dir($storage['legacy_dir'])) {
                foreach (glob($storage['legacy_dir'] . 'products-*.xml') ?: [] as $old) { @unlink($old); }
                foreach (glob($storage['legacy_dir'] . 'sitemap-*.xml') ?: [] as $old) { @unlink($old); }
            }
        }

        $files = glob($dir . 'products-*.xml') ?: [];
        $err = null;
        $this->refresh_sitemap_index($storage, $err);
        if ($err) {
            $state['message'] = $err;
            $state['message_type'] = 'error';
        } else {
            $state['message'] = sprintf(
                'Rebuilt sitemap_index.xml with %d file(s) (published count: %d).',
                count($files),
                (int)$published_count
            );
            $state['message_type'] = 'success';
        }
        $this->save_publish_state($state);
        wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
        exit;
    }

    public function handle_publish_validate() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer(self::NONCE_KEY);
        $state = $this->get_publish_state();
        $storage = $this->get_sitemap_storage_paths();
        if (!$storage) {
            $state['message'] = 'Uploads directory is not writable.';
            $state['message_type'] = 'error';
            $state['validation'] = [];
            $this->save_publish_state($state);
            wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
            exit;
        }
        $dir = $storage['dir'];
        if (!wp_mkdir_p($dir)) {
            $state['message'] = 'Failed to create sitemap directory.';
            $state['message_type'] = 'error';
            $state['validation'] = [];
            $this->save_publish_state($state);
            wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
            exit;
        }

        $files = glob($dir . 'products-*.xml') ?: [];
        $rows = [];
        $total_urls = 0;
        foreach ($files as $file) {
            $name = basename($file);
            $size = @filesize($file) ?: 0;
            $mtime = @filemtime($file) ?: time();
            $entries = $this->read_sitemap_entries($file);
            $url_count = count($entries);
            $total_urls += $url_count;
            $status = 'ok';
            $notes = [];
            if ($url_count > 50000) {
                $status = 'error';
                $notes[] = 'URL count exceeds 50k limit.';
            } elseif ($url_count >= 45000) {
                $status = 'warn';
                $notes[] = 'URL count nearing 50k limit.';
            }
            if ($size > 50 * 1024 * 1024) {
                $status = 'error';
                $notes[] = 'File size exceeds 50 MB.';
            } elseif ($size >= 45 * 1024 * 1024 && $status !== 'error') {
                $status = 'warn';
                $notes[] = 'File size nearing 50 MB.';
            }
            if ($url_count === 0) {
                $xml = @simplexml_load_file($file);
                if ($xml === false) {
                    $status = 'error';
                    $notes[] = 'Unable to parse XML.';
                }
            }
            $icon = '✅';
            if ($status === 'warn') {
                $icon = '⚠️';
            } elseif ($status === 'error') {
                $icon = '❌';
            }
            $rows[] = [
                'file' => $name,
                'urls' => $url_count,
                'size_bytes' => $size,
                'size_human' => $this->format_bytes($size),
                'last_modified' => gmdate('Y-m-d H:i', $mtime),
                'status' => $status,
                'icon' => $icon,
                'note' => implode(' ', $notes),
            ];
        }

        if (empty($rows)) {
            $state['message'] = 'Validated 0 sitemap file(s) totaling 0 URL(s).';
            $state['message_type'] = 'success';
        } else {
            $state['message'] = sprintf('Validated %d sitemap file(s) totaling %d URL(s).', count($rows), $total_urls);
            $state['message_type'] = 'success';
        }
        $state['validation'] = $rows;
        $this->save_publish_state($state);
        wp_safe_redirect(admin_url('admin.php?page=vpp_publishing'));
        exit;
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $input = isset($_POST[self::OPT_KEY]) ? (array) $_POST[self::OPT_KEY] : [];
        $clean = $this->sanitize_settings($input);
        update_option(self::OPT_KEY, $clean, false);
        $redirect = add_query_arg(['vpp_msg'=> 'Settings saved.'], admin_url('admin.php?page=vpp_settings'));
        wp_safe_redirect($redirect); exit;
    }

    public function handle_test_tidb() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $err = null;
        $conn = $this->db_connect($err);
        if ($conn) { @$conn->close(); $ok = true; } else { $ok = false; }
        $redirect = add_query_arg([$ok ? 'vpp_msg' : 'vpp_err' => $ok ? 'TiDB connection OK.' : ('TiDB connection failed: ' . $err)], admin_url('admin.php?page=vpp_settings'));
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
            $redirect = add_query_arg(['vpp_err'=> 'Algolia not configured (app_id, admin_key, index required).'], admin_url('admin.php?page=vpp_settings'));
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
            $redirect = add_query_arg(['vpp_err'=> 'Algolia request failed: ' . $resp->get_error_message()], admin_url('admin.php?page=vpp_settings'));
        } else {
            $code = wp_remote_retrieve_response_code($resp);
            if ($code >= 200 && $code < 300) {
                $redirect = add_query_arg(['vpp_msg'=> 'Algolia connection OK.'], admin_url('admin.php?page=vpp_settings'));
            } else {
                $redirect = add_query_arg(['vpp_err'=> 'Algolia HTTP ' . $code], admin_url('admin.php?page=vpp_settings'));
            }
        }
        wp_safe_redirect($redirect); exit;
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
            $id = (int)$id_or_slug; $stmt->bind_param('i', $id);
        } else {
            $sql = "UPDATE `{$table}` SET is_published = 1, last_tidb_update_at = NOW() WHERE slug = ?";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) { $err = 'DB prepare failed.'; $this->log_error('db_query', $err); return false; }
            $slug = $id_or_slug; $stmt->bind_param('s', $slug);
        }
        $ok = $stmt->execute();
        $stmt->close();
        @$mysqli->close();
        if (!$ok) { $err = 'Publish failed.'; $this->log_error('db_query', $err); return false; }
        return true;
    }

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
        $params = $fail ? ['vpp_err'=> "Published {$ok}, failed {$fail}. {$msg}"] : ['vpp_msg'=> "Published {$ok}, failed {$fail}."];
        $redirect = add_query_arg($params, admin_url('admin.php?page=vpp_settings'));
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
        $params = $fail ? ['vpp_err'=> "Algolia push: {$ok} ok, {$fail} failed. {$msg}"] : ['vpp_msg'=> "Algolia push: {$ok} ok, {$fail} failed."];
        $redirect = add_query_arg($params, admin_url('admin.php?page=vpp_settings'));
        wp_safe_redirect($redirect); exit;
    }

    public function handle_purge_cache() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $err = null;
        $ok = $this->purge_cloudflare([], $err);
        $redirect = add_query_arg([$ok ? 'vpp_msg':'vpp_err' => $ok ? 'Cloudflare cache purged.' : ($err ?: 'Cloudflare purge failed.')], admin_url('admin.php?page=vpp_settings'));
        wp_safe_redirect($redirect); exit;
    }

    public function handle_rebuild_sitemaps() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $summary = '';
        $err = null;
        $ok = $this->rebuild_sitemaps($summary, $err);
        $redirect = add_query_arg([$ok ? 'vpp_msg':'vpp_err' => $ok ? ($summary ?: 'Sitemap rebuilt.') : ($err ?: 'Sitemap rebuild failed.')], admin_url('admin.php?page=vpp_settings'));
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
        if ($path && file_exists($path)) { @file_put_contents($path, ''); }
        $redirect = add_query_arg(['vpp_msg' => rawurlencode('Log cleared.')], admin_url('admin.php?page=vpp_status'));
        wp_safe_redirect($redirect); exit;
    }

    /* ========= CF / SITEMAPS / ALGOLIA ========= */

    private function purge_cloudflare(array $urls = [], &$err = null) {
        $s = $this->get_settings();
        $token = trim($s['cloudflare']['api_token'] ?? '');
        $zone  = trim($s['cloudflare']['zone_id'] ?? '');
        if (!$token || !$zone) { $err = 'Cloudflare credentials not configured.'; return false; }
        $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zone}/purge_cache";
        $payload = empty($urls) ? ['purge_everything' => true] : ['files' => array_values(array_filter($urls))];
        $args = [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token],
            'body' => wp_json_encode($payload),
            'timeout' => 15,
        ];
        $resp = wp_remote_post($endpoint, $args);
        if (is_wp_error($resp)) { $err = 'Cloudflare request failed: ' . $resp->get_error_message(); return false; }
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code < 200 || $code >= 300 || (is_array($body) && isset($body['success']) && !$body['success'])) {
            $msg = is_array($body) && !empty($body['errors'][0]['message']) ? $body['errors'][0]['message'] : ('HTTP ' . $code);
            $err = 'Cloudflare purge failed: ' . $msg;
            return false;
        }
        return true;
    }

    private function purge_cloudflare_url($url) {
        if (!$url) return false;
        $err = null; return $this->purge_cloudflare([$url], $err);
    }

    private function rebuild_sitemaps(&$summary = '', &$err = null) {
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

        $storage = $this->get_sitemap_storage_paths();
        if (!$storage) {
            @$mysqli->close(); $err = 'Uploads directory is not writable.'; return false;
        }
        $dir = $storage['dir'];
        if (!wp_mkdir_p($dir)) { @$mysqli->close(); $err = 'Failed to create sitemap directory.'; return false; }
        $dir = trailingslashit($dir);
        $base_url = $storage['url'];

        foreach (glob($dir . 'sitemap-*.xml') ?: [] as $old) { @unlink($old); }
        foreach (glob($dir . 'products-*.xml') ?: [] as $old) { @unlink($old); }
        @unlink($dir . 'sitemap-index.xml');
        @unlink($dir . 'vpp-index.xml');
        if (!empty($storage['legacy_dir']) && is_dir($storage['legacy_dir'])) {
            foreach (glob($storage['legacy_dir'] . 'sitemap-*.xml') ?: [] as $old) { @unlink($old); }
            foreach (glob($storage['legacy_dir'] . 'products-*.xml') ?: [] as $old) { @unlink($old); }
            @unlink($storage['legacy_dir'] . 'sitemap-index.xml');
            @unlink($storage['legacy_dir'] . 'sitemap_index.xml');
            @unlink($storage['legacy_dir'] . 'vpp-index.xml');
        }

        $write_chunk = function(array $entries, $lastmod_ts, $index) use (&$files, $dir, $base_url, &$err) {
            if (empty($entries)) { return true; }
            $filename = sprintf('sitemap-products-%d.xml', $index);
            $path = $dir . $filename;
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($entries as $entry) {
                $loc = home_url('/p/' . $entry['slug']);
                $xml .= "  <url>\n";
                $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</loc>' . "\n";
                $xml .= '    <lastmod>' . gmdate('c', $entry['lastmod']) . '</lastmod>' . "\n";
                $xml .= "  </url>\n";
            }
            $xml .= '</urlset>';
            if (@file_put_contents($path, $xml) === false) { $err = 'Failed to write ' . $filename; return false; }
            $files[] = ['loc' => $base_url . $filename, 'lastmod' => gmdate('c', $lastmod_ts ?: time())];
            return true;
        };

        while (true) {
            $sql = sprintf("SELECT slug, last_tidb_update_at FROM `%s` WHERE is_published = 1 ORDER BY id LIMIT %d OFFSET %d", $table, $batch_size, $offset);
            $res = $mysqli->query($sql);
            if (!$res) { @$mysqli->close(); $err = 'DB query failed: ' . $mysqli->error; return false; }
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
                    if (!$write_chunk($chunk_entries, $chunk_lastmod, $chunk_index)) { $res->free(); @$mysqli->close(); return false; }
                    $chunk_entries = []; $chunk_lastmod = 0;
                }
            }
            $res->free();
            if ($row_count < $batch_size) { break; }
            $offset += $batch_size;
        }

        if (!empty($chunk_entries)) {
            $chunk_index++;
            if (!$write_chunk($chunk_entries, $chunk_lastmod, $chunk_index)) { @$mysqli->close(); return false; }
        }

        @$mysqli->close();

        $index_path = $dir . 'sitemap-index.xml';
        $index_xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $index_xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($files as $file) {
            $index_xml .= "  <sitemap>\n";
            $index_xml .= '    <loc>' . htmlspecialchars($file['loc'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</loc>' . "\n";
            $index_xml .= '    <lastmod>' . $file['lastmod'] . '</lastmod>' . "\n";
            $index_xml .= "  </sitemap>\n";
        }
        $index_xml .= '</sitemapindex>';
        if (@file_put_contents($index_path, $index_xml) === false) { $err = 'Failed to write sitemap-index.xml'; return false; }
        @file_put_contents($dir . 'sitemap_index.xml', $index_xml);
        @file_put_contents($dir . 'vpp-index.xml', $index_xml);
        if (!empty($storage['legacy_dir']) && wp_mkdir_p($storage['legacy_dir'])) {
            @file_put_contents($storage['legacy_dir'] . 'sitemap-index.xml', $index_xml);
            @file_put_contents($storage['legacy_dir'] . 'sitemap_index.xml', $index_xml);
            @file_put_contents($storage['legacy_dir'] . 'vpp-index.xml', $index_xml);
        }

        $meta = $this->get_sitemap_meta();
        $meta['base_url'] = $base_url;
        $this->save_sitemap_meta($meta);

        $summary = sprintf('Generated %d sitemap file(s) covering %d product(s).', count($files), $total);
        return true;
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

        $snippet = '';
        if (!empty($product['short_summary'])) { $snippet = $product['short_summary']; }
        elseif (!empty($product['desc_html'])) { $snippet = wp_strip_all_tags($product['desc_html']); $snippet = function_exists('mb_substr') ? mb_substr($snippet, 0, 180) : substr($snippet, 0, 180); }
        else { $parts = array_filter([$product['brand'] ?? '', $product['model'] ?? '', $product['sku'] ?? '']); $snippet = trim(implode(' • ', $parts)); }
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
        if (is_wp_error($resp)) { $err = $resp->get_error_message(); return false; }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) { $err = 'Algolia HTTP ' . $code; return false; }
        return true;
    }

    /* ========= SEO HELPERS ========= */

    private function current_vpp_slug() {
        $slug = get_query_var(self::QUERY_VAR);
        if ($slug) { return sanitize_title($slug); }
        return null;
    }

    private function get_current_product(&$err = null) {
        $slug = $this->current_vpp_slug();
        if (!$slug) {
            return null;
        }

        if ($this->current_product_slug === $slug && is_array($this->current_product)) {
            return $this->current_product;
        }

        $product = $this->fetch_product_by_slug($slug, $err);
        $this->current_product_slug = $slug;
        $this->current_product = $product ?: null;
        $this->current_meta_description = '';
        $this->current_canonical = '';

        return $this->current_product;
    }

    public function filter_canonical($url) {
        $slug = $this->current_vpp_slug();
        if (!$slug) return $url;
        return home_url('/p/' . $slug);
    }

    public function filter_document_title($title) {
        if (!$this->current_vpp_slug()) return $title;
        $err = null;
        $p = $this->get_current_product($err);
        if (!$p) return $title;
        $base = $p['title_h1'] ?: $p['slug'];
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        return $base . ' | ' . $site;
    }

    public function filter_yoast_metadesc($value) {
        if (!$this->current_vpp_slug()) return $value;
        $err = null;
        $p = $this->get_current_product($err);
        if (!$p) return $value;
        $meta = $this->build_meta_description($p);
        $this->last_meta_source = 'Yoast filter';
        $this->last_meta_value = $meta;
        return $meta;
    }

    public function filter_yoast_presenters($presenters) {
        if (!$this->current_vpp_slug()) {
            return $presenters;
        }
        if (!is_array($presenters)) {
            return $presenters;
        }
        foreach ($presenters as $key => $presenter) {
            if (is_string($presenter) && stripos($presenter, 'Meta_Description_Presenter') !== false) {
                unset($presenters[$key]);
            }
        }
        return array_values($presenters);
    }

    private function build_meta_description($p) {
        $desc = '';
        if (!empty($p['meta_description'])) { $desc = (string)$p['meta_description']; }
        elseif (!empty($p['short_summary'])) { $desc = (string)$p['short_summary']; }
        else { $desc = (string)($p['title_h1'] ?: $p['slug']); }
        $desc = wp_strip_all_tags($desc);
        $desc = str_replace('"', "'", $desc);
        if (function_exists('mb_substr')) { $desc = mb_substr($desc, 0, 160); } else { $desc = substr($desc, 0, 160); }
        if ($desc === '') { $desc = ' '; } // never empty
        return $desc;
    }

    /* ========= FRONT RENDER ========= */

    public function maybe_output_sitemap() {
        $mode = get_query_var(self::SITEMAP_QUERY_VAR);
        if (!$mode) {
            return;
        }
        $storage = $this->get_sitemap_storage_paths();
        if (!$storage) {
            status_header(404);
            exit;
        }
        $path = '';
        if ($mode === 'index') {
            $refresh_err = null;
            if ($this->refresh_sitemap_index($storage, $refresh_err) === false && $refresh_err) {
                error_log('VPP sitemap refresh failed: ' . $refresh_err);
            }
            $candidates = [
                $storage['dir'] . 'sitemap_index.xml',
                $storage['dir'] . 'sitemap-index.xml',
            ];
            foreach ($candidates as $candidate) {
                if (@is_readable($candidate)) {
                    $path = $candidate;
                    break;
                }
            }
        } elseif ($mode === 'file') {
            $requested = get_query_var(self::SITEMAP_FILE_QUERY_VAR);
            if (!is_string($requested) || $requested === '') {
                status_header(404);
                exit;
            }
            if (!preg_match('/^[a-z0-9\-]+\.xml$/i', $requested)) {
                status_header(404);
                exit;
            }
            if (strcasecmp($requested, 'vpp-index.xml') === 0) {
                $refresh_err = null;
                if ($this->refresh_sitemap_index($storage, $refresh_err) === false && $refresh_err) {
                    error_log('VPP sitemap refresh failed: ' . $refresh_err);
                }
            }
            $path = $storage['dir'] . $requested;
            if (!@is_readable($path)) {
                status_header(404);
                exit;
            }
        } else {
            return;
        }
        if ($path === '') {
            status_header(404);
            exit;
        }
        header('Content-Type: application/xml; charset=UTF-8');
        header('X-Robots-Tag: noindex, follow', true);
        header('Cache-Control: no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
        readfile($path);
        exit;
    }

    public function maybe_render_vpp() {
        $slug = $this->current_vpp_slug();
        if (!$slug) return;
        $err = null;
        $product = $this->get_current_product($err);
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
        $allowed = $this->allowed_html();
        $meta_line = trim(implode(' • ', array_filter([$brand, $model, $sku])));

        $short_summary = isset($p['short_summary']) ? trim((string)$p['short_summary']) : '';
        if (strlen($short_summary) > 150) { $short_summary = substr($short_summary, 0, 150); }

        $cta_definitions = [
            [
                'url' => $p['cta_lead_url'] ?? '',
                'label' => $p['cta_lead_label'] ?? '',
                'fallback' => 'Request a Quote',
                'rel' => 'nofollow',
            ],
            [
                'url' => $p['cta_stripe_url'] ?? '',
                'label' => $p['cta_stripe_label'] ?? '',
                'fallback' => 'Buy via Stripe',
                'rel' => 'nofollow',
            ],
            [
                'url' => $p['cta_affiliate_url'] ?? '',
                'label' => $p['cta_affiliate_label'] ?? '',
                'fallback' => 'Buy Now',
                'rel' => 'sponsored nofollow',
            ],
            [
                'url' => $p['cta_paypal_url'] ?? '',
                'label' => $p['cta_paypal_label'] ?? '',
                'fallback' => 'Pay with PayPal',
                'rel' => 'nofollow',
            ],
        ];

        $cta_buttons = [];
        foreach ($cta_definitions as $def) {
            $url = trim((string)$def['url']);
            if ($url === '') {
                continue;
            }
            $label = trim((string)$def['label']);
            if ($label === '') {
                $label = $def['fallback'];
            }
            $cta_buttons[] = [
                'url' => $url,
                'label' => $label,
                'rel' => $def['rel'],
            ];
        }

        $meta_description = $this->build_meta_description($p);
        $this->current_product = $p;
        $this->current_product_slug = $p['slug'];
        $this->current_meta_description = $meta_description;
        $this->current_canonical = home_url('/p/' . $p['slug']);
        if ($this->last_meta_source === '') {
            $this->last_meta_source = 'Manual tag';
        }
        $this->last_meta_value = $meta_description;

        $inline_css = $this->load_css_contents();
        $this->current_inline_css = $inline_css;

        @header('Content-Type: text/html; charset=utf-8');
        @header('Cache-Control: public, max-age=300');

        get_header();

        if ($inline_css !== '') {
            echo '<style id="vpp-inline-css-fallback">' . $inline_css . '</style>';
        }

        ?>
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
                  <?php if (!empty($cta_buttons)): ?>
                  <div class="vpp-cta-block">
                    <?php foreach ($cta_buttons as $btn): ?>
                      <a class="vpp-cta-button glow" href="<?php echo esc_url($btn['url']); ?>"<?php echo $btn['rel'] ? ' rel="' . esc_attr($btn['rel']) . '"' : ''; ?>><?php echo esc_html($btn['label']); ?></a>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
            </section>

            <section class="vpp-content card">
              <?php
                $html = $p['desc_html'] ?? '';
                if ($html) { echo wp_kses($html, $allowed); }
                else { echo '<p>No description available yet.</p>'; }
              ?>
            </section>
            <?php
                if (!empty($p['schema_json'])) {
                    $schema_decoded = json_decode($p['schema_json'], true);
                    if (is_array($schema_decoded)) {
                        echo "\n<!-- Product Schema.org JSON-LD -->\n";
                        echo '<script type="application/ld+json">' .
                             wp_json_encode($schema_decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
                             '</script>' . "\n";
                    }
                }
            ?>
          </article>
        </main>
        <?php

        get_footer();
    }

    public function output_meta_tags() {
        if (!$this->current_vpp_slug()) {
            return;
        }

        $err = null;
        $product = $this->get_current_product($err);
        if (!$product || empty($product['is_published'])) {
            return;
        }

        $meta_description = $this->current_meta_description !== ''
            ? $this->current_meta_description
            : $this->build_meta_description($product);
        $canonical = $this->current_canonical !== ''
            ? $this->current_canonical
            : home_url('/p/' . $product['slug']);

        $css_href = add_query_arg('ver', self::VERSION, plugins_url('assets/vpp.css', __FILE__));

        if (!defined('WPSEO_VERSION')) {
            echo '<link rel="canonical" href="' . esc_url($canonical) . '" />' . "\n";
        }

        echo '<link rel="preload" href="' . esc_url($css_href) . '" as="style" />' . "\n";

        echo '<meta name="description" content="' . esc_attr($meta_description) . '" data-vpp-meta="description" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($meta_description) . '" data-vpp-meta="og-description" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($meta_description) . '" data-vpp-meta="twitter-description" />' . "\n";

        if ($this->last_meta_source === '') {
            $this->last_meta_source = 'Manual tag';
        }
        $this->last_meta_value = $meta_description;
    }

    public function inject_inline_css_head() {
        if (!$this->current_vpp_slug()) {
            return;
        }
        $inline = $this->current_inline_css;
        if ($inline === '') {
            $inline = $this->load_css_contents();
        }
        if ($inline === '') {
            return;
        }
        echo '<style id="vpp-inline-css">' . $inline . '</style>';
    }

    private function load_css_contents() {
        if ($this->cached_inline_css !== null) {
            return $this->cached_inline_css;
        }
        $css_path = plugin_dir_path(__FILE__) . 'assets/vpp.css';
        if (is_readable($css_path)) {
            $css = file_get_contents($css_path);
            if (is_string($css)) {
                $css = trim($css);
                if ($css !== '') {
                    return $this->cached_inline_css = $css;
                }
            }
        }
        return $this->cached_inline_css = trim(self::CSS_FALLBACK);
    }

}

VPP_Plugin::instance();
