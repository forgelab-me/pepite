<?php

declare(strict_types=1);

namespace App\Libraries\Version;

use App\Exceptions\InvalidVersionException;

/**
 * A NuGet package version.
 *
 * NuGet versioning is *not* strict SemVer, and the differences are exactly the
 * ones that break a naive implementation:
 *
 *   - up to four numeric segments (1.2.3.4), where SemVer allows three;
 *   - one and two segment versions are legal and mean 1.0.0 / 1.2.0;
 *   - leading zeros in numeric segments are accepted and dropped;
 *   - prerelease labels compare case-insensitively, so 1.0.0-Alpha and
 *     1.0.0-alpha are the *same* version;
 *   - build metadata (+sha) is ignored both for ordering and for identity. The
 *     .NET SDK proves it: packing 1.0.0-beta.2+build.5 produces a file named
 *     Pepite.Fixtures.Prerelease.1.0.0-beta.2.nupkg;
 *   - a numeric prerelease label that overflows a 32-bit integer is compared as
 *     text, because NuGet parses it with int.TryParse.
 *
 * Getting any of these wrong does not raise an error: it silently breaks
 * `dotnet restore` for the packages concerned.
 */
final class NuGetVersion
{
    /**
     * NuGet stores version segments in a System.Version, so they are int32.
     */
    public const MAX_SEGMENT = 2147483647;

    private const PATTERN = '/^
        (\d+)                       # major
        (?:\.(\d+))?                # minor
        (?:\.(\d+))?                # patch
        (?:\.(\d+))?                # revision
        (?:-([0-9A-Za-z\-]+(?:\.[0-9A-Za-z\-]+)*))?   # prerelease labels
        (?:\+([0-9A-Za-z\-]+(?:\.[0-9A-Za-z\-]+)*))?  # build metadata
    $/x';

