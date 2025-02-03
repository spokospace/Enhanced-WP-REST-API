<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use Spoko\EnhancedRestAPI\Services\ErrorLogger;

class RelatedPosts
{
    private const POSTS_LIMIT = 5;
    private const REST_NAMESPACE = 'wp/v2';
    private const REST_ROUTE = '/posts/(?P<id>\d+)/related';
    private const TAXONOMY_TAG = 'post_tag';
    private const TAXONOMY_CATEGORY = 'category';
    private const POST_TYPE = 'post';
    private const POST_STATUS = 'publish';

    public function __construct(
        private ErrorLogger $logger
    ) {}

    public function register(): void
    {
        // Check if feature is enabled
        if (!get_option('spoko_rest_related_posts_enabled', true)) {
            return;
        }

        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            [
                'methods' => 'GET',
                'callback' => [$this, 'getRelatedPosts'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => [
                        'required' => true,
                        'validate_callback' => fn($param) => is_numeric($param) && $param > 0
                    ],
                    'limit' => [
                        'required' => false,
                        'default' => self::POSTS_LIMIT,
                        'validate_callback' => fn($param) => is_numeric($param) && $param > 0 && $param <= 20
                    ],
                    'orderby' => [
                        'required' => false,
                        'default' => 'date',
                        'enum' => ['date', 'title', 'menu_order', 'rand']
                    ]
                ]
            ]
        );
    }

    public function getRelatedPosts(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $post_id = (int) $request->get_param('id');

            if (!function_exists('pll_get_post_language')) {
                return new \WP_REST_Response(['error' => 'Polylang plugin not active'], 400);
            }

            $current_lang = pll_get_post_language($post_id);
            if (!$current_lang) {
                return new \WP_REST_Response(['error' => 'Post language not found'], 404);
            }

            $related_posts = $this->getPostsByTags($post_id, $request) ?: 
                           $this->getPostsByCategories($post_id, $request);

            if (empty($related_posts)) {
                return new \WP_REST_Response([], 200);
            }

            return new \WP_REST_Response($this->mapToRestResponse($related_posts), 200);

        } catch (\Exception $e) {
            $this->logger->logError("Related Posts Error", [
                'post_id' => $post_id ?? null,
                'error' => $e->getMessage()
            ]);
            return new \WP_REST_Response(['error' => 'Internal server error'], 500);
        }
    }

    private function getPostsByTags(int $post_id, \WP_REST_Request $request): array
    {
        $tags_query = new \WP_Term_Query([
            'taxonomy' => self::TAXONOMY_TAG,
            'object_ids' => $post_id
        ]);

        if (empty($tags_query->terms)) {
            return [];
        }

        return $this->getPostsQuery(
            $post_id,
            [
                [
                    'taxonomy' => self::TAXONOMY_TAG,
                    'field' => 'term_id',
                    'terms' => wp_list_pluck($tags_query->terms, 'term_id'),
                    'operator' => 'IN'
                ]
            ],
            $request
        );
    }

    private function getPostsByCategories(int $post_id, \WP_REST_Request $request): array
    {
        $categories = get_the_category($post_id);
        if (empty($categories)) {
            return [];
        }

        return $this->getPostsQuery(
            $post_id,
            [
                [
                    'taxonomy' => self::TAXONOMY_CATEGORY,
                    'field' => 'term_id',
                    'terms' => wp_list_pluck($categories, 'term_id'),
                    'operator' => 'IN'
                ]
            ],
            $request
        );
    }

    private function getPostsQuery(int $post_id, array $tax_query, \WP_REST_Request $request): array
    {
        $limit = (int) $request->get_param('limit');
        $orderby = $request->get_param('orderby');

        return get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => self::POST_STATUS,
            'posts_per_page' => $limit,
            'post__not_in' => [$post_id],
            'tax_query' => $tax_query,
            'orderby' => $orderby,
            'order' => 'DESC',
            'suppress_filters' => false
        ]);
    }

    private function getFeaturedImageUrls(int $post_id): ?array
    {
        try {
            if (!has_post_thumbnail($post_id)) {
                return null;
            }

            $thumbnail_id = get_post_thumbnail_id($post_id);
            $image_sizes = get_intermediate_image_sizes();

            $image_urls = array_reduce($image_sizes, function ($acc, $size) use ($thumbnail_id) {
                $image_data = wp_get_attachment_image_src($thumbnail_id, $size);
                $acc[$size] = $image_data ? $image_data[0] : null;
                return $acc;
            }, []);

            $full_image = wp_get_attachment_image_src($thumbnail_id, 'full');
            $image_urls['full'] = $full_image ? $full_image[0] : null;

            return $image_urls;
        } catch (\Exception $e) {
            $this->logger->logError("Error getting featured image", [
                'post_id' => $post_id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function getCategoriesData(int $post_id): array
    {
        try {
            $categories = wp_get_post_categories($post_id, ['fields' => 'all']);

            return array_map(function ($category) {
                return [
                    'id' => $category->term_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'count' => $category->count,
                    'parent' => $category->parent,
                    'link' => wp_make_link_relative(get_category_link($category->term_id))
                ];
            }, $categories);
        } catch (\Exception $e) {
            $this->logger->logError("Error getting categories data", [
                'post_id' => $post_id,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    private function mapToRestResponse(array $posts): array
    {
        return array_map(function ($post) {
            try {
                $featured_image_id = get_post_thumbnail_id($post->ID);
                $post_date = get_post_datetime($post);

                return [
                    'id' => $post->ID,
                    'title' => [
                        'rendered' => get_the_title($post)
                    ],
                    'slug' => $post->post_name,
                    'link' => wp_make_link_relative(get_permalink($post)),
                    'date' => $post_date ? $post_date->format('c') : null,
                    'featured_media' => $featured_image_id,
                    'featured_image_urls' => $this->getFeaturedImageUrls($post->ID),
                    'featured_image_alt' => get_post_meta($featured_image_id, '_wp_attachment_image_alt', true),
                    'excerpt' => [
                        'rendered' => apply_filters('the_excerpt', get_the_excerpt($post))
                    ],
                    'categories_data' => $this->getCategoriesData($post->ID)
                ];
            } catch (\Exception $e) {
                $this->logger->logError("Error mapping post to REST response", [
                    'post_id' => $post->ID,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        }, $posts);
    }
}