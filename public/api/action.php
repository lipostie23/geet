<?php
require __DIR__ . '/../../src/bootstrap.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'method not allowed'], 405);
}

$body   = read_json_body();
$action = (string)($body['action'] ?? '');
$ctrl   = new ServerControl($config['control']);

$result = match ($action) {
    'start'   => $ctrl->start(),
    'stop'    => $ctrl->stop(),
    'restart' => $ctrl->restart(),
    default   => null,
};

if ($result === null) {
    json_response(['ok' => false, 'error' => 'unknown action'], 400);
}

json_response(['ok' => true, 'action' => $action, 'result' => $result]);
