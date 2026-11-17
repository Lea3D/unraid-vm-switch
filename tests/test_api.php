<?php
declare(strict_types=1);
require __DIR__ . '/helpers.php';

function api(array $post, string $method = 'POST', bool $csrf = true): array
{
    if ($csrf && !isset($post['csrf_token'])) {
        $post['csrf_token'] = 'test-token-123';
    }
    $p = proc_open([PHP_BINARY, __DIR__ . '/run_api.php', json_encode(['method' => $method, 'post' => $post])],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    preg_match('/@@HTTP:(\d+)$/', $out, $m);
    $json = json_decode(preg_replace('/\n@@HTTP:\d+$/', '', $out), true);
    return ['code' => (int)($m[1] ?? 0), 'json' => is_array($json) ? $json : [], 'raw' => $out];
}

$GPU = '0000:01:00.0';
$USB = '0000:00:14.0';
$b = mk(['A' => ['state' => 'running', 'pci' => [$GPU, $USB], 'usb' => ['046d:c52b']], 'B' => ['pci' => [$GPU]]], ['plex' => 'running', 'immich' => 'exited']);

section('Zugriffsschutz');
$r = api(['action' => 'status'], 'GET');
check($r['code'] === 405, 'GET wird abgelehnt (405)');
$r = api(['action' => 'status'], 'POST', false);
check($r['code'] === 403, 'POST ohne CSRF-Token wird abgelehnt (403)');
$r = api(['action' => 'status', 'csrf_token' => 'falsch']);
check($r['code'] === 403, 'POST mit falschem Token wird abgelehnt (403)');
$r = api(['action' => 'status', 'csrf_token' => ['x']]);
check($r['code'] === 403, 'Token als Array wird abgelehnt, ohne Fehlermeldung (403)');
$r = api(['action' => 'rm -rf']);
check($r['code'] === 400, 'Unbekannte Aktion (400)');

section('Token: Unraid kann csrf_token entfernen, vms_token bleibt');
$r = api(['action' => 'status', 'csrf_token' => '', 'vms_token' => 'test-token-123']);
check($r['code'] === 200, 'Nur vms_token (csrf_token leer/entfernt) wird akzeptiert');
$r = api(['action' => 'status', 'csrf_token' => 'test-token-123', 'vms_token' => 'falsch']);
check($r['code'] === 403, 'Falsches vms_token wird abgelehnt, auch wenn csrf_token stimmt');
$r = api(['action' => 'status', 'csrf_token' => 'falsch']);
check($r['code'] === 403 && isset($r['json']['debug']['token_len']) && !str_contains($r['raw'], 'test-token-123'), 'Fehlerantwort enthält Diagnose, aber nie das echte Token');
check(($r['json']['debug']['expected_len'] ?? 0) === strlen('test-token-123') && $r['json']['debug']['same_start'] === false, 'Diagnose zeigt Länge des erwarteten Tokens und ob der Anfang übereinstimmt');

section('Status und Übersicht');
$r = api(['action' => 'status']);
check($r['code'] === 200 && $r['json']['ok'] === true && count($r['json']['vms']) === 2, 'status liefert beide VMs');
check($r['json']['job']['running'] === false, 'kein Job läuft');
$r = api(['action' => 'overview', 'devices' => '1']);
$a = array_values(array_filter($r['json']['vms'], fn($v) => $v['name'] === 'A'))[0] ?? [];
$kinds = array_column($a['devices'] ?? [], 'kind');
check(in_array('GPU', $kinds, true) && in_array('USB-Controller', $kinds, true) && in_array('USB-Gerät', $kinds, true), 'Geräte von A: GPU, USB-Controller und USB-Gerät erkannt');
check(count($r['json']['containers']) === 2, 'Container werden aufgelistet');

section('Einstellungen speichern');
$new = ['wait_default' => 99999, 'health_secs' => -5, 'fallback' => 'false', 'notify' => '1',
    'vms' => ['B' => ['wait' => '30', 'stop_containers' => ['plex', '../evil', 'nichtda', '-rf']], 'Gibtsnicht' => ['wait' => 5]]];
$r = api(['action' => 'save_settings', 'settings' => json_encode($new)]);
$s = $r['json']['settings'] ?? [];
check($r['code'] === 200 && $s['wait_default'] === 300, 'wait_default wird auf 300 begrenzt');
check(($s['health_secs'] ?? -1) === 0, 'negative Werte werden auf das Minimum gesetzt');
check(($s['fallback'] ?? null) === false && ($s['notify'] ?? null) === true, 'Boolesche Werte werden korrekt gelesen');
check(($s['vms']['B']['wait'] ?? null) === 30, 'VM-Wartezeit gespeichert');
check(($s['vms']['B']['stop_containers'] ?? null) === ['plex'], 'Nur existierende, gültige Containernamen bleiben übrig');
check(!isset($s['vms']['Gibtsnicht']), 'Unbekannte VM wird verworfen');
$disk = json_decode((string)file_get_contents("$b/cfg/settings.json"), true);
check($disk['wait_default'] === 300 && (fileperms("$b/cfg/settings.json") & 0777) === 0600, 'Datei gespeichert, Rechte 0600');
$r = api(['action' => 'save_settings', 'settings' => 'kein json']);
check($r['code'] === 400, 'Ungültiges JSON wird abgelehnt');
$r = api(['action' => 'save_settings', 'settings' => json_encode(['vms' => ['B' => ['wait' => '']]])]);
check(array_key_exists('wait', $r['json']['settings']['vms']['B'] ?? []) && $r['json']['settings']['vms']['B']['wait'] === null && ($r['json']['settings']['wait_default'] ?? 0) === 300, 'Teil-Update: leere Wartezeit setzt auf Standard zurück, Rest bleibt');

section('Einstellungen: Modus und "nie stoppen"');
$r = api(['action' => 'save_settings', 'settings' => json_encode(['stop_mode' => 'conflict', 'vms' => ['A' => ['keep_running' => '1']]])]);
check(($r['json']['settings']['stop_mode'] ?? '') === 'conflict' && ($r['json']['settings']['vms']['A']['keep_running'] ?? null) === true, 'stop_mode und keep_running werden gespeichert');
$r = api(['action' => 'save_settings', 'settings' => json_encode(['stop_mode' => 'rm -rf'])]);
check(($r['json']['settings']['stop_mode'] ?? '') === 'conflict', 'ungültiger stop_mode wird ignoriert');
api(['action' => 'save_settings', 'settings' => json_encode(['vms' => ['A' => ['keep_running' => false]]])]);

section('Plan für die Bestätigung');
$r = api(['action' => 'plan', 'target' => 'B']);
check($r['code'] === 200 && $r['json']['plan']['stop'] === ['A'] && $r['json']['plan']['stay'] === [], 'Plan Wechsel zu B: A wird gestoppt');
$r = api(['action' => 'plan', 'target' => 'B; reboot']);
check($r['code'] === 400, 'Plan mit ungültigem Ziel wird abgelehnt');
api(['action' => 'save_settings', 'settings' => json_encode(['vms' => ['A' => ['keep_running' => true]]])]);
$r = api(['action' => 'plan', 'target' => 'B']);
check(($r['json']['plan']['blocked'][0]['vm'] ?? '') === 'A' && $r['json']['plan']['stop'] === [], 'A ist "nie stoppen", teilt aber GPU mit B: blockiert');
api(['action' => 'save_settings', 'settings' => json_encode(['vms' => ['A' => ['keep_running' => false]]])]);

section('Container-Aktionen');
$r = api(['action' => 'container_op', 'name' => 'immich', 'op' => 'start']);
check($r['code'] === 200 && trim((string)file_get_contents("$b/docker/immich")) === 'running', 'Container starten');
$r = api(['action' => 'container_op', 'name' => 'plex; reboot', 'op' => 'stop']);
check($r['code'] === 400, 'Ungültiger Containername wird abgelehnt');
$r = api(['action' => 'container_op', 'name' => 'plex', 'op' => 'rm']);
check($r['code'] === 400, 'Nicht erlaubte Operation wird abgelehnt');

section('Wechsel über die API');
$r = api(['action' => 'switch', 'target' => 'B; reboot']);
check($r['code'] === 400, 'Ungültiges Ziel wird abgelehnt');
$r = api(['action' => 'switch', 'target' => '--help']);
check($r['code'] === 400, 'Ziel mit Bindestrich wird abgelehnt');
check(vm_state($b, 'A') === 'running', 'A läuft weiterhin');
putenv('VMSWITCH_SPEED=0.05');
file_put_contents("$b/cfg/settings.json", json_encode(['wait_default' => 4, 'shutdown_timeout' => 10, 'start_timeout' => 10, 'health_secs' => 4, 'notify' => false]));
$r = api(['action' => 'switch', 'target' => 'B', 'wait' => '3']);
check($r['code'] === 200 && ($r['json']['ok'] ?? false), 'Wechsel wird gestartet');
$r2 = api(['action' => 'switch', 'target' => 'A']);
check($r2['code'] === 409, 'Zweiter Wechsel während des ersten wird abgelehnt (409)');
$done = false;
for ($i = 0; $i < 100; $i++) {
    usleep(100000);
    $st = api(['action' => 'status']);
    if (!$st['json']['job']['running'] && ($st['json']['job']['state']['status'] ?? '') !== 'running') {
        $done = true;
        break;
    }
}
check($done && $st['json']['job']['state']['status'] === 'ok', 'Wechsel endet mit Status ok');
check(vm_state($b, 'B') === 'running' && vm_state($b, 'A') === 'shut off', 'B läuft, A ist aus');
check($st['json']['job']['state']['wait'] === 3, 'Wartezeit-Override 3 s kam im Worker an');
$r = api(['action' => 'cancel']);
check($r['code'] === 409, 'Abbrechen ohne laufenden Wechsel wird abgelehnt');

section('Log');
$r = api(['action' => 'log', 'lines' => '50']);
check(count($r['json']['lines'] ?? []) > 3, 'Log enthält Einträge');
$r = api(['action' => 'clear_log']);
$r = api(['action' => 'log']);
check(($r['json']['lines'] ?? ['x']) === [], 'Log lässt sich leeren');

finish_tests();
