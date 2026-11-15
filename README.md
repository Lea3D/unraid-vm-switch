# VM Switch für Unraid

Wechselt per Knopfdruck zwischen VMs mit durchgereichter Hardware (GPU, USB-Controller), ohne Monitor und ohne manuelles Herunterfahren. Gedacht für headless Systeme.

## Funktionen

- **Wechsel-Seite:** Karten pro VM mit Status und durchgereichten Geräten (GPU, USB-Controller, USB-Geräte), Ablaufanzeige mit Countdown
- **Wartezeit vor dem Start** (Standard, pro VM, pro Wechsel 0/10/30/60 s) für das PCI-Handling
- **Fallback:** Startet die Ziel-VM nicht oder stürzt sie in der Prüfphase ab, wird sie aufgeräumt und die vorherige VM startet wieder
- **Vorabprüfung:** Fehlt ein PCI-Gerät der Ziel-VM im System, wird nichts heruntergefahren
- **Sauberes Herunterfahren** per ACPI, danach optional hartes Beenden (`destroy`)
- **Stabilitätsprüfung** und optional Warten auf den QEMU-Guest-Agent
- **Sperre** gegen parallele Wechsel, **Abbrechen** während der Wartezeit
- **Docker-Seite:** Container pro VM-Profil stoppen und beim Wechsel wieder starten
- **Einstellungen**, **Log**, Unraid-Benachrichtigungen

## Installation

1. Repository auf GitHub anlegen und den Inhalt dieses Ordners hochladen.
2. In `vmswitch.plg` die Platzhalter `DEIN-GITHUB-NAME` ersetzen (der Release-Workflow tut das beim ersten Lauf automatisch).
3. Unter **Actions → Test und Release → Run workflow** eine Version eingeben (z. B. `2026.11.14`) oder einen Tag mit dieser Version pushen. Der Workflow testet, baut das `.txz`, trägt Version und SHA256 in die `.plg` ein und erstellt das Release.
4. In Unraid unter **Plugins → Install Plugin** diese URL eintragen:
   `https://raw.githubusercontent.com/DEIN-GITHUB-NAME/vmswitch/main/vmswitch.plg`

Die Seite erscheint unter **Utilities → VM Switch**.

## Tests

```
./tests/run-tests.sh
```

Benötigt `php-cli`, `php-xml`, `shellcheck`, `libxml2-utils`, `node` und für den UI-Test `npm install jsdom`. Die Tests laufen gegen ein simuliertes `virsh` und `docker`; sie prüfen Erfolg, Fehlstart, Absturz, hängendes Herunterfahren, fehlende PCI-Geräte, Abbruch, Sperre, manipulierte Eingaben, CSRF und die Oberfläche.

## Checkliste auf echter Hardware

Das Zusammenspiel mit echtem PCI-Passthrough lässt sich nur auf deinem Server prüfen:

1. Wechsel A → B mit 10 s Wartezeit: GPU-Ausgabe von B kommt, Log zeigt alle Schritte.
2. Wechsel zurück B → A.
3. Wartezeit 0 s und 30 s ausprobieren, falls die GPU nach dem Herunterfahren nicht sauber zurückkommt.
4. Fallback provozieren: Ziel-VM kurz absichtlich kaputt konfigurieren (z. B. nicht existierende ISO als Boot-CD, oder Gerät einer laufenden anderen Instanz zuweisen) und prüfen, dass die vorherige VM wieder läuft.
5. Während der Wartezeit auf **Abbrechen** klicken: vorherige VM muss wieder starten.
6. Wechsel mit zwei aktiven VMs.
7. Seite in zwei Browser-Tabs öffnen und beide Wechsel gleichzeitig starten: der zweite wird abgelehnt.
8. Docker-Profil: GPU-Container für eine VM anhaken, wechseln, Container muss gestoppt sein, beim Zurückwechseln wieder laufen.

## Sicherheit

- Der Endpunkt `include/api.php` ist nur im angemeldeten WebGUI erreichbar, akzeptiert nur POST und prüft bei jeder Anfrage das CSRF-Token.
- VM- und Containernamen werden gegen die echten Listen von libvirt und Docker geprüft. Programme laufen ohne Shell (Array-Aufruf), VM-Namen werden als `--domain`-Wert übergeben, nie als Option.
- Eingaben aus der Oberfläche werden begrenzt und bereinigt, die Einstellungsdatei hat Rechte 0600.
- Die Oberfläche setzt Server-Texte nur über `textContent` ein (kein HTML aus VM-Namen).
- Es gibt keine öffentliche API und keine externen Ressourcen (keine Webfonts, keine CDNs).
- Das Plugin läuft als root. Setze Unraid nicht ohne VPN oder Reverse-Proxy mit Authentifizierung ins Internet.
- Im Release-Workflow sind die Berechtigungen minimal gesetzt; Eingaben fließen nur über Umgebungsvariablen in Skripte.

## Hinweis zu eigenen User Scripts

Falls du bereits ein User Script nutzt, das Container abhängig von laufenden VMs stoppt und startet, sollte es nicht dieselben Container wie die Docker-Profile hier verwalten, sonst arbeiten beide gegeneinander.

## Dateien

```
vmswitch.plg                      Plugin-Definition (Installation über URL)
build.sh                          baut das .txz und aktualisiert die .plg
source/usr/local/emhttp/plugins/vmswitch/
  *.page                          Seiten (Wechsel, Docker, Einstellungen, Log)
  include/lib.php, api.php        Bibliothek und AJAX-Backend
  scripts/switch.php              Worker für den Wechsel
  css/, js/, images/              Oberfläche
tests/                            Tests mit simuliertem virsh/docker
.github/workflows/release.yml     Test und Release
```

Einstellungen liegen in `/boot/config/plugins/vmswitch/settings.json`, Laufzeitdaten und Log in `/var/tmp/vmswitch/` (RAM, nach Neustart leer).
