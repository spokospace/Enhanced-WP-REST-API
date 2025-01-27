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
        if (!function_exists('pll_get_term')) {
            return;
        }

        foreach (self::TAXONOMIES as $taxonomy) {
            register_rest_field($taxonomy, 'translations_data', [
                'get_callback' => [$this, 'getTranslationsData'],
                'schema' => ['description' => 'Translation details', 'type' => 'object']
            ]);
        }
    }

    public function getTranslationsData(array $term): ?array
    {
        return $this->cache->get('term', $term['id'], function() use ($term) {
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
                            $translations[$lang] = [
                                'id' => $translationId,
                                'name' => $translatedTerm->name,
                                'slug' => $translatedTerm->slug,
                                'link' => wp_make_link_relative(get_term_link($translationId))
                            ];
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
}