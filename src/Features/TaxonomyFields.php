<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use Spoko\EnhancedRestAPI\Services\{ErrorLogger, TranslationCache};

class TaxonomyFields
{
    private const TAXONOMIES = ['category', 'post_tag'];

    public function __construct(
        private ErrorLogger $logger,
        private TranslationCache $cache
    ) {}

    public function register(): void
    {
        foreach (self::TAXONOMIES as $taxonomy) {
            $this->registerTaxonomyFields($taxonomy);
        }

        if (get_option('spoko_rest_relative_urls_enabled', true)) {
            add_filter('term_link', 'wp_make_link_relative');
            add_filter('tag_link', 'wp_make_link_relative');
            add_filter('category_link', 'wp_make_link_relative');
            add_filter('rest_url', 'wp_make_link_relative');
        }
    }

    private function registerTaxonomyFields(string $taxonomy): void
    {
        if (!function_exists('pll_get_term')) {
            return;
        }

        register_rest_field($taxonomy, 'translations_data', [
            'get_callback' => [$this, 'getTranslationsData'],
            'schema' => ['description' => 'Translation details', 'type' => 'object']
        ]);

        register_rest_field($taxonomy, 'term_order', [
            'get_callback' => [$this, 'getTermOrder'],
            'schema' => ['description' => 'Term display order', 'type' => 'integer']
        ]);

        // Add hook to modify the REST response
        add_filter("rest_prepare_{$taxonomy}", [$this, 'ensureTranslationsData'], 10, 3);
    }

    public function getTranslationsData(array $term): ?array
    {
        return $this->cache->get('term', $term['id'], function () use ($term) {
            try {
                if (!function_exists('pll_get_term')) {
                    return null;
                }

                $translations = [];
                $languages = pll_languages_list();

                foreach ($languages as $lang) {
                    $translationId = pll_get_term($term['id'], $lang);
                    if ($translationId) {
                        $translatedTerm = get_term($translationId);
                        if (!is_wp_error($translatedTerm)) {
                            $link = get_term_link($translationId);
                            if (!is_wp_error($link)) {
                                $translations[$lang] = [
                                    'id' => $translationId,
                                    'name' => $translatedTerm->name,
                                    'slug' => $translatedTerm->slug,
                                    'link' => get_option('spoko_rest_relative_urls_enabled', true) ? 
                                        wp_make_link_relative($link) : $link,
                                    'description' => $translatedTerm->description,
                                    'count' => $translatedTerm->count
                                ];
                            }
                        }
                    }
                }

                return $this->cache->sanitizeData($translations);
            } catch (\Exception $e) {
                $this->logger->logError('Error getting term translations', [
                    'term_id' => $term['id'],
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        });
    }

    public function getTermOrder(array $term): int
    {
        try {
            return (int) get_term_meta($term['id'], 'term_order', true) ?: 0;
        } catch (\Exception $e) {
            $this->logger->logError('Error getting term order', [
                'term_id' => $term['id'],
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    public function ensureTranslationsData($response, $term, $request): \WP_REST_Response
    {
        if (!isset($response->data['translations_data'])) {
            $response->data['translations_data'] = $this->getTranslationsData([
                'id' => $term->term_id
            ]);
        }
        return $response;
    }
}