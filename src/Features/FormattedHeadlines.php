<?php

declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use WP_REST_Response;
use WP_REST_Request;
use WP_Term;
use WP_Post;

class FormattedHeadlines
{
    /**
     * List of allowed HTML tags and their attributes
     * Each tag can have specified class attribute
     */
    private const ALLOWED_HTML = [
        'b' => ['class' => []],
        'strong' => ['class' => []],
        'br' => [],
        'span' => ['class' => []],
        'sub' => ['class' => []],
        'sup' => ['class' => []],
        'small' => ['class' => []],
        'em' => ['class' => []],
        'i' => ['class' => []],
        'mark' => ['class' => []],
        'nobr' => ['class' => []]
    ];

    private const META_KEY = '_formatted_headline';
    private const DEFAULT_OPTION_VALUE = '1';

    public function register(): void
    {
        $this->registerMetaboxes();
        $this->registerRestFields();
        add_filter('rest_prepare_post_tag', [$this, 'addFormattedHeadlineToTagResponse'], 10, 3);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts']);
    }

    public function enqueueAdminScripts(): void
    {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $allowedScreens = ['post', 'page', 'category', 'post_tag'];
        if (!in_array($screen->id, $allowedScreens, true)) {
            return;
        }

        wp_enqueue_script(
            'spoko-formatted-headlines',
            plugin_dir_url(__FILE__) . './../js/formatted-headlines.js',
            [],
            '1.0.0',
            true
        );
    }

    public function addFormattedHeadlineToTagResponse(
        WP_REST_Response $response,
        WP_Term $term,
        WP_REST_Request $request
    ): WP_REST_Response {
        $headline = get_term_meta($term->term_id, self::META_KEY, true);
        
        if ($headline) {
            $response->data['formatted_headline'] = wp_kses($headline, self::ALLOWED_HTML);
        }

        return $response;
    }

    private function registerMetaboxes(): void
    {
        $postTypes = [
            'posts' => ['type' => 'post', 'action_prefix' => ''],
            'pages' => ['type' => 'page', 'action_prefix' => ''],
            'categories' => ['type' => 'category', 'action_prefix' => 'category_'],
            'post_tags' => ['type' => 'post_tag', 'action_prefix' => 'post_tag_']
        ];

        foreach ($postTypes as $option_suffix => $config) {
            if (get_option("spoko_rest_headlines_{$option_suffix}_enabled", self::DEFAULT_OPTION_VALUE)) {
                $type = $config['type'];
                $prefix = $config['action_prefix'];

                if (in_array($type, ['post', 'page'], true)) {
                    add_action("add_meta_boxes_{$type}", [$this, 'addHeadlineMetabox']);
                    add_action('save_post', [$this, 'saveHeadline']);
                } else {
                    add_action("{$prefix}add_form_fields", [$this, 'addCategoryField']);
                    add_action("{$prefix}edit_form_fields", [$this, 'editCategoryField']);
                    add_action("created_{$type}", [$this, 'saveTermHeadline']);
                    add_action("edited_{$type}", [$this, 'saveTermHeadline']);
                }
            }
        }
    }

    private function registerRestFields(): void
    {
        $restFields = [
            'post' => [$this, 'getFormattedHeadline'],
            'page' => [$this, 'getFormattedHeadline'],
            'category' => [$this, 'getTermFormattedHeadline'],
            'post_tag' => [$this, 'getTermFormattedHeadline']
        ];

        foreach ($restFields as $type => $callback) {
            register_rest_field($type, 'formatted_headline', [
                'get_callback' => $callback,
                'schema' => [
                    'description' => 'HTML formatted headline for hero section',
                    'type' => 'string'
                ]
            ]);
        }
    }

    public function addHeadlineMetabox(WP_Post $post): void
    {
        add_meta_box(
            'spoko-formatted-headline',
            'Formatted Headline (Hero Section)',
            [$this, 'renderHeadlineMetabox'],
            $post->post_type,
            'normal',
            'high'
        );
    }

