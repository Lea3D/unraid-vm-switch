<?php
declare(strict_types=1);

/*
 * Interner AJAX-Endpunkt der VM-Switch-Oberfläche. Er ist nur über das angemeldete
 * Unraid-WebGUI erreichbar (nginx auth_request) und verlangt zusätzlich das CSRF-Token.
 * Es gibt keine öffentliche API.
 */

require_once __DIR__ . '/lib.php';
ini_set('display_errors', '0');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $msg, int $code = 400, array $debug = []): never
{
    $r = ['ok' => false, 'error' => $msg];
    if ($debug) {
        $r['debug'] = $debug;
    }
    out($r, $code);
}

function post_str(string $k): string
{
    $v = $_POST[$k] ?? '';
    return is_string($v) ? $v : '';
}

set_exception_handler(function (Throwable $e): void {
    vms_log('ERROR', 'api.php: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    fail('Interner Fehler im Plugin: ' . $e->getMessage(), 500);
});

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('Diese Adresse nimmt nur Anfragen der Plugin-Seite an (POST). Rufe die Seite „VM Switch“ in Unraid auf.', 405);
}
// Die Seite sendet das Token zweimal: als csrf_token (für Unraids eigene Prüfung) und als vms_token.
// Unraid kann csrf_token nach der eigenen Prüfung aus $_POST entfernen, vms_token bleibt erhalten.
$token = post_str('vms_token') !== '' ? post_str('vms_token') : post_str('csrf_token');
if (!vms_csrf_ok($token)) {
    fail('Die Sicherheitsprüfung (CSRF-Token) ist fehlgeschlagen. Grund: Die Seite stammt aus einer abgelaufenen Sitzung, zum Beispiel nach einem Neustart von Unraid oder einer neuen Anmeldung. Das Plugin schützt dich damit vor Anfragen von fremden Webseiten. Lade die Seite mit Strg+F5 neu.', 403, vms_csrf_debug($token));
}

$action = post_str('action');

/* Zustand aller VMs (leicht, wird alle paar Sekunden abgefragt). */
function vm_states(?array $names): array
{
    $vms = [];
    foreach ($names ?? [] as $n) {
        $vms[] = ['name' => $n, 'state' => vms_state($n)];
    }
    return $vms;
}

function job_info(): array
{
    return ['running' => vms_job_running(), 'state' => vms_read_state()];
}

