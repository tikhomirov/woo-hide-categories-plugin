<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WHC_Frontend
{
    private static ?self $instance = null;

    private WHC_Plugin $core;

    /**
     * @var int[]|null
     */
    private ?array $hiddenCategoryIds = null;

    private function __construct(WHC_Plugin $core)
    {
        $this->core = $core;
    }

    public static function getInstance(WHC_Plugin $core): self
    {
        if (self::$instance === null) {
            self::$instance = new self($core);
        }

        return self::$instance;
    }

    public function init(): void
    {
        add_action('pre_get_terms', [$this, 'filterTermQuery']);
        add_filter('woocommerce_product_categories_widget_dropdown_args', [$this, 'filterWidgetCategories']);
        add_filter('woocommerce_product_categories_widget_args', [$this, 'filterWidgetCategories']);
        add_filter('woocommerce_product_subcategories_args', [$this, 'filterWidgetCategories']);
        add_filter('woocommerce_get_product_subcategories_cache', [$this, 'filterProductSubcategories'], 10, 2);
        add_action('woocommerce_product_query', [$this, 'filterProductQuery']);
        add_action('pre_get_posts', [$this, 'filterMainQuery']);
        add_filter('woocommerce_product_is_visible', [$this, 'filterProductVisibility'], 10, 2);
        add_filter('wp_get_nav_menu_items', [$this, 'filterMenuItems'], 10, 3);
        add_filter('rest_product_cat_query', [$this, 'filterRestQuery'], 10, 2);
        add_action('template_redirect', [$this, 'redirectHiddenCategory']);
    }

    private function shouldProcess(): bool
    {
        return $this->core->isFrontend();
    }

    /**
     * @return int[]
     */
    private function getHiddenCategoryIds(): array
    {
        if ($this->hiddenCategoryIds !== null) {
            return $this->hiddenCategoryIds;
        }

        $cached = wp_cache_get('whc_hidden_categories', 'whc');
        if ($cached !== false) {
            $this->hiddenCategoryIds = $this->normalizeIds((array) $cached);

            return $this->hiddenCategoryIds;
        }

        global $wpdb;

        $hidden = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT tm.term_id
                FROM {$wpdb->termmeta} tm
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                WHERE tm.meta_key = %s
                  AND tm.meta_value = %s
                  AND tt.taxonomy = %s",
                'whc_hide_from_frontend',
                '1',
                'product_cat'
            )
        );

        $this->hiddenCategoryIds = $this->normalizeIds(is_array($hidden) ? $hidden : []);
        wp_cache_set('whc_hidden_categories', $this->hiddenCategoryIds, 'whc', 300);

        return $this->hiddenCategoryIds;
    }

    /**
     * @param  array<int|string>  $ids
     * @return int[]
     */
    private function normalizeIds(array $ids): array
    {
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, static function (int $id): bool {
            return $id > 0;
        });

        return array_values(array_unique($ids));
    }

    private function queryTargetsProductCategories(WP_Term_Query $termQuery): bool
    {
        $taxonomies = $termQuery->query_vars['taxonomy'] ?? [];
        if (empty($taxonomies)) {
            return false;
        }

        $taxonomies = is_array($taxonomies) ? $taxonomies : [$taxonomies];

        return in_array('product_cat', $taxonomies, true);
    }

    private function isHiddenCategoryLookup(WP_Term_Query $termQuery): bool
    {
        $metaKey = $termQuery->query_vars['meta_key'] ?? null;
        $metaValue = (string) ($termQuery->query_vars['meta_value'] ?? '');

        return $metaKey === 'whc_hide_from_frontend' && $metaValue === '1';
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  int[]  $hiddenIds
     * @return array<string, mixed>
     */
    private function mergeExcludedTermIds(array $args, array $hiddenIds): array
    {
        if ($hiddenIds === []) {
            return $args;
        }

        $exclude = isset($args['exclude']) ? (array) $args['exclude'] : [];
        $args['exclude'] = $this->normalizeIds(array_merge($exclude, $hiddenIds));

        return $args;
    }

    public function filterTermQuery(WP_Term_Query $termQuery): void
    {
        if (! $this->shouldProcess()) {
            return;
        }

        if (! $this->queryTargetsProductCategories($termQuery)) {
            return;
        }

        if ($this->isHiddenCategoryLookup($termQuery)) {
            return;
        }

        $hiddenIds = $this->getHiddenCategoryIds();
        if ($hiddenIds === []) {
            return;
        }

        $termQuery->query_vars = $this->mergeExcludedTermIds($termQuery->query_vars, $hiddenIds);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function filterWidgetCategories(array $args): array
    {
        if (! $this->shouldProcess()) {
            return $args;
        }

        return $this->mergeExcludedTermIds($args, $this->getHiddenCategoryIds());
    }

    /**
     * @param  array<int, mixed>  $categories
     * @return array<int, mixed>
     */
    public function filterProductSubcategories(array $categories, $cacheKey): array
    {
        if (! $this->shouldProcess()) {
            return $categories;
        }

        $hiddenIds = $this->getHiddenCategoryIds();
        if ($hiddenIds === []) {
            return $categories;
        }

        return array_values(array_filter($categories, static function ($category) use ($hiddenIds): bool {
            $termId = is_object($category) && isset($category->term_id)
                ? (int) $category->term_id
                : (int) $category;

            return ! in_array($termId, $hiddenIds, true);
        }));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function filterRestQuery(array $args, WP_REST_Request $request): array
    {
        if (! $this->shouldProcess()) {
            return $args;
        }

        return $this->mergeExcludedTermIds($args, $this->getHiddenCategoryIds());
    }

    private function applyHiddenCategoryExclusion(WP_Query $query): void
    {
        if ($query->get('whc_hidden_category_exclusion_applied')) {
            return;
        }

        $hiddenIds = $this->getHiddenCategoryIds();
        if ($hiddenIds === []) {
            return;
        }

        $taxQuery = $query->get('tax_query');
        $taxQuery = is_array($taxQuery) ? $taxQuery : [];
        $taxQuery[] = [
            'taxonomy' => 'product_cat',
            'field' => 'term_id',
            'terms' => $hiddenIds,
            'operator' => 'NOT IN',
            'include_children' => true,
        ];

        $query->set('tax_query', $taxQuery);
        $query->set('whc_hidden_category_exclusion_applied', true);
    }

    public function filterProductQuery(WP_Query $query): void
    {
        if (! $this->shouldProcess()) {
            return;
        }

        $this->applyHiddenCategoryExclusion($query);
    }

    private function shouldFilterMainProductQuery(WP_Query $query): bool
    {
        if (! $query->is_main_query()) {
            return false;
        }

        if ($query->is_post_type_archive('product')) {
            return true;
        }

        if ($query->is_tax(['product_cat', 'product_tag'])) {
            return true;
        }

        if ($query->is_search()) {
            $postType = $query->get('post_type');
            if ($postType === 'product') {
                return true;
            }

            if (is_array($postType) && in_array('product', $postType, true)) {
                return true;
            }
        }

        return function_exists('is_shop') && is_shop();
    }

    public function filterMainQuery(WP_Query $query): void
    {
        if (! $this->shouldProcess()) {
            return;
        }

        if (! $this->shouldFilterMainProductQuery($query)) {
            return;
        }

        $this->applyHiddenCategoryExclusion($query);
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<string, mixed>  $args
     * @return array<int, mixed>
     */
    public function filterMenuItems(array $items, $menu, array $args): array
    {
        if (! $this->shouldProcess()) {
            return $items;
        }

        $hiddenIds = $this->getHiddenCategoryIds();
        if ($hiddenIds === []) {
            return $items;
        }

        return array_values(array_filter($items, static function ($item) use ($hiddenIds): bool {
            if (! is_object($item)) {
                return true;
            }

            if (($item->type ?? '') !== 'taxonomy' || ($item->object ?? '') !== 'product_cat') {
                return true;
            }

            return ! in_array((int) ($item->object_id ?? 0), $hiddenIds, true);
        }));
    }

    private function productHasHiddenCategory(int $productId): bool
    {
        $hiddenIds = $this->getHiddenCategoryIds();
        if ($productId <= 0 || $hiddenIds === []) {
            return false;
        }

        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($hiddenIds), '%d'));
        $sql = $wpdb->prepare(
            "SELECT 1
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tr.object_id = %d
              AND tt.taxonomy = %s
              AND tt.term_id IN ({$placeholders})
            LIMIT 1",
            array_merge([$productId, 'product_cat'], $hiddenIds)
        );

        return $wpdb->get_var($sql) !== null;
    }

    public function filterProductVisibility(bool $visible, int $productId): bool
    {
        if (! $visible || ! $this->shouldProcess()) {
            return $visible;
        }

        return ! $this->productHasHiddenCategory($productId);
    }

    public function redirectHiddenCategory(): void
    {
        if (! $this->shouldProcess() || ! function_exists('is_product_category') || ! is_product_category()) {
            return;
        }

        $currentCategory = get_queried_object();
        if (! $currentCategory instanceof WP_Term || $currentCategory->taxonomy !== 'product_cat') {
            return;
        }

        if (! in_array((int) $currentCategory->term_id, $this->getHiddenCategoryIds(), true)) {
            return;
        }

        wp_safe_redirect(home_url(), 302);
        exit;
    }
}
