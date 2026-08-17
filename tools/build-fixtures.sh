#!/usr/bin/env bash
#
# Regenerates the .nupkg test fixtures with the real .NET tooling.
#
# The generated packages are committed, so the test suite runs without the .NET
# SDK. Run this only when a fixture needs to change — and commit the result.
#
# Usage (Git Bash, .NET SDK required):
#   ./tools/build-fixtures.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/tests/_support/Fixtures/Packages"
TFM="${PEPITE_FIXTURE_TFM:-net10.0}"

# Kept inside the repo on purpose: `mktemp -d` hands back an MSYS path
# (/tmp/...) that the native Windows `dotnet` cannot resolve.
WORK="$ROOT/.fixtures-work"

trap 'rm -rf "$WORK"' EXIT

rm -rf "$WORK"
mkdir -p "$WORK" "$OUT"
rm -f "$OUT"/*.nupkg

# Careful: MSBuild splits -p: values on commas, so property values below must
# not contain any.
common_props=(
    -c Release
    -o "$WORK/out"
    -p:Authors="Pepite Fixtures"
    -p:IncludeSymbols=false
    -p:EnableDefaultCompileItems=true
    --nologo
)

echo "== plain library =="
dotnet new classlib -n Plain -o "$WORK/plain" --framework "$TFM" >/dev/null

# Three packages that differ only by version: the identity edge cases.
dotnet pack "$WORK/plain/Plain.csproj" "${common_props[@]}" \
    -p:PackageId=Pepite.Fixtures.Simple \
    -p:PackageVersion=1.0.0 \
    -p:Description="Minimal fixture without dependencies or extras." \
    -p:PackageTags="fixture simple" >/dev/null

# Four-part version: legal for NuGet, illegal for strict SemVer.
dotnet pack "$WORK/plain/Plain.csproj" "${common_props[@]}" \
    -p:PackageId=Pepite.Fixtures.Legacy \
    -p:PackageVersion=1.2.3.4 \
    -p:Description="Four-segment version fixture." >/dev/null

# SemVer 2.0.0: dotted prerelease labels plus build metadata.
dotnet pack "$WORK/plain/Plain.csproj" "${common_props[@]}" \
    -p:PackageId=Pepite.Fixtures.Prerelease \
    -p:PackageVersion=1.0.0-beta.2+build.5 \
    -p:Description="SemVer2 prerelease fixture." >/dev/null

echo "== multi-framework library with dependencies =="
dotnet new classlib -n Deps -o "$WORK/deps" --framework "$TFM" >/dev/null
python - "$WORK/deps/Deps.csproj" "$TFM" <<'PY'
import sys, pathlib
path, tfm = pathlib.Path(sys.argv[1]), sys.argv[2]
path.write_text(f"""<Project Sdk="Microsoft.NET.Sdk">
  <PropertyGroup>
    <TargetFrameworks>{tfm};netstandard2.0</TargetFrameworks>
    <Nullable>enable</Nullable>
    <LangVersion>latest</LangVersion>
  </PropertyGroup>
  <ItemGroup>
    <PackageReference Include="Newtonsoft.Json" Version="13.0.3" />
  </ItemGroup>
  <ItemGroup Condition="'$(TargetFramework)' == 'netstandard2.0'">
    <PackageReference Include="System.Text.Json" Version="8.0.5" />
  </ItemGroup>
</Project>
""", encoding="utf-8")
PY

dotnet pack "$WORK/deps/Deps.csproj" "${common_props[@]}" \
    -p:PackageId=Pepite.Fixtures.Deps \
    -p:PackageVersion=2.1.0 \
    -p:Description="Dependency groups across two target frameworks." >/dev/null

echo "== rich metadata: icon, readme, license, repository =="
dotnet new classlib -n Rich -o "$WORK/rich" --framework "$TFM" >/dev/null

# A 1x1 PNG is enough: the reader only has to locate and extract it.
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' \
    | base64 -d > "$WORK/rich/icon.png"

cat > "$WORK/rich/README.md" <<'MD'
# Pepite.Fixtures.Rich

Fixture exercising embedded icon, embedded readme, a license expression,
tags and repository metadata.
MD

python - "$WORK/rich/Rich.csproj" "$TFM" <<'PY'
import sys, pathlib
path, tfm = pathlib.Path(sys.argv[1]), sys.argv[2]
path.write_text(f"""<Project Sdk="Microsoft.NET.Sdk">
  <PropertyGroup>
    <TargetFramework>{tfm}</TargetFramework>
    <PackageIcon>icon.png</PackageIcon>
    <PackageReadmeFile>README.md</PackageReadmeFile>
    <PackageLicenseExpression>MIT</PackageLicenseExpression>
    <PackageProjectUrl>https://example.test/pepite</PackageProjectUrl>
    <RepositoryUrl>https://example.test/pepite.git</RepositoryUrl>
    <RepositoryType>git</RepositoryType>
    <PackageTags>fixture icon readme</PackageTags>
  </PropertyGroup>
  <ItemGroup>
    <None Include="icon.png" Pack="true" PackagePath="\\" />
    <None Include="README.md" Pack="true" PackagePath="\\" />
  </ItemGroup>
</Project>
""", encoding="utf-8")
PY

dotnet pack "$WORK/rich/Rich.csproj" "${common_props[@]}" \
    -p:PackageId=Pepite.Fixtures.Rich \
    -p:PackageVersion=1.2.3 \
    -p:Description="Rich metadata fixture." >/dev/null

cp "$WORK"/out/*.nupkg "$OUT/"

echo
echo "Fixtures written to $OUT:"
ls -1 "$OUT"
