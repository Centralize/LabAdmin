<?php
declare(strict_types=1);

namespace LabAdmin;

use phpseclib3\Net\SSH2;

/**
 * Interactive terminal service: browser (xterm.js) <-WebSocket-> this process <-SSH PTY-> server/container.
 *
 * Pure PHP (stream_select), no extensions, works on Windows and Linux.
 *
 * Auth: the browser first calls api.php `term.open` (logged-in session + CSRF), which stores a
 * random one-time token valid for 60 s. The WebSocket URL carries that token; it is consumed on
 * connect. The Origin header must match the Host the page was served from.
 *
 * Protocol (after the WebSocket handshake):
 *   browser -> server: text frames, JSON  {"t":"d","d":"<keystrokes>"} | {"t":"r","c":cols,"r":rows}
 *   server -> browser: binary frames with raw terminal output; text frames JSON {"t":"x","m":"..."} on exit/error
 */
final class TerminalServer
{
    private const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const MAX_FRAME = 1048576;     // 1 MiB per incoming frame
    private const MAX_OUTBUF = 8388608;    // pause SSH reads above 8 MiB queued to a slow browser

    /** @var resource */
    private $server;
    /** @var array<int,array<string,mixed>> */
    private array $clients = [];
    private int $nextId = 1;

    public function __construct(private array $cfg)
    {
    }

    public function run(): void
    {
        $addr = "tcp://{$this->cfg['terminal_listen']}:{$this->cfg['terminal_port']}";
        $srv  = @stream_socket_server($addr, $errno, $errstr);
        if (!$srv) {
            fwrite(STDERR, "LabAdmin terminal: cannot listen on $addr: $errstr ($errno)\n");
            exit(1);
        }
        stream_set_blocking($srv, false);
        $this->server = $srv;
        $this->log("listening on $addr");

        while (true) {
            $read = [$this->server];
            $write = [];
            $hasSsh = false;
            foreach ($this->clients as $c) {
                $read[] = $c['sock'];
                if ($c['out'] !== '') {
                    $write[] = $c['sock'];
                }
                if ($c['ssh'] instanceof SSH2) {
                    $hasSsh = true;
                    if (is_resource($c['ssh']->fsock)) {
                        $read[] = $c['ssh']->fsock;
                    }
                }
            }
            $except = null;
            // With live SSH sessions wake up often: phpseclib may hold already-decrypted data.
            @stream_select($read, $write, $except, 0, $hasSsh ? 25000 : 500000);

            if (in_array($this->server, $read, true)) {
                $this->accept();
            }
            foreach ($this->clients as $id => $c) {
                if (in_array($c['sock'], $read, true)) {
                    $this->onReadable($id);
                }
            }
            foreach ($this->clients as $id => $c) {
                if (isset($this->clients[$id]) && $this->clients[$id]['ssh'] instanceof SSH2) {
                    $this->pumpSsh($id);
                }
            }
            foreach ($this->clients as $id => $c) {
                if ($c['out'] !== '' && in_array($c['sock'], $write, true)) {
                    $this->flush($id);
                }
            }
            $this->housekeeping();
        }
    }

    // ------------------------------------------------------------------ connections

