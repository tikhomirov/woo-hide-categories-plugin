<?php

if (!defined('ABSPATH')) {
    exit;
}

final class WHC_Plugin
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getHiddenCategories(): array
    {
        $cacheKey = 'whc_hidden_categories';
        $hidden = wp_cache_get($cacheKey, 'whc');

        if (false !== $hidden) {
            return array_map('intval', (array) $hidden);
        }

        $hidden = get_terms([
            'taxonomy' => 'product_cat',
            'meta_key' => 'whc_hide_from_frontend',
            'meta_value' => '1',
            'fields' => 'ids',
            'hide_empty' => false,
        ]);

        if (is_wp_error($hidden) || !is_array($hidden)) {
            $hidden = [];
        }

        $hidden = array_values(array_unique(array_map('intval', $hidden)));
        wp_cache_set($cacheKey, $hidden, 'whc', 300);

        return $hidden;
    }

    public function clearHiddenCategoriesCache(): void
    {
        wp_cache_delete('whc_hidden_categories', 'whc');
    }

    public function isFrontend(): bool
    {
        if (is_admin()) {
            return false;
        }

        if (wp_doing_ajax() && isset($_SERVER['HTTP_REFERER']) && strpos((string) $_SERVER['HTTP_REFERER'], '/wp-admin/') !== false) {
            return false;
        }

        if (defined('REST_REQUEST') && REST_REQUEST && current_user_can('manage_woocommerce')) {
            return false;
        }

        return true;
    }
}
