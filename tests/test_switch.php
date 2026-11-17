<?php
declare(strict_types=1);
require __DIR__ . '/helpers.php';

$GPU = '0000:01:00.0';
$USB = '0000:00:14.0';

section('1. Erfolgreicher Wechsel A -> B (mit Container-Profil)');
$b = mk(['A' => ['state' => 'running', 'pci' => [$GPU, $USB]], 'B' => ['pci' => [$GPU]]], ['plex' => 'running', 'immich' => 'running'],
    ['vms' => ['B' => ['wait' => null, 'stop_containers' => ['plex']]]]);
$code = run_worker('B');
$c = calls($b);
check($code === 0, 'Exitcode 0');
check(job($b)['status'] === 'ok', 'Status ok');
check(vm_state($b, 'A') === 'shut off' && vm_state($b, 'B') === 'running', 'A aus, B läuft');
check(call_index($c, 'shutdown --domain A') < call_index($c, 'start --domain B') && has_call($c, 'shutdown --domain A'), 'A wird vor dem Start von B heruntergefahren');
check(trim((string)file_get_contents("$b/docker/plex")) === 'exited', 'Container plex (Profil von B) gestoppt');
check(trim((string)file_get_contents("$b/docker/immich")) === 'running', 'Container immich unberührt');
check(job($b)['wait'] === 2, 'Standard-Wartezeit 2 s übernommen');

section('1b. Zurück zu A: plex wird wieder gestartet');
$code = run_worker('A');
check($code === 0 && vm_state($b, 'A') === 'running' && vm_state($b, 'B') === 'shut off', 'A läuft wieder, B aus');
check(trim((string)file_get_contents("$b/docker/plex")) === 'running', 'plex wurde wieder gestartet');

section('2. Fehlstart der Ziel-VM -> Fallback auf vorherige VM');
$b = mk(['A' => ['state' => 'running', 'pci' => [$GPU]], 'B' => ['pci' => [$GPU]]], ['plex' => 'running'],
    ['vms' => ['B' => ['stop_containers' => ['plex']]]]);
flag($b, 'fail_start', 'B');
$code = run_worker('B');
$c = calls($b);
check($code === 1, 'Exitcode 1');
check(job($b)['status'] === 'fallback_ok', 'Status fallback_ok');
check(vm_state($b, 'A') === 'running' && vm_state($b, 'B') === 'shut off', 'A läuft wieder, B aus');
check(call_index($c, 'start --domain B') < call_index($c, 'start --domain A'), 'Reihenfolge: erst Start B (fehlgeschlagen), dann Start A');
check(trim((string)file_get_contents("$b/docker/plex")) === 'running', 'Container plex nach Fallback wieder gestartet');

section('3. Ziel-VM stürzt in der Prüfphase ab -> destroy + Fallback');
$b = mk(['A' => ['state' => 'running', 'pci' => [$GPU]], 'B' => ['pci' => [$GPU]]], [], ['health_secs' => 8]);
flag($b, 'crash', 'B', '2');
$code = run_worker('B');
check(job($b)['status'] === 'fallback_ok', 'Status fallback_ok');
check(has_call(calls($b), 'destroy --domain B'), 'abgestürzte Ziel-VM wird aufgeräumt (destroy)');
check(vm_state($b, 'A') === 'running' && vm_state($b, 'B') === 'shut off', 'A läuft wieder, B aus');

section('4. Fallback schlägt ebenfalls fehl');
$b = mk(['A' => ['state' => 'running', 'pci' => [$GPU]], 'B' => ['pci' => [$GPU]]]);
flag($b, 'fail_start', 'B');
flag($b, 'fail_start', 'A');
$code = run_worker('B');
check($code === 1 && job($b)['status'] === 'fallback_failed', 'Status fallback_failed');

section('5. Fallback deaktiviert');
$b = mk(['A' => ['state' => 'running', 'pci' => [$GPU]], 'B' => ['pci' => [$GPU]]], [], ['fallback' => false]);
flag($b, 'fail_start', 'B');
$code = run_worker('B');
check(job($b)['status'] === 'failed' && vm_state($b, 'A') === 'shut off', 'Status failed, A bleibt aus (wie konfiguriert)');

