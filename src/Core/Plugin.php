<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Core;

use Spoko\EnhancedRestAPI\Features\{
    TermOrder,
    PostFields,
    TaxonomyFields,
    PolylangSupport,
    PageExcerpt,
    TableOfContents,
    RelatedPosts,
    FormattedHeadlines,
    AdminInterface,
    CommentsSupport,
    HeadlessMode,
    GA4PopularPosts,
    CategoryFeaturedImage,
    PostCounters,
    MenusEndpoint
};
use Spoko\EnhancedRestAPI\Services\{
    TranslationCache,
    ErrorLogger,
    GA4Client
};

final class Plugin extends Singleton
{
    private ErrorLogger $logger;
    private TranslationCache $cache;
    private GA4Client $ga4Client;
    private array $features;

    protected function __construct()
    {
        $this->logger = new ErrorLogger();
        $this->cache = new TranslationCache($this->logger);
        $this->ga4Client = new GA4Client($this->logger);
        $this->initFeatures();
        $this->initHooks();

        // IMPORTANT: Call registerGlobalFeatures immediately since we're already on the init hook
        // (can't hook to init:1 when we're being called from init:10)
        $this->registerGlobalFeatures();
    }

    private function initFeatures(): void
    {
        $this->features = [
            new TermOrder($this->logger),
            new PostFields($this->logger, $this->cache),
            new TaxonomyFields($this->logger, $this->cache),
            new PolylangSupport(),
            new PageExcerpt(),
            new TableOfContents($this->logger),
            new RelatedPosts(),
            new FormattedHeadlines(),
            new CommentsSupport($this->logger),
            new HeadlessMode($this->logger),
            new GA4PopularPosts($this->logger, $this->ga4Client),
            new AdminInterface($this->cache),
            new CategoryFeaturedImage(),
            new PostCounters($this->logger),
            new MenusEndpoint()
        ];
    }

    private function initHooks(): void
    {
        // NOTE: registerGlobalFeatures() is called directly in __construct()
        // because we can't hook to init:1 when we're already being called from init:10
        // AdminInterface::register() already adds the admin_menu hook

        // Initialize REST API fields and routes
        add_action('rest_api_init', [$this, 'registerRestFields']);

        // Initialize admin features (metaboxes, columns, etc.) - separate from REST
        add_action('admin_init', [$this, 'registerAdminFeatures']);
    }

    /**
     * Register features that need to work on ALL requests (front-end, admin, REST API)
     * This includes HeadlessMode which needs to intercept front-end requests
     */
    public function registerGlobalFeatures(): void
    {
        foreach ($this->features as $feature) {
            // Only register HeadlessMode, AdminInterface and CategoryFeaturedImage here
            // Other features will be registered via their specific hooks
            if ($feature instanceof HeadlessMode || $feature instanceof AdminInterface || $feature instanceof CategoryFeaturedImage) {
                if (method_exists($feature, 'register')) {
                    $feature->register();
                }
            }
        }
    }

    public function registerRestFields(): void
    {
        foreach ($this->features as $feature) {
            // Skip features already registered globally
            if ($feature instanceof HeadlessMode || $feature instanceof AdminInterface || $feature instanceof CategoryFeaturedImage) {
                continue;
            }

            // Call registerRestRoutes() for REST routes (like TableOfContents, RelatedPosts)
            if (method_exists($feature, 'registerRestRoutes')) {
                $feature->registerRestRoutes();
            }

            // Call registerRestFields() for REST fields only
            if (method_exists($feature, 'registerRestFields')) {
                $feature->registerRestFields();
            }

            // Fallback: Call register() for features that don't have separate methods
            // These are REST-only features like TermOrder, TaxonomyFields, PolylangSupport
            if (method_exists($feature, 'register') && !method_exists($feature, 'registerRestFields')) {
                $feature->register();
            }
        }
    }

    public function registerAdminFeatures(): void
    {
        foreach ($this->features as $feature) {
            // Skip features already registered globally
            if ($feature instanceof HeadlessMode || $feature instanceof AdminInterface || $feature instanceof CategoryFeaturedImage) {
                continue;
            }

            // Call registerAdmin() for admin-specific features (metaboxes, columns, etc.)
            if (method_exists($feature, 'registerAdmin')) {
                $feature->registerAdmin();
            }
        }
    }
}