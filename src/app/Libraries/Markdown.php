<?php

declare(strict_types=1);

namespace App\Libraries;

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Util\HtmlFilter;

/**
 * Renders a package README to HTML.
 *
 * The README is content supplied by whoever pushed the package; everyone who
 * browses the package page then renders it. league/commonmark's own defaults
 * trust that input — raw HTML passes through untouched, and a `javascript:`
 * link or image is allowed — which is exactly backwards for content nobody
 * here vouches for. Both are overridden below; that override is the entire
 * reason this class exists rather than a bare `new CommonMarkConverter()`.
 */
final class Markdown
{
    private static ?MarkdownConverter $converter = null;

    public static function toHtml(string $source): string
    {
        return (string) self::converter()->convert($source);
    }

    private static function converter(): MarkdownConverter
    {
        if (self::$converter !== null) {
            return self::$converter;
        }

        $converter = new CommonMarkConverter([
            'html_input'         => HtmlFilter::ESCAPE,
            'allow_unsafe_links' => false,
            // A README nobody here wrote can nest lists/quotes as deep as it
            // likes; cap it well short of what would trouble the parser's
            // own recursion rather than trust it to stay reasonable.
            'max_nesting_level' => 100,
        ]);

        $converter->getEnvironment()->addExtension(new GithubFlavoredMarkdownExtension());

        return self::$converter = $converter;
    }
}
