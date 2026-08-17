<?php

declare(strict_types=1);

/**
 * Records a genuine `dotnet nuget push` request body as a test fixture.
 *
 * The push endpoint receives a PUT carrying multipart/form-data, and PHP only
 * populates $_FILES for POST. Everything about that body therefore has to be
 * parsed by hand, so the parser is tested against bytes the real client sent
 * rather than against a body we composed ourselves.
 *
 * Usage (see tools/capture-push-body.sh):
 *     php -S 127.0.0.1:8099 tools/capture-push-body.php
 *     dotnet nuget push <package> -s http://127.0.0.1:8099/api/v2/package -k any
 */

$name = getenv('PEPITE_CAPTURE_NAME') ?: 'push-simple';
$dir  = __DIR__ . '/../src/tests/_support/Fixtures/Http';

if (! is_dir($dir)) {
    mkdir($dir, 0o777, true);
}

$body = file_get_contents('php://input');

file_put_contents($dir . '/' . $name . '.body', $body);
file_put_contents($dir . '/' . $name . '.content-type', $_SERVER['CONTENT_TYPE'] ?? '');

// STDERR is not defined for a router script, so log through error_log().
error_log(sprintf(
    'captured %s: method=%s bytes=%d content-type=%s',
    $name,
    $_SERVER['REQUEST_METHOD'] ?? '?',
    strlen($body),
    $_SERVER['CONTENT_TYPE'] ?? '(none)',
));

// The client only stops retrying once it sees a success.
http_response_code(201);