    private function accept(): void
    {
        $sock = @stream_socket_accept($this->server, 0, $peer);
        if (!$sock) {
            return;
        }
        if (count($this->clients) >= (int) $this->cfg['terminal_max_sessions']) {
            fwrite($sock, "HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            fclose($sock);
            return;
        }
        stream_set_blocking($sock, false);
        $id = $this->nextId++;
        $this->clients[$id] = [
            'sock' => $sock, 'peer' => (string) $peer, 'state' => 'http', 'in' => '', 'out' => '',
            'ssh' => null, 'frag' => '', 'fragOp' => 0, 'closing' => false,
            'opened' => time(), 'lastInput' => time(), 'host' => null, 'target' => null, 'user' => null,
        ];
    }

    private function onReadable(int $id): void
    {
        $data = @fread($this->clients[$id]['sock'], 65536);
        if ($data === '' || $data === false) {
            if (feof($this->clients[$id]['sock'])) {
                $this->drop($id, 'browser disconnected');
            }
            return;
        }
        $this->clients[$id]['in'] .= $data;
        if ($this->clients[$id]['state'] === 'http') {
            $this->handshake($id);
        } else {
            $this->parseFrames($id);
        }
    }

    private function handshake(int $id): void
    {
        $c = &$this->clients[$id];
        $end = strpos($c['in'], "\r\n\r\n");
        if ($end === false) {
            if (strlen($c['in']) > 16384) {
                $this->httpReject($id, 400, 'Bad Request');
            }
            return;
        }
        $head = substr($c['in'], 0, $end);
        $c['in'] = substr($c['in'], $end + 4);
        $lines = explode("\r\n", $head);
        if (!preg_match('#^GET (\S+) HTTP/1\.1$#', array_shift($lines), $m)) {
            $this->httpReject($id, 400, 'Bad Request');
            return;
        }
        $uri = $m[1];
        $h = [];
        foreach ($lines as $l) {
            if (str_contains($l, ':')) {
                [$k, $v] = explode(':', $l, 2);
                $h[strtolower(trim($k))] = trim($v);
            }
        }
        if (stripos($h['upgrade'] ?? '', 'websocket') === false || empty($h['sec-websocket-key'])) {
            $this->httpReject($id, 426, 'Upgrade Required');
            return;
        }
        // Origin must match the host the page came from (blocks other sites from using a leaked token).
        $originHost = trim(strtolower((string) parse_url($h['origin'] ?? '', PHP_URL_HOST)), '[]');
        $hostHeader = strtolower(trim($h['host'] ?? ''));
        $hostHeader = str_starts_with($hostHeader, '[') ? substr($hostHeader, 1, (int) strpos($hostHeader, ']') - 1)
            : (string) preg_replace('/:\d+$/', '', $hostHeader);
        if ($originHost === '' || ($originHost !== $hostHeader && !in_array($originHost, (array) ($this->cfg['terminal_allowed_origins'] ?? []), true))) {
            $this->log("rejected origin '{$originHost}' (host '{$hostHeader}') from {$c['peer']}");
            $this->httpReject($id, 403, 'Forbidden');
            return;
        }
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
        $token = is_string($q['token'] ?? null) ? $q['token'] : '';
        $cols = (int) ($q['cols'] ?? 120);
        $rows = (int) ($q['rows'] ?? 32);

        $tok = $this->consumeToken($token);
        if (!$tok) {
            $this->httpReject($id, 401, 'Unauthorized');
            return;
        }

        $accept = base64_encode(sha1($h['sec-websocket-key'] . self::WS_GUID, true));
        $c['out'] .= "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n";
        $c['state'] = 'ws';
        $c['user'] = $tok['username'];
        unset($c);
        $this->flush($id);
        $this->startSession($id, $tok, $cols, $rows);
    }

    private function httpReject(int $id, int $code, string $text): void
    {
        $c = &$this->clients[$id];
        $c['out'] .= "HTTP/1.1 $code $text\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
        $c['closing'] = true;
        unset($c);
        $this->flush($id);
    }

    private function consumeToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        Db::run('DELETE FROM term_tokens WHERE expires < ?', [time()]);
        $row = Db::one('SELECT * FROM term_tokens WHERE token = ?', [$token]);
        if (!$row) {
            return null;
        }
        Db::run('DELETE FROM term_tokens WHERE token = ?', [$token]); // one-time
        return $row;
    }

    // ------------------------------------------------------------------ SSH session

