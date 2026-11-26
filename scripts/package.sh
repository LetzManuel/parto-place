#!/usr/bin/env bash
# Builds release/parto-place.zip: the PHP server with the built app in public/.
set -euo pipefail
cd "$(dirname "$0")/.."
npm run build
rm -rf release && mkdir -p release/place
cp -r server/. release/place/
rm -rf release/place/tests release/place/config.php release/place/db.sqlite
cp -r dist/. release/place/public/
(cd release && zip -qr parto-place.zip place)
echo "release/parto-place.zip"
