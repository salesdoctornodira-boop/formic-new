#!/usr/bin/env bash
# Инфографик PDF-ҳисоботни қайта ишлаб чиқаради. Live data/ccs.db'га ТЕГМАЙДИ —
# фақат вақтинчалик нусхадан ўқийди. Хеч нарса DB'га ёзилмайди/commit қилинмайди.
#
# Ишлатиш:   bash reports/make_report.sh
# Талаблар:  python3, Google Chrome (HTML→PDF учун).
set -euo pipefail
cd "$(dirname "$0")/.."

CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

BEFORE="$(shasum -a 256 data/ccs.db | awk '{print $1}')"
cp data/ccs.db "$TMP/ccs.db"                       # read-only снимок

python3 reports/analyze.py "$TMP/ccs.db" "$TMP/data.json"
PERIOD="$(python3 -c "import json;print(json.load(open('$TMP/data.json'))['period'] or 'report')")"
GEN="$(date +%d.%m.%Y)"
OUT="reports/CCS_Analytics_${PERIOD}"

python3 reports/generate_report.py "$TMP/data.json" "${OUT}.html" "$GEN"
"$CHROME" --headless=new --disable-gpu --no-pdf-header-footer \
  --print-to-pdf="${OUT}.pdf" "file://$(pwd)/${OUT}.html" 2>/dev/null

AFTER="$(shasum -a 256 data/ccs.db | awk '{print $1}')"
[ "$BEFORE" = "$AFTER" ] || { echo "!! ОГОҲ: data/ccs.db checksum ўзгарди — тўхтатилди"; exit 1; }

echo "✓ Тайёр: ${OUT}.pdf  (ва ${OUT}.html)"
echo "✓ data/ccs.db ўзгармаган (checksum: ${AFTER})"
