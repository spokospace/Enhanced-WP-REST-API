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
    HeadlessMode
};
use Spoko\EnhancedRestAPI\Services\{
    TranslationCache,
    ErrorLogger
};

final class Plugin extends Singleton
{
    private ErrorLogger $logger;
    private TranslationCache $cache;
    private array $features;

    protected function __construct()
    {
        $this->logger = new ErrorLogger();
        $this->cache = new TranslationCache($this->logger);
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
            new PolylangSupport($this->logger),
            new PageExcerpt($this->logger),
            new TableOfContents($this->logger),
            new RelatedPosts($this->logger),
            new FormattedHeadlines($this->logger),
            new CommentsSupport($this->logger),
            new HeadlessMode($this->logger),
            new AdminInterface($this->cache)
        ];
    }

    private function initHooks(): void
    {
        // NOTE: registerGlobalFeatures() is called directly in __construct()
        // because we can't hook to init:1 when we're already being called from init:10

        // Initialize REST API
        add_action('rest_api_init', [$this, 'registerRestFields']);

        // Initialize admin menu
        add_action('admin_menu', function () {
            foreach ($this->features as $feature) {
                if ($feature instanceof AdminInterface) {
                    $feature->addAdminMenuPage();
                }
            }
        }, 99);

        // Initialize admin features
        add_action('admin_init', [$this, 'registerAdminFeatures']);
    }

    /**
     * Register features that need to work on ALL requests (front-end, admin, REST API)
     * This includes HeadlessMode which needs to intercept front-end requests
     */
    public function registerGlobalFeatures(): void
    {
        foreach ($this->features as $feature) {
            // Only register HeadlessMode and AdminInterface here
            // Other features will be registered via their specific hooks
            if ($feature instanceof HeadlessMode || $feature instanceof AdminInterface) {
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
            if ($feature instanceof HeadlessMode) {
                continue;
            }

            // FIXED: Call registerRestRoutes() for REST routes (like TableOfContents)
            if (method_exists($feature, 'registerRestRoutes')) {
                $feature->registerRestRoutes();
            }

            // Call register() for REST fields and other features
            if (method_exists($feature, 'register')) {
                $feature->register();
            }
        }
    }

    public function registerAdminFeatures(): void
    {
        foreach ($this->features as $feature) {
            // Skip features already registered globally
            if ($feature instanceof HeadlessMode || $feature instanceof AdminInterface) {
                continue;
            }

            if (method_exists($feature, 'register')) {
                $feature->register();
            }
        }
    }
}