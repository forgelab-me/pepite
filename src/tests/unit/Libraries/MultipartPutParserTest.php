<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Exceptions\MultipartException;
use App\Exceptions\PayloadTooLargeException;
use App\Libraries\Http\MultipartBody;
use App\Libraries\Http\MultipartPutParser;
use App\Libraries\Package\NupkgReader;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures;

/**
 * @internal
 */
final class MultipartPutParserTest extends CIUnitTestCase
{
    private string $tmpDir;

    /**
     * @var list<MultipartBody>
     */
    private array $bodies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/pepite-multipart-test';
    }

    protected function tearDown(): void
    {
        foreach ($this->bodies as $body) {
            $body->cleanup();
        }

        $this->bodies = [];

        parent::tearDown();
    }

    /**
     * The reference case: the exact bytes `dotnet nuget push` put on the wire,
     * captured by tools/capture-push-body.php.
     */
    public function testParsesACapturedDotnetPush(): void
    {
        $body = $this->parseFixture();

        $this->assertCount(1, $body->parts());

        $part = $body->firstFile();

        $this->assertNotNull($part);
        $this->assertSame('package', $part->name);
        $this->assertSame('package.nupkg', $part->declaredFileName());
        $this->assertSame('application/octet-stream', $part->contentType);
        $this->assertTrue($part->isFile());
    }

    /**
     * The bytes written to disk must be the package, byte for byte — not merely
     * "something zip-shaped".
     */
    public function testTheExtractedFileIsExactlyTheOriginalPackage(): void
    {
        $body = $this->parseFixture();
        $part = $body->firstFile();

        $original = Fixtures::package('Pepite.Fixtures.Simple.1.0.0.nupkg');

        $this->assertSame(filesize($original), $part->size);
        $this->assertSame(
            hash_file('sha512', $original),
            hash_file('sha512', $part->path),
            'the uploaded bytes differ from the source package',
        );

        // And it is still a readable package once round-tripped.
        $reader = NupkgReader::open($part->path);
        $this->assertSame('Pepite.Fixtures.Simple', $reader->metadata()->id);
        $reader->close();
    }

    public function testCleanupRemovesTheTemporaryFile(): void
    {
        $body = $this->parseFixture();
        $path = $body->firstFile()->path;

        $this->assertFileExists($path);

        $body->cleanup();

        $this->assertFileDoesNotExist($path);
    }

    /**
     * Chunk size is the parser's main source of off-by-one bugs: a boundary
     * straddling two reads must still be found. Sizes below the delimiter
     * length are deliberately included.
     */
    #[DataProvider('provideChunkSizeDoesNotChangeTheResult')]
    public function testChunkSizeDoesNotChangeTheResult(int $chunkSize): void
    {
        $original = Fixtures::package('Pepite.Fixtures.Simple.1.0.0.nupkg');
        $body     = $this->parseFixture($chunkSize);

        $this->assertSame(
            hash_file('sha512', $original),
            hash_file('sha512', $body->firstFile()->path),
            sprintf('reading in %d byte chunks changed the payload', $chunkSize),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideChunkSizeDoesNotChangeTheResult(): iterable
    {
        foreach ([1, 2, 3, 7, 16, 64, 511, 1024, 4096, 1048576] as $size) {
            yield sprintf('%d bytes', $size) => [$size];
        }
    }

    public function testParsesSeveralPartsIncludingPlainFields(): void
    {
        $body = $this->parse($this->compose('X', [
            ['headers' => ['Content-Disposition' => 'form-data; name="apiKey"'], 'body' => 'secret-key'],
            [
                'headers' => [
                    'Content-Disposition' => 'form-data; name="package"; filename="thing.nupkg"',
                    'Content-Type'        => 'application/octet-stream',
                ],
                'body' => 'PK-payload',
            ],
            ['headers' => ['Content-Disposition' => 'form-data; name="note"'], 'body' => "line one\r\nline two"],
        ]));

        $this->assertCount(3, $body->parts());
        $this->assertSame('secret-key', $body->part('apiKey')->value);
        $this->assertFalse($body->part('apiKey')->isFile());
        // A CRLF inside a field must not be mistaken for a boundary.
        $this->assertSame("line one\r\nline two", $body->part('note')->value);
        $this->assertSame('PK-payload', file_get_contents($body->part('package')->path));
    }

    public function testHandlesAnEmptyFilePart(): void
    {
        $body = $this->parse($this->compose('X', [
            [
                'headers' => ['Content-Disposition' => 'form-data; name="package"; filename="empty.nupkg"'],
                'body'    => '',
            ],
        ]));

        $this->assertSame(0, $body->firstFile()->size);
        $this->assertSame('', file_get_contents($body->firstFile()->path));
    }

    public function testAPartWithoutAFilenameIsAField(): void
    {
        $body = $this->parse($this->compose('X', [
            ['headers' => ['Content-Disposition' => 'form-data; name="plain"'], 'body' => 'value'],
        ]));

        $this->assertFalse($body->part('plain')->isFile());
        $this->assertNull($body->part('plain')->path);
        $this->assertNull($body->firstFile());
    }

    public function testRfc5987FilenameWinsOverThePlainOne(): void
    {
        $body = $this->parse($this->compose('X', [
            [
                'headers' => [
                    'Content-Disposition' => "form-data; name=package; filename=fallback.nupkg; filename*=utf-8''caf%C3%A9.nupkg",
                ],
                'body' => 'x',
            ],
        ]));

        $this->assertSame('café.nupkg', $body->firstFile()->declaredFileName());
    }

    #[DataProvider('provideBoundaryExtraction')]
    public function testBoundaryExtraction(string $header, ?string $expected): void
    {
        $this->assertSame($expected, MultipartPutParser::boundaryFrom($header));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function provideBoundaryExtraction(): iterable
    {
        // What the .NET client actually sends: quoted.
        yield 'quoted' => ['multipart/form-data; boundary="abc-123"', 'abc-123'];

        yield 'bare' => ['multipart/form-data; boundary=abc-123', 'abc-123'];

        yield 'spaced' => ['multipart/form-data ;  boundary = abc-123 ', 'abc-123'];

        yield 'mixed case' => ['MULTIPART/FORM-DATA; BOUNDARY="abc"', 'abc'];

        yield 'followed by charset' => ['multipart/form-data; boundary=abc; charset=utf-8', 'abc'];

        yield 'not multipart' => ['application/json', null];

        yield 'no boundary' => ['multipart/form-data', null];

        yield 'empty' => ['', null];

        yield 'over seventy characters' => ['multipart/form-data; boundary=' . str_repeat('a', 71), null];
    }

    public function testRefusesABodyLargerThanTheLimit(): void
    {
        $this->expectException(PayloadTooLargeException::class);
        $this->expectExceptionMessageMatches('/maximum accepted size of 128 bytes/');

        $this->parse(
            $this->compose('X', [[
                'headers' => ['Content-Disposition' => 'form-data; name="package"; filename="big.nupkg"'],
                'body'    => str_repeat('a', 4096),
            ]]),
            chunkSize: 64,
            maxBytes: 128,
        );
    }

    public function testAnOversizedBodyLeavesNoTemporaryFileBehind(): void
    {
        $before = $this->temporaryFileCount();

        try {
            $this->parse(
                $this->compose('X', [[
                    'headers' => ['Content-Disposition' => 'form-data; name="package"; filename="big.nupkg"'],
                    'body'    => str_repeat('a', 8192),
                ]]),
                chunkSize: 64,
                maxBytes: 256,
            );
            $this->fail('expected the limit to be enforced');
        } catch (PayloadTooLargeException) {
            $this->assertSame($before, $this->temporaryFileCount(), 'an aborted upload was left on disk');
        }
    }

    public function testRefusesAnOversizedPlainField(): void
    {
        $this->expectException(PayloadTooLargeException::class);
        $this->expectExceptionMessageMatches('/Field "note"/');

        $this->parse(
            $this->compose('X', [[
                'headers' => ['Content-Disposition' => 'form-data; name="note"'],
                'body'    => str_repeat('a', 4096),
            ]]),
            maxFieldBytes: 512,
        );
    }

    #[DataProvider('provideRejectsMalformedBodies')]
    public function testRejectsMalformedBodies(string $body, string $contentType): void
    {
        $this->expectException(MultipartException::class);

        $this->parse($body, contentType: $contentType);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRejectsMalformedBodies(): iterable
    {
        yield 'not multipart at all' => ['{"a":1}', 'application/json'];

        yield 'no boundary declared' => ['whatever', 'multipart/form-data'];

        yield 'empty body' => ['', 'multipart/form-data; boundary=X'];

        yield 'boundary never appears' => ['nothing to see here', 'multipart/form-data; boundary=X'];

        yield 'headers never terminate' => [
            "--X\r\nContent-Disposition: form-data; name=a\r\n",
            'multipart/form-data; boundary=X',
        ];

        yield 'part body never terminates' => [
            "--X\r\nContent-Disposition: form-data; name=a\r\n\r\nunterminated",
            'multipart/form-data; boundary=X',
        ];
    }

    private function parseFixture(?int $chunkSize = null): MultipartBody
    {
        return $this->parse(
            Fixtures::contents('Http/push-simple.body'),
            contentType: trim(Fixtures::contents('Http/push-simple.content-type')),
            chunkSize: $chunkSize ?? 262144,
        );
    }

    private function parse(
        string $raw,
        string $contentType = 'multipart/form-data; boundary=X',
        int $chunkSize = 262144,
        int $maxBytes = 10485760,
        int $maxFieldBytes = 65536,
    ): MultipartBody {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $raw);
        rewind($stream);

        $parser = new MultipartPutParser($maxBytes, $this->tmpDir, $maxFieldBytes, $chunkSize);

        try {
            $body = $parser->parse($stream, $contentType);
        } finally {
            fclose($stream);
        }

        $this->bodies[] = $body;

        return $body;
    }

    /**
     * @param list<array{headers: array<string, string>, body: string}> $parts
     */
    private function compose(string $boundary, array $parts): string
    {
        $raw = '';

        foreach ($parts as $part) {
            $raw .= '--' . $boundary . "\r\n";

            foreach ($part['headers'] as $name => $value) {
                $raw .= $name . ': ' . $value . "\r\n";
            }

            $raw .= "\r\n" . $part['body'] . "\r\n";
        }

        return $raw . '--' . $boundary . "--\r\n";
    }

    private function temporaryFileCount(): int
    {
        return is_dir($this->tmpDir) ? count(glob($this->tmpDir . '/pepite-upload-*') ?: []) : 0;
    }
}
