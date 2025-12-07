<?php

declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use Spoko\EnhancedRestAPI\Services\ErrorLogger;

/**
 * Adds recursive post counting for categories.
 *
 * Provides `total_count` field that includes posts from all descendant categories,
 * not just direct posts in the category.
 */
class PostCounters
{
    private const OPTION_ENABLED = 'spoko_rest_post_counters_enabled';

    public function __construct(
        private ErrorLogger $logger
    ) {}

    public function register(): void
    {
        if (!get_option(self::OPTION_ENABLED, '1')) {
            return;
        }

        register_rest_field('category', 'total_count', [
            'get_callback' => [$this, 'getTotalCount'],
            'schema' => [
                'description' => 'Total post count including all descendant categories',
                'type' => 'integer'
            ]
        ]);
    }

    /**
     * Get total post count for a category including all descendants
     */
    public function getTotalCount(array $term): int
    {
        try {
            return $this->getRecursivePostCount((int) $term['id']);
        } catch (\Exception $e) {
            $this->logger->logError('Error calculating total count', [
                'term_id' => $term['id'],
                'error' => $e->getMessage()
            ]);
            return (int) ($term['count'] ?? 0);
        }
    }

    /**
     * Recursively count posts in category and all its children
     */
    private function getRecursivePostCount(int $categoryId): int
    {
        // Get the category term
        $term = get_term($categoryId, 'category');

        if (is_wp_error($term) || !$term) {
            return 0;
        }

        // Start with the category's own post count
        $totalCount = (int) $term->count;

        // Get all direct children
        $children = get_terms([
            'taxonomy' => 'category',
            'parent' => $categoryId,
            'hide_empty' => false,
            'fields' => 'ids'
        ]);

        if (is_wp_error($children) || empty($children)) {
            return $totalCount;
        }

        // Recursively add children's counts
        foreach ($children as $childId) {
            $totalCount += $this->getRecursivePostCount((int) $childId);
        }

        return $totalCount;
    }

    /**
     * Check if feature is enabled
     */
    public static function isEnabled(): bool
    {
        return (bool) get_option(self::OPTION_ENABLED, '1');
    }
}