    private function startSession(int $id, array $tok, int $cols, int $rows): void
    {
        try {
            $host = Hosts::get((int) $tok['host_id']);
            $this->clients[$id]['host'] = $host;
            $label = $tok['target'] ? "{$tok['engine']}:{$tok['target']}" : 'host shell';
            $this->sendText($id, "\x1b[2m# Connecting to {$host['name']} ({$label})…\x1b[0m\r\n");
            $this->flush($id);

            if ($tok['target']) {
                $drv = Hosts::driver($host, (string) $tok['engine']);
                $cmd = $drv->terminalCommand((string) $tok['target']);
                if (!empty($host['use_sudo'])) {
                    $cmd = 'sudo -n ' . $cmd;
                }
            } else {
                $cmd = 'exec "${SHELL:-/bin/sh}" -l';
            }

            $client = SshClient::interactive($host, $cols, $rows);
            /** @var InteractiveSsh $ssh */
            $ssh = $client->raw();
            $ssh->setKeepAlive(30);
            $ssh->setTimeout(10);
            $ssh->exec($cmd);           // with PTY enabled this returns immediately
            $ssh->setTimeout(0.001);
            $this->clients[$id]['ssh'] = $ssh;
            $this->clients[$id]['target'] = $label;
            Audit::log('term.open', $host, $tok['target'] ? "{$tok['engine']}:{$tok['target']}" : null, true,
                "from {$this->clients[$id]['peer']}", $tok['username'], $cmd);
            $this->log("session $id: {$tok['username']} -> {$host['name']} ($label)");
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            Audit::log('term.open', $this->clients[$id]['host'] ?? null, $tok['target'] ?: null, false, $msg, $tok['username']);
            $this->sendText($id, "\r\n\x1b[31m" . str_replace("\n", "\r\n", $msg) . "\x1b[0m\r\n");
            $this->sendJson($id, ['t' => 'x', 'm' => $msg, 'error' => true]);
            $this->closeWs($id);
        }
    }

