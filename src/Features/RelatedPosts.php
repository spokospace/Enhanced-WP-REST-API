<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use WP_REST_Request;
use WP_REST_Response;
use WP_Term_Query;

class RelatedPosts
{
    private const POSTS_LIMIT = 8;
    private const REST_NAMESPACE = 'spoko/v1';
    private const REST_ROUTE = '/posts/(?P<id>\d+)/related';
    private const TAXONOMY_TAG = 'post_tag';
    private const TAXONOMY_CATEGORY = 'category';
    private const POST_TYPE = 'post';
    private const POST_STATUS = 'publish';

    public function registerRestRoutes(): void
    {
        if (!get_option('spoko_rest_related_posts_enabled', true)) {
            return;
        }

        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods' => 'GET',
            'callback' => [$this, 'getRelatedPosts'],
            'permission_callback' => '__return_true',
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => fn($param) => filter_var($param, FILTER_VALIDATE_INT) !== false
                ],
                'limit' => [
                    'required' => false,
                    'default' => self::POSTS_LIMIT,
                    'validate_callback' => fn($param) => filter_var($param, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 20]]) !== false
                ],
                'orderby' => [
                    'required' => false,
                    'default' => 'date',
                    'enum' => ['date', 'title', 'menu_order', 'rand']
                ]
            ]
        ]);
    }

    public function getRelatedPosts(WP_REST_Request $request): WP_REST_Response
    {
        $post_id = (int) $request->get_param('id');

        if (!function_exists('pll_get_post_language')) {
            return new WP_REST_Response(['error' => 'Polylang plugin not active'], 400);
        }

        if (!pll_get_post_language($post_id)) {
            return new WP_REST_Response(['error' => 'Post language not found'], 404);
        }

        $related_posts = $this->getPostsByTags($post_id, $request)
            ?: $this->getPostsByCategories($post_id, $request);

        return new WP_REST_Response($this->mapToRestResponse($related_posts), 200);
    }

    private function getPostsByTags(int $post_id, WP_REST_Request $request): array
    {
        $tags = new WP_Term_Query([
            'taxonomy' => self::TAXONOMY_TAG,
            'object_ids' => $post_id
        ]);

        return empty($tags->terms) ? [] : $this->getPostsQuery($post_id, [
            [
                'taxonomy' => self::TAXONOMY_TAG,
                'field' => 'term_id',
                'terms' => wp_list_pluck($tags->terms, 'term_id'),
                'operator' => 'IN'
            ]
        ], $request);
    }

    private function getPostsByCategories(int $post_id, WP_REST_Request $request): array
    {
        $categories = get_the_category($post_id);

        return empty($categories) ? [] : $this->getPostsQuery($post_id, [
            [
                'taxonomy' => self::TAXONOMY_CATEGORY,
                'field' => 'term_id',
                'terms' => wp_list_pluck($categories, 'term_id'),
                'operator' => 'IN'
            ]
        ], $request);
    }

    private function getPostsQuery(int $post_id, array $tax_query, WP_REST_Request $request): array
    {
        return get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => self::POST_STATUS,
            'posts_per_page' => (int) $request->get_param('limit'),
            'post__not_in' => [$post_id],
            'tax_query' => $tax_query,
            'orderby' => $request->get_param('orderby'),
            'order' => 'DESC',
            'suppress_filters' => false
        ]);
    }

    private function getFeaturedImageUrls(int $post_id): ?array
    {
        if (!has_post_thumbnail($post_id)) {
            return null;
        }

        $thumbnail_id = get_post_thumbnail_id($post_id);
        $image_sizes = get_intermediate_image_sizes();
        $image_urls = [];

        foreach ($image_sizes as $size) {
            $image_data = wp_get_attachment_image_src($thumbnail_id, $size);
            if ($image_data) {
                $image_urls[$size] = $image_data[0];
            }
        }

        $full_image = wp_get_attachment_image_src($thumbnail_id, 'full');
        if ($full_image) {
            $image_urls['full'] = $full_image[0];
        }

        return $image_urls;
    }

    private function getCategoriesData(int $post_id): array
    {
        $categories = wp_get_post_categories($post_id, ['fields' => 'all']);

        return array_map(fn($category) => [
            'id' => $category->term_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'count' => $category->count,
            'parent' => $category->parent,
            'link' => wp_make_link_relative(get_category_link($category->term_id))
        ], $categories);
    }

    private function mapToRestResponse(array $posts): array
    {
        return array_map(function ($post) {
            $featured_image_id = get_post_thumbnail_id($post->ID);
            return [
                'id' => $post->ID,
                'title' => ['rendered' => get_the_title($post)],
                'slug' => $post->post_name,
                'link' => wp_make_link_relative(get_permalink($post)),
                'date' => ($date = get_post_datetime($post)) ? $date->format('c') : null,
                'featured_media' => $featured_image_id,
                'featured_image_urls' => $this->getFeaturedImageUrls($post->ID),
                'featured_image_alt' => get_post_meta($featured_image_id, '_wp_attachment_image_alt', true),
                'excerpt' => ['rendered' => apply_filters('the_excerpt', get_the_excerpt($post))],
                'categories_data' => $this->getCategoriesData($post->ID)
            ];
        }, $posts);
    }
}
