<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Helpers;

class LinkHelper
{
    public function makeRelative(string $url): string
    {
        return wp_make_link_relative($url);
    }
}