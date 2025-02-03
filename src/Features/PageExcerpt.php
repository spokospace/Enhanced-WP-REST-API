<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use Spoko\EnhancedRestAPI\Services\ErrorLogger;

class PageExcerpt
{
    public function __construct(
        private ErrorLogger $logger
    ) {}

    public function register(): void
    {
        // Check if feature is enabled
        if (!get_option('spoko_rest_page_excerpt_enabled', true)) {
            return;
        }

        // Add excerpt support for pages
        add_post_type_support('page', 'excerpt');

        // Add column to pages list
        add_filter('manage_pages_columns', [$this, 'addExcerptColumn']);
        add_filter('manage_edit-page_columns', [$this, 'addExcerptColumn']);
        add_action('manage_pages_custom_column', [$this, 'renderExcerptColumn'], 10, 2);

        // Add quick edit fields
        add_filter('page_row_actions', [$this, 'expandRowActions'], 10, 2);
        add_filter('post_row_actions', [$this, 'expandRowActions'], 10, 2);

        // Register column as quick-editable
        add_filter('quick_edit_show_fields', [$this, 'registerQuickEditFields'], 10, 2);

        // Add excerpt field to quick edit
        add_action('quick_edit_custom_box', [$this, 'addQuickEditField'], 10, 2);

        // Save quick edit data
        add_action('wp_ajax_inline-save', [$this, 'saveQuickEdit'], 1);
        add_filter('wp_insert_post_data', [$this, 'filterPostData'], 10, 2);
    }

    public function filterPostData($data, $postarr): array
    {
        if (
            defined('DOING_AJAX') && 
            DOING_AJAX && 
            isset($_POST['action']) && 
            $_POST['action'] === 'inline-save' &&
            isset($_POST['post_type']) && 
            $_POST['post_type'] === 'page' &&
            isset($_POST['excerpt'])
        ) {
            $data['post_excerpt'] = sanitize_textarea_field(wp_unslash($_POST['excerpt']));
            $this->logger->logError('Filtering post data', [
                'post_id' => $postarr['ID'] ?? 'not set',
                'excerpt' => $data['post_excerpt']
            ]);
        }
        return $data;
    }

    public function registerQuickEditFields($show_fields, $post_type): array
    {
        if ($post_type === 'page') {
            $show_fields['excerpt'] = true;
        }
        return $show_fields;
    }

    public function expandRowActions($actions, $post): array
    {
        if ($post->post_type === 'page') {
            $actions['inline hide-if-no-js'] = sprintf(
                '<button type="button" class="button-link editinline" aria-label="%s" aria-expanded="false">%s</button>',
                esc_attr(sprintf(__('Quick edit "%s" inline'), $post->post_title)),
                __('Quick Edit')
            );
        }
        return $actions;
    }

    public function addExcerptColumn($columns): array
    {
        $columns['excerpt'] = __('Excerpt');
        return $columns;
    }

    public function renderExcerptColumn($column_name, $post_id): void
    {
        if ($column_name !== 'excerpt') {
            return;
        }

        $excerpt = get_post_field('post_excerpt', $post_id);
        echo '<div class="excerpt" id="excerpt-' . $post_id . '" data-excerpt="' . esc_attr($excerpt) . '">';
        echo wp_trim_words($excerpt, 10);
        echo '</div>';
    }

    public function addQuickEditField($column_name, $post_type): void
    {
        if ($column_name !== 'excerpt' || $post_type !== 'page') {
            return;
        }
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label>
                    <span class="title"><?php _e('Excerpt'); ?></span>
                    <span class="input-text-wrap">
                        <textarea name="excerpt" cols="22" rows="2" class="excerpt ptitle"></textarea>
                    </span>
                </label>
            </div>
        </fieldset>
        <?php

        // Add JavaScript inline
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var wp_inline_edit = inlineEditPost.edit;
            
            inlineEditPost.edit = function(id) {
                wp_inline_edit.apply(this, arguments);
                
                var post_id = 0;
                if (typeof(id) === 'object') {
                    post_id = parseInt(this.getId(id));
                }
                
                if (post_id > 0) {
                    // Get the row data
                    var $row = $('#post-' + post_id);
                    var $excerpt = $('#excerpt-' + post_id, $row);
                    
                    // Update the excerpt field
                    var excerpt = $excerpt.data('excerpt') || '';
                    $('textarea[name="excerpt"]', '.inline-edit-row').val(excerpt).trigger('change');
                    
                    console.log('Quick Edit loaded for post:', post_id, 'excerpt:', excerpt);
                }
            };
        });
        </script>
        <?php
    }

    public function saveQuickEdit(): void
    {
        $post_id = isset($_POST['post_ID']) ? (int)$_POST['post_ID'] : 0;
        
        if (!$post_id) {
            return;
        }

        // Security checks
        if (!current_user_can('edit_page', $post_id)) {
            wp_die(-1);
        }

        check_ajax_referer('inlineeditnonce', '_inline_edit');

        if (isset($_POST['excerpt'])) {
            remove_action('wp_ajax_inline-save', [$this, 'saveQuickEdit'], 1);
            
            wp_update_post([
                'ID' => $post_id,
                'post_excerpt' => sanitize_textarea_field(wp_unslash($_POST['excerpt']))
            ]);

            add_action('wp_ajax_inline-save', [$this, 'saveQuickEdit'], 1);

            $this->logger->logError('Saving quick edit', [
                'post_id' => $post_id,
                'excerpt' => $_POST['excerpt']
            ]);
        }
    }
}