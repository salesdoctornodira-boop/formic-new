#!/usr/bin/env bash
# Строит инфографический PDF-отчёт из РЕАЛЬНОЙ де-анонимизированной выгрузки опроса (.xlsx, export deanon=1).
# ВАЖНО: результат содержит реальные имена/комментарии/оценки руководителей — конфиденциально, НЕ коммитить в git.
#
# Использование:  bash reports/make_real_report.sh "/путь/к/CCS_Evaluations_YYYY-MM-DD.xlsx"
# Требуется:      python3, Google Chrome.
set -euo pipefail
cd "$(dirname "$0")/.."
XLSX="${1:-$HOME/Downloads/CCS_Evaluations_2026-07-02.xlsx}"
[ -f "$XLSX" ] || { echo "Не найден xlsx: $XLSX"; exit 1; }

CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

python3 reports/xlsx_to_json.py "$XLSX" "$TMP/recs.json"
python3 reports/analyze_real.py "$TMP/recs.json" "$TMP/data.json"
PERIOD="$(python3 -c "import json;print(json.load(open('$TMP/data.json'))['period'] or 'report')")"
GEN="$(date +%d.%m.%Y)"
OUT="reports/CCS_Analytics_REAL_${PERIOD}"

python3 reports/generate_real.py "$TMP/data.json" "${OUT}.html" "$GEN"
"$CHROME" --headless=new --disable-gpu --no-pdf-header-footer \
  --print-to-pdf="${OUT}.pdf" "file://$(pwd)/${OUT}.html" 2>/dev/null

echo "✓ Готово: ${OUT}.pdf"
echo "⚠  Реальные данные — не коммитить в git (защищено reports/.gitignore)."
