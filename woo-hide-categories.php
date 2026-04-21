<?php
/**
 * Plugin Name: Woo Hide Categories
 * Description: Скрывает выбранные категории и товары этих категорий с фронта WooCommerce
 * Version: 1.0.0
 * Author: Aleksei Tikhomirov
 * Text Domain: woo-hide-categories
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WHC_VERSION', '1.0.0');
define('WHC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WHC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WHC_PLUGIN_DIR . 'includes/class-whc-plugin.php';
require_once WHC_PLUGIN_DIR . 'includes/class-whc-admin.php';
require_once WHC_PLUGIN_DIR . 'includes/class-whc-frontend.php';

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain(
        'woo-hide-categories',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );

    $core = WHC_Plugin::getInstance();

    if (is_admin()) {
        WHC_Admin::getInstance($core)->init();
        return;
    }

    WHC_Frontend::getInstance($core)->init();
});
