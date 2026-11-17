<?php
declare(strict_types=1);

/*
 * VM Switch – gemeinsame Funktionen für die Oberfläche (api.php) und den Worker (switch.php).
 *
 * Grundregeln:
 *  - Externe Programme (virsh, docker, lspci) werden ausschließlich im Array-Format über
 *    proc_open gestartet, nie über eine Shell. Dadurch ist Command Injection ausgeschlossen.
 *  - VM- und Containernamen werden gegen die echten Listen von libvirt/Docker geprüft.
 *  - Alle Pfade und Programme lassen sich per Umgebungsvariable ersetzen (für die Tests).
 */

function vms_env(string $k, string $d): string
{
    $v = getenv($k);
    return ($v === false || $v === '') ? $d : $v;
}

function vms_cfg_dir(): string  { return vms_env('VMSWITCH_CFG_DIR', '/boot/config/plugins/vmswitch'); }
function vms_run_dir(): string  { return vms_env('VMSWITCH_RUN_DIR', '/var/tmp/vmswitch'); }
function vms_virsh_bin(): string  { return vms_env('VMSWITCH_VIRSH', 'virsh'); }
function vms_docker_bin(): string { return vms_env('VMSWITCH_DOCKER', 'docker'); }
function vms_sysfs(): string    { return vms_env('VMSWITCH_SYSFS', '/sys'); }
function vms_qemu_log_dir(): string { return vms_env('VMSWITCH_QEMULOG_DIR', '/var/log/libvirt/qemu'); }

function vms_ensure_run_dir(): void
{
    $d = vms_run_dir();
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
}

/* Wartet $s Sekunden. VMSWITCH_SPEED skaliert die Zeit (nur für Tests). */
function vms_sleep(float $s): void
{
    $us = (int)($s * 1000000 * (float)vms_env('VMSWITCH_SPEED', '1'));
    if ($us > 0) {
        usleep($us);
    }
}

/* ---------- Programme ausführen ---------- */

function vms_exec(array $cmd, int $timeout = 30): array
{
    $p = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        return ['code' => 127, 'out' => '', 'err' => 'Befehl konnte nicht gestartet werden'];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $code = -1;
    $deadline = microtime(true) + $timeout;
    while (true) {
        $r = [$pipes[1], $pipes[2]];
        $w = null;
        $e = null;
        $got = false;
        if (@stream_select($r, $w, $e, 0, 100000) > 0) {
            foreach ($r as $s) {
                $c = fread($s, 8192);
                if ($c !== false && $c !== '') {
                    $got = true;
                    if ($s === $pipes[1]) {
                        $out .= $c;
                    } else {
                        $err .= $c;
                    }
                }
            }
            if (!$got) {
                usleep(20000);
            }
        }
        $st = proc_get_status($p);
        if (!$st['running']) {
            $code = (int)$st['exitcode'];
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($p, 9);
            $code = 124;
            $err .= "Zeitüberschreitung\n";
            break;
        }
    }
    $out .= (string)stream_get_contents($pipes[1]);
    $err .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    if ($code === -1) {
        $code = $rc;
    }
    return ['code' => $code, 'out' => $out, 'err' => $err];
}

function vms_virsh(array $args, int $timeout = 30): array
{
    return vms_exec(array_merge([vms_virsh_bin()], $args), $timeout);
}

function vms_first_line(string $s): string
{
    $s = trim($s);
    $p = strpos($s, "\n");
    return $p === false ? $s : trim(substr($s, 0, $p));
}

/* ---------- VMs ---------- */

/* Liefert alle VM-Namen oder null, wenn libvirt nicht erreichbar ist. */
function vms_vm_names(): ?array
{
    $r = vms_virsh(['list', '--all', '--name'], 15);
    if ($r['code'] !== 0) {
        return null;
    }
    $names = [];
    foreach (explode("\n", $r['out']) as $l) {
        $l = trim($l);
        if ($l !== '') {
            $names[] = $l;
        }
    }
    return $names;
}

