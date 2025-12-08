<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST API endpoints for WordPress navigation menus
 * Supports multilingual menus with configurable menu-per-language mapping
 *
 * Endpoints:
 * - GET /wp-json/menus/v1/navbar/{lang} - Get navbar menu for specific language (pl, en)
 * - GET /wp-json/menus/v1/menus - List all menus (for admin selection)
 * - GET /wp-json/menus/v1/menus/{slug} - Get any menu by slug
 */
class MenusEndpoint
{
    private const REST_NAMESPACE = 'menus/v1';
    private const OPTION_ENABLED = 'spoko_rest_menus_enabled';
    private const OPTION_MENU_PL = 'spoko_rest_menus_navbar_pl';
    private const OPTION_MENU_EN = 'spoko_rest_menus_navbar_en';

    public function registerRestRoutes(): void
    {
        if (!get_option(self::OPTION_ENABLED, true)) {
            return;
        }

        // GET /navbar/{lang} - Get navbar menu for specific language
        register_rest_route(self::REST_NAMESPACE, '/navbar/(?P<lang>pl|en)', [
            'methods' => 'GET',
            'callback' => [$this, 'getNavbarMenu'],
            'permission_callback' => '__return_true',
            'args' => [
                'lang' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['pl', 'en'],
                    'description' => 'Language code (pl or en)'
                ]
            ]
        ]);

        // GET /menus - List all menus (useful for admin dropdown)
        register_rest_route(self::REST_NAMESPACE, '/menus', [
            'methods' => 'GET',
            'callback' => [$this, 'getAllMenus'],
            'permission_callback' => '__return_true'
        ]);

        // GET /menus/{slug} - Get specific menu by slug or ID
        register_rest_route(self::REST_NAMESPACE, '/menus/(?P<slug>[a-zA-Z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getMenu'],
            'permission_callback' => '__return_true',
            'args' => [
                'slug' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Menu slug or ID'
                ]
            ]
        ]);
    }

    /**
     * Get navbar menu for specific language based on admin configuration
     */
    public function getNavbarMenu(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $lang = $request->get_param('lang');

        // Get configured menu slug for this language
        $menu_slug = match ($lang) {
            'pl' => get_option(self::OPTION_MENU_PL, ''),
            'en' => get_option(self::OPTION_MENU_EN, ''),
            default => ''
        };

        if (empty($menu_slug)) {
            return new WP_Error(
                'menu_not_configured',
                sprintf('Navbar menu for language "%s" is not configured', $lang),
                ['status' => 404]
            );
        }

        $menu = wp_get_nav_menu_object($menu_slug);

        if (!$menu) {
            return new WP_Error(
                'menu_not_found',
                sprintf('Configured menu "%s" for language "%s" not found', $menu_slug, $lang),
                ['status' => 404]
            );
        }

        return new WP_REST_Response($this->formatMenuResponse($menu, $lang), 200);
    }

    /**
     * Get all registered navigation menus
     */
    public function getAllMenus(WP_REST_Request $request): WP_REST_Response
    {
        $menus = wp_get_nav_menus();

        $response = array_map(fn($menu) => [
            'term_id' => $menu->term_id,
            'name' => $menu->name,
            'slug' => $menu->slug,
            'description' => $menu->description,
            'count' => $menu->count
        ], $menus);

        return new WP_REST_Response($response, 200);
    }

    /**
     * Get specific menu by slug or ID with all items
     */
    public function getMenu(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $slug = $request->get_param('slug');
        $menu = wp_get_nav_menu_object($slug);

        if (!$menu) {
            return new WP_Error(
                'menu_not_found',
                sprintf('Menu "%s" not found', $slug),
                ['status' => 404]
            );
        }

        return new WP_REST_Response($this->formatMenuResponse($menu), 200);
    }

    /**
     * Format menu response with hierarchical items
     */
    private function formatMenuResponse(object $menu, ?string $lang = null): array
    {
        $menu_items = wp_get_nav_menu_items($menu->term_id);

        $response = [
            'term_id' => $menu->term_id,
            'name' => $menu->name,
            'slug' => $menu->slug,
            'description' => $menu->description,
            'count' => $menu->count,
            'items' => $menu_items ? $this->buildHierarchy($menu_items) : []
        ];

        if ($lang) {
            $response['lang'] = $lang;
        }

        return $response;
    }

    /**
     * Build hierarchical menu structure with child_items
     */
    private function buildHierarchy(array $items): array
    {
        $items_by_id = [];
        $root_items = [];

        // First pass: index all items and format them
        foreach ($items as $item) {
            $formatted = $this->formatMenuItem($item);
            $formatted['child_items'] = [];
            $items_by_id[$item->ID] = $formatted;
        }

        // Second pass: build hierarchy
        foreach ($items as $item) {
            $parent_id = (int) $item->menu_item_parent;

            if ($parent_id === 0) {
                $root_items[] = &$items_by_id[$item->ID];
            } elseif (isset($items_by_id[$parent_id])) {
                $items_by_id[$parent_id]['child_items'][] = &$items_by_id[$item->ID];
            } else {
                // Orphaned item - add to root
                $root_items[] = &$items_by_id[$item->ID];
            }
        }

        return $root_items;
    }

    /**
     * Format single menu item
     */
    private function formatMenuItem(object $item): array
    {
        $formatted = [
            'id' => $item->ID,
            'title' => $item->title,
            'url' => $item->url,
            'relative_url' => wp_make_link_relative($item->url),
            'target' => $item->target ?: '_self',
            'attr_title' => $item->attr_title,
            'description' => $item->description,
            'classes' => array_filter($item->classes),
            'xfn' => $item->xfn,
            'menu_order' => $item->menu_order,
            'object_type' => $item->type,
            'object' => $item->object,
            'object_id' => (int) $item->object_id
        ];

        // Add slug for posts/pages/taxonomies
        if (in_array($item->type, ['post_type', 'taxonomy'], true)) {
            $formatted['slug'] = $this->getObjectSlug($item);
        }

        // Add ACF fields if available
        if (function_exists('get_fields')) {
            $acf_fields = get_fields($item->ID);
            if ($acf_fields) {
                $formatted['acf'] = $acf_fields;
            }
        }

        return $formatted;
    }

    /**
     * Get slug for linked object (post, page, term)
     */
    private function getObjectSlug(object $item): ?string
    {
        if ($item->type === 'post_type') {
            $post = get_post($item->object_id);
            return $post ? $post->post_name : null;
        }

        if ($item->type === 'taxonomy') {
            $term = get_term($item->object_id, $item->object);
            return ($term && !is_wp_error($term)) ? $term->slug : null;
        }

        return null;
    }

    /**
     * Get available menus for admin dropdown
     */
    public static function getMenusForSelect(): array
    {
        $menus = wp_get_nav_menus();
        $options = ['' => '— Select menu —'];

        foreach ($menus as $menu) {
            $options[$menu->slug] = $menu->name;
        }

        return $options;
    }

    /**
     * Get configured menu slug for language
     */
    public static function getConfiguredMenu(string $lang): string
    {
        return match ($lang) {
            'pl' => get_option(self::OPTION_MENU_PL, ''),
            'en' => get_option(self::OPTION_MENU_EN, ''),
            default => ''
        };
    }
}
