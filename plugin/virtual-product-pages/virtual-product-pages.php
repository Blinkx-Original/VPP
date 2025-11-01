<?php
/**
 * Plugin Name: Virtual Product Pages (TiDB + Algolia)
 * Description: Render virtual product pages at /p/{slug} from TiDB, with external CTAs. Includes Push to VPP, Push to Algolia, Edit Product, sitemap rebuild, and Cloudflare purge.
 * Version: 2.9
 * Author: ChatGPT (for Martin)
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

class VPP_Plugin {
    const OPT_KEY = 'vpp_settings';
    const NONCE_KEY = 'vpp_nonce';
    const QUERY_VAR = 'vpp_slug';
    const BLOG_QUERY_VAR = 'vpp_blog';
    const SITEMAP_QUERY_VAR = 'vpp_sitemap';
    const SITEMAP_FILE_QUERY_VAR = 'vpp_sitemap_file';
    const CATEGORY_INDEX_QUERY_VAR = 'vpp_cat_index';
    const CATEGORY_SLUG_QUERY_VAR = 'vpp_cat_slug';
    const CATEGORY_PAGE_QUERY_VAR = 'vpp_cat_page';
    const CATEGORY_RESERVED_SLUGS = ['p', 'p-cat', 'category', 'categories', 'tag', 'tags', 'product', 'products', 'page', 'search'];
    const CATEGORY_PER_PAGE_DEFAULT = 9;
    const CATEGORY_PER_PAGE_MAX = 9;
    const CATEGORY_CACHE_TTL = HOUR_IN_SECONDS;
    const SITEMAP_MAX_URLS = 50000;
    const VERSION = '2.9';
    const VERSION_OPTION = 'vpp_plugin_version';
    const CSS_FALLBACK = <<<CSS
/* Strictly-scoped VPP CSS to avoid theme/header collisions */
body.vpp-body{margin:0;min-height:100vh;background:#f7f8fb;color:#0f172a;color-scheme:light;
  -webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;
  font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;line-height:1.55}

