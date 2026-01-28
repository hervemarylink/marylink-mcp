<?php
/**
 * PHPUnit Bootstrap File
 *
 * @package MCP_No_Headless\Tests
 */

// Define testing constant
define('MCPNH_TESTING', true);
define('ABSPATH', __DIR__ . '/../');

// Mock WordPress functions for unit tests
if (!function_exists('get_post')) {
    function get_post($id) {
        global $mcpnh_test_posts;
        return $mcpnh_test_posts[$id] ?? null;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        global $mcpnh_test_meta;
        if (empty($key)) {
            return $mcpnh_test_meta[$post_id] ?? [];
        }
        $value = $mcpnh_test_meta[$post_id][$key] ?? null;
        return $single ? $value : [$value];
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $mcpnh_test_options;
        return $mcpnh_test_options[$option] ?? $default;
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '') {
        return 'https://test.marylink.io' . $path;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url($url, $component);
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string) {
        return strip_tags($string);
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) {
        global $mcpnh_test_remote_responses;
        return $mcpnh_test_remote_responses[$url] ?? [
            'response' => ['code' => 404],
            'body' => '',
        ];
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return $response['response']['code'] ?? 0;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return $response['body'] ?? '';
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = [];
        public function __construct($code = '', $message = '') {
            $this->errors[$code] = [$message];
        }
        public function get_error_message() {
            return reset($this->errors)[0] ?? '';
        }
    }
}

if (!class_exists('WP_Post')) {
    class WP_Post {
        public $ID;
        public $post_title;
        public $post_content;
        public $post_name;
        public $post_type;
        public $post_parent;
        public $post_author;
        public $post_status;
        public $post_modified;

        public function __construct($data = []) {
            foreach ($data as $key => $value) {
                $this->$key = $value;
            }
        }
    }
}

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'MCP_No_Headless\\';
    $base_dir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
