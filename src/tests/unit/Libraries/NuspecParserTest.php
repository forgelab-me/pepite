<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Exceptions\InvalidPackageException;
use App\Libraries\Package\NupkgReader;
use App\Libraries\Package\NuspecParser;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures;

/**
 * @internal
 */
final class NuspecParserTest extends CIUnitTestCase
{
    private NuspecParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new NuspecParser();
    }

    /**
     * The reason this parser ignores namespaces: these two fixtures were packed
     * by the same SDK on the same machine within the same minute, and came out
     * under different nuspec schema namespaces.
     */
    #[DataProvider('provideRealPackagesFromDifferentSchemas')]
    public function testRealPackagesFromDifferentSchemas(string $fileName, string $namespace, string $expectedId): void
    {
        $reader = NupkgReader::open(Fixtures::package($fileName));
        $xml    = $reader->nuspecXml();
        $reader->close();

        $this->assertStringContainsString($namespace, $xml, 'fixture no longer uses the expected schema');
        $this->assertSame($expectedId, $this->parser->parse($xml)->id);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideRealPackagesFromDifferentSchemas(): iterable
    {
        yield '2012/06 schema' => [
            'Pepite.Fixtures.Prerelease.1.0.0-beta.2.nupkg',
            'packaging/2012/06/nuspec.xsd',
            'Pepite.Fixtures.Prerelease',
        ];

        yield '2013/05 schema' => [
            'Pepite.Fixtures.Deps.2.1.0.nupkg',
            'packaging/2013/05/nuspec.xsd',
            'Pepite.Fixtures.Deps',
        ];
    }

    public function testReadsDependencyGroupsFromARealPackage(): void
    {
        $reader   = NupkgReader::open(Fixtures::package('Pepite.Fixtures.Deps.2.1.0.nupkg'));
        $metadata = $reader->metadata();
        $reader->close();

        $frameworks = array_map(
            static fn ($group) => $group->targetFramework,
            $metadata->dependencyGroups,
        );

        $this->assertContains('net10.0', $frameworks);
        $this->assertContains('.NETStandard2.0', $frameworks);

        $byFramework = [];

        foreach ($metadata->dependencyGroups as $group) {
            $byFramework[$group->targetFramework] = $group->dependencies;
        }

        $this->assertCount(1, $byFramework['net10.0']);
        $this->assertCount(2, $byFramework['.NETStandard2.0']);

        $newtonsoft = $byFramework['net10.0'][0];
        $this->assertSame('Newtonsoft.Json', $newtonsoft->id);
        $this->assertSame('[13.0.3, )', $newtonsoft->normalizedRange());
        $this->assertSame('Build,Analyzers', $newtonsoft->exclude);
    }

    public function testReadsRichMetadataFromARealPackage(): void
    {
        $reader   = NupkgReader::open(Fixtures::package('Pepite.Fixtures.Rich.1.2.3.nupkg'));
        $metadata = $reader->metadata();
        $reader->close();

        $this->assertSame('Pepite.Fixtures.Rich', $metadata->id);
        $this->assertSame('1.2.3', $metadata->version->normalized());
        $this->assertSame(['Pepite Fixtures'], $metadata->authors);
        $this->assertSame('icon.png', $metadata->icon);
        $this->assertSame('README.md', $metadata->readme);
        $this->assertSame('expression', $metadata->licenseType);
        $this->assertSame('MIT', $metadata->licenseValue);
        $this->assertSame('https://example.test/pepite', $metadata->projectUrl);
        $this->assertSame('git', $metadata->repositoryType);
        $this->assertSame('https://example.test/pepite.git', $metadata->repositoryUrl);
        $this->assertSame(['fixture', 'icon', 'readme'], $metadata->tags);
    }

    public function testAPackageWithNoDeclaredTypeIsALibrary(): void
    {
        $reader   = NupkgReader::open(Fixtures::package('Pepite.Fixtures.Simple.1.0.0.nupkg'));
        $metadata = $reader->metadata();
        $reader->close();

        $this->assertSame([], $metadata->packageTypes);
        $this->assertTrue($metadata->hasPackageType('Dependency'));
        $this->assertFalse($metadata->hasPackageType('ConsoleApp'));
    }

    public function testReadsCustomPackageTypes(): void
    {
        $metadata = $this->parser->parse($this->nuspec(<<<'XML'
            <packageTypes>
              <packageType name="ConsoleApp" version="1.0" />
            </packageTypes>
            XML));

        $this->assertTrue($metadata->hasPackageType('consoleapp'), 'type matching is case-insensitive');
        $this->assertFalse($metadata->hasPackageType('Dependency'));
        $this->assertSame('1.0', $metadata->packageTypes[0]->version);
    }

    /**
     * The pre-group form, still emitted by older tooling: dependencies sit
     * directly under <dependencies> and apply to every framework.
     */
    public function testReadsTheLegacyFlatDependencyForm(): void
    {
        $metadata = $this->parser->parse($this->nuspec(<<<'XML'
            <dependencies>
              <dependency id="Alpha" version="1.0.0" />
              <dependency id="Beta" />
            </dependencies>
            XML));

        $this->assertCount(1, $metadata->dependencyGroups);
        $this->assertTrue($metadata->dependencyGroups[0]->isUniversal());

        [$alpha, $beta] = $metadata->dependencyGroups[0]->dependencies;

        $this->assertSame('[1.0.0, )', $alpha->normalizedRange());
        // No version attribute means any version, not "no range".
        $this->assertSame('(, )', $beta->normalizedRange());
    }

    public function testAuthorsSplitOnCommasAndTagsOnWhitespace(): void
    {
        $metadata = $this->parser->parse($this->nuspec(
            '<authors>Alice, Bob ,  Carol</authors><tags>  one   two
             three </tags>',
        ));

        $this->assertSame(['Alice', 'Bob', 'Carol'], $metadata->authors);
        $this->assertSame(['one', 'two', 'three'], $metadata->tags);
    }

    public function testReadsMinClientVersionAndBooleanFlags(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <package xmlns="http://schemas.microsoft.com/packaging/2013/05/nuspec.xsd">
              <metadata minClientVersion="2.12">
                <id>Sample</id>
                <version>1.0.0</version>
                <requireLicenseAcceptance>TRUE</requireLicenseAcceptance>
                <developmentDependency>false</developmentDependency>
              </metadata>
            </package>
            XML;

        $metadata = $this->parser->parse($xml);

        $this->assertSame('2.12', $metadata->minClientVersion);
        $this->assertTrue($metadata->requireLicenseAcceptance);
        $this->assertFalse($metadata->developmentDependency);
        $this->assertFalse($metadata->serviceable);
    }

    public function testParsesWithoutAnyNamespaceAtAll(): void
    {
        $metadata = $this->parser->parse(
            '<package><metadata><id>Bare</id><version>1.0</version></metadata></package>',
        );

        $this->assertSame('Bare', $metadata->id);
        $this->assertSame('1.0.0', $metadata->version->normalized());
    }

    public function testToleratesAByteOrderMark(): void
    {
        $xml = "\xEF\xBB\xBF<package><metadata><id>Bom</id><version>1.0.0</version></metadata></package>";

        $this->assertSame('Bom', $this->parser->parse($xml)->id);
    }

    public function testAMalformedRangeDegradesToAnyVersion(): void
    {
        $metadata = $this->parser->parse($this->nuspec(
            '<dependencies><dependency id="Alpha" version="[not-a-range" /></dependencies>',
        ));

        $this->assertSame('(, )', $metadata->dependencyGroups[0]->dependencies[0]->normalizedRange());
    }

    #[DataProvider('provideRejectsUnusableDocuments')]
    public function testRejectsUnusableDocuments(string $xml): void
    {
        $this->expectException(InvalidPackageException::class);

        $this->parser->parse($xml);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectsUnusableDocuments(): iterable
    {
        yield 'empty' => [''];

        yield 'not xml' => ['this is not xml'];

        yield 'unclosed' => ['<package><metadata><id>A</id>'];

        yield 'wrong root' => ['<nuspec><metadata><id>A</id><version>1.0.0</version></metadata></nuspec>'];

        yield 'no metadata' => ['<package></package>'];

        yield 'no id' => ['<package><metadata><version>1.0.0</version></metadata></package>'];

        yield 'no version' => ['<package><metadata><id>A</id></metadata></package>'];

        yield 'unparseable version' => ['<package><metadata><id>A</id><version>x</version></metadata></package>'];

        yield 'id with space' => ['<package><metadata><id>A B</id><version>1.0.0</version></metadata></package>'];

        yield 'id with slash' => ['<package><metadata><id>a/b</id><version>1.0.0</version></metadata></package>'];

        yield 'id starting with a dot' => ['<package><metadata><id>.a</id><version>1.0.0</version></metadata></package>'];

        yield 'id over 100 characters' => [
            '<package><metadata><id>' . str_repeat('a', 101) . '</id><version>1.0.0</version></metadata></package>',
        ];
    }

    public function testAcceptsAnIdentifierOfExactlyOneHundredCharacters(): void
    {
        $id = str_repeat('a', 100);

        $this->assertSame($id, $this->parser->parse(
            '<package><metadata><id>' . $id . '</id><version>1.0.0</version></metadata></package>',
        )->id);
    }

    public function testIdLowerIsWhatUniquenessIsBuiltOn(): void
    {
        $metadata = $this->parser->parse(
            '<package><metadata><id>Acme.Core</id><version>1.0.0</version></metadata></package>',
        );

        $this->assertSame('acme.core', $metadata->idLower());
    }

    /**
     * Wraps fragment(s) in a minimal namespaced nuspec.
     */
    private function nuspec(string $extraMetadata): string
    {
        return <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <package xmlns="http://schemas.microsoft.com/packaging/2013/05/nuspec.xsd">
              <metadata>
                <id>Sample</id>
                <version>1.0.0</version>
                <description>Sample</description>
                {$extraMetadata}
              </metadata>
            </package>
            XML;
    }
}
