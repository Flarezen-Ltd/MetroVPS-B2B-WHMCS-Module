#!/usr/bin/env bash
#
# Build a release ZIP for the MetroVPS B2B WHMCS module.
#
# Stages the runtime files with their WHMCS-root-relative install paths
# (modules/servers/MetroVPSB2B + includes/hooks/metrovpsb2b_cart.php) and
# produces MetroVPSB2B-vX.Y.Z.zip next to this script.
# The version is read from whmcs.json.

set -euo pipefail

MODULE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WHMCS_ROOT="$(cd "$MODULE_DIR/../../.." && pwd)"
MODULE_NAME="$(basename "$MODULE_DIR")"

HOOK_FILE="$WHMCS_ROOT/includes/hooks/metrovpsb2b_cart.php"

[ -f "$HOOK_FILE" ] || { echo "ERROR: hook file not found: $HOOK_FILE" >&2; exit 1; }

VERSION="$(php -r '$m = json_decode(file_get_contents($argv[1]), true); echo $m["version"] ?? "";' "$MODULE_DIR/whmcs.json")"
[ -n "$VERSION" ] || { echo "ERROR: could not read version from whmcs.json" >&2; exit 1; }

ARTIFACT="$MODULE_DIR/$MODULE_NAME-v$VERSION.zip"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/modules/servers"
mkdir -p "$STAGE/includes/hooks"

# 1. The module directory, minus build artifacts.
cp -a "$MODULE_DIR" "$STAGE/modules/servers/$MODULE_NAME"
rm -rf "$STAGE/modules/servers/$MODULE_NAME/.claude"
rm -f  "$STAGE/modules/servers/$MODULE_NAME/build-release.sh"
find  "$STAGE/modules/servers/$MODULE_NAME" -maxdepth 1 -name '*.zip' -delete

# 2. The hooks files (cart/product details + billing hooks).
for hook in metrovpsb2b_cart.php metrovpsb2b_billing.php; do
    [ -f "$WHMCS_ROOT/includes/hooks/$hook" ] || { echo "ERROR: missing hook: $hook" >&2; exit 1; }
    cp -a "$WHMCS_ROOT/includes/hooks/$hook" "$STAGE/includes/hooks/"
done

# 3. Package with install paths starting at modules/ and includes/.
rm -f "$ARTIFACT"
(
    cd "$STAGE"
    zip -q -r "$ARTIFACT" modules includes
)

echo "Built: $ARTIFACT"
echo
unzip -l "$ARTIFACT" | tail -n +4 | sed 's/^ *[0-9]* *[0-9-]* *[0-9:]* *//'
echo
echo "Total files: $(unzip -Z1 "$ARTIFACT" | wc -l)"