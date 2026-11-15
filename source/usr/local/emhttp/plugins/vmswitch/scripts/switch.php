#!/usr/bin/php
<?php
declare(strict_types=1);

/*
 * VM Switch – Worker. Wird von api.php im Hintergrund gestartet:
 *   switch.php --target=<VM> [--wait=<Sekunden>]
 *
 * Ablauf: Vorabprüfung -> laufende VMs herunterfahren -> Container gemäß Profil stoppen ->
 *         Wartezeit -> Ziel-VM starten -> Stabilitätsprüfung -> Container wieder starten.
 * Bei Fehlstart, Absturz oder Abbruch werden die vorherigen VMs wieder gestartet (Fallback).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
set_time_limit(0);
require_once __DIR__ . '/../include/lib.php';

$opts = getopt('', ['target:', 'wait::']);
$target = (string)($opts['target'] ?? '');
$waitOverride = isset($opts['wait']) && is_numeric($opts['wait']) ? vms_clamp($opts['wait'], 0, 300, 0) : null;

vms_ensure_run_dir();
$lock = fopen(vms_run_dir() . '/switch.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    vms_log('WARN', 'Wechsel abgelehnt: Es läuft bereits ein Wechsel.');
    exit(3);
}
@unlink(vms_cancel_file());

$S = [
    'id'        => bin2hex(random_bytes(4)),
    'status'    => 'running',
    'phase'     => 'init',
    'target'    => $target,
    'previous'  => [],
    'wait'      => 0,
    'wait_left' => 0,
    'fallback'  => false,
    'message'   => 'Wechsel wird vorbereitet',
    'started'   => time(),
    'finished'  => null,
    'steps'     => [],
];

function step(string $phase, string $msg, string $lvl = 'INFO'): void
{
    global $S;
    $S['phase'] = $phase;
    $S['message'] = $msg;
    $S['steps'][] = ['t' => time(), 'phase' => $phase, 'msg' => $msg, 'lvl' => $lvl];
    vms_write_state($S);
    vms_log($lvl, $msg);
}

function finish(string $status, string $msg): int
{
    global $S, $settings;
    $lvl = $status === 'ok' ? 'INFO' : (in_array($status, ['fallback_ok', 'cancelled'], true) ? 'WARN' : 'ERROR');
    $S['status'] = $status;
    $S['phase'] = 'done';
    $S['message'] = $msg;
    $S['wait_left'] = 0;
    $S['finished'] = time();
    $S['steps'][] = ['t' => time(), 'phase' => 'done', 'msg' => $msg, 'lvl' => $lvl];
    vms_write_state($S);
    vms_log($lvl, $msg);
    if (!empty($settings['notify'])) {
        $imp = $status === 'ok' ? 'normal' : (in_array($status, ['fallback_ok', 'cancelled'], true) ? 'warning' : 'alert');
        vms_notify($status === 'ok' ? 'VM-Wechsel abgeschlossen' : 'VM-Wechsel nicht wie geplant', $msg, $imp);
    }
    return $status === 'ok' ? 0 : 1;
}

function cancelled(): bool
{
    return is_file(vms_cancel_file());
}

/* Wartet, bis die VM ausgeschaltet ist. true = ausgeschaltet. */
function wait_off(string $vm, int $seconds): bool
{
    for ($i = 0; $i <= $seconds; $i += 2) {
        if (vms_state($vm) === 'shut off') {
            return true;
        }
        vms_sleep(2);
    }
    return vms_state($vm) === 'shut off';
}

function stop_vm(string $vm, array $settings): bool
{
    $st = vms_state($vm);
    if ($st === 'shut off') {
        return true;
    }
    if ($st === 'paused' || $st === 'pmsuspended') {
        vms_virsh(['resume', '--domain', $vm], 15);
    }
    step('shutdown', "Fahre VM {$vm} herunter (ACPI)");
    vms_virsh(['shutdown', '--domain', $vm], 15);
    if (wait_off($vm, $settings['shutdown_timeout'])) {
        return true;
    }
    if (!$settings['force_destroy']) {
        step('shutdown', "VM {$vm} reagiert nicht auf ACPI-Shutdown (hartes Beenden ist deaktiviert)", 'ERROR');
        return false;
    }
    step('shutdown', "VM {$vm} reagiert nicht, beende sie hart (destroy)", 'WARN');
    vms_virsh(['destroy', '--domain', $vm], 20);
    return wait_off($vm, 20);
}