switch ($action) {
    case 'status':
        $names = vms_vm_names();
        out(['ok' => true, 'libvirt' => $names !== null, 'vms' => vm_states($names), 'job' => job_info()]);

    case 'overview':
        $names = vms_vm_names();
        $vms = [];
        $withDevices = post_str('devices') === '1';
        $res = [];
        if ($withDevices) {
            foreach ($names ?? [] as $n) {
                $res[$n] = vms_resources($n);
            }
        }
        foreach ($names ?? [] as $n) {
            $entry = ['name' => $n, 'state' => vms_state($n), 'devices' => [], 'error' => '', 'shared' => []];
            if ($withDevices) {
                foreach ($names as $other) {
                    if ($other === $n) {
                        continue;
                    }
                    $sh = vms_shared($res[$n], $res[$other]);
                    if ($sh['disks']) {
                        $entry['shared'][] = ['vm' => $other, 'disks' => $sh['disks']];
                    }
                }
                $h = vms_hostdevs($n);
                $entry['error'] = $h['error'];
                foreach ($h['pci'] as $addr) {
                    $entry['devices'][] = vms_pci_info($addr);
                }
                foreach ($h['usb'] as $u) {
                    $entry['devices'][] = ['kind' => 'USB-Gerät', 'addr' => $u, 'present' => true, 'driver' => '', 'name' => ''];
                }
            }
            $vms[] = $entry;
        }
        $cont = vms_containers();
        $list = null;
        if ($cont !== null) {
            $list = [];
            foreach ($cont as $name => $running) {
                $list[] = ['name' => $name, 'running' => $running];
            }
        }
        out([
            'ok'         => true,
            'libvirt'    => $names !== null,
            'vms'        => $vms,
            'containers' => $list,
            'settings'   => vms_load_settings(),
            'job'        => job_info(),
        ]);

    case 'plan':
        $target = post_str('target');
        $names = vms_vm_names();
        if ($names === null) {
            fail('Der VM-Dienst (libvirt) ist nicht erreichbar. Starte den VM-Manager unter Einstellungen → VM-Manager und lade die Seite neu.', 503);
        }
        if (!in_array($target, $names, true)) {
            fail('Die VM „' . $target . '“ gibt es nicht (mehr). Lade die Seite neu, die Liste ist veraltet.');
        }
        $states = [];
        foreach ($names as $n) {
            $states[$n] = vms_state($n);
        }
        $plan = vms_plan($target, $states, vms_load_settings());
        out(['ok' => true, 'plan' => ['stop' => $plan['stop'], 'stay' => $plan['stay'], 'blocked' => $plan['blocked'], 'reasons' => $plan['reasons']]]);

    case 'switch':
        $target = post_str('target');
        $names = vms_vm_names();
        if ($names === null) {
            fail('Der VM-Dienst (libvirt) ist nicht erreichbar. Starte den VM-Manager unter Einstellungen → VM-Manager und lade die Seite neu.', 503);
        }
        if (!in_array($target, $names, true)) {
            fail('Die VM „' . $target . '“ gibt es nicht (mehr). Lade die Seite neu, die Liste ist veraltet.');
        }
        if (vms_job_running()) {
            fail('Es läuft bereits ein Wechsel. Zwei Wechsel gleichzeitig würden Geräte und Festplatten doppelt vergeben. Warte, bis der laufende Wechsel fertig ist, der Fortschritt steht oben auf der Seite.', 409);
        }
        $cmd = 'setsid ' . escapeshellarg(vms_env('VMSWITCH_PHP', '/usr/bin/php'))
            . ' ' . escapeshellarg(dirname(__DIR__) . '/scripts/switch.php')
            . ' --target=' . escapeshellarg($target);
        $w = post_str('wait');
        if ($w !== '' && ctype_digit($w)) {
            $cmd .= ' --wait=' . vms_clamp($w, 0, 300, 0);
        }
        vms_ensure_run_dir();
        @unlink(vms_cancel_file());
        exec($cmd . ' >/dev/null 2>&1 &');
        for ($i = 0; $i < 40; $i++) {
            usleep(100000);
            if (vms_job_running()) {
                out(['ok' => true]);
            }
        }
        fail('Der Wechsel konnte nicht gestartet werden: Der Hintergrundprozess ist nicht angelaufen. Details stehen im Tab „Log“. Prüfe, ob /usr/bin/php und das Programm setsid auf dem Server vorhanden sind.', 500);

    case 'cancel':
        if (!vms_job_running()) {
            fail('Es läuft gerade kein Wechsel, den man abbrechen könnte. Möglicherweise ist er schon fertig.', 409);
        }
        vms_ensure_run_dir();
        @file_put_contents(vms_cancel_file(), '1');
        vms_log('WARN', 'Abbruch angefordert');
        out(['ok' => true]);

    case 'save_settings':
        $raw = json_decode(post_str('settings'), true);
        if (!is_array($raw)) {
            fail('Die gesendeten Einstellungen sind kein gültiges JSON. Lade die Seite neu und versuche es erneut.');
        }
        $notes = [];
        $merged = vms_sanitize($raw, vms_load_settings(), vms_vm_names(), vms_containers(), $notes);
        if (!vms_save_settings($merged)) {
            fail('Die Einstellungen konnten nicht gespeichert werden. Mögliche Ursache: Der USB-Stick (/boot) ist schreibgeschützt oder voll. Prüfe das unter Main → Flash.', 500);
        }
        vms_log('INFO', 'Einstellungen gespeichert');
        out(['ok' => true, 'settings' => $merged, 'notes' => $notes]);

    case 'container_op':
        $name = post_str('name');
        $op = post_str('op');
        $cont = vms_containers();
        if ($cont === null) {
            fail('Docker ist nicht erreichbar. Starte den Docker-Dienst unter Einstellungen → Docker und lade die Seite neu.', 503);
        }
        if (!array_key_exists($name, $cont)) {
            fail('Den Container „' . $name . '“ gibt es nicht (mehr). Lade die Seite neu, die Liste ist veraltet.');
        }
        if (!in_array($op, ['start', 'stop', 'restart'], true)) {
            fail('Diese Aktion ist nicht erlaubt. Möglich sind nur Start, Stopp und Neustart.');
        }
        $r = vms_container_op($name, $op);
        vms_log($r['code'] === 0 ? 'INFO' : 'ERROR', "Container {$name}: {$op} " . ($r['code'] === 0 ? 'ok' : vms_first_line($r['err'])));
        if ($r['code'] !== 0) {
            fail('Docker meldet: ' . vms_first_line($r['err']), 500);
        }
        out(['ok' => true]);

    case 'log':
        $n = vms_clamp(post_str('lines'), 10, 1000, 200);
        out(['ok' => true, 'lines' => vms_log_tail($n), 'running' => vms_job_running()]);

    case 'clear_log':
        if (vms_job_running()) {
            fail('Das Log kann während eines Wechsels nicht gelöscht werden, weil der Wechsel gerade hineinschreibt. Warte, bis er fertig ist.', 409);
        }
        @unlink(vms_run_dir() . '/vmswitch.log');
        @unlink(vms_run_dir() . '/vmswitch.log.1');
        out(['ok' => true]);

    default:
        fail('Diese Aktion kennt das Plugin nicht. Die Seite stammt vermutlich von einer anderen Plugin-Version, lade sie mit Strg+F5 neu.');
}
