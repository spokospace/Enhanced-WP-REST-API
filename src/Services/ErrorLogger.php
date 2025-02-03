<?php

declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Services;

class ErrorLogger
{
    public function logError(string $message, array $context = []): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'SPOKO REST API Error: %s %s',
                $message,
                $context ? 'Context: ' . json_encode($context) : ''
            ));
        }
    }
}
