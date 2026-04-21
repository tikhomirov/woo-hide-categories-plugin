<?php

if (!defined('ABSPATH')) {
    exit;
}

final class WHC_Admin
{
    private static ?self $instance = null;
    private WHC_Plugin $core;

    private function __construct(WHC_Plugin $core)
    {
        $this->core = $core;
    }

    public static function getInstance(WHC_Plugin $core): self
    {
        if (null === self::$instance) {
            self::$instance = new self($core);
        }

        return self::$instance;
    }

    public function init(): void
    {
        add_action('product_cat_add_form_fields', [$this, 'addCategoryField']);
        add_action('product_cat_edit_form_fields', [$this, 'editCategoryField']);
        add_action('created_product_cat', [$this, 'saveCategoryField']);
        add_action('edited_product_cat', [$this, 'saveCategoryField']);

        add_filter('manage_edit-product_cat_columns', [$this, 'addVisibilityColumn']);
        add_filter('manage_product_cat_custom_column', [$this, 'renderVisibilityColumn'], 10, 3);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('wp_ajax_whc_toggle_visibility', [$this, 'ajaxToggleVisibility']);
    }

    public function addCategoryField(): void
    {
        ?>
        <div class="form-field">
            <label for="whc_hide_from_frontend">
                <input type="checkbox" name="whc_hide_from_frontend" id="whc_hide_from_frontend" value="1">
                <?php esc_html_e('Скрыть с фронта', 'woo-hide-categories'); ?>
            </label>
            <p class="description">
                <?php esc_html_e('Если отмечено, категория и все товары в ней будут скрыты с фронтенда магазина', 'woo-hide-categories'); ?>
            </p>
        </div>
        <?php
    }

    public function editCategoryField(WP_Term $term): void
    {
        $hidden = get_term_meta($term->term_id, 'whc_hide_from_frontend', true);
        ?>
        <tr class="form-field">
            <th scope="row">
                <label for="whc_hide_from_frontend"><?php esc_html_e('Скрыть с фронта', 'woo-hide-categories'); ?></label>
            </th>
            <td>
                <label for="whc_hide_from_frontend">
                    <input type="checkbox" name="whc_hide_from_frontend" id="whc_hide_from_frontend" value="1" <?php checked($hidden, '1'); ?>>
                    <?php esc_html_e('Скрыть эту категорию и все товары в ней с фронтенда магазина', 'woo-hide-categories'); ?>
                </label>
            </td>
        </tr>
        <?php
    }

    public function saveCategoryField(int $termId): void
    {
        $value = isset($_POST['whc_hide_from_frontend']) ? '1' : '0';
        update_term_meta($termId, 'whc_hide_from_frontend', $value);
        $this->core->clearHiddenCategoriesCache();
    }

    public function addVisibilityColumn(array $columns): array
    {
        $newColumns = [];
        $added = false;

        foreach ($columns as $key => $value) {
            if ($key === 'handle') {
                $newColumns['whc_visibility'] = __('Видимость', 'woo-hide-categories');
                $added = true;
            }
            $newColumns[$key] = $value;
        }

        if (!$added) {
            $newColumns['whc_visibility'] = __('Видимость', 'woo-hide-categories');
        }

        return $newColumns;
    }

    public function renderVisibilityColumn(string $output, string $column, int $termId): string
    {
        if ($column !== 'whc_visibility') {
            return $output;
        }

        $hidden = get_term_meta($termId, 'whc_hide_from_frontend', true) === '1';
        $title = $hidden ? __('Категория скрыта с фронта', 'woo-hide-categories') : __('Категория видна на фронте', 'woo-hide-categories');

        return sprintf(
            '<button type="button" class="whc-visibility-toggle %s" data-term-id="%d" title="%s"><span class="dashicons %s"></span></button>',
            $hidden ? 'hidden' : 'visible',
            $termId,
            esc_attr($title),
            $hidden ? 'dashicons-hidden' : 'dashicons-visibility'
        );
    }

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

    private function getInlineStyles(): string
    {
        return '
        <style>
            .column-whc_visibility { width: 60px; text-align: center !important; vertical-align: middle !important; }
            .whc-visibility-toggle { background: transparent; border: 0; cursor: pointer; padding: 0; margin: 0; display: inline-block; vertical-align: middle; line-height: 1; }
            .whc-visibility-toggle:hover { opacity: 0.7; }
            .whc-visibility-toggle .dashicons { font-size: 18px; width: 18px; height: 18px; line-height: 1; vertical-align: middle; margin: 0; padding: 0; }
            .whc-visibility-toggle.visible .dashicons { color: #008000; }
            .whc-visibility-toggle.hidden .dashicons { color: #d63638; }
            .whc-visibility-toggle.processing { opacity: 0.6; pointer-events: none; }
            .whc-visibility-toggle.processing .dashicons { color: #999; }
        </style>
        ';
    }

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
                            } else {
                                \$btn.removeClass('hidden').addClass('visible')
                                    .find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
                            }
                            \$btn.attr('title', response.data.title);
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
        $this->core->clearHiddenCategoriesCache();

        $title = ($hidden === '1') ? __('Категория скрыта с фронта', 'woo-hide-categories') : __('Категория видна на фронте', 'woo-hide-categories');

        wp_send_json_success([
            'title' => $title,
            'hidden' => $hidden,
        ]);
    }
}
