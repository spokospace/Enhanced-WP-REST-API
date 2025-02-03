<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Services;

class TranslationCache
{
    // Cache group name
    private const CACHE_GROUP = 'spoko_rest_api';

    // Cache lifetime (1 hour)
    private const CACHE_EXPIRE = HOUR_IN_SECONDS;

    // Cache key prefix
    private const CACHE_KEY_PREFIX = 'translation_';

    public function __construct(
        private ErrorLogger $logger
    ) {}

    public function get(string $type, int $id, callable $callback): ?array
    {
        $key = $this->generateCacheKey($type, $id);
        $data = wp_cache_get($key, self::CACHE_GROUP);

        if ($data === false) {
            try {
                $data = $callback();
                if ($data !== null) {
                    wp_cache_set($key, $data, self::CACHE_GROUP, self::CACHE_EXPIRE);
                }
            } catch (\Exception $e) {
                $this->logger->logError('Cache callback error', [
                    'type' => $type,
                    'id' => $id,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        }

        return $data;
    }

    public function clear(): void
    {
        if (function_exists('wp_cache_delete_group')) {
            // Use wp_cache_delete_group if available (Redis Object Cache Pro)
            wp_cache_delete_group(self::CACHE_GROUP);
        } else {
            // Fallback for older versions - mark group as invalid
            wp_cache_set('purge_' . self::CACHE_GROUP, microtime(true), self::CACHE_GROUP);
        }

        $this->logger->logError('Cache cleared', [
            'group' => self::CACHE_GROUP,
            'time' => current_time('mysql')
        ]);
    }

    public function getStats(): array
    {
        $stats = [
            'group' => self::CACHE_GROUP,
            'expire_time' => self::CACHE_EXPIRE . 's',
            'using_redis' => false,
            'last_cleared' => get_option('spoko_rest_cache_last_clear')
        ];

        if (function_exists('wp_redis') && $redis = wp_redis()) {
            try {
                $info = $redis->info();
                $stats['using_redis'] = true;
                $stats['redis_version'] = $info['redis_version'] ?? 'unknown';
                $stats['redis_memory_used'] = $info['used_memory_human'] ?? 'unknown';
                $stats['redis_hits'] = $info['keyspace_hits'] ?? 0;
                $stats['redis_misses'] = $info['keyspace_misses'] ?? 0;
            } catch (\Exception $e) {
                $this->logger->logError('Redis stats error', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $stats;
    }

    private function generateCacheKey(string $type, int $id): string
    {
        return self::CACHE_KEY_PREFIX . "_{$type}_{$id}";
    }

    public function sanitizeData(?array $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }

        return array_filter($data, function ($langData) {
            return !empty($langData) && is_array($langData) &&
                !empty($langData['id']) && !empty($langData['link']);
        });
    }
}