<?php

declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

/**
 * Adds featured image support for categories
 * Allows setting a featured image in the category edit screen
 * Image ID is stored in term_meta with key 'featured_image'
 */
class CategoryFeaturedImage
{
    private const META_KEY = 'featured_image';

    /**
     * Flag to prevent infinite loop during translation sync
     */
    private bool $isSyncing = false;

    public function register(): void
    {
        // Add field to category add form
        add_action('category_add_form_fields', [$this, 'addFormField']);

        // Add field to category edit form
        add_action('category_edit_form_fields', [$this, 'editFormField']);

        // Save the field
        add_action('created_category', [$this, 'saveField']);
        add_action('edited_category', [$this, 'saveField']);

        // Enqueue media scripts on term edit pages
        add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);

        // Add column to categories list
        add_filter('manage_edit-category_columns', [$this, 'addImageColumn']);
        add_filter('manage_category_custom_column', [$this, 'renderImageColumn'], 10, 3);

        // Admin notice and migration action
        add_action('admin_notices', [$this, 'showMigrationNotice']);
        add_action('admin_post_sync_category_images', [$this, 'runMigration']);
    }

    /**
     * Show migration notice if there are unsynced category images
     */
    public function showMigrationNotice(): void
    {
        // Only show to admins
        if (!current_user_can('manage_categories')) {
            return;
        }

        // Check if Polylang is active
        if (!function_exists('pll_get_term_translations')) {
            return;
        }

        // Show on category pages or if force parameter is set
        $screen = get_current_screen();
        $isCategoryPage = $screen && ($screen->taxonomy === 'category' || $screen->base === 'edit-tags');

        // Check if migration was already done (skip check if force_check is set)
        $forceCheck = isset($_GET['force_sync_check']);
        if (!$forceCheck && get_option('spoko_category_images_synced', false)) {
            return;
        }

        // Only run the expensive check on category pages
        if (!$isCategoryPage && !$forceCheck) {
            return;
        }

        // Check if there are any unsynced images
        if (!$this->hasUnsyncedImages()) {
            if (!$forceCheck) {
                update_option('spoko_category_images_synced', true);
            }
            return;
        }

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=sync_category_images'),
            'sync_category_images'
        );

        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s" class="button button-primary">%s</a></p></div>',
            esc_html__('Some category featured images are not synced across translations.', 'spoko-enhanced-rest-api'),
            esc_url($url),
            esc_html__('Sync Now', 'spoko-enhanced-rest-api')
        );
    }

    /**
     * Check if there are categories with unsynced featured images
     */
    private function hasUnsyncedImages(): bool
    {
        $categories = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key' => self::META_KEY,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        if (is_wp_error($categories) || empty($categories)) {
            return false;
        }

        foreach ($categories as $category) {
            $imageId = get_term_meta($category->term_id, self::META_KEY, true);
            if (!$imageId) {
                continue;
            }

            $translations = pll_get_term_translations($category->term_id);
            if (!is_array($translations)) {
                continue;
            }

            foreach ($translations as $lang => $translatedTermId) {
                $translatedTermId = (int) $translatedTermId;
                if ($translatedTermId === $category->term_id) {
                    continue;
                }

                $translatedImageId = get_term_meta($translatedTermId, self::META_KEY, true);
                if ((int) $translatedImageId !== (int) $imageId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Run migration to sync all category featured images
     */
    public function runMigration(): void
    {
        if (!current_user_can('manage_categories')) {
            wp_die(__('Unauthorized', 'spoko-enhanced-rest-api'));
        }

        check_admin_referer('sync_category_images');

        $synced = self::syncAllCategoryImages();

        wp_safe_redirect(add_query_arg([
            'taxonomy' => 'category',
            'synced_images' => $synced,
        ], admin_url('edit-tags.php')));
        exit;
    }

    /**
     * Sync featured images for all categories to their translations
     * Returns number of synced translations, or -1 if Polylang is not active
     */
    public static function syncAllCategoryImages(): int
    {
        if (!function_exists('pll_get_term_translations')) {
            return -1;
        }

        $categories = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key' => 'featured_image',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        if (is_wp_error($categories) || empty($categories)) {
            return 0;
        }

        $synced = 0;
        $processed = [];

        foreach ($categories as $category) {
            // Skip if already processed as part of another translation group
            if (isset($processed[$category->term_id])) {
                continue;
            }

            $imageId = get_term_meta($category->term_id, 'featured_image', true);
            if (!$imageId) {
                continue;
            }

            $translations = pll_get_term_translations($category->term_id);
            if (!is_array($translations)) {
                continue;
            }

            // Mark all translations as processed
            foreach ($translations as $tId) {
                $processed[(int) $tId] = true;
            }

            // Sync to all translations
            foreach ($translations as $lang => $translatedTermId) {
                $translatedTermId = (int) $translatedTermId;
                if ($translatedTermId === $category->term_id) {
                    continue;
                }

                $existingImage = get_term_meta($translatedTermId, 'featured_image', true);
                if ((int) $existingImage !== (int) $imageId) {
                    update_term_meta($translatedTermId, 'featured_image', (int) $imageId);
                    $synced++;
                }
            }
        }

        // Mark as synced so the notice doesn't show anymore
        update_option('spoko_category_images_synced', true);

        return $synced;
    }

    /**
     * Add field to "Add New Category" form
     */
    public function addFormField(): void
    {
        ?>
        <div class="form-field term-featured-image-wrap">
            <label for="category-featured-image"><?php esc_html_e('Featured Image', 'spoko-enhanced-rest-api'); ?></label>
            <div id="category-featured-image-wrapper">
                <img id="category-featured-image-preview" src="" style="max-width: 200px; display: none;" />
            </div>
            <input type="hidden" id="category-featured-image" name="category_featured_image" value="" />
            <p>
                <button type="button" class="button" id="category-featured-image-upload">
                    <?php esc_html_e('Select Image', 'spoko-enhanced-rest-api'); ?>
                </button>
                <button type="button" class="button" id="category-featured-image-remove" style="display: none;">
                    <?php esc_html_e('Remove Image', 'spoko-enhanced-rest-api'); ?>
                </button>
            </p>
            <p class="description"><?php esc_html_e('Featured image for this category, displayed in REST API.', 'spoko-enhanced-rest-api'); ?></p>
        </div>
        <?php
        $this->outputScript();
    }

    /**
     * Add field to "Edit Category" form
     */
    public function editFormField(\WP_Term $term): void
    {
        $imageId = get_term_meta($term->term_id, self::META_KEY, true);
        $imageUrl = $imageId ? wp_get_attachment_image_url((int) $imageId, 'thumbnail') : '';
        ?>
        <tr class="form-field term-featured-image-wrap">
            <th scope="row">
                <label for="category-featured-image"><?php esc_html_e('Featured Image', 'spoko-enhanced-rest-api'); ?></label>
            </th>
            <td>
                <div id="category-featured-image-wrapper">
                    <img id="category-featured-image-preview"
                         src="<?php echo esc_url($imageUrl); ?>"
                         style="max-width: 200px; <?php echo $imageUrl ? '' : 'display: none;'; ?>" />
                </div>
                <input type="hidden"
                       id="category-featured-image"
                       name="category_featured_image"
                       value="<?php echo esc_attr($imageId); ?>" />
                <p>
                    <button type="button" class="button" id="category-featured-image-upload">
                        <?php esc_html_e('Select Image', 'spoko-enhanced-rest-api'); ?>
                    </button>
                    <button type="button"
                            class="button"
                            id="category-featured-image-remove"
                            style="<?php echo $imageUrl ? '' : 'display: none;'; ?>">
                        <?php esc_html_e('Remove Image', 'spoko-enhanced-rest-api'); ?>
                    </button>
                </p>
                <p class="description"><?php esc_html_e('Featured image for this category, displayed in REST API.', 'spoko-enhanced-rest-api'); ?></p>
            </td>
        </tr>
        <?php
        $this->outputScript();
    }

    /**
     * Save the featured image field and sync to translations
     */
    public function saveField(int $termId): void
    {
        if (!isset($_POST['category_featured_image'])) {
            return;
        }

        $imageId = sanitize_text_field($_POST['category_featured_image']);

        if (empty($imageId)) {
            delete_term_meta($termId, self::META_KEY);
            // Sync deletion to translations
            $this->syncToTranslations($termId, null);
        } else {
            update_term_meta($termId, self::META_KEY, (int) $imageId);
            // Sync to translations
            $this->syncToTranslations($termId, (int) $imageId);
        }
    }

    /**
     * Sync featured image to all translations of a category (Polylang)
     */
    private function syncToTranslations(int $termId, ?int $imageId): void
    {
        // Prevent infinite loop
        if ($this->isSyncing) {
            return;
        }

        // Check if Polylang is active
        if (!function_exists('pll_get_term_translations')) {
            return;
        }

        $this->isSyncing = true;

        try {
            // Get all translations of this term
            $translations = pll_get_term_translations($termId);

            if (!is_array($translations)) {
                return;
            }

            foreach ($translations as $lang => $translatedTermId) {
                $translatedTermId = (int) $translatedTermId;

                // Skip the current term
                if ($translatedTermId === $termId) {
                    continue;
                }

                // Update or delete the featured image for the translation
                if ($imageId === null) {
                    delete_term_meta($translatedTermId, self::META_KEY);
                } else {
                    update_term_meta($translatedTermId, self::META_KEY, $imageId);
                }
            }
        } finally {
            $this->isSyncing = false;
        }
    }

    /**
     * Enqueue media scripts on category edit pages
     */
    public function enqueueScripts(string $hook): void
    {
        if (!in_array($hook, ['edit-tags.php', 'term.php'], true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->taxonomy !== 'category') {
            return;
        }

        wp_enqueue_media();
    }

    /**
     * Add image column to categories list
     */
    public function addImageColumn(array $columns): array
    {
        $newColumns = [];
        foreach ($columns as $key => $value) {
            if ($key === 'name') {
                $newColumns['featured_image'] = __('Image', 'spoko-enhanced-rest-api');
            }
            $newColumns[$key] = $value;
        }
        return $newColumns;
    }

    /**
     * Render image in the column
     */
    public function renderImageColumn(string $content, string $columnName, int $termId): string
    {
        if ($columnName !== 'featured_image') {
            return $content;
        }

        $imageId = get_term_meta($termId, self::META_KEY, true);
        if ($imageId) {
            $imageUrl = wp_get_attachment_image_url((int) $imageId, 'thumbnail');
            if ($imageUrl) {
                return sprintf(
                    '<img src="%s" style="max-width: 50px; max-height: 50px;" />',
                    esc_url($imageUrl)
                );
            }
        }

        return '—';
    }

    /**
     * Output the JavaScript for media uploader
     */
    private function outputScript(): void
    {
        ?>
        <script>
        jQuery(document).ready(function($) {
            var frame;
            var $imageInput = $('#category-featured-image');
            var $imagePreview = $('#category-featured-image-preview');
            var $uploadButton = $('#category-featured-image-upload');
            var $removeButton = $('#category-featured-image-remove');

            $uploadButton.on('click', function(e) {
                e.preventDefault();

                if (frame) {
                    frame.open();
                    return;
                }

                frame = wp.media({
                    title: '<?php esc_html_e('Select Category Image', 'spoko-enhanced-rest-api'); ?>',
                    button: {
                        text: '<?php esc_html_e('Use this image', 'spoko-enhanced-rest-api'); ?>'
                    },
                    multiple: false
                });

                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    var thumbnailUrl = attachment.sizes && attachment.sizes.thumbnail
                        ? attachment.sizes.thumbnail.url
                        : attachment.url;

                    $imageInput.val(attachment.id);
                    $imagePreview.attr('src', thumbnailUrl).show();
                    $removeButton.show();
                });

                frame.open();
            });

            $removeButton.on('click', function(e) {
                e.preventDefault();
                $imageInput.val('');
                $imagePreview.attr('src', '').hide();
                $removeButton.hide();
            });
        });
        </script>
        <?php
    }
}
