#!/usr/bin/env bash
#
# Regenerates tests/_support/Fixtures/Versions/oracle.json from NuGet.Versioning,
# the library the .NET clients use themselves.
#
# The result is committed, so the test suite does not need the .NET SDK. Run
# this when tools/version-oracle/Program.cs changes — and commit the result.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROJECT="$ROOT/tools/version-oracle"
OUT="$ROOT/tests/_support/Fixtures/Versions"
TFM="${PEPITE_FIXTURE_TFM:-net10.0}"

mkdir -p "$OUT"

if [ ! -f "$PROJECT/version-oracle.csproj" ]; then
    cat > "$PROJECT/version-oracle.csproj" <<EOF
<Project Sdk="Microsoft.NET.Sdk">
  <PropertyGroup>
    <OutputType>Exe</OutputType>
    <TargetFramework>$TFM</TargetFramework>
    <Nullable>enable</Nullable>
    <ImplicitUsings>enable</ImplicitUsings>
    <RootNamespace>Pepite.VersionOracle</RootNamespace>
  </PropertyGroup>
  <ItemGroup>
    <PackageReference Include="NuGet.Versioning" Version="6.14.0" />
  </ItemGroup>
</Project>
EOF
fi

dotnet run --project "$PROJECT" -c Release --nologo > "$OUT/oracle.json"

echo "Wrote $OUT/oracle.json"