    public function renderHeadlineMetabox(WP_Post $post): void
    {
        wp_nonce_field('save_formatted_headline', 'formatted_headline_nonce');
        $value = get_post_meta($post->ID, self::META_KEY, true);
        ?>
        <div class="spoko-headline-metabox">
            <p class="description">
                Available HTML tags: <?php echo esc_html(implode(', ', array_keys(self::ALLOWED_HTML))); ?>
            </p>
            <textarea
                name="formatted_headline"
                id="formatted_headline"
                class="large-text"
                rows="2"
                style="width: 100%"
            ><?php echo esc_textarea($value); ?></textarea>
            <p class="description">
                Example: <code>&lt;b class="emphasis"&gt;Explore the new content&lt;/b&gt;</code>
            </p>
            <div class="button-container">
                <button type="button" id="add-bold" class="button">Bold</button>
                <button type="button" id="add-small" class="button">Small</button>
                <button type="button" id="insert-title" class="button">Insert Current Title</button>
            </div>
            <h1 id="formatted_headline_preview" class="formatted_headline_preview">
                <?php echo $value; ?>
            </h1>
        </div>
        <?php
    }

    private function validateSaveRequest(int $postId): bool
    {
        if (!isset($_POST['formatted_headline_nonce'])) {
            return false;
        }

        if (!wp_verify_nonce($_POST['formatted_headline_nonce'], 'save_formatted_headline')) {
            return false;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return false;
        }

        $postType = $_POST['post_type'] ?? '';
        $capability = in_array($postType, ['page', 'post'], true)
            ? "edit_{$postType}"
            : 'edit_post';

        if (!current_user_can($capability, $postId)) {
            return false;
        }

        return true;
    }

    public function saveHeadline(int $postId): void
    {
        if (!$this->validateSaveRequest($postId)) {
            return;
        }

        if (!isset($_POST['formatted_headline'])) {
            return;
        }

        $headline = wp_kses($_POST['formatted_headline'], self::ALLOWED_HTML);
        update_post_meta($postId, self::META_KEY, $headline);
    }

    public function addCategoryField(): void
    {
        ?>
        <div class="form-field">
            <label for="formatted_headline">Formatted Headline (Hero Section)</label>
            <textarea
                name="formatted_headline"
                id="formatted_headline"
                rows="2"
                style="width: 95%"
            ></textarea>
            <p class="description">
                Available HTML tags: <?php echo esc_html(implode(', ', array_keys(self::ALLOWED_HTML))); ?><br>
                Example: <code>&lt;b class="emphasis"&gt;Tech News&lt;/b&gt;</code>
            </p>
        </div>
        <?php
    }

    public function editCategoryField(WP_Term $term): void
    {
        $value = get_term_meta($term->term_id, self::META_KEY, true);
        ?>
        <tr class="form-field">
            <th scope="row">
                <label for="formatted_headline">Formatted Headline (Hero Section)</label>
            </th>
            <td>
                <textarea
                    name="formatted_headline"
                    id="formatted_headline"
                    rows="2"
                    style="width: 95%"
                ><?php echo esc_textarea($value); ?></textarea>
                <p class="description">
                    Available HTML tags: <?php echo esc_html(implode(', ', array_keys(self::ALLOWED_HTML))); ?><br>
                    Example: <code>&lt;b class="emphasis"&gt;Tech News&lt;/b&gt;</code>
                </p>
            </td>
        </tr>
        <?php
    }

    public function saveTermHeadline(int $termId): void
    {
        if (!current_user_can('edit_term', $termId)) {
            return;
        }

        if (!isset($_POST['formatted_headline'])) {
            return;
        }

        $headline = wp_kses($_POST['formatted_headline'], self::ALLOWED_HTML);
        update_term_meta($termId, self::META_KEY, $headline);
    }

    public function getFormattedHeadline(array $post): ?string
    {
        return $this->sanitizeAndGetHeadline(
            get_post_meta($post['id'], self::META_KEY, true)
        );
    }

    public function getTermFormattedHeadline(array $term): ?string
    {
        return $this->sanitizeAndGetHeadline(
            get_term_meta($term['id'], self::META_KEY, true)
        );
    }

    private function sanitizeAndGetHeadline(?string $headline): ?string
    {
        if (empty($headline)) {
            return null;
        }
        
        $sanitized = wp_kses($headline, self::ALLOWED_HTML);
        return empty($sanitized) ? null : $sanitized;
    }
}