function vms_state(string $vm): string
{
    $r = vms_virsh(['domstate', '--domain', $vm], 10);
    if ($r['code'] !== 0) {
        return 'unknown';
    }
    return strtolower(vms_first_line($r['out']));
}

/* PCI-Informationen aus sysfs (und optional lspci). */
function vms_pci_info(string $addr): array
{
    $base = vms_sysfs() . '/bus/pci/devices/' . $addr;
    $present = is_dir($base);
    $class = $present ? strtolower(trim((string)@file_get_contents($base . '/class'))) : '';
    $kind = 'PCI';
    if (str_starts_with($class, '0x03')) {
        $kind = 'GPU';
    } elseif (str_starts_with($class, '0x0c03')) {
        $kind = 'USB-Controller';
    } elseif (str_starts_with($class, '0x0403')) {
        $kind = 'Audio';
    } elseif (str_starts_with($class, '0x0108')) {
        $kind = 'NVMe';
    } elseif (str_starts_with($class, '0x02')) {
        $kind = 'Netzwerk';
    } elseif (str_starts_with($class, '0x01')) {
        $kind = 'Speicher';
    }
    $driver = ($present && is_link($base . '/driver')) ? basename((string)readlink($base . '/driver')) : '';
    $name = '';
    if ($present && preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/', $addr)) {
        $r = vms_exec(['lspci', '-mm', '-s', $addr], 5);
        if ($r['code'] === 0 && preg_match_all('/"([^"]*)"/', $r['out'], $m) && count($m[1]) >= 3) {
            $name = trim($m[1][1] . ' ' . $m[1][2]);
        }
    }
    return ['kind' => $kind, 'addr' => $addr, 'present' => $present, 'driver' => $driver, 'name' => $name];
}

/* Durchgereichte Geräte einer VM aus der Konfiguration (inaktives XML). */
function vms_hostdevs(string $vm): array
{
    static $cache = [];
    if (isset($cache[$vm])) {
        return $cache[$vm];
    }
    $cache[$vm] = vms_hostdevs_read($vm);
    return $cache[$vm];
}

function vms_hostdevs_read(string $vm): array
{
    $res = ['pci' => [], 'usb' => [], 'error' => ''];
    $r = vms_virsh(['dumpxml', '--inactive', '--domain', $vm], 15);
    if ($r['code'] !== 0) {
        $res['error'] = vms_first_line($r['err']);
        return $res;
    }
    libxml_use_internal_errors(true);
    $x = simplexml_load_string($r['out'], 'SimpleXMLElement', LIBXML_NONET);
    if ($x === false) {
        $res['error'] = 'XML der VM nicht lesbar';
        return $res;
    }
    foreach ($x->xpath('//devices/hostdev[@type="pci"]/source/address') ?: [] as $a) {
        $res['pci'][] = sprintf(
            '%04x:%02x:%02x.%x',
            hexdec((string)$a['domain']),
            hexdec((string)$a['bus']),
            hexdec((string)$a['slot']),
            hexdec((string)$a['function'])
        );
    }
    foreach ($x->xpath('//devices/hostdev[@type="usb"]/source') ?: [] as $s) {
        if (isset($s->vendor['id'], $s->product['id'])) {
            $res['usb'][] = sprintf('%04x:%04x', hexdec((string)$s->vendor['id']), hexdec((string)$s->product['id']));
        } elseif (isset($s->address['bus'], $s->address['device'])) {
            $res['usb'][] = 'Bus ' . (int)$s->address['bus'] . ' Gerät ' . (int)$s->address['device'];
        }
    }
    $res['pci'] = array_values(array_unique($res['pci']));
    $res['usb'] = array_values(array_unique($res['usb']));
    return $res;
}

