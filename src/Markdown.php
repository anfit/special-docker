<?php

declare(strict_types=1);

namespace Special;

require_once __DIR__ . '/ThirdParty/Parsedown.php';

/**
 * Adapt the pinned Parsedown dependency to the application’s display boundary.
 *
 * Spec bodies remain Markdown source in storage and over MCP. Parsedown is
 * used only for browser rendering, with safe mode enabled and only HTTP(S)
 * link destinations allowed.
 */
final class Markdown
{
    public static function render(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $parser = new SafeParsedown();
        $parser->setSafeMode(true);
        $parser->setUrlsLinked(true);

        return $parser->text($value);
    }
}

final class SafeParsedown extends \Parsedown
{
    /** @var list<string> */
    protected $safeLinksWhitelist = [
        'http://',
        'https://',
    ];
}
