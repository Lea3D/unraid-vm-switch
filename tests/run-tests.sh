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

echo "== Plugin-Datei und Vorlage (XML)"
xmllint --noout vmswitch.plg plugin/vmswitch.plg.in

echo "== Seiten-Kopfzeilen (Unraid-Konvention: Menu= zuerst, Title=, Trennzeile ---)"
for f in source/usr/local/emhttp/plugins/vmswitch/*.page; do
  head -1 "$f" | grep -q '^Menu="' || { echo "$f: erste Zeile muss Menu=... sein" >&2; exit 1; }
  grep -q '^Title="' "$f" || { echo "$f: Title fehlt" >&2; exit 1; }
  sed -n '2,5p' "$f" | grep -q '^---$' || sed -n '1,6p' "$f" | grep -q '^---$' || { echo "$f: Trennzeile --- fehlt" >&2; exit 1; }
  grep -q '^Markdown=' "$f" && { echo "$f: Markdown= nicht verwenden (ungetestet auf Unraid)" >&2; exit 1; }
done
for i in images icons; do
  test -f "source/usr/local/emhttp/plugins/vmswitch/$i/vmswitch.png" || { echo "Icon $i/vmswitch.png fehlt" >&2; exit 1; }
done
for f in source/usr/local/emhttp/plugins/vmswitch/vmswitch-*.page; do
  head -1 "$f" | grep -qE '^Menu="vmswitch:[0-9]+"$' || { echo "$f: Unterseite braucht Menu=\"vmswitch:<Nummer>\"" >&2; exit 1; }
done

echo "== PHP-Teil der Seiten (Syntax)"
for f in source/usr/local/emhttp/plugins/vmswitch/*.page; do
  sed '1,/^---$/d' "$f" > /tmp/page-body.php
  php -d short_open_tag=1 -l /tmp/page-body.php >/dev/null || { echo "$f: PHP-Syntaxfehler im Seitenkörper" >&2; exit 1; }
done
rm -f /tmp/page-body.php

echo "== Plugin-Vorlage (Konventionen laut Unraid-Plugin-Doku)"
T=plugin/vmswitch.plg.in
grep -q 'icon="' "$T" || { echo "Vorlage: icon-Attribut fehlt (Logo in der Plugin-Liste)" >&2; exit 1; }
grep -q 'upgradepkg --install-new --reinstall' "$T" || { echo "Vorlage: Installation muss als eigener Skriptblock mit --reinstall laufen" >&2; exit 1; }
grep -E '<FILE Name=.*Run=' "$T" && { echo "Vorlage: FILE-Block mit Run= wird übersprungen, wenn das Paket schon auf dem Stick liegt" >&2; exit 1; }
for f in "$T" vmswitch.plg; do
  if grep -q $'\r' "$f"; then echo "$f enthält CRLF" >&2; exit 1; fi
done

echo "== Workflow (YAML)"
python3 -c 'import yaml; yaml.safe_load(open(".github/workflows/release.yml"))' || { echo "PyYAML fehlt oder Workflow ungültig (apt install python3-yaml)" >&2; exit 1; }

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