/* Hat die VM einen QEMU-Guest-Agent-Kanal in ihrer Konfiguration? */
function vms_has_agent_channel(string $vm): bool
{
    $r = vms_virsh(['dumpxml', '--inactive', '--domain', $vm], 15);
    if ($r['code'] !== 0) {
        return false;
    }
    libxml_use_internal_errors(true);
    $x = simplexml_load_string($r['out'], 'SimpleXMLElement', LIBXML_NONET);
    return $x !== false && count($x->xpath('//devices/channel/target[@name="org.qemu.guest_agent.0"]') ?: []) > 0;
}

/* ---------- Datenträger und gemeinsam genutzte Ressourcen ---------- */

/*
 * Vergleichsschlüssel für einen Datenträger-Pfad. Unraid-Freigaben sind unter mehreren Pfaden erreichbar
 * (/mnt/user/domains/x.img, /mnt/cache/domains/x.img, /mnt/disk1/domains/x.img). Alle drei meinen dieselbe Datei,
 * darum zählt der Teil ab der Freigabe. Block-Geräte und andere Pfade werden unverändert verglichen.
 */
function vms_disk_key(string $path): string
{
    $real = @realpath($path);
    $p = $real !== false ? $real : $path;
    if (preg_match('#^/mnt/[^/]+/(.+)$#', $p, $m)) {
        return 'Pfad ' . $m[1];
    }
    return 'Pfad ' . $p;
}

/*
 * Beschreibbare Datenträger einer VM aus der Konfiguration: Kennung => Pfad.
 * Schreibgeschützte und freigegebene (shareable) Datenträger sowie CD/DVD-Laufwerke werden ignoriert,
 * denn ein gemeinsam genutztes ISO-Image ist unkritisch.
 */
function vms_disks(string $vm): array
{
    static $cache = [];
    if (!isset($cache[$vm])) {
        $cache[$vm] = vms_disks_read($vm);
    }
    return $cache[$vm];
}

function vms_disks_read(string $vm): array
{
    $res = ['writable' => [], 'all' => [], 'error' => ''];
    $r = vms_virsh(['dumpxml', '--inactive', '--domain', $vm], 15);
    if ($r['code'] !== 0) {
        $res['error'] = vms_first_line($r['err']);
        return $res;
    }
    libxml_use_internal_errors(true);
    $x = simplexml_load_string($r['out'], 'SimpleXMLElement', LIBXML_NONET);
    if ($x === false) {
        $res['error'] = 'XML der VM nicht lesbar';
        return $res;
    }
    foreach ($x->xpath('//devices/disk') ?: [] as $d) {
        $type = (string)$d['type'];
        $device = (string)$d['device'];
        $path = '';
        if ($type === 'file' && isset($d->source['file'])) {
            $path = (string)$d->source['file'];
        } elseif ($type === 'block' && isset($d->source['dev'])) {
            $path = (string)$d->source['dev'];
        }
        if ($path === '') {
            continue;
        }
        $res['all'][] = $path;
        $readonly = isset($d->readonly) || isset($d->shareable) || in_array($device, ['cdrom', 'floppy'], true);
        if ($readonly) {
            continue;
        }
        $res['writable'][vms_disk_key($path)] = $path;
        $st = @stat($path);
        if ($st !== false) {
            $res['writable']['Datei ' . $st['dev'] . ':' . $st['ino']] = $path;
        }
    }
    return $res;
}

/* Eindeutige Kennungen aller durchgereichten Geräte einer VM (PCI-Adressen, USB-IDs). */
function vms_device_ids(string $vm): array
{
    $h = vms_hostdevs($vm);
    $ids = [];
    foreach ($h['pci'] as $a) {
        $ids[] = 'PCI ' . $a;
    }
    foreach ($h['usb'] as $u) {
        $ids[] = 'USB ' . $u;
    }
    return $ids;
}

