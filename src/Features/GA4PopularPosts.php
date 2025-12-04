<?php

declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use Spoko\EnhancedRestAPI\Services\{ErrorLogger, GA4Client};
use WP_REST_Request;
use WP_REST_Response;

/**
 * GA4 Popular Posts Feature
 *
 * Provides REST API endpoint for fetching popular posts based on
 * Google Analytics 4 pageview data. Supports multilingual sites via Polylang.
 *
 * Endpoint: GET /wp-json/wp/v2/posts/popular
 *
 * @since 1.1.0
 */
class GA4PopularPosts
{
    private const REST_NAMESPACE = 'wp/v2';
    private const REST_ROUTE = '/posts/popular';
    private const DEFAULT_LIMIT = 20;
    private const CACHE_GROUP = 'spoko_rest_api';
    private const CACHE_KEY_PREFIX = 'ga4_popular_';

    public function __construct(
        private ErrorLogger $logger,
        private GA4Client $ga4Client
    ) {}

    /**
     * Register REST API routes
     */
    public function registerRestRoutes(): void
    {
        if (!get_option('spoko_rest_ga4_popular_enabled', false)) {
            return;
        }

        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods' => 'GET',
            'callback' => [$this, 'getPopularPosts'],
            'permission_callback' => '__return_true',
            'args' => [
                'limit' => [
                    'description' => 'Maximum number of posts to return',
                    'type' => 'integer',
                    'required' => false,
                    'default' => self::DEFAULT_LIMIT,
                    'minimum' => 1,
                    'maximum' => 50,
                    'validate_callback' => fn($param) =>
                        filter_var($param, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]) !== false
                ],
                'period' => [
                    'description' => 'Time period for analytics data',
                    'type' => 'string',
                    'required' => false,
                    'default' => '30d',
                    'enum' => ['7d', '14d', '30d', '90d']
                ],
                'lang' => [
                    'description' => 'Filter by language (Polylang language slug)',
                    'type' => 'string',
                    'required' => false,
                    'default' => null
                ]
            ]
        ]);
    }

    /**
     * Handle GET /wp-json/wp/v2/posts/popular
     */
    public function getPopularPosts(WP_REST_Request $request): WP_REST_Response
    {
        $limit = (int) $request->get_param('limit');
        $period = $request->get_param('period');
        $lang = $request->get_param('lang');

        // Validate GA4 configuration
        $propertyId = get_option('spoko_rest_ga4_property_id', '');
        if (empty($propertyId)) {
            return new WP_REST_Response([
                'code' => 'ga4_not_configured',
                'message' => 'GA4 Property ID is not configured'
            ], 503);
        }

        // Generate cache key
        $cacheKey = $this->getCacheKey($period, $lang);
        $cacheDuration = (int) get_option('spoko_rest_ga4_cache_hours', 6) * HOUR_IN_SECONDS;

        // Try to get from cache
        $cachedData = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cachedData !== false) {
            return $this->formatResponse($cachedData, $limit, $period, $lang, true);
        }

        // Fetch from GA4
        $ga4Data = $this->ga4Client->fetchPageViews($propertyId, $period, 100);

        if (empty($ga4Data)) {
            // Return empty result if GA4 returns no data
            return $this->formatResponse([], $limit, $period, $lang, false);
        }

        // Map paths to posts
        $posts = $this->mapPathsToPosts($ga4Data, $lang);

        // Cache the results
        wp_cache_set($cacheKey, $posts, self::CACHE_GROUP, $cacheDuration);

        return $this->formatResponse($posts, $limit, $period, $lang, false);
    }

    /**
     * Map GA4 URL paths to WordPress posts
     */
    private function mapPathsToPosts(array $ga4Data, ?string $lang): array
    {
        $posts = [];
        $seenIds = [];

        foreach ($ga4Data as $item) {
            $path = $item['path'];
            $pageviews = $item['pageviews'];

            // Try to find post by URL
            $postId = url_to_postid(home_url($path));

            // If not found, try slug-based lookup
            if (!$postId) {
                $postId = $this->findPostBySlug($path);
            }

            if (!$postId || isset($seenIds[$postId])) {
                continue;
            }

            // Get post object
            $post = get_post($postId);
            if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'post') {
                continue;
            }

            // Filter by language if specified (Polylang)
            if ($lang && function_exists('pll_get_post_language')) {
                $postLang = pll_get_post_language($postId, 'slug');
                if ($postLang !== $lang) {
                    continue;
                }
            }

            $seenIds[$postId] = true;
            $posts[] = $this->formatPostData($post, $pageviews);
        }

        return $posts;
    }

    /**
     * Try to find post by slug extracted from path
     */
    private function findPostBySlug(string $path): ?int
    {
        // Remove leading/trailing slashes and get last segment
        $path = trim($path, '/');
        $segments = explode('/', $path);
        $slug = end($segments);

        if (empty($slug)) {
            return null;
        }

        // Remove query string and fragment
        $slug = strtok($slug, '?#');

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $postId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_name = %s
                AND post_type = 'post'
                AND post_status = 'publish'
                LIMIT 1",
                $slug
            )
        );

        return $postId ? (int) $postId : null;
    }

    /**
     * Format post data for API response
     */
    private function formatPostData(\WP_Post $post, int $pageviews): array
    {
        $featuredImageId = get_post_thumbnail_id($post->ID);

        $data = [
            'id' => $post->ID,
            'title' => ['rendered' => get_the_title($post)],
            'slug' => $post->post_name,
            'link' => wp_make_link_relative(get_permalink($post)),
            'date' => get_post_datetime($post)?->format('c'),
            'pageviews' => $pageviews,
            'featured_media' => $featuredImageId ?: 0,
            'featured_image_urls' => $this->getFeaturedImageUrls($post->ID),
            'featured_image_alt' => $featuredImageId
                ? get_post_meta($featuredImageId, '_wp_attachment_image_alt', true)
                : '',
            'excerpt' => [
                'rendered' => apply_filters('the_excerpt', get_the_excerpt($post))
            ],
            'categories_data' => $this->getCategoriesData($post->ID)
        ];

        // Add language info if Polylang active
        if (function_exists('pll_get_post_language')) {
            $data['lang'] = pll_get_post_language($post->ID, 'slug');
        }

        return $data;
    }

    /**
     * Format final API response
     */
    private function formatResponse(array $posts, int $limit, string $period, ?string $lang, bool $fromCache): WP_REST_Response
    {
        // Slice to requested limit
        $posts = array_slice($posts, 0, $limit);

        $response = [
            'posts' => $posts,
            'total' => count($posts),
            'period' => $period,
            'cached' => $fromCache,
            'cached_at' => $fromCache ? current_time('c') : null
        ];

        if ($lang) {
            $response['lang'] = $lang;
        }

        return new WP_REST_Response($response, 200);
    }

    /**
     * Get featured image URLs for all sizes
     */
    private function getFeaturedImageUrls(int $postId): ?array
    {
        if (!has_post_thumbnail($postId)) {
            return null;
        }

        $thumbnailId = get_post_thumbnail_id($postId);
        $imageSizes = get_intermediate_image_sizes();
        $imageUrls = [];

        foreach ($imageSizes as $size) {
            $imageData = wp_get_attachment_image_src($thumbnailId, $size);
            if ($imageData) {
                $imageUrls[$size] = $imageData[0];
            }
        }

        $fullImage = wp_get_attachment_image_src($thumbnailId, 'full');
        if ($fullImage) {
            $imageUrls['full'] = $fullImage[0];
        }

        return $imageUrls;
    }

    /**
     * Get categories data for post
     */
    private function getCategoriesData(int $postId): array
    {
        $categories = wp_get_post_categories($postId, ['fields' => 'all']);

        return array_map(fn($category) => [
            'id' => $category->term_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'link' => wp_make_link_relative(get_category_link($category->term_id))
        ], $categories);
    }

    /**
     * Generate cache key
     */
    private function getCacheKey(string $period, ?string $lang): string
    {
        $key = self::CACHE_KEY_PREFIX . $period;
        if ($lang) {
            $key .= "_{$lang}";
        }
        return $key;
    }
}