    private function pumpSsh(int $id): void
    {
        $c = &$this->clients[$id];
        /** @var InteractiveSsh $ssh */
        $ssh = $c['ssh'];
        if (strlen($c['out']) > self::MAX_OUTBUF) {
            return; // browser is slow - let it catch up first (TCP back-pressure to the server)
        }
        unset($c);
        try {
            if ($ssh->isPTYOpen()) {
                $ssh->setTimeout(0.001);
                for ($i = 0; $i < 64; $i++) {
                    $data = $ssh->read('', SSH2::READ_NEXT);
                    if (!is_string($data) || $data === '') {
                        break;
                    }
                    $this->sendBinary($id, $data);
                    if (!$ssh->isPTYOpen()) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            if ($ssh->isConnected() && $ssh->isPTYOpen()) {
                $this->endSession($id, 'Connection lost: ' . $e->getMessage());
                return;
            }
        }
        if (!$ssh->isConnected() || !$ssh->isPTYOpen()) {
            $code = $ssh->getExitStatus();
            $this->endSession($id, 'Session ended' . ($code !== false && $code !== null ? " (exit $code)" : ''));
        }
    }

    private function onMessage(int $id, string $payload): void
    {
        $msg = json_decode($payload, true);
        if (!is_array($msg)) {
            return;
        }
        $ssh = $this->clients[$id]['ssh'];
        if (!$ssh instanceof InteractiveSsh) {
            return;
        }
        try {
            if (($msg['t'] ?? '') === 'd' && is_string($msg['d'] ?? null)) {
                $this->clients[$id]['lastInput'] = time();
                $ssh->setTimeout(10);
                $ssh->write($msg['d']);
                $ssh->setTimeout(0.001);
            } elseif (($msg['t'] ?? '') === 'r') {
                $ssh->resize((int) ($msg['c'] ?? 80), (int) ($msg['r'] ?? 24));
            }
        } catch (\Throwable $e) {
            $this->endSession($id, 'Connection lost: ' . $e->getMessage());
        }
    }

    private function endSession(int $id, string $why): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $this->sendText($id, "\r\n\x1b[2m# $why\x1b[0m\r\n");
        $this->sendJson($id, ['t' => 'x', 'm' => $why]);
        $this->closeWs($id);
    }

    // ------------------------------------------------------------------ WebSocket framing

    private function parseFrames(int $id): void
    {
        while (isset($this->clients[$id])) {
            $buf = $this->clients[$id]['in'];
            $len = strlen($buf);
            if ($len < 2) {
                return;
            }
            $b0 = ord($buf[0]);
            $b1 = ord($buf[1]);
            $fin = ($b0 & 0x80) !== 0;
            $op = $b0 & 0x0f;
            $masked = ($b1 & 0x80) !== 0;
            $plen = $b1 & 0x7f;
            $off = 2;
            if ($plen === 126) {
                if ($len < 4) { return; }
                $plen = unpack('n', substr($buf, 2, 2))[1];
                $off = 4;
            } elseif ($plen === 127) {
                if ($len < 10) { return; }
                $plen = unpack('J', substr($buf, 2, 8))[1];
                $off = 10;
            }
            if (!$masked || $plen > self::MAX_FRAME) {
                $this->drop($id, 'protocol error');
                return;
            }
            if ($len < $off + 4 + $plen) {
                return; // wait for the rest
            }
            $mask = substr($buf, $off, 4);
            $payload = substr($buf, $off + 4, $plen);
            $this->clients[$id]['in'] = (string) substr($buf, $off + 4 + $plen);
            $payload = $payload ^ str_repeat($mask, intdiv($plen, 4) + 1);
            $payload = substr($payload, 0, $plen);

            switch ($op) {
                case 0x0: // continuation
                    $this->clients[$id]['frag'] .= $payload;
                    if ($fin) {
                        $p = $this->clients[$id]['frag'];
                        $this->clients[$id]['frag'] = '';
                        $this->onMessage($id, $p);
                    }
                    break;
                case 0x1:
                case 0x2:
                    if ($fin) {
                        $this->onMessage($id, $payload);
                    } else {
                        $this->clients[$id]['frag'] = $payload;
                    }
                    break;
                case 0x8: // close
                    $this->drop($id, 'browser closed the terminal');
                    return;
                case 0x9: // ping -> pong
                    $this->frame($id, 0xA, $payload);
                    break;
                default:  // pong / unknown: ignore
                    break;
            }
        }
    }

    private function frame(int $id, int $op, string $payload): void
    {
        if (!isset($this->clients[$id]) || $this->clients[$id]['state'] !== 'ws') {
            return;
        }
        $len = strlen($payload);
        $head = chr(0x80 | $op);
        if ($len < 126) {
            $head .= chr($len);
        } elseif ($len < 65536) {
            $head .= chr(126) . pack('n', $len);
        } else {
            $head .= chr(127) . pack('J', $len);
        }
        $this->clients[$id]['out'] .= $head . $payload;
    }

    private function sendBinary(int $id, string $data): void { $this->frame($id, 0x2, $data); }
    private function sendText(int $id, string $text): void { $this->frame($id, 0x2, $text); }
    private function sendJson(int $id, array $msg): void { $this->frame($id, 0x1, (string) json_encode($msg)); }

    private function closeWs(int $id): void
    {
        $this->frame($id, 0x8, pack('n', 1000));
        if (isset($this->clients[$id])) {
            $this->clients[$id]['closing'] = true;
            $this->disconnectSsh($id);
            $this->flush($id);
        }
    }

    private function flush(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $c = &$this->clients[$id];
        if ($c['out'] !== '') {
            $n = @fwrite($c['sock'], $c['out']);
            if ($n === false) {
                unset($c);
                $this->drop($id, 'write failed');
                return;
            }
            $c['out'] = (string) substr($c['out'], $n);
        }
        if ($c['closing'] && $c['out'] === '') {
            unset($c);
            $this->drop($id, null);
        }
    }

    private function drop(int $id, ?string $why): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $c = $this->clients[$id];
        $this->disconnectSsh($id);
        @fclose($c['sock']);
        unset($this->clients[$id]);
        if ($why !== null && $c['host']) {
            $this->log("session $id closed: $why");
        }
    }

    private function disconnectSsh(int $id): void
    {
        $c = &$this->clients[$id];
        if ($c['ssh'] instanceof SSH2) {
            $mins = (int) round((time() - $c['opened']) / 60);
            Audit::log('term.close', $c['host'], $c['target'] === 'host shell' ? null : $c['target'], true, "after {$mins} min", $c['user']);
            try {
                $c['ssh']->disconnect();
            } catch (\Throwable) {
            }
            $c['ssh'] = null;
        }
    }

    private function housekeeping(): void
    {
        static $last = 0;
        if (time() - $last < 15) {
            return;
        }
        $last = time();
        $idle = (int) $this->cfg['terminal_idle_minutes'] * 60;
        foreach ($this->clients as $id => $c) {
            if ($c['state'] === 'http' && time() - $c['opened'] > 15) {
                $this->drop($id, 'handshake timeout');
            } elseif ($c['ssh'] && $idle > 0 && time() - $c['lastInput'] > $idle) {
                $this->endSession($id, 'Closed after ' . $this->cfg['terminal_idle_minutes'] . ' idle minutes');
            }
        }
    }

    private function log(string $m): void
    {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] $m\n");
    }
}
