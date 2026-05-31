<?php
require __DIR__ . '/../../src/bootstrap.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'method not allowed'], 405);
}

$body    = read_json_body();
$command = trim((string)($body['command'] ?? ''));
if ($command === '') {
    json_response(['ok' => false, 'error' => 'empty command'], 400);
}

$ctrl = new ServerControl($config['control']);
$res  = $ctrl->sendCommand($command);

json_response(['ok' => true, 'result' => $res]);