/* Startet eine VM und prüft, dass sie stabil läuft. Gibt null bei Erfolg, sonst den Fehlertext. */
function start_and_check(string $vm, array $settings, int $healthSecs, bool $useAgent = false): ?string
{
    step('start', "Starte VM {$vm}");
    $r = vms_virsh(['start', '--domain', $vm], $settings['start_timeout']);
    if ($r['code'] !== 0) {
        $e = vms_first_line($r['err'] . "\n" . $r['out']);
        return 'virsh start fehlgeschlagen: ' . ($e !== '' ? $e : 'unbekannter Fehler');
    }
    step('health', "Prüfe, ob VM {$vm} stabil läuft ({$healthSecs} s)");
    for ($i = 0; $i < $healthSecs; $i++) {
        vms_sleep(1);
        $st = vms_state($vm);
        if (in_array($st, ['shut off', 'crashed'], true)) {
            return "VM ist nach dem Start im Zustand '{$st}'";
        }
    }
    if (vms_state($vm) !== 'running') {
        return 'VM läuft nach der Prüfzeit nicht';
    }
    if ($useAgent && $settings['agent_wait'] > 0 && !vms_has_agent_channel($vm)) {
        vms_log('INFO', "VM {$vm} hat keinen Guest-Agent-Kanal, Agent-Prüfung übersprungen");
    } elseif ($useAgent && $settings['agent_wait'] > 0) {
        step('health', "Warte auf QEMU-Guest-Agent von {$vm} (max. {$settings['agent_wait']} s)");
        $ok = false;
        for ($i = 0; $i < $settings['agent_wait']; $i += 3) {
            $r = vms_virsh(['qemu-agent-command', '--domain', $vm, '{"execute":"guest-ping"}'], 5);
            if ($r['code'] === 0) {
                $ok = true;
                break;
            }
            if (in_array(vms_state($vm), ['shut off', 'crashed'], true)) {
                return 'VM ist beim Warten auf den Guest-Agent ausgefallen';
            }
            vms_sleep(3);
        }
        if (!$ok) {
            return 'Guest-Agent antwortet nicht';
        }
    }
    return null;
}

function log_qemu_tail(string $vm): void
{
    $f = vms_qemu_log_dir() . '/' . basename($vm) . '.log';
    if (!is_readable($f)) {
        return;
    }
    $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach (array_slice($lines, -8) as $l) {
        vms_log('QEMU', $l);
    }
}

/* Fährt Container hoch, die dieser Lauf gestoppt hatte. */
function restart_containers(array $names): void
{
    $known = vms_containers();
    foreach ($names as $c) {
        if ($known !== null && isset($known[$c]) && !$known[$c]) {
            step('containers', "Starte Container {$c}");
            vms_container_op($c, 'start');
        }
    }
}