/* Geräte und beschreibbare Datenträger einer VM, innerhalb eines Prozesses zwischengespeichert. */
function vms_resources(string $vm): array
{
    static $cache = [];
    if (!isset($cache[$vm])) {
        $cache[$vm] = ['devices' => vms_device_ids($vm), 'disks' => vms_disks($vm)['writable']];
    }
    return $cache[$vm];
}

/* Was teilen zwei VMs? Gibt ['devices' => [...], 'disks' => [Pfade]] zurück, leer wenn nichts. */
function vms_shared(array $a, array $b): array
{
    $devices = array_values(array_intersect($a['devices'], $b['devices']));
    $diskIds = array_intersect(array_keys($a['disks']), array_keys($b['disks']));
    $disks = [];
    foreach ($diskIds as $id) {
        $disks[$a['disks'][$id]] = true;
    }
    return ['devices' => $devices, 'disks' => array_map('strval', array_keys($disks))];
}

/* Klartext für das, was geteilt wird. */
function vms_describe_shared(array $shared): string
{
    $parts = [];
    foreach ($shared['devices'] as $d) {
        $parts[] = 'das Gerät ' . $d;
    }
    foreach ($shared['disks'] as $p) {
        $parts[] = 'die Festplatte ' . $p;
    }
    return implode(' und ', $parts);
}

/*
 * Plant einen Wechsel: Welche laufenden VMs müssen gestoppt werden, welche bleiben an?
 *  - Konflikt = die andere VM teilt ein durchgereichtes Gerät (PCI/USB) oder eine beschreibbare Festplatte
 *    mit der Ziel-VM. Zwei laufende VMs dürfen sich weder ein Gerät noch eine Festplatte teilen.
 *  - Modus 'conflict' (Standard): gestoppt wird nur bei Konflikt. Modus 'all': alle laufenden VMs.
 *  - 'keep_running' schützt eine VM in beiden Modi. Hat sie einen Konflikt, ist der Wechsel nicht möglich
 *    ('blocked'), mit Klartext-Begründung.
 * $states: VM-Name => Zustand von libvirt. $resourcesOf: Funktion VM-Name => ['devices'=>..., 'disks'=>...].
 */
function vms_plan(string $target, array $states, array $settings, ?callable $resourcesOf = null): array
{
    $resourcesOf = $resourcesOf ?? 'vms_resources';
    $tres = $resourcesOf($target);
    $plan = ['stop' => [], 'stay' => [], 'blocked' => [], 'reasons' => []];
    foreach ($states as $name => $state) {
        $name = (string)$name;
        if ($name === $target || in_array($state, ['shut off', 'unknown'], true)) {
            continue;
        }
        $keep = !empty($settings['vms'][$name]['keep_running']);
        $shared = vms_shared($tres, $resourcesOf($name));
        $conflict = $shared['devices'] || $shared['disks'];
        $what = $conflict ? vms_describe_shared($shared) : '';
        if ($keep && $conflict) {
            $plan['blocked'][] = [
                'vm'      => $name,
                'devices' => $shared['devices'],
                'disks'   => $shared['disks'],
                'text'    => "Der Wechsel zu {$target} ist nicht möglich. Grund: {$name} ist als „Beim Wechseln nie stoppen“ markiert, "
                    . "teilt aber {$what} mit {$target}. Zwei laufende VMs dürfen weder ein Gerät noch eine Festplatte gemeinsam benutzen, "
                    . "das würde zu Datenverlust oder einem Fehlstart führen. Es wurde nichts verändert. "
                    . "Entferne die Markierung bei {$name} oder wähle eine andere Ziel-VM.",
            ];
        } elseif ($keep) {
            $plan['stay'][] = $name;
        } elseif ($conflict) {
            $plan['stop'][] = $name;
            $plan['reasons'][$name] = 'teilt ' . $what . ' mit ' . $target;
        } elseif ($settings['stop_mode'] === 'all') {
            $plan['stop'][] = $name;
            $plan['reasons'][$name] = 'Einstellung „Alle laufenden VMs stoppen“';
        } else {
            $plan['stay'][] = $name;
        }
    }
    return $plan;
}

