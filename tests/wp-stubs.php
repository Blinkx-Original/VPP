<?php
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {}
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {}
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) {}
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) {}
}
if (!function_exists('add_rewrite_rule')) {
    function add_rewrite_rule($regex, $redirect, $position = 'top') {}
}
if (!function_exists('add_rewrite_tag')) {
    function add_rewrite_tag($tag, $regex) {}
}
if (!function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules($hard = true) {}
}
if (!function_exists('is_admin')) {
    function is_admin() { return false; }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim($string, '/\\') . '/';
    }
}
if (!function_exists('home_url')) {
    function home_url($path = '') {
        $base = 'https://example.com';
        if ($path === '') {
            return $base;
        }
        if ($path[0] !== '/' && substr($base, -1) !== '/') {
            $path = '/' . $path;
        }
        return rtrim($base, '/') . $path;
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        $base = sys_get_temp_dir() . '/vpp-test-uploads';
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }
        return [
            'basedir' => $base,
            'baseurl' => 'https://example.com/uploads'
        ];
    }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($path) {
        return is_dir($path) || mkdir($path, 0777, true);
    }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        global $vpp_test_options;
        if (!isset($vpp_test_options)) {
            $vpp_test_options = [];
        }
        return array_key_exists($key, $vpp_test_options) ? $vpp_test_options[$key] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($key, $value, $autoload = true) {
        global $vpp_test_options;
        if (!isset($vpp_test_options)) {
            $vpp_test_options = [];
        }
        $vpp_test_options[$key] = $value;
        return true;
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return $url; }
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return $url; }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return $text; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return $text; }
}
if (!function_exists('__')) {
    function __($text, $domain = null) { return $text; }
}
if (!function_exists('_e')) {
    function _e($text, $domain = null) { echo $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null) { return $text; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) { return strip_tags($text); }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        $title = strtolower(trim((string)$title));
        $title = preg_replace('/[^a-z0-9\-]+/', '-', $title);
        $title = preg_replace('/-+/', '-', $title);
        return trim($title, '-');
    }
}
if (!function_exists('get_permalink')) {
    function get_permalink($post) {
        if (is_object($post) && isset($post->post_name)) {
            return home_url('/blog/' . $post->post_name . '/');
        }
        return home_url('/blog/');
    }
}
if (!function_exists('get_posts')) {
    function get_posts($args = []) {
        global $vpp_test_posts;
        return is_array($vpp_test_posts) ? $vpp_test_posts : [];
    }
}
if (!function_exists('get_gmt_from_date')) {
    function get_gmt_from_date($string, $format = 'Y-m-d H:i:s') {
        $ts = strtotime($string);
        if ($ts === false) {
            return false;
        }
        return gmdate($format, $ts);
    }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw') {
        return 'Example Site';
    }
}
if (!function_exists('wp_specialchars_decode')) {
    function wp_specialchars_decode($string, $quote_style = ENT_COMPAT) {
        return html_entity_decode($string, $quote_style, 'UTF-8');
    }
}
if (!function_exists('date_i18n')) {
    function date_i18n($format, $timestamp) {
        return date($format, $timestamp);
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = null) { echo $text; }
}
if (!function_exists('esc_html_x')) {
    function esc_html_x($text, $context, $domain = null) { return $text; }
}
if (!function_exists('_x')) {
    function _x($text, $context, $domain = null) { return $text; }
}
if (!function_exists('wp_enqueue_scripts')) {
    function wp_enqueue_scripts() {}
}
if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = []) { return ['response' => ['code' => 200], 'body' => '']; }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return false; }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) { return isset($response['response']['code']) ? $response['response']['code'] : 200; }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) { return isset($response['body']) ? $response['body'] : ''; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string)$text); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true) { return $checked == $current ? 'checked' : ''; }
}
if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true) { return $selected == $current ? 'selected' : ''; }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) { return ''; }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action, $name, $referer = true, $echo = true) {}
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action) { return true; }
}
if (!function_exists('current_user_can')) {
    function current_user_can($capability) { return true; }
}
if (!function_exists('wp_die')) {
    function wp_die($message = '') { throw new RuntimeException($message); }
}
