<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Just enough Markdown to render a README: headings, bold, italic, inline
 * code, fenced code blocks, links and paragraphs. Not a CommonMark
 * implementation — a dependency-free stand-in so a v1 readme page isn't
 * either raw text or a new Composer dependency.
 */
final class Markdown
{
    public static function toHtml(string $source): string
    {
        $lines  = preg_split('/\r\n|\r|\n/', $source) ?: [];
        $html   = [];
        $inCode = false;
        $para   = [];

        $flush = static function () use (&$para, &$html): void {
            if ($para !== []) {
                $html[] = '<p>' . self::inline(implode(' ', $para)) . '</p>';
                $para   = [];
            }
        };

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '```')) {
                $flush();
                $inCode = ! $inCode;
                $html[] = $inCode ? '<pre><code>' : '</code></pre>';

                continue;
            }

            if ($inCode) {
                $html[] = esc($line) . "\n";

                continue;
            }

            if (trim($line) === '') {
                $flush();

                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $flush();
                $level  = strlen($m[1]);
                $html[] = sprintf('<h%d>%s</h%d>', $level, self::inline($m[2]), $level);

                continue;
            }

            $para[] = trim($line);
        }

        $flush();

        return implode("\n", $html);
    }

    private static function inline(string $text): string
    {
        $escaped = esc($text);

        $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $escaped) ?? $escaped;

        return preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            static fn (array $m): string => sprintf('<a href="%s" rel="nofollow">%s</a>', esc($m[2], 'attr'), $m[1]),
            $escaped,
        ) ?? $escaped;
    }
}
