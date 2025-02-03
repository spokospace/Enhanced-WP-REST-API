<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use Spoko\EnhancedRestAPI\Services\TranslationCache;

class AdminInterface
{
    private const MENU_SLUG = 'spoko-rest-cache';

    public function __construct(
        private TranslationCache $cache
    ) {}

    public function register(): void
    {
        error_log('AdminInterface register() called');
    }

    public function addAdminMenuPage(): void
    {
        error_log('AdminInterface addAdminMenuPage() called');
        add_menu_page(
            'SPOKO Enhanced REST API',  // page title
            'SPOKO REST API',        // menu title
            'manage_options',        // capability
            self::MENU_SLUG,        // menu slug
            [$this, 'renderAdminPage'], // callback
            'dashicons-performance'  // icon
        );
    }

    public function handleCacheClear(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'spoko-enhanced-rest-api'));
        }

        check_admin_referer('clear_spoko_rest_cache');

        $this->cache->clear();
        update_option('spoko_rest_cache_last_clear', current_time('mysql'));

        wp_safe_redirect(add_query_arg(
            'message',
            'cache-cleared',
            admin_url('admin.php?page=' . self::MENU_SLUG)
        ));
        exit;
    }

    public function renderAdminPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['clear_cache']) && check_admin_referer('clear_spoko_rest_cache')) {
                $this->cache->clear();
                update_option('spoko_rest_cache_last_clear', current_time('mysql'));
                $message = 'Cache cleared successfully!';
            }

            // Handle features toggling
            if (isset($_POST['save_features']) && check_admin_referer('save_spoko_rest_features')) {
                update_option(
                    'spoko_rest_related_posts_enabled',
                    isset($_POST['related_posts_enabled']) ? '1' : '0'
                );
                update_option(
                    'spoko_rest_toc_enabled',
                    isset($_POST['toc_enabled']) ? '1' : '0'
                );
                update_option(
                    'spoko_rest_page_excerpt_enabled',
                    isset($_POST['page_excerpt_enabled']) ? '1' : '0'
                );
                update_option(
                    'spoko_rest_relative_urls_enabled',
                    isset($_POST['relative_urls_enabled']) ? '1' : '0'
                );
                
                $message = 'Features settings saved successfully!';
            }
        }

        $stats = $this->cache->getStats();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (isset($message)): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <!-- Features Management -->
            <div class="card">
                <h2 class="title">Features Management</h2>
                <form method="post">
                    <?php wp_nonce_field('save_spoko_rest_features'); ?>
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

                    <?php submit_button('Save Features', 'primary', 'submit', false); ?>
                </form>
            </div>

            <!-- Cache Management -->
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

            <div class="card">
                <h2 class="title">Cache Management</h2>
                <p>Clear the REST API translations cache to force fresh data to be loaded.</p>

                <form method="post">
                    <?php wp_nonce_field('clear_spoko_rest_cache'); ?>
                    <input type="hidden" name="clear_cache" value="1">
                    <?php submit_button('Clear Cache', 'primary', 'submit', false); ?>
                </form>
            </div>
        </div>
        <?php
    }
}
