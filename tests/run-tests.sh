#!/usr/bin/env bash
# Führt alle Prüfungen aus: Syntax, shellcheck, XML, YAML, Worker-, API- und UI-Tests.
set -euo pipefail
cd "$(dirname "$0")/.."

# Ausführungsrechte können beim Hochladen über die GitHub-Weboberfläche verloren gehen
chmod +x tests/mock/virsh tests/mock/docker build.sh tests/run-tests.sh

echo "== PHP-Syntax"
find source tests -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
for f in tests/mock/virsh tests/mock/docker; do php -l "$f" >/dev/null; done

echo "== JavaScript-Syntax"
node --check source/usr/local/emhttp/plugins/vmswitch/js/vmswitch.js

echo "== shellcheck"
shellcheck build.sh tests/run-tests.sh

echo "== Plugin-Datei (XML)"
xmllint --noout vmswitch.plg

echo "== Workflow (YAML)"
python3 -c 'import yaml; yaml.safe_load(open(".github/workflows/release.yml"))'

echo "== Worker-Tests"
php tests/test_switch.php | tail -1

echo "== API-Tests"
php tests/test_api.php | tail -1

echo "== UI-Tests"
if node -e "require('jsdom')" 2>/dev/null; then
  node tests/ui_smoke.js | tail -1
else
  echo "jsdom nicht installiert, UI-Test übersprungen (npm install jsdom)"
fi
echo "Alle Prüfungen bestanden."
