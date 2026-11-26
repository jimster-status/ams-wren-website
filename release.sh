#!/usr/bin/env bash
# Build a release of the AMS Wren Website plugin and point update.json at it.
# Usage: ./release.sh "One-line summary of what changed"
# WordPress sites with the plugin then show "There is a new version ... update now".
set -euo pipefail
cd "$(dirname "$0")"
VERSION=$(sed -n "s/^ \* Version: //p" ams-wren-website/ams-wren-website.php)
CONST=$(sed -n "s/^const AMS_WREN_WEBSITE_VERSION = '\(.*\)';/\1/p" ams-wren-website/ams-wren-website.php)
[ "$VERSION" = "$CONST" ] || { echo "Header version $VERSION and constant $CONST differ"; exit 1; }
php -l ams-wren-website/ams-wren-website.php >/dev/null
ZIP="releases/ams-wren-website-$VERSION.zip"
[ -e "$ZIP" ] && { echo "$ZIP already exists; bump the version first"; exit 1; }
python3 - "$ZIP" <<'PY'
import sys, zipfile, os
with zipfile.ZipFile(sys.argv[1], 'w', zipfile.ZIP_DEFLATED) as z:
    for root, _, files in os.walk('ams-wren-website'):
        for f in sorted(files):
            z.write(os.path.join(root, f))
PY
python3 - "$VERSION" "$ZIP" "${1:-Update}" <<'PY'
import sys, json, hashlib, html, datetime, os
version, zip_path, note = sys.argv[1:4]
old = json.load(open('update.json')) if os.path.exists('update.json') else {}
log = [{'version': version, 'date': datetime.date.today().isoformat(), 'note': note}] + old.get('changelog', [])
data = {
  'version': version,
  'download_url': f'https://github.com/jimster-status/ams-wren-website/raw/main/{zip_path}',
  'sha256': hashlib.sha256(open(zip_path, 'rb').read()).hexdigest(),
  'released': datetime.date.today().isoformat(),
  'requires': '6.0', 'requires_php': '7.4', 'tested': '6.9',
  'changelog': log[:20],
  'changelog_html': ''.join(f"<h4>{html.escape(e['version'])} ({e['date']})</h4><p>{html.escape(e['note'])}</p>" for e in log[:20]),
}
json.dump(data, open('update.json', 'w'), indent=2)
print('released', version, '->', data['download_url'])
PY
