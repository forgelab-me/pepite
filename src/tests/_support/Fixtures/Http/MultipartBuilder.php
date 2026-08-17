<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Http;

/**
 * Composes a multipart/form-data body for feature tests.
 *
 * The parser itself is proven against a real `dotnet nuget push` capture in
 * MultipartPutParserTest (lot 1); this only needs to produce a body the
 * controller can be fed through a feature test's request body.
 */
final class MultipartBuilder
{
    private const BOUNDARY = 'pepite-test-boundary';

    public static function withFile(string $fieldName, string $fileName, string $contents): string
    {
        return '--' . self::BOUNDARY . "\r\n"
            . sprintf(
                "Content-Disposition: form-data; name=%s; filename=%s\r\n",
                $fieldName,
                $fileName,
            )
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $contents . "\r\n"
            . '--' . self::BOUNDARY . "--\r\n";
    }

    public static function contentType(): string
    {
        return 'multipart/form-data; boundary=' . self::BOUNDARY;
    }
}