    /**
     * @param list<string> $releaseLabels
     */
    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
        public readonly int $revision,
        public readonly array $releaseLabels,
        public readonly ?string $metadata,
        public readonly string $original,
    ) {
    }

    public static function parse(string $value): self
    {
        $version = self::tryParse($value);

        if ($version === null) {
            throw InvalidVersionException::forVersion($value);
        }

        return $version;
    }

    public static function tryParse(string $value): ?self
    {
        $trimmed = trim($value);

        if ($trimmed === '' || preg_match(self::PATTERN, $trimmed, $m) !== 1) {
            return null;
        }

        $segments = [];

        foreach ([1, 2, 3, 4] as $index) {
            $raw = $m[$index] ?? '';

            if ($raw === '') {
                $segments[] = 0;

                continue;
            }

            // ltrim before comparing: "0000000001" is ten digits but is 1.
            $normalized = ltrim($raw, '0');

            if ($normalized === '') {
                $segments[] = 0;

                continue;
            }

            if (strlen($normalized) > 10 || (int) $normalized > self::MAX_SEGMENT) {
                throw InvalidVersionException::outOfRange($trimmed);
            }

            $segments[] = (int) $normalized;
        }

        $labels = ($m[5] ?? '') === '' ? [] : explode('.', $m[5]);

        return new self(
            $segments[0],
            $segments[1],
            $segments[2],
            $segments[3],
            $labels,
            ($m[6] ?? '') === '' ? null : $m[6],
            $trimmed,
        );
    }

    public function isPrerelease(): bool
    {
        return $this->releaseLabels !== [];
    }

    /**
     * Whether the version requires a SemVer 2.0.0 aware client.
     *
     * Dotted prerelease labels and build metadata are SemVer 2 features. A
     * four-segment version is *not*: it predates SemVer entirely. Clients that
     * do not send semVerLevel=2.0.0 must not be shown these versions.
     */
    public function isSemVer2(): bool
    {
        return count($this->releaseLabels) > 1 || $this->metadata !== null;
    }

    /**
     * The normalized form, as NuGet writes it: three segments unless the
     * revision is non-zero, no leading zeros, no build metadata.
     */
    public function normalized(): string
    {
        $value = sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch);

        if ($this->revision > 0) {
            $value .= '.' . $this->revision;
        }

        if ($this->releaseLabels !== []) {
            $value .= '-' . implode('.', $this->releaseLabels);
        }

        return $value;
    }

    /**
     * The identity of the version: normalized and case folded.
     *
     * This is what uniqueness in a feed is built on, and what NuGet URLs use.
     */
    public function normalizedLower(): string
    {
        return strtolower($this->normalized());
    }

    /**
     * Normalized form plus build metadata — for display only, never for identity.
     */
    public function full(): string
    {
        return $this->metadata === null
            ? $this->normalized()
            : $this->normalized() . '+' . $this->metadata;
    }

    /**
     * A collation key whose byte order matches compareTo().
     *
     * Lets the database sort versions, page through them and pick "the latest"
     * without loading them into PHP.
     *
     * The column storing this MUST use a binary collation: a Unicode collation
     * may treat '.' as ignorable and reorder punctuation against letters, both
     * of which break the encoding below.
     */
    public function sortKey(): string
    {
        $key = sprintf(
            '%010d.%010d.%010d.%010d-',
            $this->major,
            $this->minor,
            $this->patch,
            $this->revision,
        );

        // '~' (0x7E) sorts above digits and letters, so a stable release lands
        // after every one of its prereleases.
        if ($this->releaseLabels === []) {
            return $key . '~';
        }

        $encoded = [];

        foreach ($this->releaseLabels as $label) {
            // '0' before '1' encodes the SemVer rule that numeric identifiers
            // always have lower precedence than alphanumeric ones.
            $encoded[] = self::isNumericLabel($label)
                ? '0' . str_pad(ltrim($label, '0') === '' ? '0' : ltrim($label, '0'), 10, '0', STR_PAD_LEFT)
                : '1' . strtolower($label);
        }

        return $key . implode('.', $encoded);
    }

    public function compareTo(self $other): int
    {
        foreach (['major', 'minor', 'patch', 'revision'] as $segment) {
            $result = $this->{$segment} <=> $other->{$segment};

            if ($result !== 0) {
                return $result;
            }
        }

        // A prerelease always precedes the release it leads to.
        if ($this->releaseLabels === [] || $other->releaseLabels === []) {
            return count($other->releaseLabels) <=> count($this->releaseLabels);
        }

        $count = min(count($this->releaseLabels), count($other->releaseLabels));

        for ($i = 0; $i < $count; $i++) {
            $result = self::compareLabels($this->releaseLabels[$i], $other->releaseLabels[$i]);

            if ($result !== 0) {
                return $result;
            }
        }

        // All shared labels equal: the shorter list wins (1.0.0-a < 1.0.0-a.1).
        return count($this->releaseLabels) <=> count($other->releaseLabels);
    }

    /**
     * Version equality as NuGet understands it: build metadata plays no part.
     */
    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /**
     * @param list<self> $versions
     *
     * @return list<self> ascending
     */
    public static function sort(array $versions): array
    {
        usort($versions, static fn (self $a, self $b): int => $a->compareTo($b));

        return $versions;
    }

    private static function compareLabels(string $a, string $b): int
    {
        $aNumeric = self::isNumericLabel($a);
        $bNumeric = self::isNumericLabel($b);

        if ($aNumeric && $bNumeric) {
            return (int) $a <=> (int) $b;
        }

        if ($aNumeric !== $bNumeric) {
            return $aNumeric ? -1 : 1;
        }

        return strcmp(strtolower($a), strtolower($b));
    }

    /**
     * NuGet reads numeric labels with int.TryParse, so anything above int32 is
     * compared as text instead.
     */
    private static function isNumericLabel(string $label): bool
    {
        if ($label === '' || ! ctype_digit($label)) {
            return false;
        }

        $trimmed = ltrim($label, '0');

        return $trimmed === '' || (strlen($trimmed) <= 10 && (int) $trimmed <= self::MAX_SEGMENT);
    }
}
