<?php
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$PLUG = "$ROOT/source/usr/local/emhttp/plugins/vmswitch";
$fails = 0;
$total = 0;

function check(bool $cond, string $msg): void
{
    global $fails, $total;
    $total++;
    if (!$cond) {
        $fails++;
        echo "  FEHLER: $msg\n";
    } else {
        echo "  ok: $msg\n";
    }
}

function section(string $t): void
{
    echo "\n== $t\n";
}

/*
 * Baut eine simulierte Umgebung. $vms: name => ['state'=>..., 'pci'=>[adressen]].
 * Gibt den Basispfad zurück und setzt alle VMSWITCH_*-Variablen für Kindprozesse.
 */
function mk(array $vms, array $containers = [], array $settings = [], array $missingPci = [], float $speed = 0.002): string
{
    global $ROOT;
    $base = sys_get_temp_dir() . '/vmswitch-test-' . bin2hex(random_bytes(4));
    foreach (['state', 'xml', 'crash', 'fail_start', 'hang', 'stuck', 'agent_ok', 'docker', 'disks', 'sys/bus/pci/devices', 'cfg', 'run', 'qemulog'] as $s) {
        mkdir("$base/$s", 0777, true);
    }
    $addrs = [];
    $links = [];
    foreach ($vms as $name => $cfg) {
        file_put_contents("$base/state/$name", $cfg['state'] ?? 'shut off');
        $xml = '<domain type="kvm"><name>' . htmlspecialchars((string)$name) . '</name><devices>';
        foreach ($cfg['pci'] ?? [] as $addr) {
            $addrs[$addr] = true;
            [$dom, $bus, $rest] = explode(':', $addr);
            [$slot, $fn] = explode('.', $rest);
            $xml .= "<hostdev mode='subsystem' type='pci' managed='yes'><source><address domain='0x$dom' bus='0x$bus' slot='0x$slot' function='0x$fn'/></source></hostdev>";
        }
        foreach ($cfg['usb'] ?? [] as $u) {
            [$v, $p] = explode(':', $u);
            $xml .= "<hostdev mode='subsystem' type='usb'><source><vendor id='0x$v'/><product id='0x$p'/></source></hostdev>";
        }
        foreach ($cfg['disks'] ?? [] as $dk) {
            $dk = is_array($dk) ? $dk : ['file' => $dk];
            $path = str_replace('{BASE}', $base, $dk['file']);
            $dev = $dk['device'] ?? 'disk';
            $ro = !empty($dk['readonly']) ? '<readonly/>' : '';
            $xml .= "<disk type='file' device='$dev'><driver name='qemu' type='raw'/><source file='$path'/><target dev='vd" . substr(md5($path), 0, 2) . "' bus='virtio'/>$ro</disk>";
            if (!empty($dk['link'])) {
                $links[] = [str_replace('{BASE}', $base, $dk['link']), $path];
            } elseif (empty($dk['missing'])) {
                @mkdir(dirname($path), 0777, true);
                touch($path);
            }
        }
        if (!empty($cfg['agent'])) {
            $xml .= "<channel type='unix'><target type='virtio' name='org.qemu.guest_agent.0'/></channel>";
        }
        $xml .= '</devices></domain>';
        file_put_contents("$base/xml/$name.xml", $xml);
    }
    foreach ($links as [$from, $to]) {
        @mkdir(dirname($to), 0777, true);
        link($from, $to);
    }
    foreach (array_keys($addrs) as $addr) {
        if (in_array($addr, $missingPci, true)) {
            continue;
        }
        mkdir("$base/sys/bus/pci/devices/$addr", 0777, true);
        file_put_contents("$base/sys/bus/pci/devices/$addr/class", str_starts_with($addr, '0000:01:') ? "0x030000\n" : "0x0c0330\n");
    }
    foreach ($containers as $c => $state) {
        file_put_contents("$base/docker/$c", $state);
    }
    $defaults = ['stop_mode' => 'all', 'wait_default' => 2, 'shutdown_timeout' => 10, 'start_timeout' => 10, 'health_secs' => 4, 'notify' => false];
    file_put_contents("$base/cfg/settings.json", json_encode(array_replace($defaults, $settings)));
    file_put_contents("$base/var.ini", "csrf_token=\"test-token-123\"\n");
    foreach ([
        'MOCK_DIR' => $base,
        'VMSWITCH_CFG_DIR' => "$base/cfg",
        'VMSWITCH_RUN_DIR' => "$base/run",
        'VMSWITCH_VIRSH' => "$ROOT/tests/mock/virsh",
        'VMSWITCH_DOCKER' => "$ROOT/tests/mock/docker",
        'VMSWITCH_SYSFS' => "$base/sys",
        'VMSWITCH_QEMULOG_DIR' => "$base/qemulog",
        'VMSWITCH_VAR_INI' => "$base/var.ini",
        'VMSWITCH_SPEED' => (string)$speed,
        'VMSWITCH_PHP' => PHP_BINARY,
    ] as $k => $v) {
        putenv("$k=$v");
    }
    return $base;
}

function worker_cmd(string $target, ?int $wait = null): array
{
    global $PLUG;
    $cmd = [PHP_BINARY, "$PLUG/scripts/switch.php", '--target=' . $target];
    if ($wait !== null) {
        $cmd[] = '--wait=' . $wait;
    }
    return $cmd;
}

function run_worker(string $target, ?int $wait = null): int
{
    $p = proc_open(worker_cmd($target, $wait), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($p);
}

function vm_state(string $base, string $n): string { return trim((string)@file_get_contents("$base/state/$n")); }
function calls(string $base): array { return file("$base/calls.log", FILE_IGNORE_NEW_LINES) ?: []; }
function job(string $base): array { return json_decode((string)file_get_contents("$base/run/state.json"), true) ?: []; }
function flag(string $base, string $kind, string $vm, string $content = '1'): void { file_put_contents("$base/$kind/$vm", $content); }

function call_index(array $calls, string $needle): int
{
    foreach ($calls as $i => $c) {
        if (str_contains($c, $needle)) {
            return $i;
        }
    }
    return -1;
}

function has_call(array $calls, string $needle): bool { return call_index($calls, $needle) >= 0; }

function finish_tests(): never
{
    global $fails, $total;
    echo "\n" . ($total - $fails) . " von $total Prüfungen bestanden.\n";
    exit($fails === 0 ? 0 : 1);
}
