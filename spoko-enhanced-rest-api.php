<?php
/**
 * Plugin Name: SPOKO Enhanced WP REST API
 * Description: Extends WordPress REST API with additional fields and optimizations
 * Version: 1.0.6
 * Author: spoko.space
 * Author URI: https://spoko.space
 * Requires at least: 5.0
 * Requires PHP: 8.3
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html 
 */

declare(strict_types=1);

namespace Spoko\EnhancedRestAPI;

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

spl_autoload_register(function ($class) {
    $prefix = 'Spoko\\EnhancedRestAPI\\';
    $base_dir = plugin_dir_path(__FILE__) . 'src/';

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

use Spoko\EnhancedRestAPI\Core\Plugin;

register_activation_hook(__FILE__, function() {
    add_option('spoko_rest_related_posts_enabled', '1');
});

register_deactivation_hook(__FILE__, function() {
    delete_option('spoko_rest_related_posts_enabled');
});

function initEnhancedWPRestAPI(): Plugin
{
    return Plugin::getInstance();
}

add_action('init', 'Spoko\EnhancedRestAPI\initEnhancedWPRestAPI');