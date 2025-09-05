<?php

declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use Spoko\EnhancedRestAPI\Services\TranslationCache;

class AdminInterface
{
    private const MENU_SLUG = 'spoko-rest-cache';
    private const NONCE_CACHE = 'clear_spoko_rest_cache';
    private const NONCE_FEATURES = 'save_spoko_rest_features';
    private const NONCE_HEADLINES = 'save_spoko_rest_headlines';

    public function __construct(
        private TranslationCache $cache
    ) {}

    public function register(): void
    {
        // Add admin menu and handle form submissions
        add_action('admin_menu', [$this, 'addAdminMenuPage']);
        add_action('admin_post_clear_cache', [$this, 'handleCacheClear']);
        add_action('admin_head', [$this, 'spoko_admin_custom_styles']);

    }

    public function spoko_admin_custom_styles()
    {
        echo '<style>
            .card {
                max-width: 100%;
                width: 100%;
            }

            #formatted_headline_preview {
                font-size: 2.75rem;
                font-weight: 300;
                line-height: 1.25;

                small {
                    font-size: 1.5rem;
                    white-space: nowrap;
                }

                b {
                    font-weight: 700;
                    white-space: nowrap;
                }
            }
        </style>';
    }

    public function addAdminMenuPage(): void
    {
        add_menu_page(
            'SPOKO Enhanced REST API',  // page title
            'SPOKO REST API',           // menu title
            'manage_options',           // capability
            self::MENU_SLUG,           // menu slug
            [$this, 'renderAdminPage'], // callbackcz
            'dashicons-performance'     // icon
        );
    }

    private function handleFormSubmission(): ?string
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return null;
        }

        // Handle cache clearing
        if (isset($_POST['clear_cache']) && check_admin_referer(self::NONCE_CACHE)) {
            $this->cache->clear();
            update_option('spoko_rest_cache_last_clear', current_time('mysql'));
            return 'Cache cleared successfully!';
        }

        // Handle headlines settings
        if (isset($_POST['save_headlines']) && check_admin_referer(self::NONCE_HEADLINES)) {
            $this->saveHeadlinesSettings();
            return 'Headlines settings saved successfully!';
        }

        // Handle features toggling
        if (isset($_POST['save_features']) && check_admin_referer(self::NONCE_FEATURES)) {
            $this->saveFeatureSettings();
            return 'Features settings saved successfully!';
        }

        return null;
    }

    private function saveHeadlinesSettings(): void
    {
        $options = [
            'spoko_rest_headlines_posts_enabled',
            'spoko_rest_headlines_pages_enabled',
            'spoko_rest_headlines_categories_enabled',
            'spoko_rest_headlines_post_tags_enabled'
        ];

        foreach ($options as $option) {
            update_option(
                $option,
                isset($_POST[str_replace('spoko_rest_', '', $option)]) ? '1' : '0'
            );
        }
    }

    private function saveFeatureSettings(): void
    {
        $features = [
            'spoko_rest_related_posts_enabled',
            'spoko_rest_toc_enabled',
            'spoko_rest_page_excerpt_enabled',
            'spoko_rest_relative_urls_enabled'
        ];

        foreach ($features as $feature) {
            update_option(
                $feature,
                isset($_POST[str_replace('spoko_rest_', '', $feature)]) ? '1' : '0'
            );
        }
    }

    public function renderAdminPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $message = $this->handleFormSubmission();
        $stats = $this->cache->getStats();
?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if ($message): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <!-- <div class="metabox-holder">
    <div class="postbox">
        <h2 class="hndle">Karta 1</h2>
        <div class="inside">Treść 1</div>
    </div>
    <div class="postbox">
        <h2 class="hndle">Karta 2</h2>
        <div class="inside">Treść 2</div>
    </div>