section('6. ACPI-Shutdown hängt: destroy-Eskalation');
$b = mk(['A' => ['state' => 'running', 'pci' => [$GPU]], 'B' => ['pci' => [$GPU]]]);
flag($b, 'hang', 'A');
$code = run_worker('B');
check($code === 0 && has_call(calls($b), 'destroy --domain A') && vm_state($b, 'B') === 'running', 'A hart beendet, B läuft');

section('6b. ACPI-Shutdown hängt, hartes Beenden deaktiviert');
$b = mk(['A' => ['state' => 'running', 'pci' => [$GPU]], 'B' => ['pci' => [$GPU]]], [], ['force_destroy' => false]);
flag($b, 'hang', 'A');
$code = run_worker('B');
check($code === 1 && job($b)['status'] === 'failed', 'Wechsel schlägt fehl');
check(vm_state($b, 'A') === 'running' && !has_call(calls($b), 'start --domain B') && !has_call(calls($b), 'destroy'), 'A läuft weiter, B nie gestartet, kein destroy');

section('7. Vorabprüfung: PCI-Gerät der Ziel-VM fehlt');
$b = mk(['A' => ['state' => 'running', 'pci' => [$GPU]], 'B' => ['pci' => ['0000:02:00.0']]], [], [], ['0000:02:00.0']);
$code = run_worker('B');
check($code === 1 && job($b)['status'] === 'failed', 'Wechsel wird abgelehnt');
check(str_contains(job($b)['message'], 'nicht gefunden'), 'Meldung nennt das fehlende Gerät');
check(vm_state($b, 'A') === 'running' && !has_call(calls($b), 'shutdown'), 'A läuft weiter, es wurde nichts heruntergefahren');

section('8. Manipulierte Eingaben als Ziel');
foreach (['B; touch /tmp/pwned', '--help', '../etc/passwd', 'b', "B\nA"] as $evil) {
    $b = mk(['A' => ['state' => 'running', 'pci' => [$GPU]], 'B' => ['pci' => [$GPU]]]);
    $code = run_worker($evil);
    check($code === 1 && vm_state($b, 'A') === 'running' && !has_call(calls($b), 'shutdown') && !has_call(calls($b), 'start'), 'abgelehnt, nichts verändert: ' . json_encode($evil));
}
check(!file_exists('/tmp/pwned'), 'Kein Befehl über den VM-Namen ausgeführt');

section('9. Sperre gegen parallele Wechsel');
$b = mk(['A' => ['state' => 'running', 'pci' => [$GPU]], 'B' => ['pci' => [$GPU]]]);
$lock = fopen("$b/run/switch.lock", 'c');
flock($lock, LOCK_EX);
$code = run_worker('B');
check($code === 3, 'zweiter Wechsel wird mit Exitcode 3 abgelehnt');
check(vm_state($b, 'A') === 'running', 'Zustand unverändert');
flock($lock, LOCK_UN);
fclose($lock);

