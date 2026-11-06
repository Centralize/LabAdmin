<?php
declare(strict_types=1);

/**
 * LabAdmin JSON API.  All calls: POST api.php  body {"action": "...", ...}
 * Every action except auth.status / auth.login / auth.setup requires a session
 * and the X-CSRF-Token header.
 */

use LabAdmin\ApiError;
use LabAdmin\Audit;
use LabAdmin\Auth;
use LabAdmin\Db;
use LabAdmin\Drivers\ProxmoxDriver;
use LabAdmin\Hosts;
use LabAdmin\Shell;
use LabAdmin\SshClient;

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

set_time_limit(960);
ini_set('display_errors', '0');

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new ApiError('POST required', 405);
    }
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        throw new ApiError('Invalid JSON body');
    }
    $action = (string) ($in['action'] ?? '');

    Auth::start();

    // ---- public actions -------------------------------------------------
    switch ($action) {
        case 'auth.status':
            respond(['setup' => Auth::needsSetup(), 'user' => Auth::user(), 'csrf' => Auth::csrf()]);
        case 'auth.setup':
            Auth::setup((string) ($in['username'] ?? ''), (string) ($in['password'] ?? ''));
            respond(['ok' => true, 'user' => Auth::user(), 'csrf' => Auth::csrf()]);
        case 'auth.login':
            Auth::login((string) ($in['username'] ?? ''), (string) ($in['password'] ?? ''));
            respond(['ok' => true, 'user' => Auth::user(), 'csrf' => Auth::csrf()]);
    }

    if (!Auth::user()) {
        throw new ApiError('Not logged in', 401);
    }
    if (!Auth::checkCsrf()) {
        throw new ApiError('CSRF token mismatch - reload the page', 403);
    }
    if ($action === 'auth.logout') {
        Audit::log('logout');
        Auth::logout();
        respond(['ok' => true]);
    }
    if ($action === 'auth.password') {
        Auth::changePassword((string) ($in['current'] ?? ''), (string) ($in['new'] ?? ''));
        respond(['ok' => true]);
    }

    // Release the session lock so the UI can poll several hosts in parallel.
    session_write_close();

    $host   = static fn (): array => Hosts::get((int) ($in['host'] ?? 0));
    $engine = static fn (): string => in_array($in['engine'] ?? '', ['docker', 'lxc'], true) ? $in['engine'] : throw new ApiError('engine must be docker or lxc');
    $id     = static fn (): string => Shell::name((string) ($in['id'] ?? ''), 'container id');

    switch ($action) {
        // ---- hosts --------------------------------------------------------
        case 'hosts.list':
            respond(['hosts' => Hosts::list()]);

        case 'hosts.save':
            respond(['host' => Hosts::save((array) ($in['host'] ?? []))]);

        case 'hosts.delete':
            Hosts::delete((int) ($in['id'] ?? 0));
            respond(['ok' => true]);

        case 'hosts.forgetKey':
            Hosts::forgetHostKey((int) ($in['id'] ?? 0));
            respond(['ok' => true]);

        case 'hosts.reorder':
            foreach (array_values((array) ($in['ids'] ?? [])) as $i => $hid) {
                Db::run('UPDATE hosts SET sort_order = ? WHERE id = ?', [$i, (int) $hid]);
            }
            respond(['ok' => true]);

        case 'hosts.test':
            $h = $host();
            $t = microtime(true);
            SshClient::for($h);
            $caps = Hosts::capabilities(Hosts::get((int) $h['id']), true);
            Audit::log('host.test', $h, null, true);
            respond(['ok' => true, 'ms' => (int) ((microtime(true) - $t) * 1000), 'capabilities' => $caps, 'host' => Hosts::public(Hosts::get((int) $h['id']))]);

        case 'hosts.info':
            $h = $host();
            $info = Hosts::info($h);
            $caps = Hosts::capabilities(Hosts::get((int) $h['id']), !empty($in['refresh']));
            respond(['info' => $info, 'capabilities' => $caps, 'host_key_fp' => Hosts::get((int) $h['id'])['host_key_fp']]);

        case 'hosts.exec':
            $h   = $host();
            $cmd = (string) ($in['cmd'] ?? '');
            if (trim($cmd) === '') {
                throw new ApiError('Command is empty');
            }
            $r = SshClient::for($h)->run($cmd, !empty($in['sudo']), 120);
            Audit::log('host.exec', $h, null, $r['code'] === 0, "exit {$r['code']}", null, $cmd);
            respond($r);

        // ---- containers ---------------------------------------------------
        case 'ct.list':
            $h = $host();
            $result = ['containers' => [], 'engines' => [], 'errors' => []];
            foreach (Hosts::drivers($h) as $eng => $drv) {
                $result['engines'][$eng] = ['flavor' => $drv->flavor(), 'actions' => $drv->actions()];
                try {
                    array_push($result['containers'], ...$drv->list());
                } catch (ApiError $e) {
                    $result['errors'][$eng] = $e->getMessage();
                }
            }
            respond($result);

        case 'ct.action':
            $act = (string) ($in['op'] ?? '');
            $out = Hosts::driver($host(), $engine())->action($id(), $act);
            respond(['ok' => true, 'output' => $out]);

        case 'ct.logs':
            $lines = Shell::int($in['lines'] ?? 200, 1, 10000, 'lines');
            respond(['output' => Hosts::driver($host(), $engine())->logs($id(), $lines)]);

        case 'ct.inspect':
            respond(['output' => Hosts::driver($host(), $engine())->inspect($id())]);

        case 'ct.exec':
            $cmd = (string) ($in['cmd'] ?? '');
            if (trim($cmd) === '') {
                throw new ApiError('Command is empty');
            }
            respond(Hosts::driver($host(), $engine())->exec($id(), $cmd, 120));

        case 'ct.schema':
            $d = Hosts::driver($host(), $engine());
            respond(['schema' => $d->createSchema(), 'flavor' => $d->flavor()]);

        case 'ct.create':
            $out = Hosts::driver($host(), $engine())->create((array) ($in['params'] ?? []));
            respond(['ok' => true, 'output' => $out]);

        // ---- images / templates ------------------------------------------
        case 'img.list':
            $h = $host();
            $res = ['images' => [], 'errors' => []];
            foreach (Hosts::drivers($h) as $eng => $drv) {
                try {
                    foreach ($drv->images() as $img) {
                        $res['images'][] = $img + ['engine' => $eng, 'flavor' => $drv->flavor()];
                    }
                } catch (ApiError $e) {
                    $res['errors'][$eng] = $e->getMessage();
                }
            }
            respond($res);

        case 'img.pull':
            $out = Hosts::driver($host(), $engine())->pullImage((string) ($in['ref'] ?? ''), ['storage' => $in['storage'] ?? 'local']);
            respond(['ok' => true, 'output' => $out]);

        case 'img.remove':
            $out = Hosts::driver($host(), $engine())->removeImage((string) ($in['ref'] ?? ''));
            respond(['ok' => true, 'output' => $out]);

        case 'img.available':
            $d = Hosts::driver($host(), 'lxc');
            if (!$d instanceof ProxmoxDriver) {
                throw new ApiError('Template catalogue is only available on Proxmox');
            }
            respond(['templates' => $d->available()]);

        // ---- audit --------------------------------------------------------
        case 'audit.list':
            $limit  = Shell::int($in['limit'] ?? 100, 1, 1000, 'limit');
            $offset = Shell::int($in['offset'] ?? 0, 0, PHP_INT_MAX, 'offset');
            $where  = [];
            $params = [];
            if (!empty($in['host'])) {
                $where[] = 'host_id = ?';
                $params[] = (int) $in['host'];
            }
            if (!empty($in['q'])) {
                $where[] = '(action LIKE ? OR target LIKE ? OR command LIKE ? OR message LIKE ?)';
                $q = '%' . $in['q'] . '%';
                array_push($params, $q, $q, $q, $q);
            }
            $sql = 'SELECT * FROM audit_log' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT $limit OFFSET $offset";
            respond(['entries' => Db::all($sql, $params)]);
    }

    throw new ApiError("Unknown action '$action'", 404);
} catch (ApiError $e) {
    respond(['error' => $e->getMessage()] + ($e->extra ?? []), $e->status);
} catch (\Throwable $e) {
    error_log('[LabAdmin] ' . $e);
    respond(['error' => 'Server error: ' . $e->getMessage()], 500);
}
