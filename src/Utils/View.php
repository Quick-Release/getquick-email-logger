<?php

declare(strict_types=1);

namespace GetQuick\EmailLogger\Utils;

class View
{
    public static function render(string $template, array $args = []): void
    {
        $path = dirname(__DIR__, 2) . '/templates/' . $template . '.php';

        if (! file_exists($path)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                trigger_error(sprintf('Template %s not found at %s', $template, $path), E_USER_WARNING);
            }
            return;
        }

        extract($args);
        require $path;
    }
}
