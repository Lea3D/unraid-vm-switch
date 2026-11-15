<?php
// Testharness: führt api.php wie ein POST-Request aus. Aufruf: php run_api.php '{"method":"POST","post":{...}}'
$req = json_decode($argv[1] ?? '{}', true);
$_SERVER['REQUEST_METHOD'] = $req['method'] ?? 'POST';
$_POST = $req['post'] ?? [];
register_shutdown_function(function () {
    echo "\n@@HTTP:" . http_response_code();
});
require dirname(__DIR__) . '/source/usr/local/emhttp/plugins/vmswitch/include/api.php';
