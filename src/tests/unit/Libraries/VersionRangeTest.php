<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Exceptions\InvalidVersionException;
use App\Libraries\Version\VersionRange;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures;

/**
 * Driven by the oracle: every expectation comes from NuGet.Versioning itself
 * (see tools/build-version-oracle.sh), not from our reading of the docs.
 *
 * @internal
 */
final class VersionRangeTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('provideMatchesNuGetVersioning')]
    public function testMatchesNuGetVersioning(array $expected): void
    {
        $range = VersionRange::tryParse($expected['input']);

        if (! $expected['parses']) {
            $this->assertNull($range, sprintf('NuGet rejects "%s"', $expected['input']));

            return;
        }

        $this->assertNotNull($range, sprintf('NuGet accepts "%s"', $expected['input']));

        $this->assertSame($expected['normalized'], $range->normalized(), 'ToNormalizedString()');
        $this->assertSame($expected['minVersion'], $range->minVersion?->normalized(), 'MinVersion');
        $this->assertSame($expected['maxVersion'], $range->maxVersion?->normalized(), 'MaxVersion');
        $this->assertSame($expected['isMinInclusive'], $range->isMinInclusive, 'IsMinInclusive');
        $this->assertSame($expected['isMaxInclusive'], $range->isMaxInclusive, 'IsMaxInclusive');
        $this->assertSame($expected['hasLowerBound'], $range->hasLowerBound(), 'HasLowerBound');
        $this->assertSame($expected['hasUpperBound'], $range->hasUpperBound(), 'HasUpperBound');
        $this->assertSame($expected['isFloating'], $range->isFloating(), 'IsFloating');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideMatchesNuGetVersioning(): iterable
    {
        /** @var array<string, mixed> $oracle */
        $oracle = Fixtures::json('Versions/oracle.json');

        foreach ($oracle['ranges'] as $entry) {
            yield sprintf('"%s"', $entry['input']) => [$entry];
        }
    }

    public function testBareVersionBecomesAnOpenEndedRange(): void
    {
        $this->assertSame('[1.0.0, )', VersionRange::parse('1.0')->normalized());
    }

    public function testParseThrowsOnGarbage(): void
    {
        $this->expectException(InvalidVersionException::class);

        VersionRange::parse('[1.0');
    }

    /**
     * Not covered by the oracle: NuGet only spells the exact form with square
     * brackets, so the parenthesised single value must be refused.
     */
    public function testSingleValueRequiresSquareBrackets(): void
    {
        $this->assertNull(VersionRange::tryParse('(1.0)'));
        $this->assertNull(VersionRange::tryParse('[1.0)'));
        $this->assertNotNull(VersionRange::tryParse('[1.0]'));
    }

    public function testFloatingIsRejectedOnTheUpperBound(): void
    {
        $this->assertNull(VersionRange::tryParse('[1.0,2.*)'));
    }

    public function testStarMustBeTrailingAndUnique(): void
    {
        $this->assertNull(VersionRange::tryParse('1.*.0'));
        $this->assertNull(VersionRange::tryParse('*.*'));
    }
}
