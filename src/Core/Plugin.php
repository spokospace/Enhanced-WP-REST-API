<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Core;

use Spoko\EnhancedRestAPI\Features\{
    TermOrder,
    PostFields,
    TaxonomyFields,
    PolylangSupport
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
        $this->cache = new TranslationCache();
        $this->initFeatures();
        $this->initHooks();
    }

    private function initFeatures(): void
    {
        $this->features = [
            new TermOrder($this->logger),
            new PostFields($this->logger, $this->cache),
            new TaxonomyFields($this->logger, $this->cache),
            new PolylangSupport($this->logger)
        ];
    }

    private function initHooks(): void
    {
        add_action('rest_api_init', [$this, 'registerRestFields']);
    }

    public function registerRestFields(): void
    {
        foreach ($this->features as $feature) {
            $feature->register();
        }
    }
}