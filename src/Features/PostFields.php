<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use Spoko\EnhancedRestAPI\Services\{ErrorLogger, TranslationCache};

class PostFields
{
    public function __construct(
        private ErrorLogger $logger,
        private TranslationCache $cache
    ) {}

    /**
     * Register REST API fields (called from rest_api_init)
     */
    public function registerRestFields(): void
    {
        $postTypes = get_post_types(['public' => true]);

        foreach ($postTypes as $postType) {
            $this->registerCommonFields($postType);
            if ($postType === 'post') {
                $this->registerPostSpecificFields();
            }
        }

        add_filter('rest_prepare_post', [$this, 'modifyPostLink'], 10, 3);
    }

    private function registerCommonFields(string $postType): void
    {
        register_rest_field($postType, 'author_data', [
            'get_callback' => [$this, 'getAuthorData'],
            'schema' => ['description' => 'Author details', 'type' => 'object']
        ]);

        register_rest_field($postType, 'featured_image_urls', [
            'get_callback' => [$this, 'getFeaturedImageUrls'],
            'schema' => ['description' => 'Featured image URLs', 'type' => 'object']
        ]);

        register_rest_field($postType, 'relative_link', [
            'get_callback' => [$this, 'getRelativeLink'],
            'schema' => ['description' => 'Relative URL', 'type' => 'string']
        ]);

        register_rest_field($postType, 'read_time', [
            'get_callback' => [$this, 'getReadTime'],
            'schema' => ['description' => 'Estimated reading time', 'type' => 'string']
        ]);

        if (function_exists('pll_get_post')) {
            register_rest_field($postType, 'translations_data', [
                'get_callback' => [$this, 'getTranslationsData'],
                'schema' => ['description' => 'Translation details', 'type' => 'object']
            ]);
        }
    }

    private function registerPostSpecificFields(): void
    {
        register_rest_field('post', 'categories_data', [
            'get_callback' => [$this, 'getCategoriesData'],
            'schema' => ['description' => 'Categories data', 'type' => 'array']
        ]);

        register_rest_field('post', 'tags_data', [
            'get_callback' => [$this, 'getTagsData'],
            'schema' => ['description' => 'Tags data', 'type' => 'array']
        ]);
    }

    public function getAuthorData(array $post): ?array
    {
        try {
            $author_id = $post['author'];
            $author = get_userdata($author_id);

            if (!$author) {
                return null;
            }

            return [
                'id' => $author_id,
                'name' => $author->display_name,
                'nicename' => $author->user_nicename,
                'slug' => $author->user_nicename,
                'avatar' => get_avatar_url($author_id),
                'description' => $author->description,
                'url' => wp_make_link_relative($author->user_url)
            ];
        } catch (\Exception $e) {
            $this->logger->logError('Error getting author data', ['post_id' => $post['id'], 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function getFeaturedImageUrls(array $post): ?array
    {
        try {
            if (!has_post_thumbnail($post['id'])) {
                return null;
            }

            $thumbnailId = get_post_thumbnail_id($post['id']);
            $imageSizes = get_intermediate_image_sizes();
            $imageUrls = [];

            foreach ($imageSizes as $size) {
                $imageData = wp_get_attachment_image_src($thumbnailId, $size);
                $imageUrls[$size] = $imageData ? $imageData[0] : null;
            }

            $fullImage = wp_get_attachment_image_src($thumbnailId, 'full');
            $imageUrls['full'] = $fullImage ? $fullImage[0] : null;

            return $imageUrls;
        } catch (\Exception $e) {
            $this->logger->logError('Error getting featured image URLs', ['post_id' => $post['id'], 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function getCategoriesData(array $post): array
    {
        try {
            $categories = wp_get_post_categories($post['id'], ['fields' => 'all']);

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
            $this->logger->logError('Error getting categories data', ['post_id' => $post['id'], 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function getTagsData(array $post): array
    {
        try {
            $tags = wp_get_post_tags($post['id'], ['fields' => 'all']);

            return array_map(function ($tag) {
                return [
                    'id' => $tag->term_id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'description' => $tag->description,
                    'count' => $tag->count,
                    'link' => wp_make_link_relative(get_tag_link($tag->term_id))
                ];
            }, $tags);
        } catch (\Exception $e) {
            $this->logger->logError('Error getting tags data', ['post_id' => $post['id'], 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function getTranslationsData(array $post): ?array
    {
        return $this->cache->get('post', $post['id'], function() use ($post) {
            try {
                if (!function_exists('pll_get_post')) {
                    return null;
                }

                $translations = [];
                $languages = pll_languages_list();

                foreach ($languages as $lang) {
                    $translationId = pll_get_post($post['id'], $lang);
                    if ($translationId) {
                        $translatedPost = get_post($translationId);
                        if ($translatedPost) {
                            $translations[$lang] = [
                                'id' => $translationId,
                                'title' => $translatedPost->post_title,
                                'slug' => $translatedPost->post_name,
                                'link' => wp_make_link_relative(get_permalink($translationId)),
                                'excerpt' => $translatedPost->post_excerpt,
                                'status' => $translatedPost->post_status,
                                'featured_image' => get_post_thumbnail_id($translationId)
                            ];
                        }
                    }
                }

                return $this->cache->sanitizeData($translations);
            } catch (\Exception $e) {
                $this->logger->logError('Error getting post translations', ['post_id' => $post['id'], 'error' => $e->getMessage()]);
                return null;
            }
        });
    }

    public function getRelativeLink(array $post): string
    {
        try {
            return wp_make_link_relative(get_permalink($post['id']));
        } catch (\Exception $e) {
            $this->logger->logError('Error getting relative link', ['post_id' => $post['id'], 'error' => $e->getMessage()]);
            return '';
        }
    }

    public function getReadTime(array $post): string
    {
        try {
            $postObject = get_post($post['id']);
            if (!$postObject) {
                return '0 min read';
            }

            $content = $postObject->post_content;
            $wordCount = count(explode(' ', strip_tags($content))) + 1;
            $readingTime = $wordCount / 200; // Average reading speed: 200 words per minute

            if (is_float($readingTime)) {
                $readingTime = ceil($readingTime);
            }

            return $readingTime . ' min read';
        } catch (\Exception $e) {
            $this->logger->logError('Error calculating read time', ['post_id' => $post['id'], 'error' => $e->getMessage()]);
            return '0 min read';
        }
    }

    public function modifyPostLink(\WP_REST_Response $response, \WP_Post $post): \WP_REST_Response
    {
        try {
            $response->data['link'] = wp_make_link_relative(get_permalink($post->ID));
            return $response;
        } catch (\Exception $e) {
            $this->logger->logError('Error modifying post link', ['post_id' => $post->ID, 'error' => $e->getMessage()]);
            return $response;
        }
    }
}