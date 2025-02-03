<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use Spoko\EnhancedRestAPI\Services\ErrorLogger;

class TableOfContents
{
    private const REST_NAMESPACE = 'wp/v2';
    private const REST_ROUTE = '/(?P<type>posts|pages)/(?P<id>\d+)/toc';
    private array $collisionCollector = [];

    public function __construct(
        private ErrorLogger $logger
    ) {
        // Add hook to rest_api_init
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        
        // Add hook to init for Polylang
        add_action('init', [$this, 'initHooks']);
    }

    public function register(): void
    {
        // Check if feature is enabled
        if (!get_option('spoko_rest_toc_enabled', true)) {
            return;
        }
    }

    public function registerRestRoutes(): void 
    {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            [
                'methods' => 'GET',
                'callback' => [$this, 'getTableOfContents'],
                'permission_callback' => '__return_true',
                'args' => [
                    'type' => [
                        'required' => true,
                        'enum' => ['posts', 'pages']
                    ],
                    'id' => [
                        'required' => true,
                        'validate_callback' => fn($param) => is_numeric($param) && $param > 0
                    ]
                ]
            ]
        );
    }

    public function initHooks(): void 
    {
        // Add filter to modify post content
        add_filter('the_content', [$this, 'addHeaderAnchors']);
    }

    public function getTableOfContents(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $post_id = (int) $request->get_param('id');
            $post = get_post($post_id);

            if (!$post) {
                return new \WP_REST_Response(['error' => 'Post not found'], 404);
            }

            $content = apply_filters('the_content', $post->post_content);
            $toc = $this->extractHeadings($content);

            return new \WP_REST_Response([
                'toc' => $toc
            ], 200);

        } catch (\Exception $e) {
            $this->logger->logError("TOC Error", [
                'post_id' => $post_id ?? null,
                'error' => $e->getMessage()
            ]);
            return new \WP_REST_Response(['error' => 'Internal server error'], 500);
        }
    }

    public function addHeaderAnchors(string $content): string
    {
        if (empty($content)) {
            return $content;
        }

        // Reset collision collector
        $this->collisionCollector = [];

        // Find all headers (h1-h6)
        preg_match_all('/<h([1-6])(.*?)>(.*?)<\/h\1>/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $level = $match[1];
            $attrs = $match[2];
            $title = wp_strip_all_tags($match[3]);
            
            // Generate anchor ID
            $anchor = $this->generateAnchorId($title);
            
            // Create new header with ID attribute
            $newHeader = sprintf(
                '<h%s%s id="%s">%s</h%s>',
                $level,
                $attrs,
                $anchor,
                $match[3],
                $level
            );
            
            // Replace old header with new one
            $content = str_replace($match[0], $newHeader, $content);
        }

        return $content;
    }

    private function extractHeadings(string $content): array
    {
        $headings = [];
        $this->collisionCollector = [];

        // Find all headers (h1-h6)
        preg_match_all('/<h([1-6])(.*?)>(.*?)<\/h\1>/i', $content, $matches, PREG_SET_ORDER);

        $currentLevel = 0;
        $structure = [];
        $stack = [&$structure];

        foreach ($matches as $match) {
            $level = (int)$match[1];
            $title = wp_strip_all_tags($match[3]);
            $anchor = $this->generateAnchorId($title);

            $item = [
                'title' => $title,
                'anchor' => $anchor,
                'level' => $level,
                'children' => []
            ];

            while ($level <= $currentLevel) {
                array_pop($stack);
                $currentLevel--;
            }

            $currentParent = &$stack[count($stack) - 1];
            $currentParent[] = $item;
            $stack[] = &$currentParent[count($currentParent) - 1]['children'];
            $currentLevel = $level;
        }

        return $structure;
    }

    private function generateAnchorId(string $text): string
    {
        // Convert to lowercase and transliterate
        $anchor = $this->slugify($text);

        // Handle collisions
        if (isset($this->collisionCollector[$anchor])) {
            $this->collisionCollector[$anchor]++;
            $anchor .= '-' . $this->collisionCollector[$anchor];
        } else {
            $this->collisionCollector[$anchor] = 1;
        }

        return $anchor;
    }

    private function slugify(string $text): string
    {
        // Transliterate non-ASCII characters
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        
        // Convert to lowercase
        $text = strtolower($text);
        
        // Replace spaces and other characters with hyphens
        $text = preg_replace('/[^a-z0-9-]/', '-', $text);
        
        // Remove multiple consecutive hyphens
        $text = preg_replace('/-+/', '-', $text);
        
        // Remove leading and trailing hyphens
        $text = trim($text, '-');
        
        return $text;
    }
}