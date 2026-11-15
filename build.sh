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

PKG="${NAME}-${VERSION}-x86_64-1"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p dist

cp -a source/. "$STAGE/"
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +
chmod 755 "$STAGE/usr/local/emhttp/plugins/${NAME}/scripts/switch.php"

mkdir -p "$STAGE/install"
cat > "$STAGE/install/slack-desc" <<DESC
${NAME}: ${NAME} (VM-Wechsel für Unraid)
${NAME}:
${NAME}: Wechselt auf Knopfdruck zwischen VMs mit durchgereichter Hardware.
${NAME}: Mit Wartezeit für das PCI-Handling, Fallback auf die vorherige VM bei
${NAME}: Fehlstart, Docker-Profilen pro VM und Log.
${NAME}:
${NAME}:
${NAME}:
${NAME}:
${NAME}:
${NAME}:
DESC

rm -f "dist/${PKG}.txz" "dist/${PKG}.txz.sha256"
tar --owner=0 --group=0 --numeric-owner --sort=name -C "$STAGE" -cJf "dist/${PKG}.txz" .
SHA="$(sha256sum "dist/${PKG}.txz" | cut -d' ' -f1)"
echo "$SHA  ${PKG}.txz" > "dist/${PKG}.txz.sha256"

sed -i -E \
  -e "s|(<!ENTITY version +\")[^\"]*(\">)|\1${VERSION}\2|" \
  -e "s|(<!ENTITY sha256 +\")[^\"]*(\">)|\1${SHA}\2|" vmswitch.plg
if [[ -n "${GITHUB_REPOSITORY:-}" ]]; then
  OWNER="${GITHUB_REPOSITORY%%/*}"
  sed -i -E \
    -e "s|(<!ENTITY github +\")[^\"]*(\">)|\1${GITHUB_REPOSITORY}\2|" \
    -e "s|(<!ENTITY author +\")[^\"]*(\">)|\1${OWNER}\2|" vmswitch.plg
fi

echo "Paket:    dist/${PKG}.txz"
echo "SHA256:   ${SHA}"