/* ---------- Docker ---------- */

function vms_valid_container_name(string $n): bool
{
    return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $n);
}

/* name => läuft (bool), oder null wenn Docker nicht erreichbar ist. */
function vms_containers(): ?array
{
    $r = vms_exec([vms_docker_bin(), 'ps', '-a', '--format', "{{.Names}}\t{{.State}}"], 15);
    if ($r['code'] !== 0) {
        return null;
    }
    $list = [];
    foreach (explode("\n", $r['out']) as $l) {
        $l = trim($l);
        if ($l === '') {
            continue;
        }
        $parts = explode("\t", $l, 2);
        if (!vms_valid_container_name($parts[0])) {
            continue;
        }
        $list[$parts[0]] = (strtolower($parts[1] ?? '') === 'running');
    }
    ksort($list);
    return $list;
}

function vms_container_op(string $name, string $op): array
{
    $map = ['start' => ['start'], 'stop' => ['stop', '-t', '30'], 'restart' => ['restart', '-t', '30']];
    if (!isset($map[$op]) || !vms_valid_container_name($name)) {
        return ['code' => 2, 'out' => '', 'err' => 'Ungültige Anfrage'];
    }
    return vms_exec(array_merge([vms_docker_bin()], $map[$op], [$name]), 90);
}

/* Container, die der Worker gestoppt hat (damit er nur diese wieder startet). */
function vms_load_stopped(): array
{
    $j = json_decode((string)@file_get_contents(vms_run_dir() . '/stopped.json'), true);
    $out = [];
    if (is_array($j)) {
        foreach ($j as $n) {
            if (is_string($n) && vms_valid_container_name($n)) {
                $out[] = $n;
            }
        }
    }
    return array_values(array_unique($out));
}

function vms_save_stopped(array $list): void
{
    vms_ensure_run_dir();
    vms_atomic_write(vms_run_dir() . '/stopped.json', json_encode(array_values(array_unique($list))), 0600);
}

/* ---------- Einstellungen ---------- */

function vms_defaults(): array
{
    return [
        'stop_mode'        => 'conflict', // 'conflict': nur VMs stoppen, die Geräte mit der Ziel-VM teilen; 'all': alle laufenden
        'wait_default'     => 10,    // Sekunden zwischen Herunterfahren und Start (PCI-Handling)
        'shutdown_timeout' => 120,   // Sekunden bis zum harten Beenden
        'start_timeout'    => 60,    // Sekunden, die "virsh start" brauchen darf
        'health_secs'      => 20,    // so lange muss die Ziel-VM stabil laufen
        'agent_wait'       => 0,     // >0: auf QEMU-Guest-Agent warten (Sekunden)
        'fallback'         => true,
        'force_destroy'    => true,
        'notify'           => true,
        'vms'              => [],    // Name => ['wait' => int|null, 'stop_containers' => string[], 'keep_running' => bool]
    ];
}

function vms_clamp($v, int $min, int $max, int $def): int
{
    if (!is_numeric($v)) {
        return $def;
    }
    return max($min, min($max, (int)$v));
}

function vms_bool($v, bool $def): bool
{
    if (is_bool($v)) {
        return $v;
    }
    $r = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $r ?? $def;
}

function vms_valid_vm_key(string $n): bool
{
    return $n !== '' && strlen($n) <= 128 && !preg_match('/[\x00-\x1f\x7f]/', $n);
}

/*
 * Prüft Eingaben und legt sie über $base. VM- und Containernamen werden gegen die
 * übergebenen Listen geprüft (null = nicht prüfen, z. B. beim Laden von der Platte).
 */