.vpp{--ink:#0f172a;--muted:#6b7280;--ink-2:#1e293b;--card:#ffffff;--glow:rgba(37,99,235,0.55);
     --ring:rgba(148,163,184,0.25);--stroke:rgba(148,163,184,0.15);--brand-1:#2563eb;--brand-2:#3b82f6}

.vpp-container{max-width:1120px;margin:0 auto;padding:28px 16px}
.vpp a{color:var(--brand-1);text-decoration:none}
.vpp a:hover{text-decoration:underline}

.vpp .card,.vpp .card-elevated{
  background:linear-gradient(180deg,rgba(248,250,252,.9),var(--card) 55%);
  border-radius:20px;padding:20px;border:1px solid var(--stroke);
  box-shadow:0 18px 50px rgba(15,23,42,.08)
}
.vpp .card-elevated{box-shadow:0 28px 80px rgba(15,23,42,.12),0 1px 0 var(--ring)}

.vpp-hero{margin-bottom:18px;background:linear-gradient(160deg,rgba(226,232,240,.35),rgba(255,255,255,.95))}
.vpp-grid{display:grid;grid-template-columns:1.1fr 1fr;gap:28px}
@media (max-width:960px){.vpp-grid{grid-template-columns:1fr}}

.vpp-media{display:flex;flex-direction:column;gap:12px}
.vpp-carousel{position:relative;border-radius:16px;overflow:hidden;background:#eef2f7;
  box-shadow:0 18px 40px rgba(15,23,42,.12)}
.vpp-carousel-frame{position:relative}
.vpp-carousel-image{display:none;width:100%;height:auto;border-radius:16px;object-fit:contain;
  background:#eef2f7;transition:opacity .25s ease}
.vpp-carousel-image.is-active{display:block;opacity:1}
.vpp-carousel-nav{position:absolute;top:50%;transform:translateY(-50%);border:0;background:rgba(15,23,42,.55);
  color:#fff;width:42px;height:42px;border-radius:999px;display:flex;align-items:center;justify-content:center;
  cursor:pointer;transition:background .2s ease, transform .2s ease;box-shadow:0 10px 28px rgba(15,23,42,.25)}
.vpp-carousel-nav:hover{background:rgba(15,23,42,.68);transform:translateY(-50%) scale(1.05)}
.vpp-carousel-nav:focus-visible{outline:3px solid rgba(59,130,246,.55);outline-offset:2px}
.vpp-carousel-nav[data-dir="prev"]{left:12px}
.vpp-carousel-nav[data-dir="next"]{right:12px}
.vpp-carousel-nav svg{width:18px;height:18px}
.vpp-carousel-nav[disabled]{opacity:.45;pointer-events:none}
.vpp-placeholder{display:flex;align-items:center;justify-content:center;height:360px;border-radius:16px;
  background:#fff;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.6),0 20px 40px rgba(15,23,42,.08)}
.vpp-ph-img{width:60%;height:70%;border-radius:16px;
  background:#fff;border:1px solid rgba(148,163,184,.16)}

.vpp-price-callout{margin-top:1rem;padding:1.15rem 1.35rem;border-radius:18px;background:rgba(37,99,235,.08);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.65),0 12px 30px rgba(37,99,235,.18);color:#0f172a;font-weight:800;
  font-size:clamp(1.45rem,2.75vw,2.3rem);line-height:1.2}
.vpp-price-callout span{display:block}

.vpp-summary{display:flex;flex-direction:column;gap:8px}
.vpp-title{margin:0;font-weight:800;color:#111827;font-size:clamp(1.6rem,2.4vw,2.25rem)}
.vpp-meta{margin:0;color:var(--muted)}
.vpp-short{margin:.2rem 0 0;color:#374151;font-size:.98rem;max-width:60ch}

.vpp-availability{margin:.5rem 0 0;font-weight:600;color:#047857;font-size:1.02rem}

.vpp-cta-block{margin-top:.75rem;display:flex;flex-direction:column;gap:.75rem}
.vpp-cta-button{display:flex;align-items:center;justify-content:center;text-decoration:none;color:#fff!important;
  background:linear-gradient(135deg,var(--brand-1),var(--brand-2));border-radius:999px;font-weight:700;padding:.95rem 1.4rem;
  position:relative;box-shadow:0 16px 32px rgba(37,99,235,.4);transition:transform .2s, box-shadow .2s;min-height:56px;text-align:center}
.vpp-cta-button.glow::before{content:"";position:absolute;inset:-3px;border-radius:999px;
  background:radial-gradient(120% 120% at 50% 0%, rgba(59,130,246,.65), rgba(59,130,246,.18) 60%, transparent 72%);
  filter:blur(8px);z-index:-1}
.vpp-cta-button:hover{transform:translateY(-2px);box-shadow:0 24px 48px rgba(37,99,235,.55)}
.vpp-cta-button:focus-visible{outline:3px solid rgba(59,130,246,.45);outline-offset:2px}

.vpp-content{margin-top:18px}
.vpp-content.card{background:linear-gradient(180deg,rgba(248,250,252,.9),var(--card) 55%)}
.vpp-content h2{font-size:1.25rem;margin:1rem 0 .5rem}
.vpp-content h3{font-size:1.05rem;margin:.75rem 0 .35rem}
.vpp-content p{margin:.5rem 0}
.vpp-content table{width:100%;border-collapse:collapse;margin:.75rem 0}
.vpp-content th,.vpp-content td{border:1px solid #e5e7eb;padding:.55rem .65rem;text-align:left}
.vpp-content a{color:#2563eb}

.vpp-inline{padding:.25rem .5rem;border-radius:8px;font-size:12px}
.vpp-inline.ok{background:#e6ffed;color:#065f46}
.vpp-inline.err{background:#fee2e2;color:#991b1b}

.vpp-cat-hero{display:flex;flex-direction:column;gap:8px;margin-bottom:18px;background:linear-gradient(150deg,rgba(226,232,240,.38),rgba(255,255,255,.95))}
.vpp-cat-title{margin:0;font-weight:800;color:var(--ink);font-size:clamp(1.85rem,3vw,2.45rem)}
.vpp-cat-subtitle{margin:0;color:var(--muted);font-size:1rem}
.vpp-cat-grid-wrap{margin-top:18px}
.vpp-cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:18px}
.vpp-cat-card{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;
  border-radius:22px;padding:26px;background:linear-gradient(160deg,rgba(255,255,255,.95),rgba(226,232,240,.6));
  border:1px solid rgba(148,163,184,.18);box-shadow:0 16px 48px rgba(15,23,42,.1);text-decoration:none;color:var(--ink);
  transition:transform .2s ease, box-shadow .2s ease;background-blend-mode:overlay;min-height:0;aspect-ratio:1/1}
.vpp-cat-card::after{content:"";position:absolute;inset:14px;border-radius:18px;border:1px solid rgba(148,163,184,.18);
  pointer-events:none;opacity:.75}
.vpp-cat-card:hover{transform:translateY(-4px);box-shadow:0 22px 56px rgba(37,99,235,.22)}
.vpp-cat-card:focus-visible{outline:3px solid rgba(59,130,246,.55);outline-offset:4px}
.vpp-cat-name{font-weight:700;font-size:1.05rem;text-align:center;color:var(--ink-2)}
.vpp-cat-count{font-size:.9rem;color:var(--muted)}
.vpp-cat-empty{text-align:center;font-size:1rem;color:var(--muted)}

.vpp-archive-hero{display:flex;flex-direction:column;gap:6px;margin-bottom:18px;background:linear-gradient(150deg,rgba(226,232,240,.38),rgba(255,255,255,.95))}
.vpp-archive-title{margin:0;font-weight:800;color:var(--ink);font-size:clamp(1.9rem,3vw,2.5rem)}
.vpp-archive-subtitle{margin:0;color:var(--muted)}
.vpp-archive-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:24px;margin-top:20px}
@media (max-width:1024px){.vpp-archive-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.vpp-archive-grid{grid-template-columns:1fr}}
.vpp-archive-card{position:relative;display:flex;flex-direction:column;justify-content:space-between;gap:16px;padding:24px;
  border-radius:24px;background:linear-gradient(175deg,rgba(248,250,252,.95),#fff 65%);
  border:1px solid rgba(148,163,184,.18);box-shadow:0 24px 64px rgba(15,23,42,.1);min-height:0;aspect-ratio:1/1;
  transition:transform .2s ease, box-shadow .2s ease}
.vpp-archive-card::after{content:"";position:absolute;inset:18px;border-radius:20px;border:1px solid rgba(148,163,184,.12);
  pointer-events:none;opacity:.6}
.vpp-archive-card:hover{transform:translateY(-6px);box-shadow:0 32px 84px rgba(37,99,235,.22)}
.vpp-archive-card-header{display:flex;flex-direction:column;gap:8px}
.vpp-archive-card-title{margin:0;font-size:clamp(1.05rem,1.8vw,1.3rem);font-weight:800;color:var(--ink-2)}
.vpp-archive-card-meta{margin:0;color:var(--muted);font-size:.9rem}
.vpp-archive-card-thumb{width:100%;aspect-ratio:4/3;border-radius:18px;overflow:hidden;background:linear-gradient(150deg,#e2e8f0,#f1f5f9);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.6),0 18px 40px rgba(15,23,42,.12);display:flex;align-items:center;justify-content:center}
.vpp-archive-card-thumb img{width:100%;height:100%;object-fit:cover}
.vpp-archive-card-thumb.placeholder{background:linear-gradient(150deg,#e2e8f0,#f8fafc)}
.vpp-archive-card-body{display:flex;flex-direction:column;gap:10px;flex:1 1 auto}
.vpp-archive-card-summary{margin:0;color:var(--ink);font-size:.95rem;line-height:1.5;display:-webkit-box;-webkit-line-clamp:3;
  -webkit-box-orient:vertical;overflow:hidden}
.vpp-archive-card-footer{margin-top:auto;padding-top:12px}
.vpp-archive-card-button{display:inline-flex;align-items:center;justify-content:center;padding:.8rem 1.4rem;border-radius:999px;
  background:linear-gradient(135deg,var(--brand-1),var(--brand-2));color:#fff!important;font-weight:700;text-decoration:none;
  box-shadow:0 18px 36px rgba(37,99,235,.35);transition:transform .2s ease, box-shadow .2s ease}
.vpp-archive-card-button:hover{transform:translateY(-2px);box-shadow:0 26px 52px rgba(37,99,235,.45);text-decoration:none}
.vpp-archive-card-button:focus-visible{outline:3px solid rgba(59,130,246,.5);outline-offset:3px}
.vpp-archive-empty{text-align:center;color:var(--muted);font-size:1rem}

.vpp-pagination{margin:26px 0;display:flex;justify-content:center}
.vpp-pagination-list{display:inline-flex;gap:6px;padding:0;margin:0;list-style:none}
.vpp-page-item{display:inline-flex}
.vpp-page-link{display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:999px;font-weight:600;
  background:rgba(148,163,184,.18);color:var(--ink);text-decoration:none;transition:background .2s ease, color .2s ease}
.vpp-page-item.active .vpp-page-link{background:linear-gradient(135deg,var(--brand-1),var(--brand-2));color:#fff}
.vpp-page-item.disabled .vpp-page-link{opacity:.5;pointer-events:none}
.vpp-page-link:hover{background:rgba(59,130,246,.18);text-decoration:none}
.vpp-page-link:focus-visible{outline:3px solid rgba(59,130,246,.5);outline-offset:2px}

@media (max-width:420px){
  .vpp-container{padding:18px 12px}
  .vpp-cta-button{padding:.85rem 1.1rem}
  .vpp-carousel-nav{width:38px;height:38px}
  .vpp-cat-grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}
  .vpp-archive-grid{grid-template-columns:1fr}
}
CSS;
    const SITEMAP_META_OPTION = 'vpp_sitemap_meta';
    const LOG_SUBDIR = 'vpp-logs';
    const LOG_FILENAME = 'vpp.log';
    const PUBLISH_STATE_OPTION = 'vpp_publish_state';
    const PUBLISH_HISTORY_OPTION = 'vpp_publish_history';
    const PUBLISH_RESUME_OPTION = 'vpp_publish_resume';
    const CF_LAST_BATCH_OPTION = 'vpp_cf_last_batch';

    private static $instance = null;

    // runtime debug
    private $last_meta_source = '';
    private $last_meta_value = '';

    private $cached_inline_css = null;
    private $current_inline_css = '';
    private $current_product = null;
    private $current_product_slug = null;
    private $current_blog = null;
    private $current_blog_slug = null;
    private $current_meta_description = '';
    private $current_canonical = '';
    private $table_columns_cache = [];
    private $current_category_context = null;
    private $category_slug_map = null;
    private $category_cache = null;
    private $cf_recent_sitemaps = [];
    private $cf_recent_sitemap_files = [];
    private $cf_recent_product_slugs = [];

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_rewrite']);
        add_action('init', [$this, 'maybe_flush_rewrite_on_upgrade'], 20);
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
            add_action('admin_post_vpp_cf_test_connection', [$this, 'handle_cf_test_connection']);
            add_action('admin_post_vpp_cf_test_purge', [$this, 'handle_cf_test_purge']);
            add_action('admin_post_vpp_cf_purge_sitemaps', [$this, 'handle_cf_purge_sitemaps']);
            add_action('admin_post_vpp_cf_purge_last_batch', [$this, 'handle_cf_purge_last_batch']);
            add_action('admin_post_vpp_cf_purge_everything', [$this, 'handle_cf_purge_everything']);
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
            add_action('admin_post_vpp_blog_load', [$this, 'handle_blog_load']);
            add_action('admin_post_vpp_blog_save', [$this, 'handle_blog_save']);
            add_action('admin_notices', [$this, 'maybe_admin_notice']);
        }

        // Front assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('body_class', [$this, 'filter_body_class']);

        add_action('vpp_publish_completed', [$this, 'cf_on_publish_completed'], 10, 2);
        add_action('vpp_rebuild_completed', [$this, 'cf_on_publish_completed'], 10, 2);

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

    public function maybe_flush_rewrite_on_upgrade() {
        $stored = get_option(self::VERSION_OPTION);
        if (!is_string($stored) || version_compare($stored, self::VERSION, '<')) {
            $this->register_rewrite();
            flush_rewrite_rules(false);
            update_option(self::VERSION_OPTION, self::VERSION, false);
        }
    }

    public static function on_activate() {
        $instance = self::instance();
        $instance->register_rewrite();
        flush_rewrite_rules();
        update_option(self::VERSION_OPTION, self::VERSION, false);
    }
    public static function on_deactivate() { flush_rewrite_rules(); }

    public function add_query_var($vars) {
        $vars[] = self::QUERY_VAR;
        $vars[] = self::BLOG_QUERY_VAR;
        $vars[] = self::SITEMAP_QUERY_VAR;
        $vars[] = self::SITEMAP_FILE_QUERY_VAR;
        $vars[] = self::CATEGORY_INDEX_QUERY_VAR;
        $vars[] = self::CATEGORY_SLUG_QUERY_VAR;
        $vars[] = self::CATEGORY_PAGE_QUERY_VAR;
        return $vars;
    }

    public function register_rewrite() {
        add_rewrite_rule('^p/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_tag('%' . self::QUERY_VAR . '%', '([^&]+)');
        add_rewrite_rule('^b/([^/]+)/?$', 'index.php?' . self::BLOG_QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_tag('%' . self::BLOG_QUERY_VAR . '%', '([^&]+)');
        add_rewrite_rule('^p-cat/?$', 'index.php?' . self::CATEGORY_INDEX_QUERY_VAR . '=1', 'top');
        add_rewrite_rule('^p-cat/page/([0-9]+)/?$', 'index.php?' . self::CATEGORY_INDEX_QUERY_VAR . '=1&' . self::CATEGORY_PAGE_QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_rule('^p-cat/([^/]+)/?$', 'index.php?' . self::CATEGORY_SLUG_QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_rule('^p-cat/([^/]+)/page/([0-9]+)/?$', 'index.php?' . self::CATEGORY_SLUG_QUERY_VAR . '=$matches[1]&' . self::CATEGORY_PAGE_QUERY_VAR . '=$matches[2]', 'top');
        add_rewrite_tag('%' . self::CATEGORY_INDEX_QUERY_VAR . '%', '([0-9]+)');
        add_rewrite_tag('%' . self::CATEGORY_SLUG_QUERY_VAR . '%', '([^&]+)');
        add_rewrite_tag('%' . self::CATEGORY_PAGE_QUERY_VAR . '%', '([0-9]+)');
        if (!$this->uses_wpseo_sitemaps()) {
            add_rewrite_rule('^sitemap_index\\.xml$', 'index.php?' . self::SITEMAP_QUERY_VAR . '=index', 'top');
            add_rewrite_rule('^sitemap-index\\.xml$', 'index.php?' . self::SITEMAP_QUERY_VAR . '=index', 'top');
        }
        add_rewrite_rule('^sitemaps/([^/]+\\.xml)$', 'index.php?' . self::SITEMAP_QUERY_VAR . '=file&' . self::SITEMAP_FILE_QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_tag('%' . self::SITEMAP_QUERY_VAR . '%', '([^&]+)');
        add_rewrite_tag('%' . self::SITEMAP_FILE_QUERY_VAR . '%', '([^&]+)');
        add_rewrite_rule('^p-cat/?$', 'index.php?' . self::CATEGORY_INDEX_QUERY_VAR . '=1', 'top');
        add_rewrite_rule('^p-cat/page/([0-9]+)/?$', 'index.php?' . self::CATEGORY_INDEX_QUERY_VAR . '=1&' . self::CATEGORY_PAGE_QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_rule('^p-cat/([^/]+)/page/([0-9]+)/?$', 'index.php?' . self::CATEGORY_SLUG_QUERY_VAR . '=$matches[1]&' . self::CATEGORY_PAGE_QUERY_VAR . '=$matches[2]', 'top');
        add_rewrite_rule('^p-cat/([^/]+)/?$', 'index.php?' . self::CATEGORY_SLUG_QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_tag('%' . self::CATEGORY_INDEX_QUERY_VAR . '%', '([0-9]+)');
        add_rewrite_tag('%' . self::CATEGORY_SLUG_QUERY_VAR . '%', '([^&]+)');
        add_rewrite_tag('%' . self::CATEGORY_PAGE_QUERY_VAR . '%', '([0-9]+)');
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
            $index_files = [];
            $this->refresh_sitemap_index($storage, $err, $index_files);
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
        wp_register_script('vpp-scripts', plugins_url('assets/vpp.js', __FILE__), [], self::VERSION, true);
        $is_product_page = $this->current_vpp_slug();
        $is_blog_page = $this->current_blog_slug();
        if ($is_product_page || $is_blog_page || $this->is_category_request()) {
            wp_enqueue_style('vpp-styles');
            $inline = $this->load_css_contents();
            if ($inline !== '') {
                wp_add_inline_style('vpp-styles', $inline);
            }
            if ($is_product_page) {
                wp_enqueue_script('vpp-scripts');
            }
        }
    }

    public function filter_body_class($classes) {
        if ($this->current_vpp_slug()) {
            $classes[] = 'vpp-body';
            $classes[] = 'vpp-product-page';
        } elseif ($this->current_blog_slug()) {
            $classes[] = 'vpp-body';
            $classes[] = 'vpp-blog-page';
        } elseif ($this->is_category_request()) {
            $classes[] = 'vpp-body';
            $classes[] = 'vpp-category-page';
            $err = null;
            $context = $this->ensure_category_context($err);
            if ($context) {
                $classes[] = $context['type'] === 'archive' ? 'vpp-category-archive' : 'vpp-category-index';
            }
        }
        return $classes;
    }

    /* ========= SETTINGS ========= */

    public function admin_menu() {
        add_menu_page('Virtual Products', 'Virtual Products', 'manage_options', 'vpp_settings', [$this, 'render_connectivity_page'], 'dashicons-archive', 58);
        add_submenu_page('vpp_settings', 'Connectivity', 'Connectivity', 'manage_options', 'vpp_settings', [$this, 'render_connectivity_page']);
        add_submenu_page('vpp_settings', 'Publishing', 'Publishing', 'manage_options', 'vpp_publishing', [$this, 'render_publishing_page']);
        add_submenu_page('vpp_settings', 'Edit Product', 'Edit Product', 'manage_options', 'vpp_edit', [$this, 'render_edit_page']);
        add_submenu_page('vpp_settings', 'Blog Posts', 'Blog Posts', 'manage_options', 'vpp_blogs', [$this, 'render_blog_page']);
        add_submenu_page('vpp_settings', 'Categories', 'Categories', 'manage_options', 'vpp_categories', [$this, 'render_categories_page']);
        add_submenu_page('vpp_settings', 'VPP Status', 'VPP Status', 'manage_options', 'vpp_status', [$this, 'render_status_page']);
    }

    public function register_settings() {
        register_setting(self::OPT_KEY, self::OPT_KEY, [$this, 'sanitize_settings']);
    }

    public function get_settings() {
        $defaults = [
            'tidb' => ['host'=>'','port'=>'4000','database'=>'','user'=>'','pass'=>'','table'=>'products','posts_table'=>'posts','ssl_ca'=>'/etc/ssl/certs/ca-certificates.crt'],
            'algolia' => ['app_id'=>'','admin_key'=>'','index'=>''],
            'cloudflare' => [
                'api_token' => '',
                'zone_id' => '',
                'site_base' => '',
                'enable_purge_on_publish' => 1,
                'include_product_urls' => 0,
            ],
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
            $out['tidb']['posts_table'] = sanitize_text_field($input['tidb']['posts_table'] ?? 'posts');
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
            $out['cloudflare']['enable_purge_on_publish'] = !empty($input['cloudflare']['enable_purge_on_publish']) ? 1 : 0;
            $out['cloudflare']['include_product_urls'] = !empty($input['cloudflare']['include_product_urls']) ? 1 : 0;
        }
        return $out;
    }

    public function render_connectivity_page() {
        if (!current_user_can('manage_options')) return;
        $s = $this->get_settings();
        $admin_post = admin_url('admin-post.php');
        $cf_zone = trim($s['cloudflare']['zone_id']);
        $cf_token = trim($s['cloudflare']['api_token']);
        $cf_ready = ($cf_zone !== '' && $cf_token !== '');
        $cf_status_class = $cf_ready ? 'vpp-status-ok' : 'vpp-status-missing';
        $cf_status_label = $cf_ready ? 'Configured' : 'Not configured';
        $cf_auto = !empty($s['cloudflare']['enable_purge_on_publish']);
        $cf_include = !empty($s['cloudflare']['include_product_urls']);
        $last_batch = $this->get_cf_last_batch();
        $last_batch_sitemaps = is_array($last_batch) && !empty($last_batch['sitemap_files']) ? count($last_batch['sitemap_files']) : 0;
        $last_batch_products = is_array($last_batch) && !empty($last_batch['product_slugs']) ? count($last_batch['product_slugs']) : 0;
        $last_batch_time = is_array($last_batch) && !empty($last_batch['timestamp']) ? (int)$last_batch['timestamp'] : 0;
        $last_batch_label = $last_batch_time ? sprintf('%s (UTC)', gmdate('Y-m-d H:i', $last_batch_time)) : '—';
        $cf_disabled_attr = $cf_ready ? '' : ' disabled="disabled"';
        ?>
        <div class="wrap vpp-connectivity-wrap">
            <h1>Connectivity</h1>
            <p class="description">Configure Virtual Product Pages integrations for database sync, search indexing, and cache control.</p>
            <style>
                .vpp-connectivity-wrap .vpp-connectivity-groups { display:flex; flex-direction:column; gap:24px; max-width:980px; }
                .vpp-connectivity-wrap .vpp-connection-group { border-radius:14px; border:1px solid #dcdcde; padding:24px 28px; box-shadow:inset 0 1px 0 rgba(255,255,255,0.6); display:flex; flex-direction:column; gap:16px; }
                .vpp-connectivity-wrap .vpp-connection-group--tidb { background:#f6f7f7; }
                .vpp-connectivity-wrap .vpp-connection-group--algolia { background:#f3f5f6; }
                .vpp-connectivity-wrap .vpp-connection-group--cloudflare { background:#eef2f7; }
                .vpp-connectivity-wrap .vpp-connection-group h2 { margin:0; font-size:1.35rem; }
                .vpp-connectivity-wrap .vpp-connection-description { margin:0; color:#50575e; max-width:720px; }
                .vpp-connectivity-wrap .vpp-connection-form .form-table { margin:0; }
                .vpp-connectivity-wrap .vpp-connection-form .form-table th { width:220px; }
                .vpp-connectivity-wrap .vpp-connection-actions { display:flex; flex-wrap:wrap; gap:8px; }
                .vpp-connectivity-wrap .vpp-status-badge { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:4px 12px; font-size:13px; font-weight:600; }
                .vpp-connectivity-wrap .vpp-status-ok { background:rgba(16,185,129,0.12); color:#047857; border:1px solid rgba(5,150,105,0.2); }
                .vpp-connectivity-wrap .vpp-status-missing { background:rgba(244,114,182,0.12); color:#be123c; border:1px solid rgba(190,18,60,0.2); }
                .vpp-connectivity-wrap .vpp-cloudflare-meta { font-size:13px; color:#3c434a; margin:0; }
                .vpp-connectivity-wrap details.vpp-advanced-toggle { margin-top:4px; }
                .vpp-connectivity-wrap details.vpp-advanced-toggle summary { cursor:pointer; font-weight:600; }
                .vpp-connectivity-wrap .vpp-advanced-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
                .vpp-connectivity-wrap .vpp-last-batch { font-size:12px; color:#50575e; margin:4px 0 0; }
                .vpp-connectivity-wrap .vpp-cloudflare-note { font-size:12px; color:#8c8f94; margin:0; }
                .vpp-connectivity-wrap .vpp-cloudflare-fields .regular-text { width:100%; max-width:340px; }
                .vpp-connectivity-wrap .vpp-cloudflare-checkboxes label { display:block; margin-bottom:6px; }
                .vpp-connectivity-wrap .vpp-cloudflare-buttons form { margin:0; }
                .vpp-connectivity-wrap .vpp-cloudflare-buttons .button-secondary[disabled],
                .vpp-connectivity-wrap .vpp-cloudflare-buttons .button[disabled] { opacity:0.6; cursor:not-allowed; }
                @media (max-width:782px) {
                    .vpp-connectivity-wrap .vpp-connection-group { padding:20px; }
                    .vpp-connectivity-wrap .vpp-connection-form .form-table th { width:auto; }
                }
            </style>
            <div class="vpp-connectivity-groups">
                <section class="vpp-connection-group vpp-connection-group--tidb">
                    <div>
                        <h2>TiDB (Database)</h2>
                        <p class="vpp-connection-description">Primary source of product data used to render the Virtual Product Pages.</p>
                    </div>
                    <form class="vpp-connection-form" method="post" action="<?php echo esc_url($admin_post); ?>">
                        <input type="hidden" name="action" value="vpp_save_settings" />
                        <?php wp_nonce_field(self::NONCE_KEY); ?>
                        <table class="form-table"><tbody>
                            <tr><th scope="row">TiDB Host</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][host]" value="<?php echo esc_attr($s['tidb']['host']); ?>" class="regular-text" autocomplete="off"></td></tr>
                            <tr><th scope="row">TiDB Port</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][port]" value="<?php echo esc_attr($s['tidb']['port']); ?>" class="small-text" autocomplete="off"></td></tr>
                            <tr><th scope="row">TiDB Database</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][database]" value="<?php echo esc_attr($s['tidb']['database']); ?>" class="regular-text" autocomplete="off"></td></tr>
                            <tr><th scope="row">TiDB User</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][user]" value="<?php echo esc_attr($s['tidb']['user']); ?>" class="regular-text" autocomplete="off"></td></tr>
                            <tr><th scope="row">TiDB Password</th><td><input type="password" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][pass]" value="<?php echo esc_attr($s['tidb']['pass']); ?>" class="regular-text" autocomplete="new-password"></td></tr>
                        <tr><th scope="row">TiDB Table</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][table]" value="<?php echo esc_attr($s['tidb']['table']); ?>" class="regular-text"><p class="description">Expected columns: id, slug, title_h1, brand, model, sku, images_json, schema_json, desc_html, short_summary, meta_description, cta_lead_url, cta_stripe_url, cta_affiliate_url, cta_paypal_url, is_published, last_tidb_update_at</p></td></tr>
                        <tr><th scope="row">Blog Table</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][posts_table]" value="<?php echo esc_attr($s['tidb']['posts_table'] ?? 'posts'); ?>" class="regular-text"><p class="description">Expected columns: id, slug, title_h1, short_summary, content_html, cover_image_url, category, category_slug, product_slugs_json, availability, price, seo_title, seo_description, canonical_url, cta_* fields, is_published, published_at, last_tidb_update_at</p></td></tr>
                            <tr><th scope="row">SSL CA Path</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[tidb][ssl_ca]" value="<?php echo esc_attr($s['tidb']['ssl_ca']); ?>" class="regular-text"></td></tr>
                        </tbody></table>
                        <div class="vpp-connection-actions">
                            <?php submit_button('Save TiDB Settings', 'primary', 'submit', false); ?>
                        </div>
                    </form>
                    <div class="vpp-connection-actions">
                        <form method="post" action="<?php echo esc_url($admin_post); ?>">
                            <input type="hidden" name="action" value="vpp_test_tidb" />
                            <?php wp_nonce_field(self::NONCE_KEY); ?>
                            <?php submit_button('Test TiDB Connection', 'secondary', 'submit', false); ?>
                        </form>
                    </div>
                </section>

                <section class="vpp-connection-group vpp-connection-group--algolia">
                    <div>
                        <h2>Algolia (Search)</h2>
                        <p class="vpp-connection-description">Send indexed product snapshots to Algolia for instant search results.</p>
                    </div>
                    <form class="vpp-connection-form" method="post" action="<?php echo esc_url($admin_post); ?>">
                        <input type="hidden" name="action" value="vpp_save_settings" />
                        <?php wp_nonce_field(self::NONCE_KEY); ?>
                        <table class="form-table"><tbody>
                            <tr><th scope="row">Algolia App ID</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[algolia][app_id]" value="<?php echo esc_attr($s['algolia']['app_id']); ?>" class="regular-text" autocomplete="off"></td></tr>
                            <tr><th scope="row">Algolia Admin API Key</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[algolia][admin_key]" value="<?php echo esc_attr($s['algolia']['admin_key']); ?>" class="regular-text" autocomplete="off"></td></tr>
                            <tr><th scope="row">Algolia Index Name</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[algolia][index]" value="<?php echo esc_attr($s['algolia']['index']); ?>" class="regular-text" autocomplete="off"></td></tr>
                        </tbody></table>
                        <div class="vpp-connection-actions">
                            <?php submit_button('Save Algolia Settings', 'primary', 'submit', false); ?>
                        </div>
                    </form>
                    <div class="vpp-connection-actions">
                        <form method="post" action="<?php echo esc_url($admin_post); ?>">
                            <input type="hidden" name="action" value="vpp_test_algolia" />
                            <?php wp_nonce_field(self::NONCE_KEY); ?>
                            <?php submit_button('Test Algolia Connection', 'secondary', 'submit', false); ?>
                        </form>
                    </div>
                </section>

                <section class="vpp-connection-group vpp-connection-group--cloudflare">
                    <div>
                        <h2>Cloudflare (CDN &amp; Cache)</h2>
                        <p class="vpp-connection-description">Purge cached sitemaps and newly published product URLs directly from Cloudflare.</p>
                    </div>
                    <p class="vpp-cloudflare-meta">Status: <span class="vpp-status-badge <?php echo esc_attr($cf_status_class); ?>"><?php echo esc_html($cf_status_label); ?></span></p>
                    <?php if (!$cf_ready): ?>
                        <p class="vpp-cloudflare-note">Enter a Zone ID and API token (with <code>Zone &rarr; Cache Purge</code> scope) to enable Cloudflare actions.</p>
                    <?php endif; ?>
                    <form class="vpp-connection-form" method="post" action="<?php echo esc_url($admin_post); ?>">
                        <input type="hidden" name="action" value="vpp_save_settings" />
                        <?php wp_nonce_field(self::NONCE_KEY); ?>
                        <div class="vpp-cloudflare-fields">
                            <table class="form-table"><tbody>
                                <tr><th scope="row">Zone ID</th><td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[cloudflare][zone_id]" value="<?php echo esc_attr($s['cloudflare']['zone_id']); ?>" class="regular-text" autocomplete="off"></td></tr>
                                <tr><th scope="row">API Token</th><td><div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;"><input type="password" id="vpp-cf-api-token" name="<?php echo esc_attr(self::OPT_KEY); ?>[cloudflare][api_token]" value="<?php echo esc_attr($s['cloudflare']['api_token']); ?>" class="regular-text" autocomplete="new-password"><button type="button" class="button button-secondary vpp-toggle-token" data-target="vpp-cf-api-token">Reveal</button></div><p class="description">Token scope: <strong>Zone &rarr; Cache Purge</strong> for the selected zone.</p></td></tr>
                                <tr><th scope="row">Automation</th><td class="vpp-cloudflare-checkboxes"><label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[cloudflare][enable_purge_on_publish]" value="1" <?php checked($cf_auto); ?> /> Purge on publish / rebuild completion</label><label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[cloudflare][include_product_urls]" value="1" <?php checked($cf_include); ?> /> Include product URLs from the last batch</label></td></tr>
                            </tbody></table>
                        </div>
                        <div class="vpp-connection-actions">
                            <?php submit_button('Save Cloudflare Settings', 'primary', 'submit', false); ?>
                        </div>
                    </form>
                    <div class="vpp-cloudflare-buttons">
                        <div class="vpp-connection-actions">
                            <form method="post" action="<?php echo esc_url($admin_post); ?>">
                                <input type="hidden" name="action" value="vpp_cf_test_connection" />
                                <?php wp_nonce_field(self::NONCE_KEY); ?>
                                <button type="submit" class="button button-secondary"<?php echo $cf_disabled_attr; ?>>Test Cloudflare Connection</button>
                            </form>
                            <form method="post" action="<?php echo esc_url($admin_post); ?>">
                                <input type="hidden" name="action" value="vpp_cf_test_purge" />
                                <?php wp_nonce_field(self::NONCE_KEY); ?>
                                <button type="submit" class="button button-secondary"<?php echo $cf_disabled_attr; ?>>Test CF Purge (Sitemaps)</button>
                            </form>
                            <form method="post" action="<?php echo esc_url($admin_post); ?>">
                                <input type="hidden" name="action" value="vpp_cf_purge_sitemaps" />
                                <?php wp_nonce_field(self::NONCE_KEY); ?>
                                <button type="submit" class="button"<?php echo $cf_disabled_attr; ?>>Purge Sitemaps Now</button>
                            </form>
                            <form method="post" action="<?php echo esc_url($admin_post); ?>">
                                <input type="hidden" name="action" value="vpp_cf_purge_last_batch" />
                                <?php wp_nonce_field(self::NONCE_KEY); ?>
                                <button type="submit" class="button"<?php echo $cf_disabled_attr . ($last_batch_sitemaps === 0 && $last_batch_products === 0 ? ' disabled="disabled"' : ''); ?>>Purge Last Batch URLs</button>
                            </form>
                        </div>
                        <p class="vpp-last-batch">Last batch: <?php echo esc_html($last_batch_label); ?> — <?php echo esc_html($last_batch_sitemaps); ?> sitemap file(s), <?php echo esc_html($last_batch_products); ?> product URL(s).</p>
                        <details class="vpp-advanced-toggle">
                            <summary>Advanced</summary>
                            <div class="vpp-advanced-actions">
                                <form method="post" action="<?php echo esc_url($admin_post); ?>">
                                    <input type="hidden" name="action" value="vpp_cf_purge_everything" />
                                    <?php wp_nonce_field(self::NONCE_KEY); ?>
                                    <label style="display:flex; align-items:center; gap:8px;">
                                        <input type="text" name="vpp_cf_confirm" placeholder="Type PURGE" style="max-width:120px;"<?php echo $cf_disabled_attr; ?> />
                                        <button type="submit" class="button button-secondary"<?php echo $cf_disabled_attr; ?> onclick="return (this.previousElementSibling && this.previousElementSibling.value === 'PURGE') ? confirm('This will purge everything from Cloudflare cache. Continue?') : (alert('Enter PURGE to confirm.'), false);">Purge Everything</button>
                                    </label>
                                </form>
                            </div>
                        </details>
                    </div>
                </section>

                <section class="vpp-connection-group" style="background:#fff;">
                    <div>
                        <h2>Push Actions</h2>
                        <p class="vpp-connection-description">Manually publish products to VPP or Algolia by ID or slug.</p>
                    </div>
                    <form method="post" action="<?php echo esc_url($admin_post); ?>" style="margin-bottom:1rem;">
                        <input type="hidden" name="action" value="vpp_push_publish" />
                        <?php wp_nonce_field(self::NONCE_KEY); ?>
                        <textarea name="vpp_ids" rows="2" style="width:100%;" placeholder="e.g. 60001, siemens-s7-1200-60001"></textarea>
                        <?php submit_button('Push to VPP (Publish)'); ?>
                    </form>
                    <form method="post" action="<?php echo esc_url($admin_post); ?>">
                        <input type="hidden" name="action" value="vpp_push_algolia" />
                        <?php wp_nonce_field(self::NONCE_KEY); ?>
                        <textarea name="vpp_ids" rows="2" style="width:100%;" placeholder="e.g. 60001, siemens-s7-1200-60001"></textarea>
                        <?php submit_button('Push to Algolia'); ?>
                    </form>
                </section>

                <section class="vpp-connection-group" style="background:#fff;">
                    <div>
                        <h2>SEO Tools</h2>
                        <p class="vpp-connection-description">Regenerate XML sitemap files for published products.</p>
                    </div>
                    <form method="post" action="<?php echo esc_url($admin_post); ?>">
                        <input type="hidden" name="action" value="vpp_rebuild_sitemaps" />
                        <?php wp_nonce_field(self::NONCE_KEY); ?>
                        <?php submit_button('Rebuild Sitemap'); ?>
                    </form>
                </section>
            </div>
        </div>
        <script>
        (function(){
            document.addEventListener('DOMContentLoaded', function(){
                document.querySelectorAll('.vpp-toggle-token').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var targetId = btn.getAttribute('data-target');
                        var field = targetId ? document.getElementById(targetId) : null;
                        if (!field) { return; }
                        if (field.type === 'password') {
                            field.type = 'text';
                            btn.textContent = 'Hide';
                        } else {
                            field.type = 'password';
                            btn.textContent = 'Reveal';
                        }
                    });
                });
            });
        })();
        </script>
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

    private function format_items_label($count) {
        $count = (int)$count;
        return sprintf(_n('%d item', '%d items', $count, 'virtual-product-pages'), $count);
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
        $select[] = isset($cols['availability']) ? '`availability`' : "'' AS availability";
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
        $this->cf_reset_recent();
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
            if (!empty($prepared)) {
                $this->cf_record_recent_products(array_column($prepared, 'slug'));
            }
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

        $cf_context = $this->cf_get_recent_context();
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
            'cf_context' => $cf_context,
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

    private function determine_sitemap_type($path) {
        $name = basename((string)$path);
        if (preg_match('/(^|-)blog-/', $name)) {
            return 'blog';
        }
        if (preg_match('/(^|-)cat(?:egory)?-/', $name) || strpos($name, 'category') === 0) {
            return 'category';
        }
        return 'product';
    }

    private function build_sitemap_xml(array $entries, $type) {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $entry) {
            $slug = sanitize_title($entry['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $lastmod = $entry['lastmod'] ?? gmdate('c');
            switch ($type) {
                case 'blog':
                    if (!empty($entry['loc'])) {
                        $loc = esc_url($entry['loc']);
                    } else {
                        $loc = home_url('/b/' . $slug);
                    }
                    break;
                case 'category':
                    $loc = home_url('/p-cat/' . $slug . '/');
                    break;
                default:
                    $loc = home_url('/p/' . $slug . '/');
                    break;
            }
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</loc>' . "\n";
            $xml .= '    <lastmod>' . htmlspecialchars($lastmod, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</lastmod>' . "\n";
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';
        if (strlen($xml) > 50 * 1024 * 1024) {
            return false;
        }
        return $xml;
    }

    private function write_sitemap_file($path, array $entries) {
        $type = $this->determine_sitemap_type($path);
        $xml = $this->build_sitemap_xml($entries, $type);
        if ($xml === false) {
            return false;
        }
        return @file_put_contents($path, $xml) !== false;
    }

    private function refresh_sitemap_index(array $storage, &$err = null, ?array &$touched_files = null) {
        $dir = $storage['dir'];
        $base_url = $storage['url'];
        $files = glob($dir . '*.xml') ?: [];
        $meta = $this->get_sitemap_meta();
        $needs_rewrite = isset($meta['base_url']) ? ($meta['base_url'] !== $base_url) : true;
        if ($touched_files === null) {
            $touched_files = [];
        }
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
                $touched_files[] = $name;
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
        $touched_files[] = 'sitemap_index.xml';
        $touched_files[] = 'sitemap-index.xml';
        $touched_files[] = 'vpp-index.xml';
        if (!empty($storage['legacy_dir'])) {
            if (wp_mkdir_p($storage['legacy_dir'])) {
                @file_put_contents($storage['legacy_dir'] . 'sitemap_index.xml', $xml);
                @file_put_contents($storage['legacy_dir'] . 'sitemap-index.xml', $xml);
                @file_put_contents($storage['legacy_dir'] . 'vpp-index.xml', $xml);
                $touched_files[] = 'sitemap_index.xml';
                $touched_files[] = 'sitemap-index.xml';
                $touched_files[] = 'vpp-index.xml';
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
        $purge_files = [];
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
                $purge_files[] = basename($file);
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
                $purge_files[] = basename($file);
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
                $purge_files[] = $filename;
            }
        }
        $index_files = [];
        $index_url = $this->refresh_sitemap_index($storage, $err, $index_files);
        if ($index_url === false) {
            return false;
        }
        $purge_targets = array_merge($purge_files, $index_files);
        if (!empty($purge_targets)) {
            $this->purge_sitemap_cache($storage, $purge_targets);
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
        if (isset($row['availability']) && $row['availability'] !== '') {
            $record['availability'] = trim((string)$row['availability']);
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

    private function get_blog_table() {
        $s = $this->get_settings();
        $table = isset($s['tidb']['posts_table']) ? $s['tidb']['posts_table'] : 'posts';
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
        return $table === '' ? 'posts' : $table;
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

    private function build_blog_select_columns($mysqli, $table) {
        $existing = array_flip($this->get_table_columns($mysqli, $table));
        $optional = [
            'short_summary' => "''",
            'content_html' => "''",
            'cover_image_url' => "''",
            'category' => "''",
            'category_slug' => "''",
            'product_slugs_json' => "''",
            'availability' => "''",
            'price' => "''",
            'seo_title' => "''",
            'seo_description' => "''",
            'canonical_url' => "''",
            'meta_description' => "''",
            'cta_lead_label' => "''",
            'cta_lead_url' => "''",
            'cta_stripe_label' => "''",
            'cta_stripe_url' => "''",
            'cta_affiliate_label' => "''",
            'cta_affiliate_url' => "''",
            'cta_paypal_label' => "''",
            'cta_paypal_url' => "''",
            'is_published' => '0',
            'published_at' => 'NULL',
            'last_tidb_update_at' => 'NULL',
        ];
        $select = 'id, slug, title_h1';
        foreach ($optional as $column => $default) {
            if (isset($existing[$column])) {
                $select .= ", `{$column}`";
            } else {
                $select .= ", {$default} AS {$column}";
            }
        }
        return $select;
    }

    private function get_category_column($mysqli, $table) {
        static $cache = [];
        $table_clean = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
        if ($table_clean === '') {
            return '';
        }
        if (isset($cache[$table_clean])) {
            return $cache[$table_clean];
        }
        $columns = $this->get_table_columns($mysqli, $table_clean);
        foreach (['category', 'Category', 'CATEGORY'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $cache[$table_clean] = $candidate;
            }
        }
        return $cache[$table_clean] = '';
    }

    private function build_category_select_clause($mysqli, $table) {
        $col = $this->get_category_column($mysqli, $table);
        if ($col === '') {
            return ", '' AS category";
        }
        $col_esc = $mysqli->real_escape_string($col);
        return ", `{$col_esc}` AS category";
    }

    private function slugify_category_value($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return 'category';
        }
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        } elseif (class_exists('Transliterator')) {
            $trans = @Transliterator::create('Any-Latin; Latin-ASCII');
            if ($trans instanceof Transliterator) {
                $value = $trans->transliterate($value);
            }
        } elseif (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }
        $value = strtolower((string)$value);
        $value = preg_replace('/[_\s]+/', '-', $value);
        $value = preg_replace('/[^a-z0-9\-]/', '', $value);
        $value = preg_replace('/-+/', '-', $value);
        $value = trim($value, '-');
        if ($value === '') {
            $value = 'category';
        }
        if (in_array($value, self::CATEGORY_RESERVED_SLUGS, true)) {
            $value .= '-cat';
        }
        return $value;
    }

    private function assign_category_slugs(array $rows) {
        $groups = [];
        foreach ($rows as $row) {
            $name = $row['name'];
            $base = $this->slugify_category_value($name);
            if (!isset($groups[$base])) {
                $groups[$base] = [];
            }
            $groups[$base][] = $row;
        }

        $entries = [];
        $map = [];
        foreach ($groups as $base => $items) {
            usort($items, function($a, $b) {
                $lenA = function_exists('mb_strlen') ? mb_strlen($a['name']) : strlen($a['name']);
                $lenB = function_exists('mb_strlen') ? mb_strlen($b['name']) : strlen($b['name']);
                if ($lenA === $lenB) {
                    return strcasecmp($a['name'], $b['name']);
                }
                return $lenB <=> $lenA;
            });
            $suffix = 2;
            foreach ($items as $index => $item) {
                $slug = $base;
                if ($index > 0) {
                    $slug = $base . '-' . $suffix++;
                    $this->log_error('category_slug', sprintf('Category slug collision for "%s" (base %s); assigned %s.', $item['name'], $base, $slug));
                }
                $entries[] = [
                    'slug' => $slug,
                    'name' => $item['name'],
                    'count' => (int)($item['count'] ?? 0),
                    'last_updated' => $item['last_updated'] ?? '',
                ];
                $map[$slug] = $item['name'];
            }
        }

        usort($entries, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return ['list' => $entries, 'map' => $map];
    }

    private function get_category_transient_key($type, $slug = '', $per_page = 0) {
        if ($type === 'index') {
            return 'vpp_cat_index';
        }
        if ($type === 'archive') {
            $slug = sanitize_title($slug);
            $per_page = (int)$per_page;
            return 'vpp_cat_archive_' . md5($slug . '|' . $per_page);
        }
        return '';
    }

    private function get_category_per_page() {
        $per_page = (int)apply_filters('vpp_category_per_page', self::CATEGORY_PER_PAGE_DEFAULT);
        if ($per_page < 1) {
            $per_page = self::CATEGORY_PER_PAGE_DEFAULT;
        }
        if ($per_page > self::CATEGORY_PER_PAGE_MAX) {
            $per_page = self::CATEGORY_PER_PAGE_MAX;
        }
        return $per_page;
    }

    private function get_category_cache($force_refresh = false, &$err = null) {
        if ($force_refresh) {
            delete_transient($this->get_category_transient_key('index'));
            $this->category_cache = null;
            $this->category_slug_map = null;
        }
        if (is_array($this->category_cache)) {
            return $this->category_cache;
        }
        $cache = get_transient($this->get_category_transient_key('index'));
        if (is_array($cache) && isset($cache['categories'], $cache['map'])) {
            $this->category_cache = $cache;
            $this->category_slug_map = $cache['map'];
            return $cache;
        }

        $mysqli = $this->db_connect($err);
        if (!$mysqli) {
            return null;
        }

        $settings = $this->get_settings();
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $settings['tidb']['table']);
        if ($table === '') {
            @$mysqli->close();
            $empty = ['categories' => [], 'map' => [], 'column' => '', 'generated' => time()];
            $this->category_cache = $empty;
            $this->category_slug_map = [];
            set_transient($this->get_category_transient_key('index'), $empty, self::CATEGORY_CACHE_TTL);
            return $empty;
        }

        $column = $this->get_category_column($mysqli, $table);
        if ($column === '') {
            @$mysqli->close();
            $empty = ['categories' => [], 'map' => [], 'column' => '', 'generated' => time()];
            $this->category_cache = $empty;
            $this->category_slug_map = [];
            set_transient($this->get_category_transient_key('index'), $empty, self::CATEGORY_CACHE_TTL);
            return $empty;
        }

        $table_esc = $mysqli->real_escape_string($table);
        $col_esc = $mysqli->real_escape_string($column);
        $sql = "SELECT `{$col_esc}` AS category_name, COUNT(*) AS items, MAX(last_tidb_update_at) AS last_updated FROM `{$table_esc}` WHERE is_published = 1 AND `{$col_esc}` IS NOT NULL AND TRIM(`{$col_esc}`) <> '' GROUP BY `{$col_esc}`";
        $res = @$mysqli->query($sql);
        if (!$res) {
            $err = 'Failed to load categories: ' . $mysqli->error;
            $this->log_error('db_query', $err);
            @$mysqli->close();
            return null;
        }

        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $name = isset($row['category_name']) ? trim((string)$row['category_name']) : '';
            if ($name === '') {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'count' => isset($row['items']) ? (int)$row['items'] : 0,
                'last_updated' => $row['last_updated'] ?? '',
            ];
        }
        $res->free();
        @$mysqli->close();

        $assigned = $this->assign_category_slugs($rows);
        $cache = [
            'categories' => $assigned['list'],
            'map' => $assigned['map'],
            'column' => $column,
            'generated' => time(),
        ];
        $this->category_cache = $cache;
        $this->category_slug_map = $assigned['map'];
        set_transient($this->get_category_transient_key('index'), $cache, self::CATEGORY_CACHE_TTL);
        return $cache;
    }

    private function get_category_index_data($page, $per_page, $force_refresh = false, &$err = null) {
        $cache = $this->get_category_cache($force_refresh, $err);
        if ($cache === null) {
            return null;
        }
        $categories = isset($cache['categories']) && is_array($cache['categories']) ? $cache['categories'] : [];
        $total = count($categories);
        $per_page = max(1, (int)$per_page);
        $total_pages = max(1, (int)ceil($total / $per_page));
        if (($total > 0 && $page > $total_pages) || ($total === 0 && $page > 1)) {
            return null;
        }
        $offset = max(0, ($page - 1) * $per_page);
        $items = array_slice($categories, $offset, $per_page);
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $canonical = $page > 1 ? trailingslashit(home_url('/p-cat/page/' . $page)) : trailingslashit(home_url('/p-cat/'));
        $meta_description = $total > 0
            ? sprintf(__('Explore %d product categories.', 'virtual-product-pages'), $total)
            : __('No categories are available yet.', 'virtual-product-pages');
        $title = sprintf(__('Categories | %s', 'virtual-product-pages'), $site);

        return [
            'type' => 'index',
            'items' => $items,
            'total' => $total,
            'page' => max(1, (int)$page),
            'per_page' => $per_page,
            'total_pages' => $total_pages,
            'offset' => $offset,
            'canonical' => $canonical,
            'meta_description' => $meta_description,
            'title' => $title,
        ];
    }

    private function get_category_archive_data($slug, $page, $per_page, &$err = null, $force_refresh = false) {
        $slug = sanitize_title($slug);
        if ($slug === '') {
            return null;
        }
        $cache = $this->get_category_cache($force_refresh, $err);
        if ($cache === null) {
            return null;
        }
        $map = isset($cache['map']) && is_array($cache['map']) ? $cache['map'] : [];
        if (!isset($map[$slug])) {
            return null;
        }
        $category_name = $map[$slug];

        $per_page = max(1, (int)$per_page);
        $transient_key = $this->get_category_transient_key('archive', $slug, $per_page);
        if ($force_refresh) {
            delete_transient($transient_key);
        }
        $cached = get_transient($transient_key);
        if (is_array($cached) && isset($cached['pages'][$page])) {
            return $cached['pages'][$page];
        }

        $mysqli = $this->db_connect($err);
        if (!$mysqli) {
            return null;
        }

        $settings = $this->get_settings();
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $settings['tidb']['table']);
        if ($table === '') {
            @$mysqli->close();
            return null;
        }

        $column = $this->get_category_column($mysqli, $table);
        if ($column === '') {
            @$mysqli->close();
            return null;
        }

        $table_esc = $mysqli->real_escape_string($table);
        $col_esc = $mysqli->real_escape_string($column);
        $count_sql = "SELECT COUNT(*) AS total, MAX(last_tidb_update_at) AS last_updated FROM `{$table_esc}` WHERE is_published = 1 AND `{$col_esc}` = ?";
        $stmt = $mysqli->prepare($count_sql);
        if (!$stmt) {
            $err = 'DB prepare failed: ' . $mysqli->error;
            $this->log_error('db_query', $err);
            @$mysqli->close();
            return null;
        }
        $stmt->bind_param('s', $category_name);
        $stmt->execute();
        $res = $stmt->get_result();
        $total_row = $res ? $res->fetch_assoc() : null;
        $res && $res->free();
        $stmt->close();
        $total = $total_row && isset($total_row['total']) ? (int)$total_row['total'] : 0;
        $last_updated = $total_row['last_updated'] ?? '';
        $total_pages = max(1, (int)ceil($total / $per_page));
        if ($total > 0 && $page > $total_pages) {
            @$mysqli->close();
            return null;
        }
        if ($total === 0 && $page > 1) {
            @$mysqli->close();
            return null;
        }

        $offset = max(0, ($page - 1) * $per_page);
        $item_sql = "SELECT slug, title_h1, brand, model, short_summary, images_json, last_tidb_update_at FROM `{$table_esc}` WHERE is_published = 1 AND `{$col_esc}` = ? ORDER BY last_tidb_update_at DESC, id DESC LIMIT ? OFFSET ?";
        $stmt = $mysqli->prepare($item_sql);
        if (!$stmt) {
            $err = 'DB prepare failed: ' . $mysqli->error;
            $this->log_error('db_query', $err);
            @$mysqli->close();
            return null;
        }
        $stmt->bind_param('sii', $category_name, $per_page, $offset);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $items[] = $row;
            }
            $res->free();
        }
        $stmt->close();
        @$mysqli->close();

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $canonical = $page > 1 ? trailingslashit(home_url('/p-cat/' . $slug . '/page/' . $page)) : trailingslashit(home_url('/p-cat/' . $slug));
        $meta_description = $total > 0
            ? sprintf(_n('%1$d item in %2$s.', '%1$d items in %2$s.', $total, 'virtual-product-pages'), $total, $category_name)
            : sprintf(__('No products in %s yet.', 'virtual-product-pages'), $category_name);
        if ($page > 1) {
            $meta_description .= ' ' . sprintf(__('Page %d of %d.', 'virtual-product-pages'), $page, $total_pages);
        }
        $title = sprintf(__('%1$s — %2$d items | %3$s', 'virtual-product-pages'), $category_name, $total, $site);
        if ($page > 1) {
            $title = sprintf(__('%1$s — Page %2$d | %3$s', 'virtual-product-pages'), $category_name, $page, $site);
        }

        $data = [
            'type' => 'archive',
            'slug' => $slug,
            'name' => $category_name,
            'items' => $items,
            'count' => $total,
            'page' => max(1, (int)$page),
            'per_page' => $per_page,
            'total_pages' => $total_pages,
            'offset' => $offset,
            'canonical' => $canonical,
            'meta_description' => $meta_description,
            'title' => $title,
            'last_updated' => $last_updated,
        ];

        if (!is_array($cached)) {
            $cached = ['pages' => []];
        }
        if (!isset($cached['pages']) || !is_array($cached['pages'])) {
            $cached['pages'] = [];
        }
        $cached['pages'][$page] = $data;
        $cached['generated'] = time();
        set_transient($transient_key, $cached, self::CATEGORY_CACHE_TTL);

        return $data;
    }

    private function clear_category_caches(array $slugs = []) {
        delete_transient($this->get_category_transient_key('index'));
        $this->category_cache = null;
        $this->category_slug_map = null;
        if (empty($slugs)) {
            return;
        }
        $per_page = $this->get_category_per_page();
        foreach ($slugs as $slug) {
            $slug = sanitize_title($slug);
            if ($slug === '') {
                continue;
            }
            delete_transient($this->get_category_transient_key('archive', $slug, $per_page));
        }
    }

    private function get_category_slug_map() {
        if (is_array($this->category_slug_map)) {
            return $this->category_slug_map;
        }
        $err = null;
        $cache = $this->get_category_cache(false, $err);
        if (is_array($cache) && isset($cache['map']) && is_array($cache['map'])) {
            return $this->category_slug_map = $cache['map'];
        }
        return [];
    }

    private function get_all_category_names() {
        $err = null;
        $cache = $this->get_category_cache(false, $err);
        if (!is_array($cache) || empty($cache['categories']) || !is_array($cache['categories'])) {
            return [];
        }
        $names = [];
        foreach ($cache['categories'] as $item) {
            if (!empty($item['name'])) {
                $names[] = $item['name'];
            }
        }
        $names = array_values(array_unique($names));
        natcasesort($names);
        return array_values($names);
    }

    private function purge_category_urls_by_names(array $names) {
        $filtered = [];
        foreach ($names as $name) {
            $name = trim((string)$name);
            if ($name !== '') {
                $filtered[] = $name;
            }
        }
        $filtered = array_values(array_unique($filtered));

        $relative_urls = ['/p-cat/'];

        $err = null;
        $cache = $this->get_category_cache(true, $err);
        $map = is_array($cache) && isset($cache['map']) && is_array($cache['map']) ? $cache['map'] : [];
        $slugs = [];
        foreach ($map as $slug => $label) {
            if (in_array($label, $filtered, true)) {
                $slugs[] = $slug;
            }
        }
        foreach ($filtered as $name) {
            $slug = array_search($name, $map, true);
            if ($slug === false) {
                $slugs[] = $this->slugify_category_value($name);
            }
        }
        $slugs = array_values(array_unique(array_filter($slugs)));
        foreach ($slugs as $slug) {
            $relative_urls[] = '/p-cat/' . $slug . '/';
        }

        $urls = $this->cf_normalize_urls($relative_urls);
        if (!empty($urls)) {
            $result = $this->cf_purge_files($urls);
            $this->cf_log_result('category_purge', $result);
        }
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

    private function normalize_blog_product_slugs_input($raw) {
        if (is_array($raw)) {
            $raw = implode("\n", $raw);
        }
        $value = wp_unslash((string)$raw);
        $lines = preg_split('/\r?\n/', $value);
        $slugs = [];
        foreach ($lines as $line) {
            $slug = sanitize_title($line);
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }
        $slugs = array_values(array_unique($slugs));
        if (empty($slugs)) {
            return '[]';
        }
        return wp_json_encode($slugs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function decode_blog_product_slugs($json) {
        $json = (string)$json;
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $slugs = [];
        foreach ($decoded as $slug) {
            $slug = sanitize_title($slug);
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }
        return array_values(array_unique($slugs));
    }

    private function sanitize_blog_seo_title($value) {
        $value = sanitize_text_field($value ?? '');
        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, 180);
        } else {
            $value = substr($value, 0, 180);
        }
        return trim($value);
    }

    private function sanitize_blog_seo_description($value) {
        $value = sanitize_text_field($value ?? '');
        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, 160);
        } else {
            $value = substr($value, 0, 160);
        }
        return trim($value);
    }

    private function sanitize_blog_canonical_url($value) {
        $value = esc_url_raw($value ?? '');
        return trim($value);
    }

    private function select_has_meta_column($mysqli, $table) {
        return $this->column_exists($mysqli, $table, 'meta_description');
    }

    private function select_has_schema_column($mysqli, $table) {
        return $this->column_exists($mysqli, $table, 'schema_json');
    }

    private function select_has_price_column($mysqli, $table) {
        return $this->column_exists($mysqli, $table, 'price');
    }

    private function select_has_availability_column($mysqli, $table) {
        return $this->column_exists($mysqli, $table, 'availability');
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
        $has_price = $this->select_has_price_column($mysqli, $table);
        $price_sql = $has_price ? ', price' : ", '' AS price";
        $has_availability = $this->select_has_availability_column($mysqli, $table);
        $availability_sql = $has_availability ? ', availability' : ", '' AS availability";
        $cta_sql = $this->build_cta_select_clause($mysqli, $table);
        $category_sql = $this->build_category_select_clause($mysqli, $table);
        $sql = "SELECT id, slug, title_h1, brand, model, sku, images_json{$schema_sql}{$availability_sql}{$price_sql}, desc_html, short_summary, is_published, last_tidb_update_at {$meta_sql}{$cta_sql}{$category_sql}
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
        $has_price = $this->select_has_price_column($mysqli, $table);
        $price_sql = $has_price ? ', price' : ", '' AS price";
        $has_availability = $this->select_has_availability_column($mysqli, $table);
        $availability_sql = $has_availability ? ', availability' : ", '' AS availability";
        $cta_sql = $this->build_cta_select_clause($mysqli, $table);
        $category_sql = $this->build_category_select_clause($mysqli, $table);
        $sql = "SELECT id, slug, title_h1, brand, model, sku, images_json{$schema_sql}{$availability_sql}{$price_sql}, desc_html, short_summary, is_published, last_tidb_update_at {$meta_sql}{$cta_sql}{$category_sql}
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

    private function fetch_blog_post_by_slug($slug, &$err = null) {
        $table = $this->get_blog_table();
        $mysqli = $this->db_connect($err);
        if (!$mysqli) { return null; }
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
        if ($table === '') {
            $err = 'Invalid posts table.';
            return null;
        }
        $columns = $this->build_blog_select_columns($mysqli, $table);
        $sql = "SELECT {$columns} FROM `{$table}` WHERE slug = ? LIMIT 2";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            $err = 'DB prepare failed: ' . $mysqli->error;
            $this->log_error('db_query', $err);
            @$mysqli->close();
            return null;
        }
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        @$mysqli->close();
        if (!$row) {
            $err = 'Post not found.';
            return null;
        }
        return $row;
    }

    private function fetch_blog_post_by_id($id, &$err = null) {
        $table = $this->get_blog_table();
        $mysqli = $this->db_connect($err);
        if (!$mysqli) { return null; }
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
        if ($table === '') {
            $err = 'Invalid posts table.';
            return null;
        }
        $columns = $this->build_blog_select_columns($mysqli, $table);
        $sql = "SELECT {$columns} FROM `{$table}` WHERE id = ? LIMIT 1";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            $err = 'DB prepare failed: ' . $mysqli->error;
            $this->log_error('db_query', $err);
            @$mysqli->close();
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        @$mysqli->close();
        if (!$row) {
            $err = 'Post not found.';
            return null;
        }
        return $row;
    }

    private function normalize_price_input($raw) {
        if (is_array($raw)) {
            $raw = implode("\n", $raw);
        }
        $value = is_string($raw) ? sanitize_textarea_field(wp_unslash($raw)) : '';
        if ($value === '') {
            return '';
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace("/\n{3,}/", "\n\n", $value);
        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, 220);
        } else {
            $value = substr($value, 0, 220);
        }
        return trim($value);
    }

    private function normalize_availability_input($raw) {
        if (is_array($raw)) {
            $raw = implode(' ', $raw);
        }
        $value = is_string($raw) ? sanitize_text_field(wp_unslash($raw)) : '';
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/\s+/', ' ', $value);
        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, 80);
        } else {
            $value = substr($value, 0, 80);
        }
        return trim($value);
    }

    private function normalize_images_input($raw) {
        $raw = trim((string)$raw);
        if ($raw === '') return '';
        $t = ltrim($raw);
        if ($t && $t[0] === '[') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $urls = [];
                foreach ($decoded as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $urls[] = trim($item);
                    }
                }
                if (empty($urls)) {
                    return '';
                }
                $urls = array_values(array_unique($urls));
                return wp_json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            return $raw;
        }
        $lines = preg_split('/\r?\n/', $raw);
        $urls = [];
        foreach ($lines as $ln) {
            $u = trim($ln);
            if ($u !== '') {
                $urls[] = $u;
            }
        }
        if (empty($urls)) {
            return '';
        }
        $urls = array_values(array_unique($urls));
        return wp_json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

    private function parse_blog_lookup_key($key) {
        $key = trim($key);
        if ($key === '') {
            return [null, null];
        }
        $home = home_url();
        if (stripos($key, $home) === 0) {
            $path = parse_url($key, PHP_URL_PATH);
            $parts = explode('/', trim($path, '/'));
            if (isset($parts[0]) && $parts[0] === 'b' && isset($parts[1])) {
                return ['slug', sanitize_title($parts[1])];
            }
        }
        if (ctype_digit($key)) {
            return ['id', (int)$key];
        }
        return ['slug', sanitize_title($key)];
    }

    private function get_blog_editor_defaults() {
        return [
            'id' => 0,
            'slug' => '',
            'title_h1' => '',
            'short_summary' => '',
            'content_html' => '',
            'cover_image_url' => '',
            'category' => '',
            'category_slug' => '',
            'product_slugs_json' => '',
            'availability' => '',
            'price' => '',
            'seo_title' => '',
            'seo_description' => '',
            'canonical_url' => '',
            'meta_description' => '',
            'cta_lead_label' => '',
            'cta_lead_url' => '',
            'cta_stripe_label' => '',
            'cta_stripe_url' => '',
            'cta_affiliate_label' => '',
            'cta_affiliate_url' => '',
            'cta_paypal_label' => '',
            'cta_paypal_url' => '',
            'is_published' => 0,
            'published_at' => '',
            'last_tidb_update_at' => '',
        ];
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
                        <tr><th scope="row">Category</th><td>
                            <input type="text" name="category" value="<?php echo esc_attr($row['category'] ?? ''); ?>" class="regular-text" list="vpp-category-options" placeholder="e.g. Air Compressors">
                            <?php if (!empty($category_options)): ?>
                                <datalist id="vpp-category-options">
                                    <?php foreach ($category_options as $option): ?>
                                        <option value="<?php echo esc_attr($option); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            <?php endif; ?>
                            <p class="description">Leave blank to remove the category.</p>
                        </td></tr>
                        <tr><th scope="row">Model</th><td><input type="text" name="model" value="<?php echo esc_attr($row['model']); ?>" class="regular-text"></td></tr>
                        <tr><th scope="row">SKU</th><td><input type="text" name="sku" value="<?php echo esc_attr($row['sku']); ?>" class="regular-text"></td></tr>
                        <tr><th scope="row">Availability</th><td>
                            <input type="text" name="availability" value="<?php echo esc_attr($row['availability'] ?? ''); ?>" class="regular-text" placeholder="e.g. In stock" style="max-width:320px;">
                            <p class="description">Shown above the price banner. Freeform text saved to TiDB.</p>
                        </td></tr>
                        <tr><th scope="row">Price</th><td>
                            <textarea name="price" rows="3" class="large-text" placeholder="e.g. $650 solo esta semana" style="max-width:520px;"><?php echo esc_textarea($row['price'] ?? ''); ?></textarea>
                            <p class="description">Shown prominently on the product page. Supports numbers and short text, including line breaks.</p>
                        </td></tr>
                        <tr><th scope="row">Short summary (max 150 chars)</th><td><input type="text" maxlength="150" name="short_summary" value="<?php echo esc_attr($row['short_summary'] ?? ''); ?>" class="regular-text"></td></tr>
                        <tr><th scope="row">Meta description (max 160)</th><td><input type="text" maxlength="160" name="meta_description" value="<?php echo esc_attr($row['meta_description'] ?? ''); ?>" class="regular-text"></td></tr>
                        <tr><th scope="row">Images</th><td>
                            <textarea name="images_json" rows="4" style="width:100%;" placeholder="One image URL per line or paste a JSON array."><?php
                                $images_val = (string)($row['images_json'] ?? '');
                                if ($images_val !== '') {
                                    $decoded_images = json_decode($images_val, true);
                                    if (is_array($decoded_images)) {
                                        $normalized_images = [];
                                        foreach ($decoded_images as $img_url) {
                                            if (is_string($img_url) && trim($img_url) !== '') {
                                                $normalized_images[] = trim($img_url);
                                            }
                                        }
                                        if (!empty($normalized_images)) {
                                            $images_val = implode("\n", $normalized_images);
                                        }
                                    }
                                }
                                echo esc_textarea($images_val);
                            ?></textarea>
                            <p class="description">Enter each image URL on its own line. The plugin will save them as JSON automatically.</p>
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

    public function render_blog_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $lookup_type = isset($_GET['lookup_type']) ? sanitize_text_field(wp_unslash($_GET['lookup_type'])) : '';
        $lookup_val = isset($_GET['lookup_val']) ? sanitize_text_field(wp_unslash($_GET['lookup_val'])) : '';
        $blog_action = isset($_GET['blog_action']) ? sanitize_text_field(wp_unslash($_GET['blog_action'])) : '';

        $row = null;
        $err = null;

        if ($lookup_type && $lookup_val !== '') {
            $row = ($lookup_type === 'id')
                ? $this->fetch_blog_post_by_id((int)$lookup_val, $err)
                : $this->fetch_blog_post_by_slug($lookup_val, $err);
        } elseif ($blog_action === 'new') {
            $row = $this->get_blog_editor_defaults();
        }

        $inline = isset($_GET['inline']) ? sanitize_text_field(wp_unslash($_GET['inline'])) : '';
        $inline_msg = isset($_GET['inline_msg']) ? sanitize_text_field(wp_unslash($_GET['inline_msg'])) : '';

        $product_slugs_text = '';
        if ($row && isset($row['product_slugs_json'])) {
            $product_slugs = $this->decode_blog_product_slugs($row['product_slugs_json']);
            if (!empty($product_slugs)) {
                $product_slugs_text = implode("\n", $product_slugs);
            }
        }

        $published_at_val = $row ? trim((string)($row['published_at'] ?? '')) : '';
        ?>
        <div class="wrap">
            <h1>Blog Posts</h1>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1rem; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <input type="hidden" name="action" value="vpp_blog_load" />
                <?php wp_nonce_field(self::NONCE_KEY); ?>
                <input type="text" name="vpp_key" class="regular-text" placeholder="Paste /b/slug, slug or ID" value="<?php echo esc_attr($lookup_val); ?>" />
                <?php submit_button('Load', 'secondary', 'submit', false); ?>
                <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page' => 'vpp_blogs', 'blog_action' => 'new'], admin_url('admin.php'))); ?>">Create new</a>
            </form>

            <?php if ($row): ?>
                <?php $is_new = empty($row['id']); ?>
                <h2 class="title" style="margin-top:1.5rem;">
                    <?php echo $is_new ? esc_html__('Create Blog Post', 'virtual-product-pages') : esc_html__('Edit Blog Post', 'virtual-product-pages'); ?>
                    <?php if (!$is_new): ?>
                        <small style="font-weight:normal;">(ID <?php echo (int)$row['id']; ?>, slug <code><?php echo esc_html($row['slug']); ?></code>)</small>
                    <?php endif; ?>
                </h2>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="vpp_blog_save" />
                    <?php wp_nonce_field(self::NONCE_KEY); ?>
                    <input type="hidden" name="id" value="<?php echo (int)($row['id'] ?? 0); ?>" />

                    <table class="form-table"><tbody>
                        <tr><th scope="row">Slug</th><td><input type="text" name="slug" value="<?php echo esc_attr($row['slug'] ?? ''); ?>" class="regular-text" required><p class="description">Use lowercase letters, numbers and hyphens only. Changing the slug updates the public URL (/b/{slug}).</p></td></tr>
                        <tr><th scope="row">Title (H1)</th><td><input type="text" name="title_h1" value="<?php echo esc_attr($row['title_h1'] ?? ''); ?>" class="regular-text" required></td></tr>
                        <tr><th scope="row">Short summary</th><td><input type="text" name="short_summary" maxlength="150" value="<?php echo esc_attr($row['short_summary'] ?? ''); ?>" class="regular-text" placeholder="Max 150 characters"></td></tr>
                        <tr><th scope="row">Cover image URL</th><td><input type="url" name="cover_image_url" value="<?php echo esc_attr($row['cover_image_url'] ?? ''); ?>" class="regular-text" placeholder="https://example.com/cover.jpg"></td></tr>
                        <tr><th scope="row">Category</th><td>
                            <input type="text" name="category" value="<?php echo esc_attr($row['category'] ?? ''); ?>" class="regular-text" placeholder="Category name">
                            <p class="description">Optional label shown in metadata. Leave blank to omit.</p>
                        </td></tr>
                        <tr><th scope="row">Category slug</th><td><input type="text" name="category_slug" value="<?php echo esc_attr($row['category_slug'] ?? ''); ?>" class="regular-text" placeholder="category-slug"><p class="description">Optional slug for linking or taxonomy integrations.</p></td></tr>
                        <tr><th scope="row">Availability</th><td><input type="text" name="availability" value="<?php echo esc_attr($row['availability'] ?? ''); ?>" class="regular-text" placeholder="e.g. In stock"></td></tr>
                        <tr><th scope="row">Price</th><td><textarea name="price" rows="3" class="large-text" style="max-width:520px;" placeholder="Optional price callout."><?php echo esc_textarea($row['price'] ?? ''); ?></textarea></td></tr>
                        <tr><th scope="row">Related product slugs</th><td><textarea name="product_slugs" rows="3" class="large-text" placeholder="One product slug per line."><?php echo esc_textarea($product_slugs_text); ?></textarea><p class="description">Optional. These slugs are stored as JSON for downstream integrations.</p></td></tr>
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
                        <tr><th scope="row">Published at</th><td><input type="text" name="published_at" value="<?php echo esc_attr($published_at_val); ?>" class="regular-text" placeholder="YYYY-MM-DD HH:MM:SS"><p class="description">Leave blank to publish immediately when saving. Automatically set when using “Save &amp; View”.</p></td></tr>
                        <?php if (!empty($row['last_tidb_update_at'])): ?>
                            <tr><th scope="row">Last updated</th><td><code><?php echo esc_html($row['last_tidb_update_at']); ?></code></td></tr>
                        <?php endif; ?>
                    </tbody></table>

                    <h3>Content</h3>
                    <?php
                        $content = (string)($row['content_html'] ?? '');
                        wp_editor($content, 'content_html', [
                            'textarea_name' => 'content_html',
                            'textarea_rows' => 18,
                            'media_buttons' => false,
                            'teeny' => false,
                        ]);
                    ?>

                    <h3>SEO</h3>
                    <table class="form-table"><tbody>
                        <tr><th scope="row">SEO title</th><td><input type="text" name="seo_title" value="<?php echo esc_attr($row['seo_title'] ?? ''); ?>" class="regular-text" placeholder="Optional override (max 180 chars)"></td></tr>
                        <tr><th scope="row">SEO description</th><td><textarea name="seo_description" rows="2" class="large-text" placeholder="Optional description (max 160 chars)"><?php echo esc_textarea($row['seo_description'] ?? ''); ?></textarea></td></tr>
                        <tr><th scope="row">Canonical URL</th><td><input type="url" name="canonical_url" value="<?php echo esc_attr($row['canonical_url'] ?? ''); ?>" class="regular-text" placeholder="https://example.com/blog/post"></td></tr>
                    </tbody></table>

                    <p class="submit" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <?php submit_button($is_new ? 'Create' : 'Save', 'primary', 'do_save', false); ?>
                        <?php submit_button('Save & View', 'secondary', 'do_save_view', false); ?>
                        <?php submit_button('Save & Purge CF', 'secondary', 'do_save_purge', false); ?>
                        <?php if ($inline): ?>
                            <span class="vpp-inline <?php echo $inline === 'ok' ? 'ok' : 'err'; ?>"><?php echo esc_html($inline_msg); ?></span>
                        <?php endif; ?>
                    </p>
                </form>
            <?php elseif ($lookup_type || $lookup_val !== ''): ?>
                <p><?php esc_html_e('Blog post not found.', 'virtual-product-pages'); ?> <?php echo $err ? esc_html('(' . $err . ')') : ''; ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_categories_page() {
        if (!current_user_can('manage_options')) return;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $err = null;
        $cache = $this->get_category_cache(false, $err);
        $categories = [];
        if (is_array($cache) && isset($cache['categories']) && is_array($cache['categories'])) {
            $categories = $cache['categories'];
        }
        if ($search !== '') {
            $categories = array_values(array_filter($categories, function($item) use ($search) {
                return isset($item['name']) && stripos($item['name'], $search) !== false;
            }));
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Categories', 'virtual-product-pages'); ?></h1>

            <form method="get" class="vpp-cat-search" style="margin:1rem 0;">
                <input type="hidden" name="page" value="vpp_categories"/>
                <label class="screen-reader-text" for="vpp-cat-search"><?php esc_html_e('Search categories', 'virtual-product-pages'); ?></label>
                <input type="search" id="vpp-cat-search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search categories…', 'virtual-product-pages'); ?>"/>
                <?php submit_button(__('Search', 'virtual-product-pages'), 'secondary', '', false); ?>
            </form>

            <?php if ($err): ?>
                <div class="notice notice-error"><p><?php echo esc_html($err); ?></p></div>
            <?php endif; ?>

            <table class="widefat striped" style="max-width:720px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Category', 'virtual-product-pages'); ?></th>
                        <th><?php esc_html_e('Count', 'virtual-product-pages'); ?></th>
                        <th><?php esc_html_e('Link', 'virtual-product-pages'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                        <tr><td colspan="3"><?php esc_html_e('No categories found.', 'virtual-product-pages'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($categories as $item): ?>
                            <tr>
                                <td><?php echo esc_html($item['name']); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int)($item['count'] ?? 0))); ?></td>
                                <td>
                                    <?php if (!empty($item['slug'])): ?>
                                        <?php $url = home_url('/p-cat/' . $item['slug'] . '/'); ?>
                                        <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($url); ?></a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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
        $category_input = sanitize_text_field($_POST['category'] ?? '');
        if (function_exists('mb_substr')) { $category_input = mb_substr($category_input, 0, 120); }
        else { $category_input = substr($category_input, 0, 120); }
        $category_input = trim($category_input);
        $short_summary = sanitize_text_field($_POST['short_summary'] ?? '');
        if (strlen($short_summary) > 150) $short_summary = substr($short_summary, 0, 150);
        $meta_description = sanitize_text_field($_POST['meta_description'] ?? '');
        if (strlen($meta_description) > 160) $meta_description = substr($meta_description, 0, 160);
        $availability = $this->normalize_availability_input($_POST['availability'] ?? '');
        $price = $this->normalize_price_input($_POST['price'] ?? '');
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

        $previous_category = '';
        if ($id > 0) {
            $prev_err = null;
            $prev_row = $this->fetch_product_by_id($id, $prev_err);
            if (is_array($prev_row) && isset($prev_row['category'])) {
                $previous_category = trim((string)$prev_row['category']);
            }
        }

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
        $category_col = $this->get_category_column($mysqli, $table);

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

        $schema_invalid = false;
        if ($clear_schema) {
            $schema_json = null;
        } elseif ($schema_json_raw === '') {
            $schema_json = $current_schema;
        } else {
            $schema_clean = wp_check_invalid_utf8($schema_json_raw, true);
            $decoded = json_decode($schema_clean, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $schema_json = wp_json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } else {
                $schema_json = $schema_clean;
                $schema_invalid = true;
            }
        }

        $cols = [
            'title_h1' => $title_h1,
            'brand' => $brand,
            'model' => $model,
            'sku' => $sku,
            'short_summary' => $short_summary,
            'meta_description' => $meta_description,
            'availability' => $availability,
            'price' => $price,
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
        if ($category_col !== '' && isset($existing_cols[$category_col])) {
            $cols[$category_col] = $category_input;
        }
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
            $result = $this->cf_purge_products([$slug]);
            $this->cf_log_result('edit_save_product', $result);
            $this->purge_category_urls_by_names([$previous_category, $category_input]);
            $inline = 'ok'; $inline_msg = 'Saved & purged'; $top_msg = 'Saved and purged Cloudflare.';
        }
        if ($ok) {
            $this->clear_category_caches();
        }
        if ($ok && isset($_POST['do_push_algolia'])) {
            $p = $this->fetch_product_by_id($id, $tmp);
            if ($p && $this->push_algolia($p, $tmp2)) {
                $inline = 'ok'; $inline_msg = 'Pushed to Algolia'; $top_msg = 'Saved and pushed to Algolia.';
            } else {
                $inline = 'err'; $inline_msg = 'Algolia: ' . ($tmp2 ?: 'error'); $top_msg = 'Saved, but Algolia push failed.';
            }
        }

        if ($ok && $schema_invalid) {
            $schema_note = 'Schema kept as raw text (invalid JSON).';
            $inline_msg = trim($inline_msg . ' ' . $schema_note);
            $top_msg = trim($top_msg . ' ' . $schema_note);
        }

        $args = ['lookup_type'=>'id','lookup_val'=>$id,'vpp_msg'=>$top_msg,'inline'=>$inline,'inline_msg'=>$inline_msg];
        $redirect = add_query_arg($args, admin_url('admin.php?page=vpp_edit'));
        if (isset($_POST['do_save_view']) && $ok) {
            $redirect = add_query_arg(['vpp_msg'=> $top_msg . ' Open: ' . home_url('/p/' . $slug)] + $args, admin_url('admin.php?page=vpp_edit'));
        }
        wp_safe_redirect($redirect); exit;
    }

    public function handle_blog_load() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer(self::NONCE_KEY);
        $key = isset($_POST['vpp_key']) ? sanitize_text_field(wp_unslash($_POST['vpp_key'])) : '';
        list($type, $val) = $this->parse_blog_lookup_key($key);
        if (!$type) {
            $redirect = add_query_arg(['page' => 'vpp_blogs', 'vpp_err' => 'Enter a valid URL, slug or ID.'], admin_url('admin.php'));
        } else {
            $redirect = add_query_arg(['page' => 'vpp_blogs', 'lookup_type' => $type, 'lookup_val' => $val], admin_url('admin.php'));
        }
        wp_safe_redirect($redirect); exit;
    }

    public function handle_blog_save() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer(self::NONCE_KEY);

        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $slug_raw = isset($_POST['slug']) ? wp_unslash($_POST['slug']) : '';
        $slug = sanitize_title($slug_raw);
        $title_h1 = sanitize_text_field($_POST['title_h1'] ?? '');
        if ($title_h1 === '' && $slug !== '') { $title_h1 = ucwords(str_replace('-', ' ', $slug)); }
        $short_summary = sanitize_text_field($_POST['short_summary'] ?? '');
        if (function_exists('mb_substr')) { $short_summary = mb_substr($short_summary, 0, 150); }
        else { $short_summary = substr($short_summary, 0, 150); }
        $cover_image_url = esc_url_raw($_POST['cover_image_url'] ?? '');
        $category = sanitize_text_field($_POST['category'] ?? '');
        if (function_exists('mb_substr')) { $category = mb_substr($category, 0, 120); }
        else { $category = substr($category, 0, 120); }
        $category = trim($category);
        $category_slug = sanitize_title($_POST['category_slug'] ?? '');
        $availability = $this->normalize_availability_input($_POST['availability'] ?? '');
        $price = $this->normalize_price_input($_POST['price'] ?? '');
        $product_slugs_json = $this->normalize_blog_product_slugs_input($_POST['product_slugs'] ?? '');
        $cta_lead_label = $this->sanitize_cta_label($_POST['cta_lead_label'] ?? '');
        $cta_lead_url = esc_url_raw($_POST['cta_lead_url'] ?? '');
        $cta_stripe_label = $this->sanitize_cta_label($_POST['cta_stripe_label'] ?? '');
        $cta_stripe_url = esc_url_raw($_POST['cta_stripe_url'] ?? '');
        $cta_affiliate_label = $this->sanitize_cta_label($_POST['cta_affiliate_label'] ?? '');
        $cta_affiliate_url = esc_url_raw($_POST['cta_affiliate_url'] ?? '');
        $cta_paypal_label = $this->sanitize_cta_label($_POST['cta_paypal_label'] ?? '');
        $cta_paypal_url = esc_url_raw($_POST['cta_paypal_url'] ?? '');

        $content_raw = wp_unslash($_POST['content_html'] ?? '');
        $content_html = wp_kses_post($content_raw);
        $content_html = trim($content_html);
        $content_plain = trim(wp_strip_all_tags($content_html));
        if ($content_plain === '') { $content_html = ''; }

        $seo_title = $this->sanitize_blog_seo_title($_POST['seo_title'] ?? '');
        $seo_description = $this->sanitize_blog_seo_description($_POST['seo_description'] ?? '');
        $canonical_url = $this->sanitize_blog_canonical_url($_POST['canonical_url'] ?? '');

        $is_published = !empty($_POST['is_published']) ? 1 : 0;
        $do_save_view = isset($_POST['do_save_view']);
        if ($do_save_view) { $is_published = 1; }

        $published_at_input = isset($_POST['published_at']) ? trim((string)wp_unslash($_POST['published_at'])) : '';
        $published_at = null;
        if ($published_at_input !== '') {
            $ts = strtotime($published_at_input);
            if ($ts !== false) { $published_at = gmdate('Y-m-d H:i:s', $ts); }
        }
        if ($is_published && $published_at === null) { $published_at = gmdate('Y-m-d H:i:s'); }

        if ($slug === '') {
            $redirect = add_query_arg(['page' => 'vpp_blogs', 'vpp_err' => 'Slug is required.', 'blog_action' => $id ? '' : 'new'], admin_url('admin.php'));
            wp_safe_redirect($redirect); exit;
        }

        $err = null;
        $mysqli = $this->db_connect($err);
        if (!$mysqli) {
            $redirect = add_query_arg(['page' => 'vpp_blogs', 'vpp_err' => 'DB error: ' . $err], admin_url('admin.php'));
            wp_safe_redirect($redirect); exit;
        }

        $table = $this->get_blog_table();
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
        if ($table === '') {
            @$mysqli->close();
            $redirect = add_query_arg(['page' => 'vpp_blogs', 'vpp_err' => 'Invalid posts table.'], admin_url('admin.php'));
            wp_safe_redirect($redirect); exit;
        }

        $this->ensure_cta_label_columns($mysqli, $table);
        $existing_cols = array_flip($this->get_table_columns($mysqli, $table));

        $previous_slug = '';
        if ($id > 0) {
            $prev_err = null; $prev_row = $this->fetch_blog_post_by_id($id, $prev_err);
            if (is_array($prev_row) && !empty($prev_row['slug'])) { $previous_slug = sanitize_title($prev_row['slug']); }
        }
        if ($id <= 0) {
            $existing = $this->fetch_blog_post_by_slug($slug, $tmp_err);
            if ($existing && !empty($existing['id'])) {
                $id = (int)$existing['id'];
                $previous_slug = sanitize_title($existing['slug']);
            }
        }

        $cols = [
            'slug' => $slug,
            'title_h1' => $title_h1,
            'short_summary' => $short_summary,
            'content_html' => $content_html,
            'cover_image_url' => $cover_image_url,
            'category' => $category,
            'category_slug' => $category_slug,
            'product_slugs_json' => $product_slugs_json,
            'availability' => $availability,
            'price' => $price,
            'seo_title' => $seo_title,
            'seo_description' => $seo_description,
            'canonical_url' => $canonical_url,
            'meta_description' => $seo_description,
            'cta_lead_label' => $cta_lead_label,
            'cta_lead_url' => $cta_lead_url,
            'cta_stripe_label' => $cta_stripe_label,
            'cta_stripe_url' => $cta_stripe_url,
            'cta_affiliate_label' => $cta_affiliate_label,
            'cta_affiliate_url' => $cta_affiliate_url,
            'cta_paypal_label' => $cta_paypal_label,
            'cta_paypal_url' => $cta_paypal_url,
            'is_published' => $is_published,
        ];

        $ok = false; $stmt_error = ''; $saved_id = $id;

        if ($id > 0) {
            $set_parts = [];
            $bind_types = '';
            $bind_values = [];
            foreach ($cols as $column => $value) {
                if (!isset($existing_cols[$column])) { continue; }
                $set_parts[] = "`{$column}` = ?";
                $bind_types .= ($column === 'is_published') ? 'i' : 's';
                $bind_values[] = $value;
            }
            if (isset($existing_cols['published_at'])) {
                if ($published_at === null) { $set_parts[] = "`published_at` = NULL"; }
                else { $set_parts[] = "`published_at` = ?"; $bind_types .= 's'; $bind_values[] = $published_at; }
            }
            if (isset($existing_cols['last_tidb_update_at'])) { $set_parts[] = "`last_tidb_update_at` = NOW()"; }

            if (empty($set_parts)) {
                @$mysqli->close();
                $redirect = add_query_arg(['page' => 'vpp_blogs', 'lookup_type' => 'id', 'lookup_val' => $id, 'vpp_err' => 'No editable columns found in table.'], admin_url('admin.php'));
                wp_safe_redirect($redirect); exit;
            }

            $sql = "UPDATE `{$table}` SET " . implode(', ', $set_parts) . " WHERE id = ?";
            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
                $bind_types .= 'i';
                $bind_values[] = $id;
                $params = array_merge([$bind_types], $bind_values);
                $refs = [];
                foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
                call_user_func_array([$stmt, 'bind_param'], $refs);
                $ok = $stmt->execute();
                $stmt_error = $stmt->error;
                $stmt->close();
            } else {
                $stmt_error = $mysqli->error;
            }
        } else {
            $columns = [];
            $values = [];
            $bind_types = '';
            $bind_values = [];
            foreach ($cols as $column => $value) {
                if (!isset($existing_cols[$column])) { continue; }
                $columns[] = "`{$column}`";
                $values[] = '?';
                $bind_types .= ($column === 'is_published') ? 'i' : 's';
                $bind_values[] = $value;
            }
            if (isset($existing_cols['published_at'])) {
                $columns[] = '`published_at`';
                if ($published_at === null) { $values[] = 'NULL'; }
                else { $values[] = '?'; $bind_types .= 's'; $bind_values[] = $published_at; }
            }
            if (isset($existing_cols['last_tidb_update_at'])) { $columns[] = '`last_tidb_update_at`'; $values[] = 'NOW()'; }

            if (empty($columns)) {
                @$mysqli->close();
                $redirect = add_query_arg(['page' => 'vpp_blogs', 'blog_action' => 'new', 'vpp_err' => 'Posts table is missing editable columns.'], admin_url('admin.php'));
                wp_safe_redirect($redirect); exit;
            }

            $sql = "INSERT INTO `{$table}` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";
            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
                if ($bind_types !== '') {
                    $params = array_merge([$bind_types], $bind_values);
                    $refs = [];
                    foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
                    call_user_func_array([$stmt, 'bind_param'], $refs);
                }
                $ok = $stmt->execute();
                $stmt_error = $stmt->error;
                if ($ok) { $saved_id = (int)$mysqli->insert_id; }
                $stmt->close();
            } else {
                $stmt_error = $mysqli->error;
            }
        }

        @$mysqli->close();

        $inline = 'ok'; $inline_msg = 'Saved'; $top_msg = 'Saved.';
        if (!$ok) {
            $inline = 'err'; $inline_msg = 'Error saving'; $top_msg = 'Save failed.';
            if ($stmt_error !== '') { $this->log_error('db_query', 'Post save failed: ' . $stmt_error); }
        }

        if ($ok) {
            if ($do_save_view) { $top_msg = 'Saved. Open: ' . home_url('/b/' . $slug); }
            if (!empty($_POST['do_save_purge'])) {
                $purge_slugs = [$slug];
                if ($previous_slug && $previous_slug !== $slug) { $purge_slugs[] = $previous_slug; }
                $purge_result = $this->cf_purge_blogs($purge_slugs);
                $this->cf_log_result('edit_save_blog', $purge_result);
                $inline = !empty($purge_result['success']) ? 'ok' : 'err';
                $inline_msg = !empty($purge_result['messages'][0]) ? $purge_result['messages'][0] : ($purge_result['success'] ? 'Purged' : 'Purge failed');
                $top_msg = $purge_result['success'] ? 'Saved and purged Cloudflare.' : 'Saved, but purge failed.';
            }
            $sitemap_err = null;
            $sitemap_changed = [];
            if ($this->regenerate_blog_sitemaps($sitemap_err, $sitemap_changed)) {
                $top_msg = rtrim($top_msg);
                if ($top_msg !== '') { $top_msg .= ' '; }
                $top_msg .= 'Blog sitemap updated.';
            } else {
                $this->log_error('sitemap', $sitemap_err ?: 'Blog sitemap update failed.');
                $inline = 'err';
                $inline_msg = 'Saved, but sitemap update failed.';
                $top_msg = 'Saved, but sitemap update failed.';
            }
        }

        $lookup_id = $ok ? $saved_id : $id;
        $args = ['page' => 'vpp_blogs'];
        if ($lookup_id > 0) { $args['lookup_type'] = 'id'; $args['lookup_val'] = $lookup_id; }
        else { $args['blog_action'] = 'new'; }
        $args['inline'] = $inline; $args['inline_msg'] = $inline_msg;
        if ($ok) { $args['vpp_msg'] = $top_msg; }
        else { $args['vpp_err'] = $top_msg . ($stmt_error ? ' (' . $stmt_error . ')' : ''); }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php'))); exit;
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
        if (!empty($outcome['message_type']) && $outcome['message_type'] === 'success' && !empty($outcome['cf_context'])) {
            $context = $outcome['cf_context'];
            $files = isset($context['sitemap_files']) && is_array($context['sitemap_files']) ? $context['sitemap_files'] : [];
            $slugs = isset($context['product_slugs']) && is_array($context['product_slugs']) ? $context['product_slugs'] : [];
            do_action('vpp_publish_completed', $files, $slugs);
        }
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
        if (!empty($outcome['message_type']) && $outcome['message_type'] === 'success' && !empty($outcome['cf_context'])) {
            $context = $outcome['cf_context'];
            $files = isset($context['sitemap_files']) && is_array($context['sitemap_files']) ? $context['sitemap_files'] : [];
            $slugs = isset($context['product_slugs']) && is_array($context['product_slugs']) ? $context['product_slugs'] : [];
            do_action('vpp_publish_completed', $files, $slugs);
        }
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
        $index_files = [];
        $this->refresh_sitemap_index($storage, $refresh_err, $index_files);
        if ($refresh_err) {
            $state['message'] = $refresh_err;
            $state['message_type'] = 'error';
        } else {
            $state['message'] = sprintf('Rotation complete. Next sitemap file: %s', $filename);
            $state['message_type'] = 'success';
        }
        $purge_targets = array_merge([$filename], $index_files);
        if (!empty($purge_targets)) {
            $this->purge_sitemap_cache($storage, $purge_targets);
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

        $purge_files = [];
        if ((int)$published_count === 0) {
            foreach (glob($dir . 'products-*.xml') ?: [] as $old) { $purge_files[] = basename($old); @unlink($old); }
            foreach (glob($dir . 'sitemap-*.xml') ?: [] as $old) { $purge_files[] = basename($old); @unlink($old); }
            if (!empty($storage['legacy_dir']) && is_dir($storage['legacy_dir'])) {
                foreach (glob($storage['legacy_dir'] . 'products-*.xml') ?: [] as $old) { $purge_files[] = basename($old); @unlink($old); }
                foreach (glob($storage['legacy_dir'] . 'sitemap-*.xml') ?: [] as $old) { $purge_files[] = basename($old); @unlink($old); }
            }
        }

        $files = glob($dir . 'products-*.xml') ?: [];
        $err = null;
        $index_files = [];
        $this->refresh_sitemap_index($storage, $err, $index_files);
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
        $purge_targets = array_merge($purge_files, $index_files);
        if (!empty($purge_targets)) {
            $this->purge_sitemap_cache($storage, $purge_targets);
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

        $files = glob($dir . '*.xml') ?: [];
        $skip_names = [
            'sitemap_index.xml',
            'sitemap-index.xml',
            'vpp-index.xml',
        ];
        $rows = [];
        $total_urls = 0;
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $skip_names, true)) {
                continue;
            }
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

    public function handle_cf_test_connection() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        if (!$this->cf_is_ready()) {
            $redirect = add_query_arg(['vpp_err' => rawurlencode('Configure Zone ID + API Token first.')], admin_url('admin.php?page=vpp_settings'));
            wp_safe_redirect($redirect); exit;
        }
        $response = $this->cf_request('GET', $this->cf_build_endpoint(''));
        if (!empty($response['success'])) {
            $zone_name = '';
            if (is_array($response['body']) && !empty($response['body']['result']['name'])) {
                $zone_name = (string)$response['body']['result']['name'];
            }
            $message = $zone_name ? sprintf('Cloudflare connection OK (zone %s).', $zone_name) : 'Cloudflare connection OK.';
            $this->cf_log_result('test_connection', [
                'success' => true,
                'count' => 0,
                'duration' => 0,
                'messages' => [$message],
                'ray_ids' => !empty($response['ray']) ? [$response['ray']] : [],
            ]);
            $redirect = add_query_arg(['vpp_msg' => rawurlencode($message)], admin_url('admin.php?page=vpp_settings'));
        } else {
            $message = $response['message'] ?: 'Cloudflare connection failed.';
            $this->cf_log_result('test_connection', [
                'success' => false,
                'count' => 0,
                'duration' => 0,
                'messages' => [$message],
                'ray_ids' => !empty($response['ray']) ? [$response['ray']] : [],
            ]);
            $redirect = add_query_arg(['vpp_err' => rawurlencode('Cloudflare connection failed: ' . $message)], admin_url('admin.php?page=vpp_settings'));
        }
        wp_safe_redirect($redirect); exit;
    }

    public function handle_cf_test_purge() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        if (!$this->cf_is_ready()) {
            $redirect = add_query_arg(['vpp_err' => rawurlencode('Configure Zone ID + API Token first.')], admin_url('admin.php?page=vpp_settings'));
            wp_safe_redirect($redirect); exit;
        }
        $files = $this->cf_collect_existing_sitemap_files();
        $result = $this->cf_purge_sitemaps($files);
        $this->cf_log_result('test_purge_sitemaps', $result);
        $message = $result['messages'][0] ?? ($result['success'] ? 'Cloudflare purge successful.' : 'Cloudflare purge failed.');
        $redirect = add_query_arg([!empty($result['success']) ? 'vpp_msg' : 'vpp_err' => rawurlencode($message)], admin_url('admin.php?page=vpp_settings'));
        wp_safe_redirect($redirect); exit;
    }

    public function handle_cf_purge_sitemaps() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        if (!$this->cf_is_ready()) {
            $redirect = add_query_arg(['vpp_err' => rawurlencode('Configure Zone ID + API Token first.')], admin_url('admin.php?page=vpp_settings'));
            wp_safe_redirect($redirect); exit;
        }
        $files = $this->cf_collect_existing_sitemap_files();
        $result = $this->cf_purge_sitemaps($files);
        $this->cf_log_result('manual_purge_sitemaps', $result);
        $message = $result['messages'][0] ?? ($result['success'] ? 'Cloudflare purge successful.' : 'Cloudflare purge failed.');
        $redirect = add_query_arg([!empty($result['success']) ? 'vpp_msg' : 'vpp_err' => rawurlencode($message)], admin_url('admin.php?page=vpp_settings'));
        wp_safe_redirect($redirect); exit;
    }

    public function handle_cf_purge_last_batch() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        if (!$this->cf_is_ready()) {
            $redirect = add_query_arg(['vpp_err' => rawurlencode('Configure Zone ID + API Token first.')], admin_url('admin.php?page=vpp_settings'));
            wp_safe_redirect($redirect); exit;
        }
        $batch = $this->get_cf_last_batch();
        if (empty($batch) || (empty($batch['sitemap_files']) && empty($batch['product_slugs']))) {
            $redirect = add_query_arg(['vpp_err' => rawurlencode('No cached batch information available yet.')], admin_url('admin.php?page=vpp_settings'));
            wp_safe_redirect($redirect); exit;
        }
        $sitemap_result = $this->cf_purge_sitemaps($batch['sitemap_files']);
        $this->cf_log_result('manual_purge_last_batch_sitemaps', $sitemap_result);
        $product_result = null;
        if (!empty($batch['product_slugs'])) {
            $product_result = $this->cf_purge_products($batch['product_slugs']);
            $this->cf_log_result('manual_purge_last_batch_products', $product_result);
        }
        $success = !empty($sitemap_result['success']) && ($product_result === null || !empty($product_result['success']));
        $sitemap_count = (int)($sitemap_result['count'] ?? 0);
        $product_count = (int)($product_result['count'] ?? 0);
        $message = $success
            ? sprintf('Cloudflare purge successful (%d sitemap URL%s, %d product URL%s).', $sitemap_count, $sitemap_count === 1 ? '' : 's', $product_count, $product_count === 1 ? '' : 's')
            : ($sitemap_result['messages'][0] ?? ($product_result['messages'][0] ?? 'Cloudflare purge failed.'));
        $redirect = add_query_arg([$success ? 'vpp_msg' : 'vpp_err' => rawurlencode($message)], admin_url('admin.php?page=vpp_settings'));
        wp_safe_redirect($redirect); exit;
    }

    public function handle_cf_purge_everything() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $confirm = isset($_POST['vpp_cf_confirm']) ? sanitize_text_field(wp_unslash($_POST['vpp_cf_confirm'])) : '';
        if (strtoupper($confirm) !== 'PURGE') {
            $redirect = add_query_arg(['vpp_err' => rawurlencode('Type PURGE to confirm full Cloudflare purge.')], admin_url('admin.php?page=vpp_settings'));
            wp_safe_redirect($redirect); exit;
        }
        if (!$this->cf_is_ready()) {
            $redirect = add_query_arg(['vpp_err' => rawurlencode('Configure Zone ID + API Token first.')], admin_url('admin.php?page=vpp_settings'));
            wp_safe_redirect($redirect); exit;
        }
        $result = $this->cf_purge_everything();
        $this->cf_log_result('manual_purge_everything', $result);
        $success = !empty($result['success']);
        $message = $result['messages'][0] ?? ($success ? 'Cloudflare cache purged.' : 'Cloudflare purge failed.');
        $redirect = add_query_arg([$success ? 'vpp_msg' : 'vpp_err' => rawurlencode($message)], admin_url('admin.php?page=vpp_settings'));
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
                    $result = $this->cf_purge_products([$slug]);
                    $this->cf_log_result('manual_publish_product', $result);
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
        $result = $this->cf_purge_everything();
        $this->cf_log_result('manual_purge_everything', $result);
        $success = !empty($result['success']);
        $message = $success ? ($result['messages'][0] ?? 'Cloudflare cache purged.') : ($result['messages'][0] ?? 'Cloudflare purge failed.');
        $redirect = add_query_arg([$success ? 'vpp_msg':'vpp_err' => $message], admin_url('admin.php?page=vpp_settings'));
        wp_safe_redirect($redirect); exit;
    }

    public function handle_rebuild_sitemaps() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE_KEY);
        $summary = '';
        $err = null;
        $changed_files = [];
        $ok = $this->rebuild_sitemaps($summary, $err, $changed_files);
        if ($ok) {
            do_action('vpp_rebuild_completed', $changed_files, []);
        }
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

    private function cf_get_zone_id() {
        $settings = $this->get_settings();
        return trim($settings['cloudflare']['zone_id'] ?? '');
    }

    private function cf_get_api_token() {
        $settings = $this->get_settings();
        return trim($settings['cloudflare']['api_token'] ?? '');
    }

    private function cf_is_ready() {
        return ($this->cf_get_zone_id() !== '' && $this->cf_get_api_token() !== '');
    }

    private function cf_build_endpoint($path = '') {
        $zone = $this->cf_get_zone_id();
        if ($zone === '') {
            return '';
        }
        $base = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone);
        if ($path !== '') {
            $base .= '/' . ltrim($path, '/');
        }
        return $base;
    }

    private function cf_get_site_base_override() {
        $settings = $this->get_settings();
        $base = trim($settings['cloudflare']['site_base'] ?? '');
        return $base !== '' ? rtrim($base, '/') : '';
    }

    private function cf_normalize_urls(array $urls) {
        $home = rtrim(home_url(), '/');
        $override = $this->cf_get_site_base_override();
        $normalized = [];
        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url === '') {
                continue;
            }
            if (stripos($url, 'http://') !== 0 && stripos($url, 'https://') !== 0) {
                if ($url[0] !== '/') {
                    $url = '/' . $url;
                }
                $url = $home . $url;
            }
            $normalized[] = $url;
            if ($override) {
                $swapped = $this->cf_swap_base($url, $override);
                if ($swapped && $swapped !== $url) {
                    $normalized[] = $swapped;
                }
            }
        }
        return array_values(array_unique($normalized));
    }

    private function cf_swap_base($url, $site_base) {
        $target = wp_parse_url($url);
        $base = wp_parse_url($site_base);
        if (!$target || !$base || empty($target['path'])) {
            return '';
        }
        $scheme = $base['scheme'] ?? ($target['scheme'] ?? 'https');
        $host = $base['host'] ?? ($target['host'] ?? '');
        if (!$host) {
            return '';
        }
        $port = isset($base['port']) ? ':' . $base['port'] : '';
        $path = $target['path'];
        $query = isset($target['query']) ? '?' . $target['query'] : '';
        return $scheme . '://' . $host . $port . $path . $query;
    }

    private function cf_collect_sitemap_urls(array $filenames = [], $include_index = true) {
        $storage = $this->get_sitemap_storage_paths();
        if (!$storage) {
            return [];
        }
        $urls = [];
        if ($include_index) {
            $urls[] = '/sitemap_index.xml';
            $urls[] = '/sitemap-index.xml';
            $urls[] = '/vpp-index.xml';
            foreach (['index_url', 'alias_index_url', 'root_index_url', 'yoast_index_url'] as $key) {
                if (!empty($storage[$key])) {
                    $urls[] = $storage[$key];
                }
            }
            $bases = ['sitemap_index.xml', 'sitemap-index.xml', 'vpp-index.xml'];
            foreach ($bases as $name) {
                if (!empty($storage['url'])) {
                    $urls[] = trailingslashit($storage['url']) . $name;
                }
                if (!empty($storage['upload_url'])) {
                    $urls[] = trailingslashit($storage['upload_url']) . $name;
                }
                if (!empty($storage['legacy_url'])) {
                    $urls[] = trailingslashit($storage['legacy_url']) . $name;
                }
            }
        }
        $filenames = array_values(array_unique(array_filter(array_map('basename', $filenames))));
        foreach ($filenames as $file) {
            if (!empty($storage['url'])) {
                $urls[] = trailingslashit($storage['url']) . $file;
            }
            if (!empty($storage['upload_url'])) {
                $urls[] = trailingslashit($storage['upload_url']) . $file;
            }
            if (!empty($storage['legacy_url'])) {
                $urls[] = trailingslashit($storage['legacy_url']) . $file;
            }
        }
        return $this->cf_normalize_urls($urls);
    }

    private function cf_collect_existing_sitemap_files() {
        $storage = $this->get_sitemap_storage_paths();
        if (!$storage) {
            return [];
        }
        $files = [];
        $dirs = [];
        if (!empty($storage['dir'])) {
            $dirs[] = $storage['dir'];
        }
        if (!empty($storage['legacy_dir'])) {
            $dirs[] = $storage['legacy_dir'];
        }
        foreach ($dirs as $dir) {
            $dir = trailingslashit($dir);
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '*.xml') ?: [] as $path) {
                $files[] = basename($path);
            }
        }
        return array_values(array_unique($files));
    }

    private function cf_collect_product_urls(array $slugs) {
        $urls = [];
        $base = rtrim(home_url(), '/');
        foreach ($slugs as $slug) {
            $slug = sanitize_title($slug);
            if ($slug === '') {
                continue;
            }
            $urls[] = $base . '/p/' . $slug;
        }
        return $this->cf_normalize_urls($urls);
    }

    private function cf_collect_blog_urls(array $slugs) {
        $urls = [];
        $base = rtrim(home_url(), '/');
        foreach ($slugs as $slug) {
            $slug = sanitize_title($slug);
            if ($slug === '') {
                continue;
            }
            $urls[] = $base . '/b/' . $slug;
        }
        return $this->cf_normalize_urls($urls);
    }

    private function cf_request($method, $endpoint, ?array $payload = null) {
        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->cf_get_api_token(),
                'Content-Type' => 'application/json',
            ],
            'timeout' => 25,
        ];
        if ($payload !== null) {
            $args['body'] = wp_json_encode($payload);
        }
        $response = wp_remote_request($endpoint, $args);
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'code' => 0,
                'body' => null,
                'message' => 'Cloudflare request failed: ' . $response->get_error_message(),
                'ray' => '',
                'should_retry' => true,
            ];
        }
        $code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);
        $success = ($code >= 200 && $code < 300 && (!is_array($body) || !isset($body['success']) || $body['success']));
        $message = '';
        if (!$success) {
            if (is_array($body) && !empty($body['errors'][0]['message'])) {
                $message = $body['errors'][0]['message'];
            } elseif ($code) {
                $message = 'HTTP ' . $code;
            } else {
                $message = 'Unknown error';
            }
        }
        $ray = function_exists('wp_remote_retrieve_header') ? wp_remote_retrieve_header($response, 'cf-ray') : '';
        $should_retry = !$success && ($code >= 500 || $code === 429);
        return [
            'success' => $success,
            'code' => $code,
            'body' => $body,
            'message' => $message,
            'ray' => $ray,
            'should_retry' => $should_retry,
        ];
    }

    private function cf_purge_files(array $urls) {
        $urls = $this->cf_normalize_urls($urls);
        $result = [
            'success' => false,
            'count' => 0,
            'duration' => 0,
            'messages' => [],
            'ray_ids' => [],
        ];
        if (empty($urls)) {
            $result['success'] = true;
            $result['messages'][] = 'No URLs to purge.';
            return $result;
        }
        if (!$this->cf_is_ready()) {
            $result['messages'][] = 'Cloudflare credentials not configured.';
            return $result;
        }
        $endpoint = $this->cf_build_endpoint('purge_cache');
        if ($endpoint === '') {
            $result['messages'][] = 'Cloudflare zone ID not configured.';
            return $result;
        }
        $start = microtime(true);
        foreach (array_chunk($urls, 2000) as $chunk) {
            $attempts = 0;
            $response = null;
            do {
                $attempts++;
                $response = $this->cf_request('POST', $endpoint, ['files' => $chunk]);
                if (!empty($response['success'])) {
                    $result['count'] += count($chunk);
                    if (!empty($response['ray'])) {
                        $result['ray_ids'][] = $response['ray'];
                    }
                    break;
                }
            } while ($attempts < 2 && !empty($response['should_retry']));
            if (empty($response['success'])) {
                $result['messages'][] = $response['message'] ?: 'Cloudflare purge failed.';
                $result['duration'] = microtime(true) - $start;
                return $result;
            }
        }
        $result['success'] = true;
        $result['duration'] = microtime(true) - $start;
        return $result;
    }

    private function cf_purge_sitemaps(array $filenames = []) {
        $urls = $this->cf_collect_sitemap_urls($filenames, true);
        $result = $this->cf_purge_files($urls);
        if (empty($result['messages'])) {
            $result['messages'][] = $result['success']
                ? sprintf('Cloudflare purge successful (%d URL%s).', $result['count'], $result['count'] === 1 ? '' : 's')
                : 'Cloudflare purge failed.';
        }
        $result['urls'] = $urls;
        return $result;
    }

    private function cf_purge_products(array $slugs) {
        $urls = $this->cf_collect_product_urls($slugs);
        $result = $this->cf_purge_files($urls);
        if (empty($result['messages'])) {
            $result['messages'][] = $result['success']
                ? sprintf('Cloudflare purge successful (%d URL%s).', $result['count'], $result['count'] === 1 ? '' : 's')
                : 'Cloudflare purge failed.';
        }
        $result['urls'] = $urls;
        return $result;
    }

    private function cf_purge_blogs(array $slugs) {
        $urls = $this->cf_collect_blog_urls($slugs);
        $result = $this->cf_purge_files($urls);
        if (empty($result['messages'])) {
            $result['messages'][] = $result['success']
                ? sprintf('Cloudflare purge successful (%d URL%s).', $result['count'], $result['count'] === 1 ? '' : 's')
                : 'Cloudflare purge failed.';
        }
        $result['urls'] = $urls;
        return $result;
    }

    private function cf_purge_everything() {
        $result = [
            'success' => false,
            'count' => 0,
            'duration' => 0,
            'messages' => [],
            'ray_ids' => [],
        ];
        if (!$this->cf_is_ready()) {
            $result['messages'][] = 'Cloudflare credentials not configured.';
            return $result;
        }
        $endpoint = $this->cf_build_endpoint('purge_cache');
        if ($endpoint === '') {
            $result['messages'][] = 'Cloudflare zone ID not configured.';
            return $result;
        }
        $start = microtime(true);
        $response = $this->cf_request('POST', $endpoint, ['purge_everything' => true]);
        $result['duration'] = microtime(true) - $start;
        if (!empty($response['success'])) {
            $result['success'] = true;
            $result['messages'][] = 'Cloudflare purge everything executed.';
            if (!empty($response['ray'])) {
                $result['ray_ids'][] = $response['ray'];
            }
        } else {
            $result['messages'][] = $response['message'] ?: 'Cloudflare purge failed.';
            if (!empty($response['ray'])) {
                $result['ray_ids'][] = $response['ray'];
            }
        }
        return $result;
    }

    private function cf_log_result($context, array $result) {
        $success = !empty($result['success']);
        $status = $success ? 'SUCCESS' : 'FAIL';
        $count = isset($result['count']) ? (int)$result['count'] : 0;
        $duration = isset($result['duration']) ? sprintf('%.2fs', $result['duration']) : 'n/a';
        $messages = !empty($result['messages']) ? implode(' | ', array_map('strval', $result['messages'])) : '';
        $rays = !empty($result['ray_ids']) ? 'ray=' . implode(',', array_map('strval', $result['ray_ids'])) : '';
        $parts = array_filter([$status, "context={$context}", "urls={$count}", "duration={$duration}", $rays, $messages]);
        $this->log_error('cloudflare', implode(' ', $parts));
    }

    private function get_cf_last_batch() {
        $stored = get_option(self::CF_LAST_BATCH_OPTION);
        if (!is_array($stored)) {
            return null;
        }
        $files = [];
        foreach ((array)($stored['sitemap_files'] ?? []) as $file) {
            $file = basename((string)$file);
            if ($file !== '') {
                $files[] = $file;
            }
        }
        $files = array_values(array_unique($files));
        $slugs = [];
        foreach ((array)($stored['product_slugs'] ?? []) as $slug) {
            $slug = sanitize_title($slug);
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }
        $slugs = array_values(array_unique($slugs));
        $timestamp = isset($stored['timestamp']) ? (int)$stored['timestamp'] : 0;
        return [
            'timestamp' => $timestamp,
            'sitemap_files' => $files,
            'product_slugs' => $slugs,
        ];
    }

    private function save_cf_last_batch(array $data) {
        $files = [];
        foreach ((array)($data['sitemap_files'] ?? []) as $file) {
            $file = basename((string)$file);
            if ($file !== '') {
                $files[] = $file;
            }
        }
        $files = array_values(array_unique($files));
        $slugs = [];
        foreach ((array)($data['product_slugs'] ?? []) as $slug) {
            $slug = sanitize_title($slug);
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }
        $slugs = array_values(array_unique($slugs));
        $payload = [
            'timestamp' => isset($data['timestamp']) ? (int)$data['timestamp'] : time(),
            'sitemap_files' => $files,
            'product_slugs' => $slugs,
        ];
        update_option(self::CF_LAST_BATCH_OPTION, $payload, false);
    }

    private function cf_reset_recent() {
        $this->cf_recent_sitemaps = [];
        $this->cf_recent_sitemap_files = [];
        $this->cf_recent_product_slugs = [];
    }

    private function cf_record_recent_sitemaps(array $urls, array $filenames = []) {
        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url !== '') {
                $this->cf_recent_sitemaps[$url] = true;
            }
        }
        foreach ($filenames as $file) {
            $file = basename((string)$file);
            if ($file !== '') {
                $this->cf_recent_sitemap_files[$file] = true;
            }
        }
    }

    private function cf_record_recent_products(array $slugs) {
        foreach ($slugs as $slug) {
            $slug = sanitize_title($slug);
            if ($slug !== '') {
                $this->cf_recent_product_slugs[$slug] = true;
            }
        }
    }

    private function cf_get_recent_context() {
        return [
            'sitemap_files' => array_keys($this->cf_recent_sitemap_files),
            'sitemap_urls' => array_keys($this->cf_recent_sitemaps),
            'product_slugs' => array_keys($this->cf_recent_product_slugs),
        ];
    }

    public function cf_on_publish_completed($changed_sitemaps, $published_slugs) {
        $files = [];
        foreach ((array)$changed_sitemaps as $file) {
            $file = basename((string)$file);
            if ($file !== '') {
                $files[] = $file;
            }
        }
        $files = array_values(array_unique($files));
        $slugs = [];
        foreach ((array)$published_slugs as $slug) {
            $slug = sanitize_title($slug);
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }
        $slugs = array_values(array_unique($slugs));
        $this->save_cf_last_batch([
            'timestamp' => time(),
            'sitemap_files' => $files,
            'product_slugs' => $slugs,
        ]);
        $settings = $this->get_settings();
        if (empty($settings['cloudflare']['enable_purge_on_publish']) || !$this->cf_is_ready()) {
            return;
        }
        $sitemap_result = $this->cf_purge_sitemaps($files);
        $this->cf_log_result('auto_publish_sitemaps', $sitemap_result);
        if (!empty($settings['cloudflare']['include_product_urls']) && !empty($slugs)) {
            $product_result = $this->cf_purge_products($slugs);
            $this->cf_log_result('auto_publish_products', $product_result);
        }
    }

    private function purge_sitemap_cache(array $storage, array $filenames = []) {
        $settings = $this->get_settings();
        $token = trim($settings['cloudflare']['api_token'] ?? '');
        $zone  = trim($settings['cloudflare']['zone_id'] ?? '');
        if (!$token || !$zone) {
            return;
        }

        $site_base = trim($settings['cloudflare']['site_base'] ?? '');
        $urls = [];

        $candidates = [
            $storage['index_url'] ?? '',
            $storage['alias_index_url'] ?? '',
            $storage['root_index_url'] ?? '',
            $storage['yoast_index_url'] ?? '',
        ];
        foreach ($candidates as $candidate) {
            if ($candidate) {
                $urls[] = $candidate;
            }
        }

        $index_variants = ['sitemap_index.xml', 'sitemap-index.xml', 'vpp-index.xml'];
        foreach ($index_variants as $index) {
            if (!empty($storage['upload_url'])) {
                $urls[] = $storage['upload_url'] . $index;
            }
            if (!empty($storage['legacy_url'])) {
                $urls[] = $storage['legacy_url'] . $index;
            }
        }

        $filenames = array_unique(array_filter(array_map('basename', $filenames)));
        foreach ($filenames as $name) {
            if (!empty($storage['url'])) {
                $urls[] = rtrim($storage['url'], '/') . '/' . $name;
            }
            if (!empty($storage['upload_url'])) {
                $urls[] = $storage['upload_url'] . $name;
            }
            if (!empty($storage['legacy_url'])) {
                $urls[] = $storage['legacy_url'] . $name;
            }
        }

        $urls = $this->expand_site_base_urls(array_values(array_unique(array_filter($urls))), $site_base);
        $this->cf_purge_files($urls);
    }

    private function expand_site_base_urls(array $urls, $site_base) {
        if (!$site_base) {
            return $urls;
        }
        $expanded = $urls;
        foreach ($urls as $url) {
            $rewritten = $this->swap_url_base($url, $site_base);
            if ($rewritten) {
                $expanded[] = $rewritten;
            }
        }
        return array_values(array_unique(array_filter($expanded)));
    }

    private function swap_url_base($url, $site_base) {
        $target = wp_parse_url($url);
        $base = wp_parse_url($site_base);
        if (!$target || !$base || empty($target['path'])) {
            return $url;
        }
        $scheme = $base['scheme'] ?? ($target['scheme'] ?? 'https');
        $host = $base['host'] ?? ($target['host'] ?? '');
        if (!$host) {
            return $url;
        }
        $port = isset($base['port']) ? ':' . $base['port'] : '';
        $path = $target['path'];
        $query = isset($target['query']) ? '?' . $target['query'] : '';
        return $scheme . '://' . $host . $port . $path . $query;
    }

    private function rebuild_sitemaps(&$summary = '', &$err = null) {
        $mysqli = $this->db_connect($err);
        if (!$mysqli) { return false; }
        $this->cf_reset_recent();
        if (!is_array($changed_files)) { $changed_files = []; }

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
        $purge_files = [];

        foreach (glob($dir . 'sitemap-*.xml') ?: [] as $old) { $purge_files[] = basename($old); @unlink($old); }
        foreach (glob($dir . 'products-*.xml') ?: [] as $old) { $purge_files[] = basename($old); @unlink($old); }
        foreach (glob($dir . 'blog-*.xml') ?: [] as $old) { $purge_files[] = basename($old); @unlink($old); }
        @unlink($dir . 'sitemap-index.xml');
        @unlink($dir . 'sitemap_index.xml');
        @unlink($dir . 'vpp-index.xml');
        $purge_files[] = 'sitemap-index.xml';
        $purge_files[] = 'sitemap_index.xml';
        $purge_files[] = 'vpp-index.xml';
        if (!empty($storage['legacy_dir']) && is_dir($storage['legacy_dir'])) {
            foreach (glob($storage['legacy_dir'] . 'sitemap-*.xml') ?: [] as $old) { $purge_files[] = basename($old); @unlink($old); }
            foreach (glob($storage['legacy_dir'] . 'products-*.xml') ?: [] as $old) { $purge_files[] = basename($old); @unlink($old); }
            foreach (glob($storage['legacy_dir'] . 'blog-*.xml') ?: [] as $old) { $purge_files[] = basename($old); @unlink($old); }
            @unlink($storage['legacy_dir'] . 'sitemap-index.xml');
            @unlink($storage['legacy_dir'] . 'sitemap_index.xml');
            @unlink($storage['legacy_dir'] . 'vpp-index.xml');
            $purge_files[] = 'sitemap-index.xml';
            $purge_files[] = 'sitemap_index.xml';
            $purge_files[] = 'vpp-index.xml';
        }

        $write_chunk = function(array $entries, $lastmod_ts, $index) use (&$files, $dir, $base_url, &$err, &$purge_files) {
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
            $purge_files[] = $filename;
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
                    if (!$write_chunk($chunk_entries, $chunk_lastmod, $chunk_index)) {
                        $res->free();
                        @$mysqli->close();
                        $changed_files = array_values(array_unique($purge_files));
                        return false;
                    }
                    $chunk_entries = []; $chunk_lastmod = 0;
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
                $changed_files = array_values(array_unique($purge_files));
                return false;
            }
        }

        $blog_table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$this->get_blog_table());
        $blog_chunk_index = 0;
        $blog_chunk_entries = [];
        $blog_chunk_lastmod = 0;
        $blog_chunk_total = 0;
        $write_blog_chunk = function(array $entries, $lastmod_ts, $index) use (&$files, $dir, $base_url, &$err, &$purge_files) {
            if (empty($entries)) { return true; }
            $filename = sprintf('blog-%d.xml', $index);
            $path = $dir . $filename;
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($entries as $entry) {
                $slug = sanitize_title($entry['slug'] ?? '');
                if ($slug === '') { continue; }
                $loc = isset($entry['loc']) && $entry['loc'] !== '' ? (string)$entry['loc'] : home_url('/b/' . $slug);
                $loc = esc_url($loc);
                if ($loc === '') { continue; }
                $xml .= "  <url>\n";
                $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</loc>' . "\n";
                $xml .= '    <lastmod>' . gmdate('c', (int)$entry['lastmod']) . '</lastmod>' . "\n";
                $xml .= "  </url>\n";
            }
            $xml .= '</urlset>';
            if (@file_put_contents($path, $xml) === false) { $err = 'Failed to write ' . $filename; return false; }
            $files[] = ['loc' => $base_url . $filename, 'lastmod' => gmdate('c', $lastmod_ts ?: time())];
            $purge_files[] = $filename;
            return true;
        };
        $table_exists = $blog_table !== '' && $this->table_exists($mysqli, $blog_table);
        if ($table_exists) {
            $blog_chunk_entries = [];
            $blog_chunk_lastmod = 0;
            $blog_batch_size = 2000;
            $blog_offset = 0;
            while (true) {
                $sql = sprintf("SELECT slug, published_at, last_tidb_update_at, is_published FROM `%s` WHERE is_published = 1 ORDER BY id LIMIT %d OFFSET %d", $blog_table, $blog_batch_size, $blog_offset);
                $res = $mysqli->query($sql);
                if (!$res) { @$mysqli->close(); $err = 'DB query failed: ' . $mysqli->error; return false; }
                $row_count = 0;
                while ($row = $res->fetch_assoc()) {
                    $row_count++;
                    $row_slug = trim((string)($row['slug'] ?? ''));
                    if ($row_slug === '') { continue; }
                    $row_data = [
                        'slug' => $row_slug,
                        'is_published' => (int)($row['is_published'] ?? 0),
                        'published_at' => $row['published_at'] ?? '',
                        'last_tidb_update_at' => $row['last_tidb_update_at'] ?? '',
                    ];
                    if (!$this->is_blog_post_public($row_data)) { continue; }
                    $lastmod = !empty($row_data['last_tidb_update_at']) ? strtotime($row_data['last_tidb_update_at']) : false;
                    if (!$lastmod && !empty($row_data['published_at'])) { $lastmod = strtotime($row_data['published_at']); }
                    if (!$lastmod) { $lastmod = time(); }
                    $blog_chunk_entries[] = ['slug' => $row_slug, 'lastmod' => $lastmod];
                    $blog_chunk_lastmod = max($blog_chunk_lastmod, $lastmod);
                    $blog_chunk_total++;
                    if (count($blog_chunk_entries) >= $chunk_size) {
                        $blog_chunk_index++;
                        if (!$write_blog_chunk($blog_chunk_entries, $blog_chunk_lastmod, $blog_chunk_index)) {
                            $res->free();
                            @$mysqli->close();
                            $changed_files = array_values(array_unique($purge_files));
                            return false;
                        }
                        $blog_chunk_entries = [];
                        $blog_chunk_lastmod = 0;
                    }
                }
                $res->free();
                if ($row_count < $blog_batch_size) { break; }
                $blog_offset += $blog_batch_size;
            }
            if (!empty($blog_chunk_entries)) {
                $blog_chunk_index++;
                if (!$write_blog_chunk($blog_chunk_entries, $blog_chunk_lastmod, $blog_chunk_index)) {
                    @$mysqli->close();
                    $changed_files = array_values(array_unique($purge_files));
                    return false;
                }
            }
        }

        if (!$table_exists || $blog_chunk_total === 0) {
            $blog_entries = $this->collect_wp_blog_posts_for_sitemap();
            if (!empty($blog_entries)) {
                $blog_chunk_entries = [];
                $blog_chunk_lastmod = 0;
                foreach ($blog_entries as $entry) {
                    $slug = sanitize_title($entry['slug'] ?? '');
                    if ($slug === '') { continue; }
                    $lastmod = isset($entry['lastmod']) ? (int)$entry['lastmod'] : time();
                    $loc = isset($entry['loc']) ? (string)$entry['loc'] : '';
                    $blog_chunk_entries[] = ['slug' => $slug, 'lastmod' => $lastmod, 'loc' => $loc];
                    $blog_chunk_lastmod = max($blog_chunk_lastmod, $lastmod);
                    $blog_chunk_total++;
                    if (count($blog_chunk_entries) >= $chunk_size) {
                        $blog_chunk_index++;
                        if (!$write_blog_chunk($blog_chunk_entries, $blog_chunk_lastmod, $blog_chunk_index)) {
                            @$mysqli->close();
                            $changed_files = array_values(array_unique($purge_files));
                            return false;
                        }
                        $blog_chunk_entries = [];
                        $blog_chunk_lastmod = 0;
                    }
                }
                if (!empty($blog_chunk_entries)) {
                    $blog_chunk_index++;
                    if (!$write_blog_chunk($blog_chunk_entries, $blog_chunk_lastmod, $blog_chunk_index)) {
                        @$mysqli->close();
                        $changed_files = array_values(array_unique($purge_files));
                        return false;
                    }
                }
            }
        }

        @$mysqli->close();

        $cat_err = null;
        $category_entry = $this->write_category_sitemap($cat_err);
        if (is_array($category_entry)) {
            $files[] = ['loc' => $category_entry['url'], 'lastmod' => $category_entry['lastmod']];
        } elseif (!empty($cat_err)) {
            $this->log_error('sitemap', $cat_err);
        }

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
        $purge_files[] = 'sitemap-index.xml';
        $purge_files[] = 'sitemap_index.xml';
        $purge_files[] = 'vpp-index.xml';
        if (!empty($storage['legacy_dir']) && wp_mkdir_p($storage['legacy_dir'])) {
            @file_put_contents($storage['legacy_dir'] . 'sitemap-index.xml', $index_xml);
            @file_put_contents($storage['legacy_dir'] . 'sitemap_index.xml', $index_xml);
            @file_put_contents($storage['legacy_dir'] . 'vpp-index.xml', $index_xml);
            $purge_files[] = 'sitemap-index.xml';
            $purge_files[] = 'sitemap_index.xml';
            $purge_files[] = 'vpp-index.xml';
        }

        $meta = $this->get_sitemap_meta();
        $meta['base_url'] = $base_url;
        $this->save_sitemap_meta($meta);

        if (!empty($purge_files)) {
            $this->purge_sitemap_cache($storage, $purge_files);
        }

        $meta = $this->get_sitemap_meta();
        $meta['base_url'] = $base_url;
        $this->save_sitemap_meta($meta);

        $summary = sprintf('Generated %d sitemap file(s) covering %d product(s).', count($files), $total);
        $changed_files = array_values(array_unique($purge_files));
        return true;
    }

    private function regenerate_blog_sitemaps(&$err = null, ?array &$changed_files = null) {
        $storage = $this->get_sitemap_storage_paths();
        if (!$storage) { $err = 'Uploads directory is not writable.'; return false; }
        $dir = $storage['dir'];
        if (!wp_mkdir_p($dir)) { $err = 'Failed to create sitemap directory.'; return false; }
        $dir = trailingslashit($dir);
        $legacy_dir = !empty($storage['legacy_dir']) ? trailingslashit($storage['legacy_dir']) : '';
        $purge_files = [];
        foreach (glob($dir . 'blog-*.xml') ?: [] as $old) { $purge_files[] = basename($old); @unlink($old); }
        foreach (glob($dir . 'sitemap-blog-*.xml') ?: [] as $old) { $purge_files[] = basename($old); @unlink($old); }
        if ($legacy_dir && is_dir($legacy_dir)) {
            foreach (glob($legacy_dir . 'blog-*.xml') ?: [] as $old) { $purge_files[] = basename($old); @unlink($old); }
            foreach (glob($legacy_dir . 'sitemap-blog-*.xml') ?: [] as $old) { $purge_files[] = basename($old); @unlink($old); }
        }

        $mysqli = $this->db_connect($err);
        if (!$mysqli) { return false; }
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$this->get_blog_table());
        $has_table = $table !== '' && $this->table_exists($mysqli, $table);
        $chunk_size = 50000;
        $chunk_entries = [];
        $file_index = 0;
        $written_total = 0;

        if ($has_table) {
            $blog_batch_size = 2000;
            $blog_offset = 0;
            while (true) {
                $sql = sprintf("SELECT slug, published_at, last_tidb_update_at, is_published FROM `%s` WHERE is_published = 1 ORDER BY id LIMIT %d OFFSET %d", $table, $blog_batch_size, $blog_offset);
                $res = $mysqli->query($sql);
                if (!$res) { @$mysqli->close(); $err = 'DB query failed: ' . $mysqli->error; return false; }
                $row_count = 0;
                while ($row = $res->fetch_assoc()) {
                    $row_count++;
                    $slug = sanitize_title($row['slug'] ?? '');
                    if ($slug === '') { continue; }
                    $data = [
                        'slug' => $slug,
                        'is_published' => (int)($row['is_published'] ?? 0),
                        'published_at' => $row['published_at'] ?? '',
                        'last_tidb_update_at' => $row['last_tidb_update_at'] ?? '',
                    ];
                    if (!$this->is_blog_post_public($data)) { continue; }
                    $lastmod = !empty($data['last_tidb_update_at']) ? strtotime($data['last_tidb_update_at']) : false;
                    if (!$lastmod && !empty($data['published_at'])) { $lastmod = strtotime($data['published_at']); }
                    if (!$lastmod) { $lastmod = time(); }
                    $chunk_entries[] = [
                        'slug' => $slug,
                        'lastmod' => gmdate('c', $lastmod),
                    ];
                    $written_total++;
                    if (count($chunk_entries) >= $chunk_size) {
                        $file_index++;
                        $filename = sprintf('blog-%d.xml', $file_index);
                        $xml = $this->build_sitemap_xml($chunk_entries, 'blog');
                        if ($xml === false || @file_put_contents($dir . $filename, $xml) === false) { @$mysqli->close(); $err = 'Failed to write ' . $filename; return false; }
                        if ($legacy_dir) { @file_put_contents($legacy_dir . $filename, $xml); }
                        $purge_files[] = $filename;
                        $chunk_entries = [];
                    }
                }
                $res->free();
                if ($row_count < $blog_batch_size) { break; }
                $blog_offset += $blog_batch_size;
            }
        }

        if (!$has_table || $written_total === 0) {
            $fallback_entries = $this->collect_wp_blog_posts_for_sitemap();
            foreach ($fallback_entries as $entry) {
                $slug = sanitize_title($entry['slug'] ?? '');
                if ($slug === '') { continue; }
                $lastmod_ts = isset($entry['lastmod']) ? (int)$entry['lastmod'] : time();
                $chunk_entries[] = [
                    'slug' => $slug,
                    'lastmod' => gmdate('c', $lastmod_ts),
                    'loc' => isset($entry['loc']) ? (string)$entry['loc'] : '',
                ];
                $written_total++;
                if (count($chunk_entries) >= $chunk_size) {
                    $file_index++;
                    $filename = sprintf('blog-%d.xml', $file_index);
                    $xml = $this->build_sitemap_xml($chunk_entries, 'blog');
                    if ($xml === false || @file_put_contents($dir . $filename, $xml) === false) { @$mysqli->close(); $err = 'Failed to write ' . $filename; return false; }
                    if ($legacy_dir) { @file_put_contents($legacy_dir . $filename, $xml); }
                    $purge_files[] = $filename;
                    $chunk_entries = [];
                }
            }
        }

        if (!empty($chunk_entries)) {
            $file_index++;
            $filename = sprintf('blog-%d.xml', $file_index);
            $xml = $this->build_sitemap_xml($chunk_entries, 'blog');
            if ($xml === false || @file_put_contents($dir . $filename, $xml) === false) { @$mysqli->close(); $err = 'Failed to write ' . $filename; return false; }
            if ($legacy_dir) { @file_put_contents($legacy_dir . $filename, $xml); }
            $purge_files[] = $filename;
        }

        @$mysqli->close();

        $refresh_err = null;
        $index_files = [];
        if ($this->refresh_sitemap_index($storage, $refresh_err, $index_files) === false) { $err = $refresh_err ?: 'Failed to refresh sitemap index.'; return false; }
        $purge_targets = array_values(array_unique(array_merge($purge_files, $index_files)));
        if (!empty($purge_targets)) {
            $this->purge_sitemap_cache($storage, $purge_targets);
            $urls = $this->cf_collect_sitemap_urls($purge_targets, false);
            $this->cf_record_recent_sitemaps($urls, $purge_targets);
        }
        if ($changed_files === null) { $changed_files = []; }
        $changed_files = array_values(array_unique(array_merge($changed_files, $purge_targets)));
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

    private function current_blog_slug() {
        $slug = get_query_var(self::BLOG_QUERY_VAR);
        if ($slug) {
            return sanitize_title($slug);
        }
        return null;
    }

    private function current_vpp_slug() {
        $slug = get_query_var(self::QUERY_VAR);
        if ($slug) { return sanitize_title($slug); }
        return null;
    }

    private function current_category_slug() {
        $slug = get_query_var(self::CATEGORY_SLUG_QUERY_VAR);
        if (!$slug) {
            return null;
        }
        $slug = sanitize_title($slug);
        return $slug === '' ? null : $slug;
    }

    private function current_category_page() {
        $page = get_query_var(self::CATEGORY_PAGE_QUERY_VAR);
        if (!$page) {
            return 1;
        }
        $page = (int)$page;
        return $page > 0 ? $page : 1;
    }

    private function is_category_request() {
        $index = get_query_var(self::CATEGORY_INDEX_QUERY_VAR);
        $slug = get_query_var(self::CATEGORY_SLUG_QUERY_VAR);
        return !empty($index) || !empty($slug);
    }

    private function ensure_category_context(&$err = null) {
        if (is_array($this->current_category_context)) {
            return $this->current_category_context;
        }
        if (!$this->is_category_request()) {
            return null;
        }
        $per_page = $this->get_category_per_page();
        $page = $this->current_category_page();
        $slug = $this->current_category_slug();
        $context = $slug
            ? $this->get_category_archive_data($slug, $page, $per_page, $err)
            : $this->get_category_index_data($page, $per_page, false, $err);
        if ($context) {
            $this->current_category_context = $context;
        }
        return $this->current_category_context;
    }

    private function get_current_blog_post(&$err = null) {
        $slug = $this->current_blog_slug();
        if (!$slug) {
            return null;
        }
        if ($this->current_blog_slug === $slug && is_array($this->current_blog)) {
            return $this->current_blog;
        }
        $post = $this->fetch_blog_post_by_slug($slug, $err);
        $this->current_blog_slug = $slug;
        $this->current_blog = $post ?: null;
        $this->current_meta_description = '';
        $this->current_canonical = '';
        return $this->current_blog;
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
        $blog_slug = $this->current_blog_slug();
        if ($blog_slug) {
            $err = null;
            $post = $this->get_current_blog_post($err);
            if ($post) {
                return $this->get_blog_canonical_url($post);
            }
        }
        $slug = $this->current_vpp_slug();
        if ($slug) {
            return home_url('/p/' . $slug);
        }
        if ($this->is_category_request()) {
            $err = null;
            $context = $this->ensure_category_context($err);
            if ($context && !empty($context['canonical'])) {
                return $context['canonical'];
            }
        }
        return $url;
    }

    public function filter_document_title($title) {
        if ($this->current_blog_slug()) {
            $err = null;
            $post = $this->get_current_blog_post($err);
            if ($post) {
                return $this->build_blog_document_title($post);
            }
        }
        if ($this->current_vpp_slug()) {
            $err = null;
            $p = $this->get_current_product($err);
            if (!$p) return $title;
            $base = $p['title_h1'] ?: $p['slug'];
            $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
            return $base . ' | ' . $site;
        }
        if ($this->is_category_request()) {
            $err = null;
            $context = $this->ensure_category_context($err);
            if ($context && !empty($context['title'])) {
                return $context['title'];
            }
        }
        return $title;
    }

    public function filter_yoast_metadesc($value) {
        if ($this->current_blog_slug()) {
            $err = null;
            $post = $this->get_current_blog_post($err);
            if ($post) {
                $meta = $this->build_blog_meta_description($post);
                $this->last_meta_source = 'Blog page';
                $this->last_meta_value = $meta;
                return $meta;
            }
        }
        if ($this->current_vpp_slug()) {
            $err = null;
            $p = $this->get_current_product($err);
            if (!$p) return $value;
            $meta = $this->build_meta_description($p);
            $this->last_meta_source = 'Yoast filter';
            $this->last_meta_value = $meta;
            return $meta;
        }
        if ($this->is_category_request()) {
            $err = null;
            $context = $this->ensure_category_context($err);
            if ($context && !empty($context['meta_description'])) {
                $this->last_meta_source = 'Category';
                $this->last_meta_value = $context['meta_description'];
                return $context['meta_description'];
            }
        }
        return $value;
    }

    public function filter_yoast_presenters($presenters) {
        if (!$this->current_vpp_slug() && !$this->current_blog_slug() && !$this->is_category_request()) {
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
            $index_files = [];
            if ($this->refresh_sitemap_index($storage, $refresh_err, $index_files) === false && $refresh_err) {
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
                $index_files = [];
                if ($this->refresh_sitemap_index($storage, $refresh_err, $index_files) === false && $refresh_err) {
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
        $blog_slug = $this->current_blog_slug();
        if ($blog_slug) {
            $err = null;
            $post = $this->get_current_blog_post($err);
            if (!$post || !$this->is_blog_post_public($post)) {
                status_header(404);
                nocache_headers();
                echo '<!doctype html><html><head><meta charset="utf-8"><title>Not found</title></head><body><h1>404</h1><p>Post not found.</p></body></html>';
                exit;
            }
            $this->render_blog_post($post);
            exit;
        }

        $slug = $this->current_vpp_slug();
        if ($slug) {
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

        if ($this->is_category_request()) {
            $err = null;
            $context = $this->ensure_category_context($err);
            if (!$context) {
                $this->render_category_not_found();
                exit;
            }

            if (($context['type'] ?? '') === 'archive') {
                $this->render_category_archive($context);
            } else {
                $this->render_category_index($context);
            }
            exit;
        }
    }

    private function build_category_page_url($base, $page) {
        $base = trailingslashit($base);
        if ($page <= 1) {
            return $base;
        }
        return trailingslashit($base . 'page/' . (int)$page);
    }

    private function render_category_pagination($current, $total, $base_url) {
        $current = (int)$current;
        $total = (int)$total;
        if ($total <= 1) {
            return;
        }
        $base_url = trailingslashit($base_url);
        $start = max(1, $current - 2);
        $end = min($total, $current + 2);
        echo '<nav class="vpp-pagination" aria-label="Pagination"><ul class="vpp-pagination-list">';
        if ($current > 1) {
            $prev = $this->build_category_page_url($base_url, $current - 1);
            echo '<li class="vpp-page-item"><a class="vpp-page-link" href="' . esc_url($prev) . '">' . esc_html__('Prev', 'virtual-product-pages') . '</a></li>';
        } else {
            echo '<li class="vpp-page-item disabled"><span class="vpp-page-link">' . esc_html__('Prev', 'virtual-product-pages') . '</span></li>';
        }
        for ($i = $start; $i <= $end; $i++) {
            $url = $this->build_category_page_url($base_url, $i);
            if ($i === $current) {
                echo '<li class="vpp-page-item active"><span class="vpp-page-link" aria-current="page">' . esc_html($i) . '</span></li>';
            } else {
                echo '<li class="vpp-page-item"><a class="vpp-page-link" href="' . esc_url($url) . '">' . esc_html($i) . '</a></li>';
            }
        }
        if ($current < $total) {
            $next = $this->build_category_page_url($base_url, $current + 1);
            echo '<li class="vpp-page-item"><a class="vpp-page-link" href="' . esc_url($next) . '">' . esc_html__('Next', 'virtual-product-pages') . '</a></li>';
        } else {
            echo '<li class="vpp-page-item disabled"><span class="vpp-page-link">' . esc_html__('Next', 'virtual-product-pages') . '</span></li>';
        }
        echo '</ul></nav>';
    }

    private function render_category_index($context) {
        $items = isset($context['items']) ? $context['items'] : [];
        $total = isset($context['total']) ? (int)$context['total'] : 0;
        $page = isset($context['page']) ? (int)$context['page'] : 1;
        $total_pages = isset($context['total_pages']) ? (int)$context['total_pages'] : 1;
        $count_label = $this->format_items_label($total);
        $inline_css = $this->current_inline_css;
        if ($inline_css === '') {
            $inline_css = $this->load_css_contents();
            $this->current_inline_css = $inline_css;
        }

        @header('Content-Type: text/html; charset=utf-8');
        @header('Cache-Control: public, max-age=300');

        get_header();

        if ($inline_css !== '') {
            echo '<style id="vpp-inline-css-fallback">' . $inline_css . '</style>';
        }

        $base_url = home_url('/p-cat/');

        echo '<main class="vpp-container vpp-categories">';
        echo '<div class="vpp">';
        echo '<section class="vpp card-elevated vpp-cat-hero">';
        echo '<h1 class="vpp-cat-title">' . esc_html__('Categories', 'virtual-product-pages') . '</h1>';
        echo '<p class="vpp-cat-subtitle">' . esc_html($count_label) . '</p>';
        echo '</section>';

        if (empty($items)) {
            echo '<section class="vpp card vpp-cat-empty"><p>' . esc_html__('No categories yet.', 'virtual-product-pages') . '</p></section>';
        } else {
            echo '<section class="vpp card vpp-cat-grid-wrap">';
            echo '<div class="vpp-cat-grid">';
            foreach ($items as $item) {
                $slug = $item['slug'];
                $name = $item['name'];
                $count = isset($item['count']) ? (int)$item['count'] : 0;
                $href = $this->build_category_page_url(home_url('/p-cat/' . $slug . '/'), 1);
                echo '<a class="vpp-cat-card" href="' . esc_url($href) . '" aria-label="' . esc_attr(sprintf(__('View category: %s', 'virtual-product-pages'), $name)) . '">';
                echo '<span class="vpp-cat-name">' . esc_html($name) . '</span>';
                echo '<span class="vpp-cat-count">' . esc_html($this->format_items_label($count)) . '</span>';
                echo '</a>';
            }
            echo '</div>';
            echo '</section>';
            $this->render_category_pagination($page, $total_pages, $base_url);
        }

        $this->output_category_json_ld($context);

        echo '</div>';
        echo '</main>';

        get_footer();
    }

    private function render_category_archive($context) {
        $items = isset($context['items']) ? $context['items'] : [];
        $count = isset($context['count']) ? (int)$context['count'] : 0;
        $page = isset($context['page']) ? (int)$context['page'] : 1;
        $total_pages = isset($context['total_pages']) ? (int)$context['total_pages'] : 1;
        $name = isset($context['name']) ? $context['name'] : '';
        $slug = isset($context['slug']) ? $context['slug'] : '';
        $count_label = $this->format_items_label($count);
        $inline_css = $this->current_inline_css;
        if ($inline_css === '') {
            $inline_css = $this->load_css_contents();
            $this->current_inline_css = $inline_css;
        }

        @header('Content-Type: text/html; charset=utf-8');
        @header('Cache-Control: public, max-age=300');

        get_header();

        if ($inline_css !== '') {
            echo '<style id="vpp-inline-css-fallback">' . $inline_css . '</style>';
        }

        $base_url = home_url('/p-cat/' . $slug . '/');

        echo '<main class="vpp-container vpp-category-archive">';
        echo '<div class="vpp">';
        echo '<section class="vpp card-elevated vpp-archive-hero">';
        echo '<h1 class="vpp-archive-title">' . esc_html($name) . '</h1>';
        $subtitle = $count_label;
        if ($total_pages > 1) {
            $subtitle .= ' • ' . sprintf(__('Page %d of %d', 'virtual-product-pages'), $page, $total_pages);
        }
        echo '<p class="vpp-archive-subtitle">' . esc_html($subtitle) . '</p>';
        echo '</section>';

        if (empty($items)) {
            echo '<section class="vpp card vpp-archive-empty"><p>' . esc_html__('No products in this category yet.', 'virtual-product-pages') . '</p></section>';
        } else {
            echo '<section class="vpp card vpp-archive-grid">';
            foreach ($items as $item) {
                $product_slug = $item['slug'];
                if (!$product_slug) { continue; }
                $title = $item['title_h1'] ?: $product_slug;
                $brand = $item['brand'] ?? '';
                $model = $item['model'] ?? '';
                $summary = isset($item['short_summary']) ? trim((string)$item['short_summary']) : '';
                if ($summary !== '' && function_exists('mb_substr')) {
                    $summary = mb_substr($summary, 0, 160);
                } elseif ($summary !== '') {
                    $summary = substr($summary, 0, 160);
                }
                $parts = array_filter([$brand, $model]);
                $meta = implode(' • ', $parts);
                $image_url = '';
                if (!empty($item['images_json'])) {
                    $decoded = json_decode($item['images_json'], true);
                    if (is_array($decoded) && !empty($decoded[0])) {
                        $image_url = trim((string)$decoded[0]);
                    }
                }
                $href = home_url('/p/' . $product_slug . '/');
                echo '<article class="vpp-archive-card">';
                if ($image_url !== '') {
                    echo '<div class="vpp-archive-card-thumb"><img src="' . esc_url($image_url) . '" alt="' . esc_attr($title) . '" loading="lazy" decoding="async"></div>';
                } else {
                    echo '<div class="vpp-archive-card-thumb placeholder" aria-hidden="true"></div>';
                }
                echo '<div class="vpp-archive-card-body">';
                echo '<div class="vpp-archive-card-header">';
                echo '<h2 class="vpp-archive-card-title">' . esc_html($title) . '</h2>';
                if ($meta !== '') {
                    echo '<p class="vpp-archive-card-meta">' . esc_html($meta) . '</p>';
                }
                echo '</div>';
                if ($summary !== '') {
                    echo '<p class="vpp-archive-card-summary">' . esc_html($summary) . '</p>';
                }
                echo '</div>';
                echo '<div class="vpp-archive-card-footer">';
                echo '<a class="vpp-archive-card-button" href="' . esc_url($href) . '">' . esc_html__('GoToProduct', 'virtual-product-pages') . '</a>';
                echo '</div>';
                echo '</article>';
            }
            echo '</section>';
            $this->render_category_pagination($page, $total_pages, $base_url);
        }

        $this->output_category_json_ld($context);

        echo '</div>';
        echo '</main>';

        get_footer();
    }

    private function render_category_not_found() {
        status_header(404);
        nocache_headers();
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Not found</title></head><body><h1>404</h1><p>' . esc_html__('Category not found.', 'virtual-product-pages') . '</p></body></html>';
    }

    private function output_category_json_ld($context) {
        if (!is_array($context) || empty($context['canonical'])) {
            return;
        }
        $canonical = $context['canonical'];
        $offset = isset($context['offset']) ? (int)$context['offset'] : 0;
        $items = isset($context['items']) && is_array($context['items']) ? $context['items'] : [];
        $breadcrumb = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => get_bloginfo('name'),
                    'item' => home_url('/'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => __('Categories', 'virtual-product-pages'),
                    'item' => trailingslashit(home_url('/p-cat/')),
                ],
            ],
        ];

        if ($context['type'] === 'archive') {
            $breadcrumb['itemListElement'][] = [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $context['name'],
                'item' => $context['canonical'],
            ];
            if ($context['page'] > 1) {
                $breadcrumb['itemListElement'][] = [
                    '@type' => 'ListItem',
                    'position' => 4,
                    'name' => sprintf(__('Page %d', 'virtual-product-pages'), $context['page']),
                    'item' => $context['canonical'],
                ];
            }
            $item_list = [];
            $position = $offset + 1;
            foreach ($items as $item) {
                if (empty($item['slug'])) {
                    continue;
                }
                $item_list[] = [
                    '@type' => 'ListItem',
                    'position' => $position++,
                    'name' => $item['title_h1'] ?: $item['slug'],
                    'url' => home_url('/p/' . $item['slug'] . '/'),
                ];
            }
            $collection = [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => $context['name'],
                'url' => $canonical,
                'mainEntity' => [
                    '@type' => 'ItemList',
                    'itemListOrder' => 'http://schema.org/ItemListOrderDescending',
                    'itemListElement' => $item_list,
                ],
            ];
        } else {
            $breadcrumb['itemListElement'][1]['item'] = $canonical;
            $item_list = [];
            $position = $offset + 1;
            foreach ($items as $item) {
                $item_list[] = [
                    '@type' => 'ListItem',
                    'position' => $position++,
                    'name' => $item['name'],
                    'url' => $this->build_category_page_url(home_url('/p-cat/' . $item['slug'] . '/'), 1),
                ];
            }
            $collection = [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => __('Categories', 'virtual-product-pages'),
                'url' => $canonical,
                'mainEntity' => [
                    '@type' => 'ItemList',
                    'itemListOrder' => 'http://schema.org/ItemListOrderAscending',
                    'itemListElement' => $item_list,
                ],
            ];
        }

        $payload = [$collection, $breadcrumb];
        echo '<script type="application/ld+json">' . wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
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

    private function is_blog_post_public($post) {
        if (!is_array($post)) {
            return false;
        }

        $is_published = null;
        if (array_key_exists('is_published', $post)) {
            $is_published = (int)$post['is_published'] > 0;
        }
        if ($is_published === null && isset($post['post_status'])) {
            $status = strtolower((string)$post['post_status']);
            $is_published = ($status === 'publish');
        }
        if (!$is_published) {
            return false;
        }

        $published_at = '';
        if (isset($post['published_at'])) {
            $published_at = trim((string)$post['published_at']);
        }
        if ($published_at === '' && isset($post['post_date_gmt'])) {
            $published_at = trim((string)$post['post_date_gmt']);
        }
        if ($published_at === '' && isset($post['post_date'])) {
            $published_at = trim((string)$post['post_date']);
            if ($published_at !== '' && function_exists('get_gmt_from_date')) {
                $converted = get_gmt_from_date($published_at);
                if (is_string($converted) && $converted !== '') {
                    $published_at = $converted;
                }
            }
        }

        if ($published_at === '' || $published_at === '0000-00-00 00:00:00') {
            return true;
        }
        $timestamp = $this->parse_blog_datetime($published_at, true);
        if ($timestamp === false) {
            $timestamp = strtotime($published_at);
        }
        if ($timestamp === false) {
            return true;
        }
        return $timestamp <= time();
    }

    private function parse_blog_datetime($value, $is_gmt = true)
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return false;
        }
        if ($is_gmt) {
            $ts = strtotime($value . ' UTC');
            if ($ts === false) {
                $ts = strtotime($value);
            }
            return $ts;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return false;
        }
        return $ts;
    }

    private function collect_wp_blog_posts_for_sitemap()
    {
        if (!function_exists('get_posts')) {
            return [];
        }
        $args = [
            'post_type' => 'post',
            'post_status' => ['publish', 'future'],
            'orderby' => 'ID',
            'order' => 'ASC',
            'posts_per_page' => -1,
        ];
        $posts = get_posts($args);
        if (!is_array($posts) || empty($posts)) {
            return [];
        }
        $entries = [];
        foreach ($posts as $post) {
            if (!is_object($post)) {
                continue;
            }
            $slug = '';
            if (isset($post->post_name)) {
                $slug = sanitize_title((string)$post->post_name);
            }
            if ($slug === '' && isset($post->post_title)) {
                $slug = sanitize_title((string)$post->post_title);
            }
            if ($slug === '') {
                continue;
            }
            $data = [
                'slug' => $slug,
                'post_status' => isset($post->post_status) ? (string)$post->post_status : '',
                'post_date_gmt' => isset($post->post_date_gmt) ? (string)$post->post_date_gmt : '',
                'post_date' => isset($post->post_date) ? (string)$post->post_date : '',
            ];
            if (!empty($post->post_modified_gmt)) {
                $data['post_modified_gmt'] = (string)$post->post_modified_gmt;
            }
            $permalink = '';
            if (function_exists('get_permalink')) {
                $permalink = (string)get_permalink($post);
            }
            if ($permalink !== '') {
                $data['permalink'] = $permalink;
            }
            if (!$this->is_blog_post_public($data)) {
                continue;
            }
            $lastmod = null;
            if (!empty($data['post_modified_gmt'])) {
                $lastmod = $this->parse_blog_datetime($data['post_modified_gmt'], true);
            }
            if (!$lastmod && !empty($data['post_date_gmt'])) {
                $lastmod = $this->parse_blog_datetime($data['post_date_gmt'], true);
            }
            if (!$lastmod && !empty($data['post_date'])) {
                $lastmod = $this->parse_blog_datetime($data['post_date'], false);
            }
            if (!$lastmod) {
                $lastmod = time();
            }
            $entries[] = [
                'slug' => $slug,
                'lastmod' => $lastmod,
                'loc' => $permalink,
            ];
        }
        return $entries;
    }

    private function build_blog_meta_description($post) {
        $desc = '';
        if (!empty($post['seo_description'])) { $desc = (string)$post['seo_description']; }
        elseif (!empty($post['short_summary'])) { $desc = (string)$post['short_summary']; }
        elseif (!empty($post['content_html'])) { $desc = wp_strip_all_tags((string)$post['content_html']); }
        else { $desc = (string)($post['title_h1'] ?? $post['slug'] ?? ''); }
        $desc = wp_strip_all_tags($desc);
        $desc = str_replace('"', "'", $desc);
        if (function_exists('mb_substr')) { $desc = mb_substr($desc, 0, 160); }
        else { $desc = substr($desc, 0, 160); }
        if ($desc === '') { $desc = ' '; }
        return $desc;
    }

    private function build_blog_document_title($post) {
        $base = !empty($post['seo_title']) ? (string)$post['seo_title'] : ((string)($post['title_h1'] ?? $post['slug'] ?? ''));
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        return $base !== '' ? $base . ' | ' . $site : $site;
    }

    private function get_blog_canonical_url($post) {
        $custom = isset($post['canonical_url']) ? trim((string)$post['canonical_url']) : '';
        if ($custom !== '') {
            $sanitized = esc_url($custom);
            if ($sanitized !== '') {
                return $sanitized;
            }
        }
        if (!empty($post['permalink'])) {
            $permalink = esc_url($post['permalink']);
            if ($permalink !== '') {
                return $permalink;
            }
        }
        $slug = sanitize_title($post['slug'] ?? '');
        return home_url('/b/' . $slug);
    }

    private function render_blog_post($post) {
        $title = $post['title_h1'] ?: $post['slug'];
        $slug = $post['slug'];
        $cover_image = isset($post['cover_image_url']) ? trim((string)$post['cover_image_url']) : '';
        $availability_display = isset($post['availability']) ? trim((string)$post['availability']) : '';
        $price_value = isset($post['price']) ? $post['price'] : '';
        if (is_string($price_value)) { $price_value = trim($price_value); }
        elseif (is_numeric($price_value)) { $price_value = trim((string)(0 + $price_value)); }
        else { $price_value = ''; }
        $availability_display_html = $availability_display !== '' ? esc_html($availability_display) : '';
        $price_display_html = $price_value !== '' ? nl2br(esc_html($price_value)) : '';
        $allowed = $this->allowed_html();

        $meta_line_parts = [];
        if (!empty($post['category'])) { $meta_line_parts[] = trim((string)$post['category']); }
        $published_at_raw = isset($post['published_at']) ? trim((string)$post['published_at']) : '';
        $published_ts = $published_at_raw !== '' ? strtotime($published_at_raw) : false;
        if ($published_ts) {
            $meta_line_parts[] = date_i18n(get_option('date_format') ?: 'F j, Y', $published_ts);
        }
        $meta_line = trim(implode(' • ', array_filter($meta_line_parts)));

        $short_summary = isset($post['short_summary']) ? trim((string)$post['short_summary']) : '';
        if (function_exists('mb_substr')) { $short_summary = mb_substr($short_summary, 0, 180); }
        else { $short_summary = substr($short_summary, 0, 180); }

        $cta_definitions = [
            [ 'url' => $post['cta_lead_url'] ?? '', 'label' => $post['cta_lead_label'] ?? '', 'fallback' => 'Request a Quote', 'rel' => 'nofollow' ],
            [ 'url' => $post['cta_stripe_url'] ?? '', 'label' => $post['cta_stripe_label'] ?? '', 'fallback' => 'Buy via Stripe', 'rel' => 'nofollow' ],
            [ 'url' => $post['cta_affiliate_url'] ?? '', 'label' => $post['cta_affiliate_label'] ?? '', 'fallback' => 'Buy Now', 'rel' => 'sponsored nofollow' ],
            [ 'url' => $post['cta_paypal_url'] ?? '', 'label' => $post['cta_paypal_label'] ?? '', 'fallback' => 'Pay with PayPal', 'rel' => 'nofollow' ],
        ];
        $cta_buttons = [];
        foreach ($cta_definitions as $def) {
            $url = trim((string)$def['url']);
            if ($url === '') { continue; }
            $label = trim((string)$def['label']);
            if ($label === '') { $label = $def['fallback']; }
            $cta_buttons[] = ['url' => $url, 'label' => $label, 'rel' => $def['rel']];
        }

        $meta_description = $this->build_blog_meta_description($post);
        $canonical = $this->get_blog_canonical_url($post);

        $this->current_blog = $post;
        $this->current_blog_slug = $slug;
        $this->current_meta_description = $meta_description;
        $this->current_canonical = $canonical;
        if ($this->last_meta_source === '') { $this->last_meta_source = 'Blog page'; }
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
                  <?php if ($cover_image !== ''): ?>
                    <div class="vpp-carousel" data-vpp-carousel>
                      <div class="vpp-carousel-frame" data-vpp-carousel-frame aria-live="polite">
                        <img src="<?php echo esc_url($cover_image); ?>" alt="<?php echo esc_attr($title); ?>" loading="eager" decoding="async" class="vpp-carousel-image vpp-main-image is-active" data-vpp-carousel-item data-index="0" aria-current="true" />
                      </div>
                    </div>
                  <?php else: ?>
                    <div class="vpp-placeholder"><div class="vpp-ph-img"></div></div>
                  <?php endif; ?>
                </div>
                <div class="vpp-summary">
                  <h1 class="vpp-title"><?php echo esc_html($title); ?></h1>
                  <?php if ($meta_line): ?><p class="vpp-meta"><?php echo esc_html($meta_line); ?></p><?php endif; ?>
                  <?php if ($short_summary): ?><p class="vpp-short"><?php echo esc_html($short_summary); ?></p><?php endif; ?>
                  <?php if ($availability_display_html !== ''): ?>
                    <p class="vpp-availability"><?php echo $availability_display_html; ?></p>
                  <?php endif; ?>
                  <?php if ($price_display_html !== ''): ?>
                    <div class="vpp-price-callout"><span><?php echo $price_display_html; ?></span></div>
                  <?php endif; ?>
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
                $html = isset($post['content_html']) ? trim((string)$post['content_html']) : '';
                if ($html !== '') {
                    $rendered_html = wpautop($html);
                    echo wp_kses($rendered_html, $allowed);
                } else {
                    echo '<p>' . esc_html__('No content available yet.', 'virtual-product-pages') . '</p>';
                }
              ?>
            </section>
            <?php
                $article_body = isset($post['content_html']) ? wp_strip_all_tags((string)$post['content_html']) : '';
                if ($article_body === '') { $article_body = $short_summary; }
                if (function_exists('mb_substr')) { $article_body = mb_substr($article_body, 0, 5000); }
                else { $article_body = substr($article_body, 0, 5000); }
                $article_body = trim($article_body);
                $published_iso = $published_ts ? gmdate('c', $published_ts) : gmdate('c');
                $modified_raw = isset($post['last_tidb_update_at']) ? trim((string)$post['last_tidb_update_at']) : '';
                $modified_ts = $modified_raw !== '' ? strtotime($modified_raw) : false;
                $modified_iso = $modified_ts ? gmdate('c', $modified_ts) : $published_iso;
                $schema = [
                    '@context' => 'https://schema.org',
                    '@type' => 'BlogPosting',
                    'headline' => $post['seo_title'] ?: $title,
                    'description' => $meta_description,
                    'articleBody' => $article_body,
                    'datePublished' => $published_iso,
                    'dateModified' => $modified_iso,
                    'mainEntityOfPage' => $canonical,
                    'url' => $canonical,
                    'author' => [
                        '@type' => 'Organization',
                        'name' => get_bloginfo('name'),
                        'url' => home_url('/'),
                    ],
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => get_bloginfo('name'),
                    ],
                ];
                if (!empty($post['category'])) {
                    $schema['articleSection'] = (string)$post['category'];
                }
                if ($cover_image !== '') {
                    $schema['image'] = [esc_url($cover_image)];
                }
                echo "\n<!-- BlogPosting Schema.org JSON-LD -->\n";
                echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
            ?>
          </article>
        </main>
        <?php

        get_footer();
    }

    private function render_product($p) {
        $title = $p['title_h1'] ?: $p['slug'];
        $brand = $p['brand'] ?? '';
        $model = $p['model'] ?? '';
        $sku   = $p['sku'] ?? '';
        $images = [];
        if (!empty($p['images_json'])) {
            $arr = json_decode($p['images_json'], true);
            if (is_array($arr)) {
                $images = $arr;
            }
        }
        if (!empty($images)) {
            $images = array_values(array_filter(array_map(static function ($value) {
                $value = is_string($value) ? trim($value) : '';
                return $value !== '' ? $value : null;
            }, $images)));
        }
        $image_count = count($images);
        $primary_image = $images[0] ?? '';
        $availability_display = '';
        if (isset($p['availability']) && $p['availability'] !== '') {
            $availability_display = trim((string)$p['availability']);
        }
        $price_display = '';
        if (isset($p['price']) && $p['price'] !== '') {
            if (is_string($p['price'])) {
                $price_display = trim($p['price']);
            } elseif (is_numeric($p['price'])) {
                $price_display = trim((string)(0 + $p['price']));
            }
        }
        $availability_display_html = $availability_display !== '' ? esc_html($availability_display) : '';
        $price_display_html = $price_display !== '' ? nl2br(esc_html($price_display)) : '';
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
                  <?php if ($primary_image !== ''): ?>
                      <div class="vpp-carousel" data-vpp-carousel>
                        <div class="vpp-carousel-frame" data-vpp-carousel-frame aria-live="polite">
                          <?php foreach ($images as $index => $image_url): ?>
                            <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($title); ?>" loading="<?php echo $index === 0 ? 'eager' : 'lazy'; ?>" decoding="async" class="vpp-carousel-image vpp-main-image<?php echo $index === 0 ? ' is-active' : ''; ?>" data-vpp-carousel-item data-index="<?php echo (int)$index; ?>"<?php echo $index === 0 ? ' aria-current="true"' : ' aria-hidden="true"'; ?> />
                          <?php endforeach; ?>
                        </div>
                        <?php if ($image_count > 1): ?>
                          <button type="button" class="vpp-carousel-nav" data-dir="prev" data-vpp-carousel-prev aria-label="<?php echo esc_attr__('Previous image', 'virtual-product-pages'); ?>">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12.75 4.75 7.5 10l5.25 5.25" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                          </button>
                          <button type="button" class="vpp-carousel-nav" data-dir="next" data-vpp-carousel-next aria-label="<?php echo esc_attr__('Next image', 'virtual-product-pages'); ?>">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="m7.25 15.25 5.25-5.25L7.25 4.75" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                          </button>
                        <?php endif; ?>
                      </div>
                      <?php if ($image_count > 1): ?>
                        <div class="vpp-carousel-thumbs" data-vpp-carousel-thumbs role="group" aria-label="<?php echo esc_attr__('Carousel thumbnails', 'virtual-product-pages'); ?>">
                          <?php foreach ($images as $index => $image_url): ?>
                            <?php $thumb_label = sprintf(__('Show image %1$d of %2$d', 'virtual-product-pages'), $index + 1, $image_count); ?>
                            <button type="button" class="vpp-carousel-thumb<?php echo $index === 0 ? ' is-active' : ''; ?>" data-vpp-carousel-thumb data-index="<?php echo (int)$index; ?>" aria-pressed="<?php echo $index === 0 ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr($thumb_label); ?>">
                              <img src="<?php echo esc_url($image_url); ?>" alt="" loading="lazy" decoding="async" />
                            </button>
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
                  <?php if ($availability_display_html !== ''): ?>
                    <p class="vpp-availability"><?php echo $availability_display_html; ?></p>
                  <?php endif; ?>
                  <?php if ($price_display_html !== ''): ?>
                    <div class="vpp-price-callout"><span><?php echo $price_display_html; ?></span></div>
                  <?php endif; ?>
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
                $html = isset($p['desc_html']) ? trim((string)$p['desc_html']) : '';
                if ($html !== '') {
                    $rendered_html = wpautop($html);
                    echo wp_kses($rendered_html, $allowed);
                } else {
                    echo '<p>No description available yet.</p>';
                }
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
        $css_href = add_query_arg('ver', self::VERSION, plugins_url('assets/vpp.css', __FILE__));
        if ($this->current_blog_slug()) {
            $err = null;
            $post = $this->get_current_blog_post($err);
            if (!$post || !$this->is_blog_post_public($post)) {
                return;
            }

            $meta_description = $this->current_meta_description !== ''
                ? $this->current_meta_description
                : $this->build_blog_meta_description($post);
            $canonical = $this->current_canonical !== ''
                ? $this->current_canonical
                : $this->get_blog_canonical_url($post);

            if (!defined('WPSEO_VERSION')) {
                echo '<link rel="canonical" href="' . esc_url($canonical) . '" />' . "\n";
            }

            echo '<link rel="preload" href="' . esc_url($css_href) . '" as="style" />' . "\n";
            echo '<meta name="description" content="' . esc_attr($meta_description) . '" data-vpp-meta="description" />' . "\n";
            echo '<meta property="og:description" content="' . esc_attr($meta_description) . '" data-vpp-meta="og-description" />' . "\n";
            echo '<meta name="twitter:description" content="' . esc_attr($meta_description) . '" data-vpp-meta="twitter-description" />' . "\n";
            echo '<meta property="og:type" content="article" />' . "\n";

            if ($this->last_meta_source === '') {
                $this->last_meta_source = 'Blog page';
            }
            $this->last_meta_value = $meta_description;
            return;
        }
        if ($this->current_vpp_slug()) {
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
            return;
        }

        if ($this->is_category_request()) {
            $err = null;
            $context = $this->ensure_category_context($err);
            if (!$context) {
                return;
            }
            $meta_description = $context['meta_description'] ?? '';
            $canonical = $context['canonical'] ?? trailingslashit(home_url('/p-cat/'));

            if (!defined('WPSEO_VERSION')) {
                echo '<link rel="canonical" href="' . esc_url($canonical) . '" />' . "\n";
            }

            echo '<link rel="preload" href="' . esc_url($css_href) . '" as="style" />' . "\n";

            if ($meta_description !== '') {
                echo '<meta name="description" content="' . esc_attr($meta_description) . '" data-vpp-meta="description" />' . "\n";
                echo '<meta property="og:description" content="' . esc_attr($meta_description) . '" data-vpp-meta="og-description" />' . "\n";
                echo '<meta name="twitter:description" content="' . esc_attr($meta_description) . '" data-vpp-meta="twitter-description" />' . "\n";
                if ($this->last_meta_source === '') {
                    $this->last_meta_source = 'Category page';
                }
                $this->last_meta_value = $meta_description;
            }
        }
    }

    public function inject_inline_css_head() {
        if (!$this->current_vpp_slug() && !$this->current_blog_slug() && !$this->is_category_request()) {
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