function main(): int
{
    global $S, $target, $waitOverride, $settings;
    $settings = vms_load_settings();

    $names = vms_vm_names();
    if ($names === null) {
        return finish('failed', 'libvirt ist nicht erreichbar. Ist der VM-Dienst in Unraid gestartet?');
    }
    if ($target === '' || !in_array($target, $names, true)) {
        return finish('failed', 'Unbekannte Ziel-VM. Es wurde nichts verändert.');
    }

    // Zustand erfassen
    $prev = [];
    foreach ($names as $n) {
        if ($n !== $target && !in_array(vms_state($n), ['shut off', 'unknown'], true)) {
            $prev[] = $n;
        }
    }
    $targetState = vms_state($target);
    $targetActive = !in_array($targetState, ['shut off', 'unknown'], true);
    $S['previous'] = $prev;
    step('init', 'Wechsel zu ' . $target . ($prev ? ' (aktiv: ' . implode(', ', $prev) . ')' : ' (keine andere VM aktiv)'));

    if ($targetActive && !$prev) {
        if ($targetState === 'paused') {
            vms_virsh(['resume', '--domain', $target], 15);
            return finish('ok', "VM {$target} war pausiert und läuft jetzt weiter.");
        }
        return finish('ok', "VM {$target} läuft bereits. Es wurde nichts verändert.");
    }

    // Vorabprüfung: Sind alle durchgereichten PCI-Geräte der Ziel-VM im System vorhanden?
    $dev = vms_hostdevs($target);
    if ($dev['error'] !== '') {
        return finish('failed', "Konfiguration der VM {$target} nicht lesbar: {$dev['error']}. Es wurde nichts verändert.");
    }
    foreach ($dev['pci'] as $addr) {
        $info = vms_pci_info($addr);
        if (!$info['present']) {
            return finish('failed', "PCI-Gerät {$addr} der VM {$target} wurde nicht gefunden (Hardware oder Slot geändert?). Es wurde nichts verändert.");
        }
        vms_log('INFO', "Gerät {$addr} ({$info['kind']}) Treiber: " . ($info['driver'] ?: 'keiner'));
    }

    $profile = vms_profile($settings, $target);
    $wait = $waitOverride ?? ($profile['wait'] ?? $settings['wait_default']);
    $S['wait'] = $wait;

    // Vorherige VMs herunterfahren
    $stopped = [];
    foreach ($prev as $vm) {
        if (stop_vm($vm, $settings)) {
            $stopped[] = $vm;
        } else {
            $msg = "VM {$vm} ließ sich nicht herunterfahren.";
            if ($stopped) {
                return fallback($stopped, [], $msg, $wait);
            }
            return finish('failed', $msg . ' Die Ziel-VM wurde nicht gestartet.');
        }
    }

    // Container gemäß Profil der Ziel-VM stoppen
    $recorded = vms_load_stopped();
    $stoppedNow = [];
    $known = vms_containers();
    if ($profile['stop_containers'] && $known === null) {
        vms_log('WARN', 'Docker ist nicht erreichbar, Container-Profil wird übersprungen.');
    }
    foreach ($profile['stop_containers'] as $c) {
        if ($known !== null && !empty($known[$c])) {
            step('containers', "Stoppe Container {$c}");
            vms_container_op($c, 'stop');
            $stoppedNow[] = $c;
        }
    }

    // Wartezeit (z. B. für das PCI-Handling)
    if ($stopped && $wait > 0) {
        step('wait', "Wartezeit {$wait} s vor dem Start");
        for ($i = $wait; $i > 0; $i--) {
            if (cancelled()) {
                return fallback($stopped, $stoppedNow, 'Wechsel abgebrochen.', $wait, true);
            }
            $S['wait_left'] = $i;
            vms_write_state($S);
            vms_sleep(1);
        }
        $S['wait_left'] = 0;
    }
    if (cancelled()) {
        return fallback($stopped, $stoppedNow, 'Wechsel abgebrochen.', $wait, true);
    }

    // Ziel-VM starten und prüfen
    $err = $targetActive ? null : start_and_check($target, $settings, $settings['health_secs'], true);
    if ($err !== null) {
        step('start', "Start von {$target} fehlgeschlagen: {$err}", 'ERROR');
        log_qemu_tail($target);
        if (!in_array(vms_state($target), ['shut off', 'unknown'], true)) {
            vms_virsh(['destroy', '--domain', $target], 20);
            wait_off($target, 20);
        }
        return fallback($stopped, $stoppedNow, "Ziel-VM {$target} nicht gestartet ({$err}).", $wait);
    }

    // Erfolg: Container der vorherigen Profile wieder starten, die dieser Lauf nicht mehr braucht
    $keep = array_values(array_intersect($recorded, $profile['stop_containers']));
    $again = array_values(array_diff($recorded, $profile['stop_containers']));
    restart_containers($again);
    vms_save_stopped(array_merge($keep, $stoppedNow));

    return finish('ok', "VM {$target} läuft." . ($stopped ? ' Vorher aktiv: ' . implode(', ', $stopped) . '.' : ''));
}

/* Stellt den vorherigen Zustand wieder her. */
function fallback(array $stopped, array $stoppedNow, string $why, int $wait, bool $isCancel = false): int
{
    global $S, $settings;
    $S['fallback'] = true;
    if (!$stopped) {
        restart_containers($stoppedNow);
        return finish($isCancel ? 'cancelled' : 'failed', $why . ' Es war keine andere VM aktiv, die wiederhergestellt werden müsste.');
    }
    if (!$settings['fallback'] && !$isCancel) {
        restart_containers($stoppedNow);
        return finish('failed', $why . ' Fallback ist deaktiviert, die vorherige VM bleibt aus.');
    }
    step('fallback', $why . ' Starte vorherige VM(s) wieder: ' . implode(', ', $stopped), 'WARN');
    if ($wait > 0) {
        for ($i = $wait; $i > 0; $i--) {
            $S['wait_left'] = $i;
            vms_write_state($S);
            vms_sleep(1);
        }
        $S['wait_left'] = 0;
    }
    $failed = [];
    foreach ($stopped as $vm) {
        $err = start_and_check($vm, $settings, min($settings['health_secs'], 10));
        if ($err !== null) {
            $failed[] = $vm;
            step('fallback', "Fallback-Start von {$vm} fehlgeschlagen: {$err}", 'ERROR');
            log_qemu_tail($vm);
        }
    }
    restart_containers($stoppedNow);
    if ($failed) {
        return finish('fallback_failed', $why . ' Auch der Fallback-Start ist fehlgeschlagen für: ' . implode(', ', $failed) . '. Bitte manuell prüfen.');
    }
    return finish($isCancel ? 'cancelled' : 'fallback_ok', $why . ' Vorherige VM(s) wurden wieder gestartet: ' . implode(', ', $stopped) . '.');
}

$settings = vms_defaults();
exit(main());
