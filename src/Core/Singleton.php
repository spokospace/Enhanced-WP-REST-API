<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Core;

abstract class Singleton
{
    protected static ?self $instance = null;

    public static function getInstance(): self
    {
        if (null === static::$instance) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    protected function __construct() {}
    protected function __clone() {}
}