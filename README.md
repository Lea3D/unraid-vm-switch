# VM Switch für Unraid

Wechselt per Knopfdruck zwischen VMs mit durchgereichter Hardware (GPU, USB-Controller), ohne Monitor und ohne manuelles Herunterfahren. Gedacht für headless Systeme.

## Funktionen

- **Wechsel-Seite:** Karten pro VM mit Status und durchgereichten Geräten (GPU, USB-Controller, USB-Geräte), Ablaufanzeige mit Countdown
- **Wartezeit vor dem Start** (Standard, pro VM, pro Wechsel 0/10/30/60 s) für das PCI-Handling
- **Fallback:** Startet die Ziel-VM nicht oder stürzt sie in der Prüfphase ab, wird sie aufgeräumt und die vorherige VM startet wieder
- **Vorabprüfung:** Fehlt ein PCI-Gerät der Ziel-VM im System, wird nichts heruntergefahren
- **Nie zwei VMs auf derselben Festplatte:** VMs, die sich eine vdisk teilen (auch unter verschiedenen Pfaden wie `/mnt/user` und `/mnt/cache`), laufen nie gleichzeitig. Das Plugin stoppt die eine vor dem Start der anderen und startet im Fallback nie eine VM, deren Platte noch von einer laufenden VM benutzt wird
- **Klartext bei jedem Schutz:** Jede Ablehnung nennt den Grund, die Folge und was du tun kannst
- **Nur stoppen, was nötig ist:** Standardmäßig werden nur laufende VMs gestoppt, die ein Gerät (PCI oder USB) mit der Ziel-VM teilen. Alternativ „alle stoppen“. Einzelne VMs lassen sich mit „Beim Wechseln nie stoppen“ schützen
- **Sauberes Herunterfahren** per ACPI, danach optional hartes Beenden (`destroy`)
- **Stabilitätsprüfung** und optional Warten auf den QEMU-Guest-Agent
- **Sperre** gegen parallele Wechsel, **Abbrechen** während der Wartezeit
- **Docker-Seite:** Container pro VM-Profil stoppen und beim Wechsel wieder starten
- **Einstellungen**, **Log**, Unraid-Benachrichtigungen

## Installation

1. Den Inhalt dieses Ordners ins GitHub-Repository pushen (Branch `main`).
2. Der Workflow **Test und Release** läuft bei jedem Push, der Plugin-Code, `build.sh`, Tests oder den Workflow ändert. Er testet, baut das `.txz`, trägt Version, SHA256 und Repository in `vmswitch.plg` ein (die wird bei jedem Release frisch aus der Vorlage `plugin/vmswitch.plg.in` erzeugt), committet die `.plg` zurück und erstellt das Release. Die Version ist das heutige Datum, bei mehreren Releases am selben Tag kommt ein Buchstabe dazu (`2026.11.15`, `2026.11.15a`, ...).
3. Vor dem nächsten Push einmal `git pull` ausführen, weil der Workflow die `.plg` aktualisiert hat. Die Struktur der Plugin-Datei änderst du nur in `plugin/vmswitch.plg.in`, nie in `vmswitch.plg`.
4. In Unraid unter **Plugins → Install Plugin** diese URL eintragen:
   `https://raw.githubusercontent.com/Lea3D/unraid-vm-switch/refs/heads/main/vmswitch.plg`

Falls der Schritt „Plugin-Datei zurück ins Repository schreiben" mit einem Berechtigungsfehler endet: Repository → Settings → Actions → General → Workflow permissions auf **Read and write permissions** stellen.

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
vmswitch.plg                      erzeugte Plugin-Datei (Installation über URL), nicht von Hand ändern
plugin/vmswitch.plg.in            Vorlage der Plugin-Datei
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
