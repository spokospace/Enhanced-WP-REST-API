<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use Spoko\EnhancedRestAPI\Services\ErrorLogger;

class PolylangSupport
{
    private const SUPPORTED_TYPES = ['post', 'category', 'post_tag'];

    public function __construct(
        private ErrorLogger $logger
    ) {}

    public function register(): void
    {
        if (!function_exists('pll_languages_list')) {
            return;
        }

        $this->registerLanguageField();
        $this->addLanguageFilters();
    }

    private function registerLanguageField(): void
    {
        register_rest_field(
            self::SUPPORTED_TYPES,
            'available_languages',
            [
                'get_callback' => fn() => pll_languages_list(),
                'schema' => ['description' => 'Available languages', 'type' => 'array']
            ]
        );
    }

    private function addLanguageFilters(): void
    {
        add_filter('rest_post_query', [$this, 'filterByLanguage'], 10, 2);
        add_filter('rest_category_query', [$this, 'filterByLanguage'], 10, 2);
        add_filter('rest_post_tag_query', [$this, 'filterByLanguage'], 10, 2);
    }

    public function filterByLanguage(array $args, $request): array
    {
        if (function_exists('pll_current_language') && !isset($request['lang'])) {
            $args['lang'] = pll_current_language();
        }
        return $args;
    }
}