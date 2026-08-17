// Emits the reference answers for NuGet version handling, straight from the
// library the .NET clients themselves use.
//
// The output is committed as tests/_support/Fixtures/Versions/oracle.json so
// the PHP test suite asserts against NuGet's real behaviour instead of against
// our reading of the specification.
//
// Regenerate with tools/build-version-oracle.sh

using System.Text.Json;
using NuGet.Versioning;

// Versions that must parse. Ordering is asserted over this whole set.
string[] valid =
{
    "0.9.0",
    "1",
    "1.2",
    "1.2.3",
    "1.2.3.0",
    "1.2.3.4",
    "01.02.03",
    "0.0.0",
    "2147483647",
    "1.0.0-alpha",
    "1.0.0-Alpha",
    "1.0.0-alpha.1",
    "1.0.0-alpha.beta",
    "1.0.0-beta",
    "1.0.0-beta.2",
    "1.0.0-beta.11",
    "1.0.0-rc.1",
    "1.0.0-rc.2",
    "1.0.0-1",
    "1.0.0-99999999999",
    "1.0.0",
    "1.0.0+build.5",
    "1.0.0-beta.2+build.5",
    "1.0.0.1",
    "1.0.1",
    "1.1.0",
    "1.2.3.4-beta",
    "2.0.0",
};

// Strings whose acceptance we want confirmed rather than assumed.
string[] candidates =
{
    "",
    "   ",
    "abc",
    "1.2.3.4.5",
    "1..0",
    "1.0.",
    "1.0.0-",
    "1.0.0+",
    "1.0.0-alpha..1",
    "1.0.0-alpha_1",
    "v1.0.0",
    "-1.0.0",
    "2147483648.0.0",
    "  1.0.0  ",
    "1.0.0-alpha+",
    "1.0.0++a",
};

// Dependency ranges as they appear in a .nuspec, plus the malformed shapes we
// need to be sure are rejected.
string[] rangeCandidates =
{
    "1.0",
    "1.0.0",
    "1.2.3.4",
    "[1.0]",
    "[1.0.0]",
    "[1.0,)",
    "(1.0,)",
    "[,2.0]",
    "(,2.0)",
    "(,2.0]",
    "[1.0,2.0]",
    "[1.0,2.0)",
    "(1.0,2.0)",
    "(1.0,2.0]",
    "[1.0.0-beta,)",
    "[1.0.0-beta.1,2.0.0-rc.1)",
    "  [1.0, 2.0)  ",
    "",
    "   ",
    "*",
    "1.*",
    "1.0.*",
    "1.0.0-*",
    "1.0.0-beta.*",
    "[*,)",
    "[1.0.*,2.0)",
    "[1.0",
    "1.0]",
    "(,)",
    "[,]",
    "[2.0,1.0]",
    "(1.0,1.0)",
    "[1.0,,2.0]",
    "abc",
};

var parsed = valid
    .Select(input => new { input, version = NuGetVersion.Parse(input) })
    .ToList();

var report = new
{
    versions = parsed.Select(p => new
    {
        p.input,
        normalized = p.version.ToNormalizedString(),
        full = p.version.ToFullString(),
        isPrerelease = p.version.IsPrerelease,
        isSemVer2 = p.version.IsSemVer2,
        hasMetadata = p.version.HasMetadata,
        metadata = p.version.HasMetadata ? p.version.Metadata : null,
    }),

    // VersionComparer.Default is the comparison NuGet uses for identity and
    // ordering: it disregards build metadata.
    sorted = parsed
        .OrderBy(p => p.version, VersionComparer.Default)
        .Select(p => p.input),

    // Pairs that compare equal despite differing text.
    equalPairs = (
        from a in parsed
        from b in parsed
        where string.CompareOrdinal(a.input, b.input) < 0
              && VersionComparer.Default.Equals(a.version, b.version)
        select new { left = a.input, right = b.input }
    ),

    candidates = candidates.Select(input => new
    {
        input,
        parses = NuGetVersion.TryParse(input, out _),
    }),

    ranges = rangeCandidates.Select(input =>
    {
        var parses = VersionRange.TryParse(input, out var range);

        return new
        {
            input,
            parses,
            normalized     = parses ? range!.ToNormalizedString() : null,
            minVersion     = parses ? range!.MinVersion?.ToNormalizedString() : null,
            isMinInclusive = parses ? range!.IsMinInclusive : (bool?)null,
            maxVersion     = parses ? range!.MaxVersion?.ToNormalizedString() : null,
            isMaxInclusive = parses ? range!.IsMaxInclusive : (bool?)null,
            hasLowerBound  = parses ? range!.HasLowerBound : (bool?)null,
            hasUpperBound  = parses ? range!.HasUpperBound : (bool?)null,
            isFloating     = parses ? range!.IsFloating : (bool?)null,
        };
    }),
};

Console.WriteLine(JsonSerializer.Serialize(report, new JsonSerializerOptions
{
    WriteIndented = true,
}));
