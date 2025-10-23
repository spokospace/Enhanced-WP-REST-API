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

    public function registerRestFields(): void
    {
        foreach ($this->features as $feature) {
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
            if (method_exists($feature, 'register')) {
                $feature->register();
            }
        }
    }
}