function vms_sanitize(array $in, array $base, ?array $vmNames, ?array $containers, ?array &$notes = null): array
{
    $o = $base;
    $labels = [
        'wait_default' => 'Standard-Wartezeit', 'shutdown_timeout' => 'Zeit zum Herunterfahren', 'start_timeout' => 'Zeit für den Start',
        'health_secs' => 'Stabilitätsprüfung', 'agent_wait' => 'Wartezeit auf den Guest-Agent',
    ];
    $ints = [
        'wait_default'     => [0, 300],
        'shutdown_timeout' => [10, 600],
        'start_timeout'    => [10, 300],
        'health_secs'      => [0, 120],
        'agent_wait'       => [0, 300],
    ];
    foreach ($ints as $k => [$min, $max]) {
        if (array_key_exists($k, $in)) {
            $o[$k] = vms_clamp($in[$k], $min, $max, (int)$base[$k]);
            if ($notes !== null) {
                if (!is_numeric($in[$k])) {
                    $notes[] = "{$labels[$k]}: Der Wert ist keine Zahl, der bisherige Wert {$o[$k]} bleibt erhalten.";
                } elseif ((int)$in[$k] !== $o[$k]) {
                    $notes[] = "{$labels[$k]} wurde von " . (int)$in[$k] . " auf {$o[$k]} begrenzt (erlaubt: {$min} bis {$max} Sekunden).";
                }
            }
        }
    }
    if (isset($in['stop_mode'])) {
        if (in_array($in['stop_mode'], ['conflict', 'all'], true)) {
            $o['stop_mode'] = $in['stop_mode'];
        } elseif ($notes !== null) {
            $notes[] = 'Der Modus für das Stoppen von VMs ist ungültig und wurde nicht geändert.';
        }
    }
    foreach (['fallback', 'force_destroy', 'notify'] as $k) {
        if (array_key_exists($k, $in)) {
            $o[$k] = vms_bool($in[$k], (bool)$base[$k]);
        }
    }
    if (isset($in['vms']) && is_array($in['vms'])) {
        foreach ($in['vms'] as $name => $cfg) {
            $name = (string)$name;
            if (!is_array($cfg) || !vms_valid_vm_key($name)) {
                continue;
            }
            if ($vmNames !== null && !in_array($name, $vmNames, true)) {
                if ($notes !== null) {
                    $notes[] = "Die VM „{$name}“ gibt es nicht (mehr). Ihre Einstellungen wurden nicht gespeichert.";
                }
                continue;
            }
            $cur = $o['vms'][$name] ?? ['wait' => null, 'stop_containers' => [], 'keep_running' => false];
            $cur += ['keep_running' => false];
            if (array_key_exists('keep_running', $cfg)) {
                $cur['keep_running'] = vms_bool($cfg['keep_running'], false);
            }
            if (array_key_exists('wait', $cfg)) {
                $cur['wait'] = ($cfg['wait'] === null || $cfg['wait'] === '') ? null : vms_clamp($cfg['wait'], 0, 300, 0);
                if ($notes !== null && $cur['wait'] !== null && is_numeric($cfg['wait']) && (int)$cfg['wait'] !== $cur['wait']) {
                    $notes[] = "Wartezeit für {$name} wurde von " . (int)$cfg['wait'] . " auf {$cur['wait']} begrenzt (erlaubt: 0 bis 300 Sekunden).";
                }
            }
            if (isset($cfg['stop_containers']) && is_array($cfg['stop_containers'])) {
                $list = [];
                foreach ($cfg['stop_containers'] as $c) {
                    $c = (string)$c;
                    if (!vms_valid_container_name($c)) {
                        if ($notes !== null) {
                            $notes[] = "„{$c}“ ist kein gültiger Containername und wurde aus dem Profil von {$name} entfernt.";
                        }
                        continue;
                    }
                    if ($containers !== null && !array_key_exists($c, $containers)) {
                        if ($notes !== null) {
                            $notes[] = "Den Container „{$c}“ gibt es nicht (mehr). Er wurde aus dem Profil von {$name} entfernt.";
                        }
                        continue;
                    }
                    $list[$c] = true;
                }
                $cur['stop_containers'] = array_map('strval', array_keys($list));
            }
            $o['vms'][$name] = $cur;
        }
    }
    return $o;
}

