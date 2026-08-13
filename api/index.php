<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use EsxiV9\DTO\VMConfig;
use EsxiV9\Exceptions\ESXiException;

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

// Works whether this front controller is reached at /api/... (via a
// rewrite rule / reverse proxy) or served directly at /.
$path = preg_replace('#^/api#', '', $path);
$path = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}

try {
    // ---------------- Auth ----------------

    if ($method === 'POST' && $path === '/login') {
        $body = json_body();

        foreach (['host', 'username', 'password'] as $field) {
            if (empty($body[$field])) {
                fail("Missing field: {$field}");
            }
        }

        $host = trim($body['host']);
        $host = preg_replace('#^https?://#i', '', $host);
        $host = rtrim($host, '/');

        $_SESSION['esxi'] = [
            'host' => $host,
            'username' => $body['username'],
            'password' => $body['password'],
            'ssl' => (bool) ($body['ssl'] ?? true),
            'allowSelfSigned' => (bool) ($body['allowSelfSigned'] ?? true),
        ];

        // Fail fast if the credentials are actually wrong, instead of
        // waiting for the first real API call to discover it.
        esxiClientFromSession();

        respond(['ok' => true, 'host' => $body['host']]);
    }

    if ($method === 'POST' && $path === '/logout') {
        unset($_SESSION['esxi']);
        respond(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/session') {
        respond([
            'authenticated' => !empty($_SESSION['esxi']),
            'host' => $_SESSION['esxi']['host'] ?? null,
        ]);
    }

    // ---------------- Datastores / Networks ----------------

    if ($method === 'GET' && $path === '/datastores') {
        $client = esxiClientFromSession();
        respond($client->datastore()->list());
    }

    if ($method === 'GET' && $path === '/networks') {
        $client = esxiClientFromSession();
        respond($client->network()->list());
    }

    // ---------------- VMs ----------------

    if ($method === 'GET' && $path === '/vms') {
        $client = esxiClientFromSession();
        respond($client->vm()->list());
    }

    if ($method === 'POST' && $path === '/vms') {
        $client = esxiClientFromSession();
        $body = json_body();

        if (empty($body['name'])) {
            fail('Missing field: name');
        }

        $config = new VMConfig();
        $config->setName($body['name']);
        $config->setCpu((int) ($body['cpu'] ?? 1));
        $config->setMemory((int) ($body['memoryMB'] ?? 2048)); // MB
        $config->setStorage((int) ($body['storageGB'] ?? 20)); // GB

        if (!empty($body['datastore'])) {
            $config->setDatastore($body['datastore']);
        }
        if (!empty($body['network'])) {
            $config->setNetwork($body['network']);
        }
        if (!empty($body['iso'])) {
            $config->setIso($body['iso']);
        }
        if (!empty($body['guestId'])) {
            $config->setGuestId($body['guestId']);
        }

        // Blocks until ESXi finishes creating the VM (can take a while for
        // a full disk allocation); the frontend should show a spinner.
        $vmId = $client->vm()->createAndWait($config, 180);

        respond(['id' => $vmId], 201);
    }

    if ($method === 'GET' && preg_match('#^/vms/([^/]+)$#', $path, $m)) {
        $client = esxiClientFromSession();
        respond($client->vm()->get(urldecode($m[1])));
    }

    if ($method === 'DELETE' && preg_match('#^/vms/([^/]+)$#', $path, $m)) {
        $client = esxiClientFromSession();
        $result = $client->vm()->removeAndWait(urldecode($m[1]));

        if (!$result->isSuccess()) {
            fail('Delete failed: ' . ($result->getError() ?? 'unknown error'), 500);
        }

        respond(['ok' => true]);
    }

    if ($method === 'POST' && preg_match('#^/vms/([^/]+)/power$#', $path, $m)) {
        $client = esxiClientFromSession();
        $body = json_body();
        $vmId = urldecode($m[1]);
        $action = $body['action'] ?? '';

        $result = match ($action) {
            'on' => $client->vm()->powerOnAndWait($vmId),
            'off' => $client->vm()->powerOffAndWait($vmId),
            'reset' => $client->vm()->rebootAndWait($vmId),
            default => fail('Invalid action, expected on|off|reset'),
        };

        if (!$result->isSuccess()) {
            fail('Power action failed: ' . ($result->getError() ?? 'unknown error'), 500);
        }

        respond(['ok' => true]);
    }

    if ($method === 'POST' && preg_match('#^/vms/([^/]+)/resize$#', $path, $m)) {
        $client = esxiClientFromSession();
        $body = json_body();
        $vmId = urldecode($m[1]);

        $cpu = isset($body['cpu']) ? (int) $body['cpu'] : null;
        $memoryMB = isset($body['memoryMB']) ? (int) $body['memoryMB'] : null;

        $result = $client->vm()->resizeAndWait($vmId, $cpu, $memoryMB);

        if (!$result->isSuccess()) {
            fail('Resize failed: ' . ($result->getError() ?? 'unknown error'), 500);
        }

        respond(['ok' => true]);
    }

    fail('Not found', 404);
} catch (ESXiException $e) {
    // Errors that came from talking to ESXi itself (auth, VM creation, ...).
    fail($e->getMessage(), 502);
} catch (\Throwable $e) {
    fail('Unexpected error: ' . $e->getMessage(), 500);
}