<?php
declare(strict_types=1);

namespace LabAdmin;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

/**
 * Thin wrapper around phpseclib's SSH2 client.
 *  - Trust-on-first-use host key pinning (SHA256 fingerprint stored per host)
 *  - Optional `sudo -n` prefix for container tooling
 *  - One connection per host per request
 */
final class SshClient
{
    /** @var array<int,self> */
    private static array $pool = [];

    private SSH2 $ssh;

    private function __construct(private array $host, private bool $acceptNewKey, private string $sshClass = SSH2::class)
    {
    }

    /**
     * A dedicated (not pooled) connection prepared for an interactive PTY session.
     * Used by the terminal service; host-key pinning and login work exactly as for `for()`.
     */
    public static function interactive(array $host, int $cols, int $rows): self
    {
        $c = new self($host, false, InteractiveSsh::class);
        $c->connect();
        $c->ssh->setTerminal('xterm-256color');
        $c->ssh->setWindowSize(max(20, $cols), max(5, $rows));
        $c->ssh->enablePTY();
        return $c;
    }

    public function raw(): SSH2
    {
        return $this->ssh;
    }

    public static function for(array $host, bool $acceptNewKey = false): self
    {
        $id = (int) $host['id'];
        if (!isset(self::$pool[$id])) {
            $c = new self($host, $acceptNewKey);
            $c->connect();
            self::$pool[$id] = $c;
        }
        return self::$pool[$id];
    }

    public static function fingerprint(string $publicKeyLine): string
    {
        $parts = explode(' ', trim($publicKeyLine));
        $blob  = base64_decode($parts[1] ?? '', true) ?: $publicKeyLine;
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    private function connect(): void
    {
        $cfg = $GLOBALS['LABADMIN_CONFIG'];
        $h   = $this->host;
        $this->ssh = new ($this->sshClass)($h['hostname'], (int) $h['port'], $cfg['ssh_timeout']);

        try {
            $serverKey = @$this->ssh->getServerPublicHostKey();
        } catch (\Throwable $e) {
            $m = $e->getMessage();
            throw new ApiError(str_starts_with($m, 'Cannot connect') ? $m : "Cannot connect to {$h['hostname']}:{$h['port']}: $m", 502);
        }
        if ($serverKey === false) {
            throw new ApiError("Cannot connect to {$h['hostname']}:{$h['port']} (network/SSH handshake failed)", 502);
        }
        $fp = self::fingerprint($serverKey);
        if (!empty($h['host_key_fp']) && !hash_equals($h['host_key_fp'], $fp) && !$this->acceptNewKey) {
            Audit::log('ssh.hostkey_mismatch', $h, null, false, "Pinned {$h['host_key_fp']}, got $fp");
            throw new ApiError(
                "HOST KEY MISMATCH for {$h['name']}. Pinned {$h['host_key_fp']} but server presented $fp. "
                . 'If you reinstalled the server, use "Trust new host key" in the host settings.',
                409,
                ['hostKeyMismatch' => true, 'fingerprint' => $fp]
            );
        }

        $secret = Crypto::decrypt($h['secret_enc'] ?? null);
        if ($h['auth_type'] === 'key') {
            try {
                $pass = Crypto::decrypt($h['passphrase_enc'] ?? null);
                $cred = PublicKeyLoader::load((string) $secret, $pass ?? false);
            } catch (\Throwable $e) {
                throw new ApiError('Cannot load private key: ' . $e->getMessage(), 400);
            }
        } else {
            $cred = (string) $secret;
        }

        if (!@$this->ssh->login($h['username'], $cred)) {
            throw new ApiError("SSH authentication failed for {$h['username']}@{$h['hostname']}", 401);
        }

        if (empty($h['host_key_fp']) || $h['host_key_fp'] !== $fp) {
            Db::run('UPDATE hosts SET host_key_fp = ? WHERE id = ?', [$fp, $h['id']]);
            Audit::log('ssh.hostkey_pinned', $h, null, true, $fp);
            $this->host['host_key_fp'] = $fp;
        }
        $this->ssh->enableQuietMode(); // separate stderr from stdout
    }

    /**
     * Run a command on the host.
     * @param bool $privileged prefix with `sudo -n` when the host is configured for sudo
     * @return array{out:string,err:string,code:int|null,timeout:bool}
     */
    public function run(string $cmd, bool $privileged = true, ?int $timeout = null): array
    {
        // Always run under POSIX sh with stdin from /dev/null. Without a TTY, tools such as
        // `lxc launch` / `incus launch` try to read instance config (YAML) from stdin and
        // would otherwise wait forever, because the SSH channel's stdin never reaches EOF.
        $cmd = ($privileged && !empty($this->host['use_sudo']) ? 'sudo -n sh -c ' : 'sh -c ')
            . Shell::q($cmd) . ' </dev/null';
        $this->ssh->setTimeout($timeout ?? $GLOBALS['LABADMIN_CONFIG']['cmd_timeout']);
        $out = $this->ssh->exec($cmd);
        $res = [
            'out'     => is_string($out) ? $out : '',
            'err'     => (string) $this->ssh->getStdError(),
            'code'    => $this->ssh->getExitStatus() === false ? null : (int) $this->ssh->getExitStatus(),
            'timeout' => $this->ssh->isTimeout(),
        ];
        if ($res['timeout']) {
            // Channel is in an undefined state after a timeout - drop the connection.
            unset(self::$pool[(int) $this->host['id']]);
            $this->ssh->disconnect();
        }
        return $res;
    }

    /** Run and throw on non-zero exit. Returns stdout. */
    public function must(string $cmd, bool $privileged = true, ?int $timeout = null): string
    {
        $r = $this->run($cmd, $privileged, $timeout);
        if ($r['timeout']) {
            throw new ApiError(self::timeoutMessage($r, $timeout ?? $GLOBALS['LABADMIN_CONFIG']['cmd_timeout']), 504);
        }
        if ($r['code'] !== 0) {
            $msg = trim($r['err'] !== '' ? $r['err'] : $r['out']);
            if (str_contains($msg, 'sudo: a password is required')) {
                $msg .= ' — configure NOPASSWD sudo for this user, or connect as root.';
            }
            throw new ApiError($msg !== '' ? $msg : "Command failed (exit {$r['code']})", 500);
        }
        return $r['out'];
    }

    /** Timeout error that keeps whatever the command printed before it stalled. */
    public static function timeoutMessage(array $r, int $seconds): string
    {
        $partial = trim($r['out'] . ($r['err'] !== '' ? "\n" . $r['err'] : ''));
        $partial = preg_replace('/[^\n]*\r(?!\n)/', '', $partial); // keep only the last state of progress lines
        if (strlen($partial) > 4000) {
            $partial = '...' . substr($partial, -4000);
        }
        return "Command timed out after {$seconds}s (it may still be running on the server)."
            . ($partial !== '' ? "\nOutput before it stalled:\n" . $partial : "\nThe command printed nothing before it stalled.");
    }

    public function host(): array
    {
        return $this->host;
    }
}
