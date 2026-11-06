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

    private function __construct(private array $host, private bool $acceptNewKey)
    {
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
        $this->ssh = new SSH2($h['hostname'], (int) $h['port'], $cfg['ssh_timeout']);

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
        if ($privileged && !empty($this->host['use_sudo'])) {
            $cmd = 'sudo -n sh -c ' . Shell::q($cmd);
        }
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
            throw new ApiError('Command timed out', 504);
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

    public function host(): array
    {
        return $this->host;
    }
}
