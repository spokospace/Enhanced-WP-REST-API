<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Services;

class TranslationCache
{
    private array $cache = [];

    public function get(string $type, int $id, callable $callback): ?array
    {
        $key = "{$type}_{$id}";

        if (!isset($this->cache[$key])) {
            $this->cache[$key] = $callback();
        }

        return $this->cache[$key];
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