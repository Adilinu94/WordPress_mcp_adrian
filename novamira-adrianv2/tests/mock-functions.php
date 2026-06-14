<?php
/**
 * Mock WordPress Functions — Minimal mocks for Unit-Tests without WordPress.
 *
 * @package Novamira\AdrianV2\Tests
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

// ── i18n ──
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}

// ── Ability Registration ──
if (!function_exists('wp_register_ability')) {
    function wp_register_ability($name, $callable, $args = []) {}
}

// ── Hooks ──
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {}
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { return null; }
}
if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {}
}
if (!function_exists('did_action')) {
    function did_action($hook) { return 0; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) { return $value; }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) {}
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) {}
}

// ── Options ──
if (!function_exists('get_option')) {
    function get_option($option, $default = false) { return $default; }
}
if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) { return true; }
}

// ── Posts / CPT ──
if (!function_exists('wp_insert_post')) {
    function wp_insert_post($args = [], $wp_error = false) { return 99999; }
}
if (!function_exists('wp_update_post')) {
    function wp_update_post($args = [], $wp_error = false) { return 99999; }
}
if (!function_exists('get_post')) {
    function get_post($post = null, $output = OBJECT, $filter = 'raw') { return null; }
}
if (!function_exists('get_posts')) {
    function get_posts($args = null) { return []; }
}

// ── Paths / URLs ──
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) { return '/tmp/wp-content/plugins/novamira-adrianv2/'; }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) { return 'https://solar.local/wp-content/plugins/novamira-adrianv2/'; }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($file) { return 'novamira-adrianv2/novamira-adrianv2.php'; }
}

// ── REST-API ──
if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = [], $override = false) { return true; }
}

// ── Meta ──
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) { return $single ? '' : []; }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '') { return true; }
}

// ── Admin / Activation ──
if (!function_exists('current_user_can')) {
    function current_user_can($capability) { return true; }
}
if (!function_exists('wp_admin_notice')) {
    function wp_admin_notice($message, $args = []) {}
}
if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = []) { exit(1); }
}
if (!function_exists('deactivate_plugins')) {
    function deactivate_plugins($plugins, $silent = false, $network_wide = null) {}
}

// ── Misc ──
if (!isset($wp_version)) {
    $wp_version = '6.9';
}
if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() { return false; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data) { return $data; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return $str; }
}
if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($filename) {
        // Strip path components like WordPress does
        $filename = preg_replace('#[\\/]+#', '', $filename);
        $filename = preg_replace('#\.\.+#', '', $filename);
        $filename = trim($filename, '. ');
        return $filename;
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return $text; }
}

// ── WP_Error stub ──
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = [];
        public $error_data = [];
        public function __construct($code = '', $message = '', $data = '') {
            $this->errors[$code] = [$message];
            $this->error_data[$code] = $data;
        }
        public function get_error_message($code = '') { return ''; }
    }
}
