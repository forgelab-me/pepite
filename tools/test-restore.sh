#!/usr/bin/env bash
#
# The acceptance test for the read side: a real `dotnet restore` against a
# running Pepite feed.
#
# Nothing else proves conformance. Our unit tests check the documents we emit
# against the shapes nuget.org emits, but only the real client proves that a
# restore resolves — and when it does not, it answers NU1101 rather than
# anything about what is actually wrong.
#
# PackageReference is used on purpose. A packages.config restore only touches
# the flat container: it downloads exactly the versions it is given and never
# reads registration, so it cannot tell you whether dependency resolution
# works.
#
# The feed must already be running and populated (from src/):
#   php spark serve
#   php spark pepite:import "tests/_support/Fixtures/Packages/*.nupkg"
#
# Usage (.NET SDK required):
#   ./tools/test-restore.sh [feed-index-url]
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FEED="${1:-http://127.0.0.1:8080/feeds/default/v3/index.json}"
WORK="$ROOT/.restore-check"
TFM="${PEPITE_FIXTURE_TFM:-net10.0}"

trap 'rm -rf "$WORK"' EXIT

rm -rf "$WORK"
mkdir -p "$WORK"

cat > "$WORK/nuget.config" <<EOF
<?xml version="1.0" encoding="utf-8"?>
<configuration>
  <packageSources>
    <clear />
    <add key="pepite" value="$FEED" allowInsecureConnections="true" />
    <add key="nuget.org" value="https://api.nuget.org/v3/index.json" />
  </packageSources>
</configuration>
EOF

# Three packages, chosen for what each one exercises:
#   Simple      the plain path;
#   Deps        dependency resolution, including a transitive package only
#               nuget.org has — which resolves only if our registration
#               reported the dependency correctly;
#   Prerelease  a SemVer 2 version, reachable only through the semver2
#               registration base URL.
cat > "$WORK/RestoreCheck.csproj" <<EOF
<Project Sdk="Microsoft.NET.Sdk">
  <PropertyGroup>
    <TargetFramework>$TFM</TargetFramework>
    <RestorePackagesPath>packages</RestorePackagesPath>
  </PropertyGroup>
  <ItemGroup>
    <PackageReference Include="Pepite.Fixtures.Simple" Version="1.0.0" />
    <PackageReference Include="Pepite.Fixtures.Deps" Version="2.1.0" />
    <PackageReference Include="Pepite.Fixtures.Prerelease" Version="1.0.0-beta.2" />
  </ItemGroup>
</Project>
EOF

echo "Restoring against $FEED"
cd "$WORK"
dotnet restore --nologo

echo
python - "$WORK/obj/project.assets.json" <<'PY'
import json, pathlib, sys

assets = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8-sig"))
libraries = set(assets.get("libraries", {}).keys())

expected = [
    "Pepite.Fixtures.Simple/1.0.0",
    "Pepite.Fixtures.Deps/2.1.0",
    "Pepite.Fixtures.Prerelease/1.0.0-beta.2",
    # Transitive: declared by Deps, served by nuget.org. Its presence is the
    # proof that our registration document carried the dependency.
    "Newtonsoft.Json/13.0.3",
]

missing = [name for name in expected if name not in libraries]

for name in expected:
    print(("  ok      " if name not in missing else "  MISSING ") + name)

if missing:
    sys.exit(f"\n{len(missing)} package(s) did not resolve.")

print("\nRestore resolved every package, transitive dependency included.")
PY
