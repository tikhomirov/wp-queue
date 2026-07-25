#!/usr/bin/env bash
#
# Build a WordPress.org-ready zip archive for the WP Queue plugin.
#
# Usage:
#   cd wp-content/plugins/wp-queue
#   ./build-plugin.sh
#
# The archive is written to build/wp-queue.<version>.zip
# and contains only the files required for publishing/installing the plugin.
#

set -euo pipefail

# Ensure the script is run from the plugin root.
if [[ ! -f "wp-queue.php" ]]; then
    echo "Error: wp-queue.php not found. Please run this script from the plugin root directory." >&2
    exit 1
fi

PLUGIN_SLUG="wp-queue"
VERSION=$(grep -m1 "Version:" wp-queue.php | sed -E 's/^[^:]*Version:[[:space:]]*//')
BUILD_DIR="build"
ZIP_NAME="${PLUGIN_SLUG}.${VERSION}.zip"
ZIP_PATH="${BUILD_DIR}/${ZIP_NAME}"

if [[ -z "${VERSION}" ]]; then
    echo "Error: could not detect plugin version from wp-queue.php" >&2
    exit 1
fi

TMP_DIR=$(mktemp -d)
STAGING_DIR="${TMP_DIR}/${PLUGIN_SLUG}"
mkdir -p "${STAGING_DIR}"

echo "Building ${ZIP_NAME}..."

# Required plugin files.
cp wp-queue.php readme.txt LICENSE "${STAGING_DIR}/"

# Source code.
cp -r src "${STAGING_DIR}/"

# Runtime admin assets only (CSS/JS). Images are excluded because they are
# either WordPress.org promo assets or unused in the admin UI.
mkdir -p "${STAGING_DIR}/assets"
cp -r assets/css assets/js "${STAGING_DIR}/assets/"

# Translations: include compiled and source files, remove editor backups.
cp -r languages "${STAGING_DIR}/"
find "${STAGING_DIR}/languages" -type f \( \
    -name '*backup*' \
    -o -name '*.po~' \
    -o -name '*.pot~' \
    -o -name '*.mo~' \
\) -delete

# Verify the main plugin file exists in the staging directory.
if [[ ! -f "${STAGING_DIR}/wp-queue.php" ]]; then
    echo "Error: staged plugin file is missing." >&2
    rm -rf "${TMP_DIR}"
    exit 1
fi

# Prepare build output directory.
mkdir -p "${BUILD_DIR}"
rm -f "${ZIP_PATH}"

# Create the zip with the plugin slug as the top-level directory.
(
    cd "${TMP_DIR}"
    zip -r "${OLDPWD}/${ZIP_PATH}" "${PLUGIN_SLUG}" -q
)

# Clean up staging directory.
rm -rf "${TMP_DIR}"

echo "Archive created: ${ZIP_PATH}"
echo ""
echo "Contents:"
unzip -l "${ZIP_PATH}"
