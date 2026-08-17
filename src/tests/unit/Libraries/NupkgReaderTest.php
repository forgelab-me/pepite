<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Exceptions\InvalidPackageException;
use App\Libraries\Package\NupkgReader;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures;
use ZipArchive;

/**
 * @internal
 */
final class NupkgReaderTest extends CIUnitTestCase
{
    /**
     * @var list<string>
     */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->temporary = [];

        parent::tearDown();
    }

    #[DataProvider('provideReadsIdentityFromEveryFixture')]
    public function testReadsIdentityFromEveryFixture(string $fileName, string $id, string $version): void
    {
        $reader   = NupkgReader::open(Fixtures::package($fileName));
        $metadata = $reader->metadata();

        $this->assertSame($id, $metadata->id);
        $this->assertSame($version, $metadata->version->normalized());

        $reader->close();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideReadsIdentityFromEveryFixture(): iterable
    {
        yield 'simple' => ['Pepite.Fixtures.Simple.1.0.0.nupkg', 'Pepite.Fixtures.Simple', '1.0.0'];

        yield 'four segment version' => ['Pepite.Fixtures.Legacy.1.2.3.4.nupkg', 'Pepite.Fixtures.Legacy', '1.2.3.4'];

        yield 'semver2 prerelease' => [
            'Pepite.Fixtures.Prerelease.1.0.0-beta.2.nupkg',
            'Pepite.Fixtures.Prerelease',
            '1.0.0-beta.2',
        ];

        yield 'dependencies' => ['Pepite.Fixtures.Deps.2.1.0.nupkg', 'Pepite.Fixtures.Deps', '2.1.0'];

        yield 'rich metadata' => ['Pepite.Fixtures.Rich.1.2.3.nupkg', 'Pepite.Fixtures.Rich', '1.2.3'];
    }

    /**
     * The file name drops the build metadata that the .nuspec keeps — the
     * clearest evidence that metadata plays no part in package identity.
     */
    public function testTheArchiveNameOmitsBuildMetadataThatTheNuspecKeeps(): void
    {
        $reader = NupkgReader::open(Fixtures::package('Pepite.Fixtures.Prerelease.1.0.0-beta.2.nupkg'));

        $this->assertStringContainsString('1.0.0-beta.2+build.5', $reader->nuspecXml());
        $this->assertSame('1.0.0-beta.2+build.5', $reader->metadata()->version->full());
        $this->assertSame('1.0.0-beta.2', $reader->metadata()->version->normalized());

        $reader->close();
    }

    public function testContentEntriesExcludeOpcBookkeeping(): void
    {
        $reader = NupkgReader::open(Fixtures::package('Pepite.Fixtures.Simple.1.0.0.nupkg'));

        $this->assertContains('[Content_Types].xml', $reader->entries());
        $this->assertNotContains('[Content_Types].xml', $reader->contentEntries());

        foreach ($reader->contentEntries() as $entry) {
            $this->assertStringNotContainsString('_rels/', $entry);
            $this->assertStringNotContainsString('package/services/metadata/', $entry);
        }

        $reader->close();
    }

    public function testPackageHashIsBase64EncodedSha512(): void
    {
        $reader = NupkgReader::open(Fixtures::package('Pepite.Fixtures.Simple.1.0.0.nupkg'));
        $hash   = $reader->sha512Base64();
        $reader->close();

        $raw = base64_decode($hash, true);

        $this->assertNotFalse($raw, 'the hash must be valid base64');
        $this->assertSame(64, strlen($raw), 'SHA-512 is 64 bytes');
    }

    public function testFindEntryAbsorbsWindowsSeparatorsAndCase(): void
    {
        $reader = NupkgReader::open(Fixtures::package('Pepite.Fixtures.Rich.1.2.3.nupkg'));

        $this->assertSame('icon.png', $reader->findEntry('icon.png'));
        // MSBuild writes Windows separators into the nuspec.
        $this->assertSame('icon.png', $reader->findEntry('\\icon.png'));
        $this->assertSame('icon.png', $reader->findEntry('ICON.PNG'));
        $this->assertNull($reader->findEntry('nope.png'));

        $reader->close();
    }

    public function testExtractEntryWritesTheFileVerbatim(): void
    {
        $reader = NupkgReader::open(Fixtures::package('Pepite.Fixtures.Rich.1.2.3.nupkg'));
        $target = $this->temporaryPath();

        $reader->extractEntry('icon.png', $target, 1024 * 1024);

        $this->assertFileExists($target);
        $this->assertSame($reader->readEntry('icon.png'), file_get_contents($target));
        // A real PNG, not a placeholder that happens to have the right name.
        $this->assertSame("\x89PNG", substr(file_get_contents($target), 0, 4));

        $reader->close();
    }

    public function testReadEntryRefusesAnythingOverTheLimit(): void
    {
        $reader = NupkgReader::open(Fixtures::package('Pepite.Fixtures.Rich.1.2.3.nupkg'));

        $this->expectException(InvalidPackageException::class);
        $this->expectExceptionMessageMatches('/larger than the 4 byte limit/');

        try {
            $reader->readEntry('README.md', 4);
        } finally {
            $reader->close();
        }
    }

    public function testExtractEntryRemovesThePartialFileWhenItOverruns(): void
    {
        $reader = NupkgReader::open(Fixtures::package('Pepite.Fixtures.Rich.1.2.3.nupkg'));
        $target = $this->temporaryPath();

        try {
            $reader->extractEntry('README.md', $target, 8);
            $this->fail('expected the size limit to be enforced');
        } catch (InvalidPackageException) {
            $this->assertFileDoesNotExist($target, 'a truncated file must not be left behind');
        } finally {
            $reader->close();
        }
    }

    /**
     * Zip slip: an archive is free to name an entry "../../public/index.php",
     * and PHP would write it there. Names are checked before anything is read.
     */
    #[DataProvider('provideRejectsUnsafeEntryNames')]
    public function testRejectsUnsafeEntryNames(string $entryName): void
    {
        $archive = $this->makeZip([
            'Sample.nuspec' => $this->minimalNuspec(),
            $entryName      => 'payload',
        ]);

        $this->expectException(InvalidPackageException::class);
        $this->expectExceptionMessageMatches('/unsafe entry name/');

        NupkgReader::open($archive);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectsUnsafeEntryNames(): iterable
    {
        yield 'parent traversal' => ['../evil.txt'];

        yield 'nested traversal' => ['lib/../../evil.txt'];

        yield 'absolute path' => ['/etc/passwd'];

        yield 'windows drive' => ['C:/windows/evil.txt'];

        yield 'backslash traversal' => ['..\\evil.txt'];

        yield 'current directory' => ['./evil.txt'];
    }

    public function testRejectsAnArchiveWithoutANuspec(): void
    {
        $reader = NupkgReader::open($this->makeZip(['lib/net10.0/Thing.dll' => 'binary']));

        $this->expectException(InvalidPackageException::class);
        $this->expectExceptionMessageMatches('/no \.nuspec file at its root/');

        try {
            $reader->nuspecEntry();
        } finally {
            $reader->close();
        }
    }

    public function testRejectsAnArchiveWithSeveralNuspecs(): void
    {
        $reader = NupkgReader::open($this->makeZip([
            'One.nuspec' => $this->minimalNuspec(),
            'Two.nuspec' => $this->minimalNuspec(),
        ]));

        $this->expectException(InvalidPackageException::class);
        $this->expectExceptionMessageMatches('/exactly one is required/');

        try {
            $reader->nuspecEntry();
        } finally {
            $reader->close();
        }
    }

    /**
     * A .nuspec nested in a folder does not count: NuGet only looks at the root.
     */
    public function testANestedNuspecIsNotTheManifest(): void
    {
        $reader = NupkgReader::open($this->makeZip(['tools/Inner.nuspec' => $this->minimalNuspec()]));

        $this->expectException(InvalidPackageException::class);
        $this->expectExceptionMessageMatches('/no \.nuspec file at its root/');

        try {
            $reader->nuspecEntry();
        } finally {
            $reader->close();
        }
    }

    public function testRejectsSomethingThatIsNotAZip(): void
    {
        $path = $this->temporaryPath();
        file_put_contents($path, 'definitely not a zip archive');

        $this->expectException(InvalidPackageException::class);
        $this->expectExceptionMessageMatches('/not a valid zip archive/');

        NupkgReader::open($path);
    }

    public function testRejectsAMissingFile(): void
    {
        $this->expectException(InvalidPackageException::class);
        $this->expectExceptionMessageMatches('/no such readable file/');

        NupkgReader::open(sys_get_temp_dir() . '/pepite-does-not-exist.nupkg');
    }

    public function testAClosedReaderRefusesToRead(): void
    {
        $reader = NupkgReader::open(Fixtures::package('Pepite.Fixtures.Simple.1.0.0.nupkg'));
        $reader->close();

        $this->expectException(InvalidPackageException::class);
        $this->expectExceptionMessageMatches('/the archive is closed/');

        $reader->nuspecXml();
    }

    /**
     * @param array<string, string> $entries
     */
    private function makeZip(array $entries): string
    {
        $path = $this->temporaryPath();

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    private function temporaryPath(): string
    {
        $path              = tempnam(sys_get_temp_dir(), 'pepite-test-');
        $this->temporary[] = $path;

        return $path;
    }

    private function minimalNuspec(): string
    {
        return '<package><metadata><id>Sample</id><version>1.0.0</version></metadata></package>';
    }
}