</div> -->

            <!-- Features Management -->
            <div class="card">
                <h2 class="title">Features Management</h2>
                <form method="post">
                    <?php wp_nonce_field(self::NONCE_FEATURES); ?>
                    <input type="hidden" name="save_features" value="1">

                    <table class="form-table">
                        <tr>
                            <th scope="row">Related Posts</th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                        name="related_posts_enabled"
                                        value="1"
                                        <?php checked('1', get_option('spoko_rest_related_posts_enabled', '1')); ?>>
                                    Enable Related Posts endpoint
                                </label>
                                <p class="description">
                                    <code><?php echo esc_html(get_site_url() . '/wp-json/wp/v2/posts/{post_id}/related'); ?></code> endpoint
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Table of Contents</th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                        name="toc_enabled"
                                        value="1"
                                        <?php checked('1', get_option('spoko_rest_toc_enabled', '1')); ?>>
                                    Enable Table of Contents endpoint
                                </label>
                                <p class="description">
                                    <code><?php echo esc_html(get_site_url() . '/wp-json/wp/v2/posts/{post_id}/toc'); ?></code> and
                                    <code><?php echo esc_html(get_site_url() . '/wp-json/wp/v2/pages/{post_id}/toc'); ?></code> endpoints
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Page Excerpts</th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                        name="page_excerpt_enabled"
                                        value="1"
                                        <?php checked('1', get_option('spoko_rest_page_excerpt_enabled', '1')); ?>>
                                    Enable excerpts for pages
                                </label>
                                <p class="description">
                                    Adds excerpt support for pages in admin panel and REST API
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Relative URLs</th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                        name="relative_urls_enabled"
                                        value="1"
                                        <?php checked('1', get_option('spoko_rest_relative_urls_enabled', '1')); ?>>
                                    Convert URLs to relative format
                                </label>
                                <p class="description">
                                    Make all URLs relative instead of absolute in taxonomy responses
                                </p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button('Save Features', 'primary', 'save_features', false); ?>
                </form>
            </div>

            <!-- Formatted Headlines -->
            <div class="card">
                <h2 class="title">Formatted Headlines</h2>
                <p class="description">
                    Configure where formatted headlines should appear in your posts, pages and categories.
                </p>

                <form method="post">
                    <?php wp_nonce_field(self::NONCE_HEADLINES); ?>
                    <input type="hidden" name="save_headlines" value="1">

                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable For</th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                        name="headlines_posts_enabled"
                                        value="1"
                                        <?php checked('1', get_option('spoko_rest_headlines_posts_enabled', '1')); ?>>
                                    Posts
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox"
                                        name="headlines_pages_enabled"
                                        value="1"
                                        <?php checked('1', get_option('spoko_rest_headlines_pages_enabled', '1')); ?>>
                                    Pages
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox"
                                        name="headlines_categories_enabled"
                                        value="1"
                                        <?php checked('1', get_option('spoko_rest_headlines_categories_enabled', '1')); ?>>
                                    Categories
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox"
                                        name="headlines_post_tags_enabled"
                                        value="1"
                                        <?php checked('1', get_option('spoko_rest_headlines_post_tags_enabled', '1')); ?>>
                                    Tags
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Allowed HTML Tags</th>
                            <td>
                                <code>b, strong, br, span, sub, sup, small, em, i, mark, nobr</code>
                                <p class="description">These tags can be used in formatted headlines with CSS classes.</p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button('Save Headlines Settings', 'primary', 'save_headlines', false); ?>
                </form>
            </div>

            <!-- Cache Statistics -->
            <div class="card">
                <h2 class="title">Cache Statistics</h2>
                <p>
                    <strong>Cache Type:</strong> <?php echo $stats['using_redis'] ? 'Redis' : 'Object Cache'; ?><br>
                    <?php if ($stats['using_redis']): ?>
                        <strong>Redis Version:</strong> <?php echo esc_html($stats['redis_version']); ?><br>
                        <strong>Memory Used:</strong> <?php echo esc_html($stats['redis_memory_used']); ?><br>
                        <strong>Cache Hits:</strong> <?php echo number_format($stats['redis_hits']); ?><br>
                        <strong>Cache Misses:</strong> <?php echo number_format($stats['redis_misses']); ?><br>
                    <?php endif; ?>
                    <strong>Cache Group:</strong> <?php echo esc_html($stats['group']); ?><br>
                    <strong>Cache Lifetime:</strong> <?php echo esc_html($stats['expire_time']); ?><br>
                    <strong>Last Cleared:</strong> <?php echo $stats['last_cleared'] ? esc_html($stats['last_cleared']) : 'Never'; ?>
                </p>
            </div>

            <!-- Cache Management -->
            <div class="card">
                <h2 class="title">Cache Management</h2>
                <p>Clear the REST API translations cache to force fresh data to be loaded.</p>

                <form method="post">
                    <?php wp_nonce_field(self::NONCE_CACHE); ?>
                    <input type="hidden" name="clear_cache" value="1">
                    <?php submit_button('Clear Cache', 'primary', 'clear_cache', false); ?>
                </form>
            </div>
        </div>
<?php
    }
}
