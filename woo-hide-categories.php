<?php
/**
 * Plugin Name: Woo Hide Categories
 * Description: Скрывает выбранные категории и товары этих категорий с фронта WooCommerce
 * Version: 1.0.0
 * Author: Woo2iiko Team
 * Text Domain: woo-hide-categories
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WHC_VERSION', '1.0.0');
define('WHC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WHC_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Основной класс плагина
 */
class WooHideCategories
{
    private static ?self $instance = null;
    private static bool $gettingHiddenCategories = false;
    private static bool $filteringTermsArgs = false;

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'init']);
    }

    /**
     * Проверяем, что мы на фронтенде (не админка, не REST API для админки)
     */
    private function isFrontend(): bool
    {
        // Если это админка - не фронтенд
        if (is_admin()) {
            return false;
        }

        // Если это AJAX запрос от админки
        if (wp_doing_ajax() && isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], '/wp-admin/') !== false) {
            return false;
        }

        // Если это REST API запрос с авторизацией (админ)
        if (defined('REST_REQUEST') && REST_REQUEST && current_user_can('manage_woocommerce')) {
            return false;
        }

        return true;
    }

    public function init(): void
    {
        // UI для управления категориями
        add_action('product_cat_add_form_fields', [$this, 'addCategoryField']);
        add_action('product_cat_edit_form_fields', [$this, 'editCategoryField']);
        add_action('created_product_cat', [$this, 'saveCategoryField']);
        add_action('edited_product_cat', [$this, 'saveCategoryField']);

        // Колонка видимости в списке категорий
        add_filter('manage_edit-product_cat_columns', [$this, 'addVisibilityColumn']);
        add_filter('manage_product_cat_custom_column', [$this, 'renderVisibilityColumn'], 10, 3);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

        // AJAX для быстрого переключения
        add_action('wp_ajax_whc_toggle_visibility', [$this, 'ajaxToggleVisibility']);

        // Фильтры для скрытия на фронте
        add_filter('woocommerce_product_categories_widget_dropdown_args', [$this, 'filterWidgetCategories']);
        add_filter('woocommerce_product_categories_widget_args', [$this, 'filterWidgetCategories']);
        // Фильтруем аргументы get_terms чтобы исключить скрытые категории из самого запроса
        add_filter('get_terms_args', [$this, 'filterTermsArgs'], 999, 2);
        // Фильтруем результаты get_terms как fallback (высокий приоритет)
        add_filter('get_terms', [$this, 'filterTerms'], 999, 2);
        // Фильтруем список категорий в WC
        add_filter('woocommerce_product_subcategories_args', [$this, 'filterWidgetCategories']);
        add_filter('woocommerce_get_product_subcategories_cache', [$this, 'filterProductSubcategories'], 10, 2);
        // Фильтр для wp_list_categories (используется в некоторых темах)
        add_filter('wp_list_categories', [$this, 'filterCategoryList'], 10, 2);
        add_action('woocommerce_product_query', [$this, 'filterProductQuery']);
        add_filter('woocommerce_product_is_visible', [$this, 'filterProductVisibility'], 10, 2);
        add_action('pre_get_posts', [$this, 'filterMainQuery']);
        // Фильтруем меню (если категории добавлены в меню)
        add_filter('wp_get_nav_menu_items', [$this, 'filterMenuItems'], 10, 3);
        // Фильтр для REST API (блоки WooCommerce)
        add_filter('rest_product_cat_query', [$this, 'filterRestQuery'], 10, 2);
        // Редирект со страниц скрытых категорий
        add_action('template_redirect', [$this, 'redirectHiddenCategory']);
    }

    /**
     * Добавление поля при создании категории
     */
    public function addCategoryField(): void
    {
        ?>
        <div class="form-field">
            <label for="whc_hide_from_frontend">
                <input type="checkbox" name="whc_hide_from_frontend" id="whc_hide_from_frontend" value="1">
                <?php _e('Скрыть с фронта', 'woo-hide-categories'); ?>
            </label>
            <p class="description">
                <?php _e('Если отмечено, категория и все товары в ней будут скрыты с фронтенда магазина', 'woo-hide-categories'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Добавление поля при редактировании категории
     */
    public function editCategoryField(WP_Term $term): void
    {
        $hidden = get_term_meta($term->term_id, 'whc_hide_from_frontend', true);
        ?>
        <tr class="form-field">
            <th scope="row">
                <label for="whc_hide_from_frontend"><?php _e('Скрыть с фронта', 'woo-hide-categories'); ?></label>
            </th>
            <td>
                <label for="whc_hide_from_frontend">
                    <input type="checkbox" name="whc_hide_from_frontend" id="whc_hide_from_frontend" value="1" <?php checked($hidden, '1'); ?>>
                    <?php _e('Скрыть эту категорию и все товары в ней с фронтенда магазина', 'woo-hide-categories'); ?>
                </label>
            </td>
        </tr>
        <?php
    }

    /**
     * Сохранение поля
     */
    public function saveCategoryField(int $termId): void
    {
        $value = isset($_POST['whc_hide_from_frontend']) ? '1' : '0';
        update_term_meta($termId, 'whc_hide_from_frontend', $value);

        // Очищаем кеш
        $this->clearHiddenCategoriesCache();
    }

    /**
     * Добавить колонку "Видимость" в таблицу категорий
     */
    public function addVisibilityColumn(array $columns): array
    {
        $newColumns = [];
        $added = false;

        foreach ($columns as $key => $value) {
            // Добавляем перед handle (сортировка) или в конец
            if ($key === 'handle') {
                $newColumns['whc_visibility'] = __('Видимость', 'woo-hide-categories');
                $added = true;
            }
            $newColumns[$key] = $value;
        }

        // Если нет handle, добавляем в конец
        if (!$added) {
            $newColumns['whc_visibility'] = __('Видимость', 'woo-hide-categories');
        }

        return $newColumns;
    }

    /**
     * Отрендерить ячейку с иконкой видимости
     */
    public function renderVisibilityColumn(string $output, string $column, int $termId): string
    {
        if ($column !== 'whc_visibility') {
            return $output;
        }

        $hidden = get_term_meta($termId, 'whc_hide_from_frontend', true);
        $isHidden = ($hidden === '1');
        $iconClass = $isHidden ? 'hidden' : 'visible';
        $title = $isHidden
            ? __('Категория скрыта с фронта', 'woo-hide-categories')
            : __('Категория видна на фронте', 'woo-hide-categories');

        return sprintf(
            '<button type="button" class="whc-visibility-toggle %s" data-term-id="%d" title="%s">
                <span class="dashicons %s"></span>
            </button>',
            $iconClass,
            $termId,
            esc_attr($title),
            $isHidden ? 'dashicons-hidden' : 'dashicons-visibility'
        );
    }

    /**
     * Подключение стилей и скриптов в админке
     */
    public function enqueueAdminAssets(string $hook): void
    {
        global $taxonomy;

        if ($hook !== 'edit-tags.php' || $taxonomy !== 'product_cat') {
            return;
        }

        wp_enqueue_style('dashicons');
        wp_add_inline_style('dashicons', $this->getInlineStyles());
        wp_add_inline_script('jquery', $this->getInlineScript());
    }

    /**
     * Inline стили для колонки видимости
     */
    private function getInlineStyles(): string
    {
        return '
        <style>
            .column-whc_visibility {
                width: 60px;
                text-align: center !important;
                vertical-align: middle !important;
            }
            .whc-visibility-toggle {
                background: transparent;
                border: 0;
                cursor: pointer;
                padding: 0;
                margin: 0;
                display: inline-block;
                vertical-align: middle;
                line-height: 1;
            }
            .whc-visibility-toggle:hover {
                opacity: 0.7;
            }
            .whc-visibility-toggle .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
                line-height: 1;
                vertical-align: middle;
                margin: 0;
                padding: 0;
            }
            .whc-visibility-toggle.visible .dashicons {
                color: #008000;
            }
            .whc-visibility-toggle.hidden .dashicons {
                color: #d63638;
            }
            .whc-visibility-toggle.processing {
                opacity: 0.6;
                pointer-events: none;
            }
            .whc-visibility-toggle.processing .dashicons {
                color: #999;
            }
        </style>
        ';
    }

    /**
     * Inline скрипт для AJAX переключения
     */
    private function getInlineScript(): string
    {
        $ajaxUrl = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('whc_toggle_visibility');

        return "
        <script>
        jQuery(function($) {
            'use strict';

            $(document).on('click', '.whc-visibility-toggle', function(e) {
                e.preventDefault();

                var \$btn = $(this);
                if (\$btn.hasClass('processing')) return;

                var termId = \$btn.data('term-id');
                var isHidden = \$btn.hasClass('hidden');
                var newState = isHidden ? '0' : '1';

                \$btn.addClass('processing');

                $.ajax({
                    url: '$ajaxUrl',
                    type: 'POST',
                    data: {
                        action: 'whc_toggle_visibility',
                        term_id: termId,
                        hidden: newState,
                        _ajax_nonce: '$nonce'
                    },
                    success: function(response) {
                        if (response.success) {
                            if (newState === '1') {
                                \$btn.removeClass('visible').addClass('hidden')
                                    .find('.dashicons').removeClass('dashicons-visibility').addClass('dashicons-hidden');
                                \$btn.attr('title', response.data.title);
                            } else {
                                \$btn.removeClass('hidden').addClass('visible')
                                    .find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
                                \$btn.attr('title', response.data.title);
                            }
                        } else {
                            alert(response.data.message || 'Ошибка');
                        }
                    },
                    error: function() {
                        alert('Сетевая ошибка');
                    },
                    complete: function() {
                        \$btn.removeClass('processing');
                    }
                });
            });
        });
        </script>
        ";
    }

    /**
     * AJAX обработчик переключения видимости
     */
    public function ajaxToggleVisibility(): void
    {
        check_ajax_referer('whc_toggle_visibility');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Недостаточно прав', 'woo-hide-categories')]);
        }

        $termId = isset($_POST['term_id']) ? (int) $_POST['term_id'] : 0;
        $hidden = isset($_POST['hidden']) ? sanitize_text_field(wp_unslash($_POST['hidden'])) : '0';

        if (!$termId) {
            wp_send_json_error(['message' => __('Неверный ID категории', 'woo-hide-categories')]);
        }

        update_term_meta($termId, 'whc_hide_from_frontend', $hidden);
        $this->clearHiddenCategoriesCache();

        $isHidden = ($hidden === '1');
        $title = $isHidden
            ? __('Категория скрыта с фронта', 'woo-hide-categories')
            : __('Категория видна на фронте', 'woo-hide-categories');

        wp_send_json_success([
            'title' => $title,
            'hidden' => $hidden,
        ]);
    }

    /**
     * Получить ID скрытых категорий (с кешированием)
     */
    private function getHiddenCategories(): array
    {
        $cacheKey = 'whc_hidden_categories';
        $hidden = wp_cache_get($cacheKey);

        if (false === $hidden) {
            // Устанавливаем флаг защиты от рекурсии
            self::$gettingHiddenCategories = true;

            $hidden = get_terms([
                'taxonomy' => 'product_cat',
                'meta_key' => 'whc_hide_from_frontend',
                'meta_value' => '1',
                'fields' => 'ids',
                'hide_empty' => false,
            ]);

            // Сбрасываем флаг
            self::$gettingHiddenCategories = false;

            if (is_wp_error($hidden)) {
                $hidden = [];
            }

            wp_cache_set($cacheKey, $hidden, '', 300);
        }

        return $hidden;
    }

    /**
     * Очистить кеш скрытых категорий
     */
    private function clearHiddenCategoriesCache(): void
    {
        wp_cache_delete('whc_hidden_categories');
        // Очищаем кеш object cache если используется
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }

    /**
     * Фильтр аргументов get_terms - исключаем скрытые категории из запроса
     */
    public function filterTermsArgs(array $args, array $taxonomies): array
    {
        // Защита от рекурсии: is_tax() и is_product_category() могут вызывать get_terms()
        if (self::$filteringTermsArgs) {
            return $args;
        }

        // Только для product_cat и только на фронте
        if (!$this->isFrontend() || !in_array('product_cat', $taxonomies, true)) {
            return $args;
        }

        // Защита от рекурсии
        if (self::$gettingHiddenCategories) {
            return $args;
        }

        self::$filteringTermsArgs = true;

        // Не вмешиваемся в саму страницу категории — иначе можно сломать её рендеринг и хлебные крошки
        if (is_tax('product_cat') || is_product_category()) {
            self::$filteringTermsArgs = false;
            return $args;
        }

        $hidden = $this->getHiddenCategories();
        self::$filteringTermsArgs = false;
        if (empty($hidden)) {
            return $args;
        }

        if (!isset($args['exclude'])) {
            $args['exclude'] = [];
        }
        $args['exclude'] = array_merge((array) $args['exclude'], $hidden);

        return $args;
    }

    /**
     * Фильтр элементов меню - скрываем категории в меню
     */
    public function filterMenuItems(array $items, $menu, array $args): array
    {
        if (!$this->isFrontend()) {
            return $items;
        }

        $hidden = $this->getHiddenCategories();
        if (empty($hidden)) {
            return $items;
        }

        return array_filter($items, function ($item) use ($hidden) {
            // Если это категория товара (тип taxonomy)
            if ($item->type === 'taxonomy' && $item->object === 'product_cat') {
                return !in_array((int) $item->object_id, $hidden);
            }
            return true;
        });
    }

    /**
     * Фильтр подкатегорий товара (кеш WooCommerce)
     */
    public function filterProductSubcategories(array $categories, int $parentId): array
    {
        if (!$this->isFrontend()) {
            return $categories;
        }

        $hidden = $this->getHiddenCategories();
        if (empty($hidden)) {
            return $categories;
        }

        return array_filter($categories, function ($cat) use ($hidden) {
            return !in_array((int) $cat->term_id, $hidden);
        });
    }

    /**
     * Фильтр wp_list_categories (используется в некоторых темах)
     */
    public function filterCategoryList(string $output, array $args): string
    {
        if (!$this->isFrontend()) {
            return $output;
        }

        // Если это не product_cat, возвращаем как есть
        if (!isset($args['taxonomy']) || $args['taxonomy'] !== 'product_cat') {
            return $output;
        }

        $hidden = $this->getHiddenCategories();
        if (empty($hidden)) {
            return $output;
        }

        // Удаляем скрытые категории из HTML вывода
        foreach ($hidden as $termId) {
            // Паттерн для поиска li с категорией
            $pattern = '/<li[^>]*class="cat-item cat-item-' . $termId . '[^"]*"[^>]*>.*?<\/li>/s';
            $output = preg_replace($pattern, '', $output);
        }

        return $output;
    }

    /**
     * Фильтр REST API запросов для категорий
     */
    public function filterRestQuery(array $args, WP_REST_Request $request): array
    {
        if (!$this->isFrontend()) {
            return $args;
        }

        $hidden = $this->getHiddenCategories();
        if (empty($hidden)) {
            return $args;
        }

        if (!isset($args['exclude'])) {
            $args['exclude'] = [];
        }
        $args['exclude'] = array_merge((array) $args['exclude'], $hidden);

        return $args;
    }

    /**
     * Фильтр категорий в виджете
     */
    public function filterWidgetCategories(array $args): array
    {
        if (!$this->isFrontend()) {
            return $args;
        }

        $hidden = $this->getHiddenCategories();
        if (empty($hidden)) {
            return $args;
        }

        if (!isset($args['exclude'])) {
            $args['exclude'] = [];
        }
        $args['exclude'] = array_merge((array) $args['exclude'], $hidden);

        return $args;
    }

    /**
     * Фильтр get_terms - скрываем категории везде на фронте
     */
    public function filterTerms(array $terms, array $taxonomies): array
    {
        if (!$this->isFrontend() || !in_array('product_cat', $taxonomies, true)) {
            return $terms;
        }

        // Не трогаем саму архивную страницу категории — иначе ломаются term links, breadcrumbs и главная выборка
        if (is_tax('product_cat') || is_product_category()) {
            return $terms;
        }

        // Защита от рекурсии: если уже получаем скрытые категории, пропускаем фильтрацию
        if (self::$gettingHiddenCategories) {
            return $terms;
        }

        $hidden = $this->getHiddenCategories();
        if (empty($hidden)) {
            return $terms;
        }

        return array_filter($terms, function ($term) use ($hidden) {
            $termId = is_object($term) ? $term->term_id : $term;
            return !in_array($termId, $hidden, true);
        });
    }

    /**
     * Проверить, принадлежит ли товар скрытым категориям
     */
    private function isProductInHiddenCategory(int $productId): bool
    {
        $hiddenCats = $this->getHiddenCategories();
        if (empty($hiddenCats)) {
            return false;
        }

        return has_term($hiddenCats, 'product_cat', $productId);
    }

    /**
     * Фильтр видимости товара
     */
    public function filterProductVisibility(bool $visible, int $productId): bool
    {
        if (!$this->isFrontend()) {
            return $visible;
        }

        if ($this->isProductInHiddenCategory($productId)) {
            return false;
        }

        return $visible;
    }

    /**
     * Фильтр запроса товаров
     */
    public function filterProductQuery(WP_Query $q): void
    {
        if (!$this->isFrontend()) {
            return;
        }

        $hiddenCats = $this->getHiddenCategories();
        if (empty($hiddenCats)) {
            return;
        }

        $taxQuery = $q->get('tax_query') ?: [];
        $taxQuery[] = [
            'taxonomy' => 'product_cat',
            'field' => 'term_id',
            'terms' => $hiddenCats,
            'operator' => 'NOT IN',
            'include_children' => true,
        ];
        $q->set('tax_query', $taxQuery);
    }

    /**
     * Фильтр основного запроса WP
     */
    public function filterMainQuery(WP_Query $query): void
    {
        if (!$this->isFrontend() || !$query->is_main_query()) {
            return;
        }

        if (!is_shop() && !is_product_category() && !is_product_tag()) {
            return;
        }

        $hiddenCats = $this->getHiddenCategories();
        if (empty($hiddenCats)) {
            return;
        }

        $taxQuery = $query->get('tax_query') ?: [];
        $taxQuery[] = [
            'taxonomy' => 'product_cat',
            'field' => 'term_id',
            'terms' => $hiddenCats,
            'operator' => 'NOT IN',
            'include_children' => true,
        ];
        $query->set('tax_query', $taxQuery);
    }

    /**
     * Редирект со страниц скрытых категорий
     */
    public function redirectHiddenCategory(): void
    {
        if (!is_product_category()) {
            return;
        }

        $hidden = $this->getHiddenCategories();
        if (empty($hidden)) {
            return;
        }

        $currentCat = get_queried_object();
        if (!$currentCat || !is_a($currentCat, 'WP_Term')) {
            return;
        }

        if (in_array((int) $currentCat->term_id, $hidden)) {
            wp_safe_redirect(home_url(), 302);
            exit;
        }
    }
}

// Инициализация
add_action('plugins_loaded', [WooHideCategories::class, 'getInstance']);
