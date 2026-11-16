#!/usr/bin/env bash
# Baut das Slackware-Paket (.txz), berechnet die Prüfsumme und trägt Version, Hash und
# Repository in vmswitch.plg ein.
# Aufruf: ./build.sh 2026.11.14        (optional: GITHUB_REPOSITORY=owner/repo)
set -euo pipefail
cd "$(dirname "$0")"

NAME=vmswitch
VERSION="${1:?Bitte eine Version angeben, z. B. ./build.sh 2026.11.14}"
if [[ ! "$VERSION" =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$ ]]; then
  echo "Ungültige Version '$VERSION' (erwartet JJJJ.MM.TT, optional mit Buchstabe)" >&2
  exit 1
fi

if [[ ! -d "source/usr/local/emhttp/plugins/${NAME}" ]]; then
  echo "Ordner source/usr/local/emhttp/plugins/${NAME} fehlt. Wurde er umbenannt?" >&2
  exit 1
fi

PKG="${NAME}-${VERSION}-x86_64-1"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p dist

cp -a source/. "$STAGE/"
# Windows-Zeilenenden (CRLF) würden Seitenköpfe und Skripte zerstören, daher immer auf LF normalisieren
find "$STAGE" -type f \( -name '*.page' -o -name '*.php' -o -name '*.js' -o -name '*.css' -o -name '*.sh' -o -name '*.md' \) -exec sed -i 's/\r$//' {} +
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +
chmod 755 "$STAGE/usr/local/emhttp/plugins/${NAME}/scripts/switch.php"

mkdir -p "$STAGE/install"
{
  echo "       |-----handy-ruler------------------------------------------------------|"
  echo "${NAME}: ${NAME} (VM-Wechsel fuer Unraid)"
  echo "${NAME}:"
  echo "${NAME}: Wechselt auf Knopfdruck zwischen VMs mit durchgereichter Hardware."
  echo "${NAME}: Mit Wartezeit fuer das PCI-Handling, Fallback auf die vorherige VM"
  echo "${NAME}: bei Fehlstart, Docker-Profilen pro VM und Log."
  for _ in 1 2 3 4 5 6; do echo "${NAME}:"; done
} > "$STAGE/install/slack-desc"


rm -f "dist/${PKG}.txz" "dist/${PKG}.txz.sha256"
tar --owner=0 --group=0 --numeric-owner --sort=name -C "$STAGE" -cJf "dist/${PKG}.txz" .
SHA="$(sha256sum "dist/${PKG}.txz" | cut -d' ' -f1)"
echo "$SHA  ${PKG}.txz" > "dist/${PKG}.txz.sha256"

# Name, Version und Hash kommen immer aus diesem Skript, damit .plg und Paket nie auseinanderlaufen
sed -i -E \
  -e "s|(<!ENTITY name +\")[^\"]*(\">)|\1${NAME}\2|" \
  -e "s|(<!ENTITY version +\")[^\"]*(\">)|\1${VERSION}\2|" \
  -e "s|(<!ENTITY sha256 +\")[^\"]*(\">)|\1${SHA}\2|" vmswitch.plg
# Überschrift im Änderungsprotokoll auf die neue Version setzen
sed -i -E "0,/^###[0-9][0-9.a-z]*/s//###${VERSION}/" vmswitch.plg
if [[ -n "${GITHUB_REPOSITORY:-}" ]]; then
  OWNER="${GITHUB_REPOSITORY%%/*}"
  sed -i -E \
    -e "s|(<!ENTITY github +\")[^\"]*(\">)|\1${GITHUB_REPOSITORY}\2|" \
    -e "s|(<!ENTITY author +\")[^\"]*(\">)|\1${OWNER}\2|" vmswitch.plg
fi

echo "Paket:    dist/${PKG}.txz"
echo "SHA256:   ${SHA}"