function vms_load_settings(): array
{
    $d = vms_defaults();
    $f = vms_cfg_dir() . '/settings.json';
    if (is_file($f)) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j)) {
            return vms_sanitize($j, $d, null, null);
        }
    }
    return $d;
}

function vms_atomic_write(string $path, string $data, int $mode = 0644): bool
{
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $data, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, $mode);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function vms_save_settings(array $s): bool
{
    $dir = vms_cfg_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return vms_atomic_write($dir . '/settings.json', json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 0600);
}

function vms_profile(array $settings, string $vm): array
{
    $p = $settings['vms'][$vm] ?? [];
    return [
        'wait'            => $p['wait'] ?? null,
        'stop_containers' => $p['stop_containers'] ?? [],
        'keep_running'    => (bool)($p['keep_running'] ?? false),
    ];
}

/* ---------- Log, Status, Sperre ---------- */

function vms_log(string $level, string $msg): void
{
    vms_ensure_run_dir();
    $f = vms_run_dir() . '/vmswitch.log';
    if ((int)@filesize($f) > 524288) {
        @rename($f, $f . '.1');
    }
    $line = date('Y-m-d H:i:s') . ' [' . $level . '] ' . str_replace(["\r", "\n"], ' ', $msg) . "\n";
    @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
}

function vms_log_tail(int $n): array
{
    $f = vms_run_dir() . '/vmswitch.log';
    if (!is_file($f)) {
        return [];
    }
    $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_slice($lines, -max(1, min(1000, $n)));
}

function vms_write_state(array $s): void
{
    vms_ensure_run_dir();
    vms_atomic_write(vms_run_dir() . '/state.json', json_encode($s, JSON_UNESCAPED_UNICODE), 0600);
}

function vms_read_state(): ?array
{
    $j = json_decode((string)@file_get_contents(vms_run_dir() . '/state.json'), true);
    return is_array($j) ? $j : null;
}

/* Der Worker hält während des Wechsels eine exklusive Sperre auf switch.lock. */
function vms_job_running(): bool
{
    vms_ensure_run_dir();
    $f = @fopen(vms_run_dir() . '/switch.lock', 'c');
    if (!$f) {
        return false;
    }
    $got = flock($f, LOCK_SH | LOCK_NB);
    if ($got) {
        flock($f, LOCK_UN);
    }
    fclose($f);
    return !$got;
}

function vms_cancel_file(): string
{
    return vms_run_dir() . '/cancel';
}

function vms_notify(string $subject, string $desc, string $importance): void
{
    $bin = '/usr/local/emhttp/webGui/scripts/notify';
    if (!is_executable($bin) || !in_array($importance, ['normal', 'warning', 'alert'], true)) {
        return;
    }
    vms_exec([$bin, '-e', 'VM Switch', '-s', $subject, '-d', $desc, '-i', $importance], 15);
}

/* ---------- CSRF ---------- */

function vms_csrf_expected(): string
{
    $ini = @parse_ini_file(vms_env('VMSWITCH_VAR_INI', '/var/local/emhttp/var.ini'));
    return is_array($ini) ? (string)($ini['csrf_token'] ?? '') : '';
}

function vms_csrf_ok(string $token): bool
{
    $expected = vms_csrf_expected();
    return $expected !== '' && hash_equals($expected, $token);
}

/* Hilft bei der Fehlersuche, ohne das Token selbst zu verraten. */
function vms_csrf_debug(string $token): array
{
    $expected = vms_csrf_expected();
    return [
        'post_keys'    => array_map('strval', array_keys($_POST)),
        'token_len'    => strlen($token),
        'expected_len' => strlen($expected),
        'same_start'   => $token !== '' && $expected !== '' && substr($token, 0, 4) === substr($expected, 0, 4),
    ];
}