section('10. Abbruch während der Wartezeit stellt die vorherige VM wieder her');
$b = mk(['A' => ['state' => 'running', 'pci' => [$GPU]], 'B' => ['pci' => [$GPU]]], [], ['wait_default' => 40], [], 0.1);
$p = proc_open(worker_cmd('B'), [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
$seen = false;
for ($i = 0; $i < 100; $i++) {
    usleep(50000);
    $j = @json_decode((string)@file_get_contents("$b/run/state.json"), true);
    if (($j['phase'] ?? '') === 'wait' && ($j['wait_left'] ?? 0) > 0) {
        file_put_contents("$b/run/cancel", '1');
        $seen = true;
        break;
    }
}
$code = proc_close($p);
check($seen, 'Wartephase wurde beobachtet');
check(job($b)['status'] === 'cancelled', 'Status cancelled');
check(vm_state($b, 'A') === 'running' && vm_state($b, 'B') === 'shut off', 'A läuft wieder, B nie gestartet');

section('11. Keine andere VM aktiv');
$b = mk(['A' => ['pci' => [$GPU]], 'B' => ['pci' => [$GPU]]]);
$code = run_worker('B');
check($code === 0 && vm_state($b, 'B') === 'running' && !has_call(calls($b), 'shutdown'), 'B startet ohne Herunterfahren');

section('12. Ziel-VM läuft bereits');
$b = mk(['A' => ['pci' => [$GPU]], 'B' => ['state' => 'running', 'pci' => [$GPU]]]);
$code = run_worker('B');
check($code === 0 && !has_call(calls($b), 'start --domain') && !has_call(calls($b), 'shutdown'), 'es passiert nichts');

section('13. Mehrere VMs aktiv, Fehlstart: alle werden wiederhergestellt');
$b = mk(['A' => ['state' => 'running', 'pci' => [$USB]], 'C' => ['state' => 'running', 'pci' => []], 'B' => ['pci' => [$GPU]]]);
flag($b, 'fail_start', 'B');
$code = run_worker('B');
check(job($b)['status'] === 'fallback_ok' && vm_state($b, 'A') === 'running' && vm_state($b, 'C') === 'running', 'A und C laufen wieder');

section('13b. Standardmodus: nur VMs stoppen, die Geräte teilen (HomeAssistant bleibt an)');
$HA_USB = '1b1f:c020';
$b = mk(['HA' => ['state' => 'running', 'usb' => [$HA_USB]], 'A' => ['state' => 'running', 'pci' => [$GPU, $USB]], 'B' => ['pci' => [$GPU]]], [], ['stop_mode' => 'conflict']);
$code = run_worker('B');
$c = calls($b);
check($code === 0 && job($b)['status'] === 'ok', 'Wechsel gelingt');
check(vm_state($b, 'A') === 'shut off' && vm_state($b, 'B') === 'running' && vm_state($b, 'HA') === 'running', 'A gestoppt, B läuft, HA läuft weiter');
check(!has_call($c, 'shutdown --domain HA') && !has_call($c, 'destroy --domain HA'), 'HA wurde nie angefasst');
check(job($b)['previous'] === ['A'] && job($b)['stay'] === ['HA'], 'Plan im Status: stoppen A, bleibt an HA');

section('13c. Fehlstart im Standardmodus: nur die tatsächlich gestoppte VM wird wiederhergestellt');
$b = mk(['HA' => ['state' => 'running', 'usb' => [$HA_USB]], 'A' => ['state' => 'running', 'pci' => [$GPU, $USB]], 'B' => ['pci' => [$GPU]]], [], ['stop_mode' => 'conflict']);
flag($b, 'fail_start', 'B');
$code = run_worker('B');
check(job($b)['status'] === 'fallback_ok' && vm_state($b, 'A') === 'running' && vm_state($b, 'HA') === 'running', 'A läuft wieder, HA lief durchgehend');
check(!preg_match('/(shutdown|destroy|start|resume) --domain HA/', implode("\n", calls($b))), 'keinerlei Befehl an HA');

section('13d. Gemeinsames USB-Gerät gilt als Konflikt');
$b = mk(['HA' => ['state' => 'running', 'usb' => ['046d:c52b']], 'A' => ['state' => 'running', 'pci' => [$GPU]], 'B' => ['pci' => [$GPU], 'usb' => ['046d:c52b']]], [], ['stop_mode' => 'conflict']);
$code = run_worker('B');
check($code === 0 && vm_state($b, 'HA') === 'shut off' && vm_state($b, 'A') === 'shut off' && vm_state($b, 'B') === 'running', 'HA teilt das USB-Gerät mit B und wird gestoppt');

section('13e. "Nie stoppen" schützt eine VM im Modus "alle"');
$b = mk(['HA' => ['state' => 'running', 'pci' => []], 'A' => ['state' => 'running', 'pci' => [$GPU]], 'B' => ['pci' => [$GPU]]], [], ['stop_mode' => 'all', 'vms' => ['HA' => ['keep_running' => true]]]);
$code = run_worker('B');
check($code === 0 && vm_state($b, 'HA') === 'running' && vm_state($b, 'A') === 'shut off' && vm_state($b, 'B') === 'running', 'A gestoppt, HA bleibt trotz Modus "alle" an');

section('13f. "Nie stoppen" und Gerätekonflikt: Wechsel wird abgelehnt, nichts verändert');
$b = mk(['K' => ['state' => 'running', 'pci' => [$GPU]], 'A' => ['state' => 'running', 'pci' => [$USB]], 'B' => ['pci' => [$GPU]]], [], ['stop_mode' => 'all', 'vms' => ['K' => ['keep_running' => true]]]);
$code = run_worker('B');
check($code === 1 && job($b)['status'] === 'failed' && str_contains(job($b)['message'], 'K') && str_contains(job($b)['message'], 'nie stoppen'), 'Meldung nennt die geschützte VM');
check(vm_state($b, 'K') === 'running' && vm_state($b, 'A') === 'running' && !has_call(calls($b), 'shutdown') && !has_call(calls($b), 'start'), 'es wurde nichts heruntergefahren oder gestartet');

section('13g. Ziel-VM ohne Geräte im Standardmodus: laufende VMs bleiben');
$b = mk(['A' => ['state' => 'running', 'pci' => [$GPU]], 'L' => []], [], ['stop_mode' => 'conflict']);
$code = run_worker('L');
check($code === 0 && vm_state($b, 'A') === 'running' && vm_state($b, 'L') === 'running' && !has_call(calls($b), 'shutdown'), 'beide laufen, nichts gestoppt');

section('13h. Standardwert ohne Einstellung ist "conflict"');
putenv('VMSWITCH_CFG_DIR=' . $b . '/leer');
require_once dirname(__DIR__) . '/source/usr/local/emhttp/plugins/vmswitch/include/lib.php';
check(vms_load_settings()['stop_mode'] === 'conflict', 'Standardmodus ist "conflict"');
putenv('VMSWITCH_CFG_DIR=' . $b . '/cfg');

section('14. Guest-Agent-Prüfung');
$b = mk(['A' => ['state' => 'running'], 'B' => ['agent' => true]], [], ['agent_wait' => 6]);
flag($b, 'agent_ok', 'B');
check(run_worker('B') === 0 && job($b)['status'] === 'ok', 'Agent antwortet: Wechsel ok');
$b = mk(['A' => ['state' => 'running'], 'B' => ['agent' => true]], [], ['agent_wait' => 6]);
check(run_worker('B') === 1 && job($b)['status'] === 'fallback_ok' && vm_state($b, 'A') === 'running', 'Agent antwortet nicht: Fallback, A läuft wieder (ohne Agent-Prüfung)');
$b = mk(['A' => ['state' => 'running'], 'B' => []], [], ['agent_wait' => 6]);
check(run_worker('B') === 0 && job($b)['status'] === 'ok' && !has_call(calls($b), 'qemu-agent-command'), 'VM ohne Agent-Kanal: Prüfung wird übersprungen');

section('15. Wartezeit-Override aus der Oberfläche');
$b = mk(['A' => ['state' => 'running'], 'B' => []]);
run_worker('B', 7);
check(job($b)['wait'] === 7, 'Override 7 s schlägt Standard 2 s');
$b = mk(['A' => ['state' => 'running'], 'B' => []], [], ['vms' => ['B' => ['wait' => 5]]]);
run_worker('B');
check(job($b)['wait'] === 5, 'VM-Profil 5 s schlägt Standard 2 s');

section('16. libvirt nicht erreichbar');
$b = mk(['A' => ['state' => 'running']]);
putenv('VMSWITCH_VIRSH=/nonexistent/virsh');
$code = run_worker('A');
check($code === 1 && str_contains(job($b)['message'], 'libvirt'), 'verständliche Fehlermeldung');

finish_tests();
