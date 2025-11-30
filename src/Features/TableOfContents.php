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
        // FIXED: Remove hooks from constructor - causes timing issues
        // REMOVED: add_action('rest_api_init', [$this, 'registerRestRoutes']);
        // REMOVED: add_action('init', [$this, 'initHooks']);
    }

    /**
     * Register REST API fields and filters (called from rest_api_init)
     */
    public function registerRestFields(): void
    {
        // Check if feature is enabled
        if (!get_option('spoko_rest_toc_enabled', true)) {
            return;
        }

        // Add content filter for frontend display
        add_filter('the_content', [$this, 'addHeaderAnchors']);

        // Add filter for REST API responses to ensure headings have IDs
        add_filter('rest_prepare_post', [$this, 'addHeaderAnchorsToRestAPI'], 20, 3);
        add_filter('rest_prepare_page', [$this, 'addHeaderAnchorsToRestAPI'], 20, 3);

        // Clear any REST API cache when posts are updated
        add_action('save_post', [$this, 'clearRestCache']);
        add_action('post_updated', [$this, 'clearRestCache']);
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

    // REMOVED: initHooks() method - no longer needed

    public function getTableOfContents(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $post_id = (int) $request->get_param('id');
            $post = get_post($post_id);

            if (!$post) {
                return new \WP_REST_Response(['error' => 'Post not found'], 404);
            }

            // Get the processed content with anchors already added
            $content = apply_filters('the_content', $post->post_content);
            $toc = $this->extractHeadingsFromProcessedContent($content);

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
        $usedIds = [];
        $idCounters = [];
        $headingHierarchy = []; // Track heading hierarchy for contextual IDs

        // Find all headers and process them sequentially
        preg_match_all('/<h([1-6])([^>]*?)>(.*?)<\/h\1>/i', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        // Process matches in reverse order to avoid position shifts when replacing
        $matches = array_reverse($matches);
        
        // First pass: collect all headings to understand structure
        $allHeadings = [];
        foreach (array_reverse($matches) as $match) {
            $level = (int)$match[1][0];
            $title = wp_strip_all_tags($match[3][0]);
            $baseId = $this->slugify($title);
            
            $allHeadings[] = [
                'level' => $level,
                'title' => $title,
                'baseId' => $baseId,
                'match' => $match
            ];
        }
        
        // Build hierarchy and track duplicates
        $titleCounts = [];
        $hierarchicalContext = [];
        
        foreach ($allHeadings as $i => $heading) {
            $baseId = $heading['baseId'];
            $level = $heading['level'];
            
            // Count occurrences of each title
            if (!isset($titleCounts[$baseId])) {
                $titleCounts[$baseId] = 0;
            }
            $titleCounts[$baseId]++;
            
            // Build hierarchical context - find closest parent heading
            $parentContext = '';
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($allHeadings[$j]['level'] < $level) {
                    $parentContext = $allHeadings[$j]['baseId'];
                    break;
                }
            }
            
            $hierarchicalContext[$i] = $parentContext;
        }

        // Second pass: generate IDs and replace content
        foreach ($matches as $matchIndex => $match) {
            $headingIndex = count($matches) - 1 - $matchIndex;
            $fullMatch = $match[0][0];
            $offset = $match[0][1];
            $level = $match[1][0];
            $attrs = $match[2][0];
            $title = wp_strip_all_tags($match[3][0]);
            $baseId = $this->slugify($title);
            
            // Check if header already has an ID attribute
            if (preg_match('/id\s*=\s*["\']([^"\']*)["\']/', $attrs, $idMatch)) {
                $currentId = $idMatch[1];
                
                // Check if this ID has been used before
                if (isset($usedIds[$currentId])) {
                    // Generate a unique version
                    if (!isset($idCounters[$currentId])) {
                        $idCounters[$currentId] = 1;
                    }
                    $idCounters[$currentId]++;
                    
                    $newId = $currentId . '-' . $idCounters[$currentId];
                    
                    // Make sure this new ID is also unique
                    while (isset($usedIds[$newId])) {
                        $idCounters[$currentId]++;
                        $newId = $currentId . '-' . $idCounters[$currentId];
                    }
                    
                    $usedIds[$newId] = true;
                    
                    // Replace the ID in the header
                    $newAttrs = str_replace($idMatch[0], 'id="' . $newId . '"', $attrs);
                    $newHeader = sprintf('<h%s%s>%s</h%s>', $level, $newAttrs, $match[3][0], $level);
                    
                    // Replace in content using substr_replace for exact position
                    $content = substr_replace($content, $newHeader, $offset, strlen($fullMatch));
                } else {
                    // First occurrence of this ID, mark it as used
                    $usedIds[$currentId] = true;
                }
                continue;
            }
            
            // Header doesn't have ID - generate one with hierarchical context if needed
            $finalId = $baseId;
            
            // If this title appears multiple times, use hierarchical naming
            if ($titleCounts[$baseId] > 1) {
                $parentContext = $hierarchicalContext[$headingIndex];
                if (!empty($parentContext)) {
                    // Create flattened hierarchical ID: parent-context-title
                    $finalId = $parentContext . '-' . $baseId;
                }
            }
            
            // Ensure uniqueness (fallback to numbered system if hierarchical ID is still duplicate)
            $counter = 1;
            $originalId = $finalId;
            while (isset($usedIds[$finalId])) {
                $counter++;
                $finalId = $originalId . '-' . $counter;
            }
            
            $usedIds[$finalId] = true;
            
            // Handle malformed HTML - if attrs doesn't start with space, add one
            $spaceBefore = (empty($attrs) || substr($attrs, 0, 1) === ' ') ? '' : ' ';
            
            // Add ID attribute to existing attributes
            $newAttrs = $attrs . $spaceBefore . ' id="' . $finalId . '"';
            
            // Create new header with ID attribute
            $newHeader = sprintf('<h%s%s>%s</h%s>', $level, $newAttrs, $match[3][0], $level);
            
            // Replace in content using substr_replace for exact position
            $content = substr_replace($content, $newHeader, $offset, strlen($fullMatch));
        }

        return $content;
    }

    /**
     * Add header anchors to REST API responses
     * This ensures the content.rendered field has proper heading IDs
     */
    public function addHeaderAnchorsToRestAPI(\WP_REST_Response $response, \WP_Post $post, \WP_REST_Request $request): \WP_REST_Response
    {
        $data = $response->get_data();
        
        // Process the rendered content to add header anchors
        if (isset($data['content']['rendered'])) {
            $original_content = $data['content']['rendered'];
            
            // Add header anchors directly to the already rendered content
            $content_with_anchors = $this->addHeaderAnchors($original_content);
            
            // Only update if content actually changed
            if ($content_with_anchors !== $original_content) {
                $data['content']['rendered'] = $content_with_anchors;
                $response->set_data($data);
            }
        }
        
        return $response;
    }

    /**
     * Clear REST API cache when posts are updated
     */
    public function clearRestCache($post_id): void
    {
        if (wp_is_post_revision($post_id)) {
            return;
        }
        
        // Clear any object cache related to REST API
        wp_cache_delete("rest_post_{$post_id}", 'posts');
        wp_cache_delete("rest_prepare_post_{$post_id}", 'posts');
        
        // Also clear any transients that might be caching REST responses
        delete_transient("rest_api_post_{$post_id}");
        
        // Clear WordPress object cache
        wp_cache_flush();
    }
    
    /**
     * Method to manually clear all caches - useful for debugging
     */
    public function clearAllCaches(): void
    {
        wp_cache_flush();
        
        // Clear any REST API related transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rest_api_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_rest_api_%'");
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
            $title_html = $match[3]; // Preserve HTML formatting
            $anchor = $this->generateAnchorId($title);

            $item = [
                'title' => $title,
                'title_html' => $title_html,
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

    /**
     * Extract headings from content that already has ID attributes added
     * This ensures the TOC uses the exact same IDs as in the processed content
     */
    private function extractHeadingsFromProcessedContent(string $content): array
    {
        $currentLevel = 0;
        $structure = [];
        $stack = [&$structure];

        // Find all headers (h1-h6) - handles both properly formatted and malformed HTML
        // This regex handles cases like <h2class="..." id="..."> and <h2 class="..." id="...">
        preg_match_all('/<h([1-6])(?:[^>]*?)id\s*=\s*["\']([^"\']+)["\'][^>]*?>(.*?)<\/h\1>/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $level = (int)$match[1];
            $id = $match[2]; // ID will be present due to regex requirement
            $title = wp_strip_all_tags($match[3]);
            $title_html = $match[3]; // Preserve HTML formatting

            $item = [
                'title' => $title,
                'title_html' => $title_html,
                'anchor' => $id,
                'level' => $level,
                'children' => []
            ];

            // Adjust the stack based on heading levels
            while ($level <= $currentLevel && count($stack) > 1) {
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
        $baseAnchor = $this->slugify($text);
        
        // Handle collisions - increment counter for each occurrence
        if (isset($this->collisionCollector[$baseAnchor])) {
            // This is a duplicate, increment the counter
            $this->collisionCollector[$baseAnchor]++;
            $anchor = $baseAnchor . '-' . $this->collisionCollector[$baseAnchor];
        } else {
            // First occurrence - initialize counter and use base anchor
            $this->collisionCollector[$baseAnchor] = 1;
            $anchor = $baseAnchor;
        }

        return $anchor;
    }
    

    private function slugify(string $text): string
    {
        // Handle Polish characters manually if transliterator is not available
        $polishChars = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N',
            'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z'
        ];
        
        // Replace Polish characters first
        $text = str_replace(array_keys($polishChars), array_values($polishChars), $text);
        
        // Try to use transliterator if available, otherwise use manual replacements
        if (class_exists('Transliterator')) {
            $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        }
        
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