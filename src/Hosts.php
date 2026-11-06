<?php
declare(strict_types=1);

namespace LabAdmin;

use LabAdmin\Drivers\ContainerDriver;
use LabAdmin\Drivers\DockerDriver;
use LabAdmin\Drivers\IncusDriver;
use LabAdmin\Drivers\LxcClassicDriver;
use LabAdmin\Drivers\ProxmoxDriver;

final class Hosts
{
    public static function get(int $id): array
    {
        $h = Db::one('SELECT * FROM hosts WHERE id = ?', [$id]);
        if (!$h) {
            throw new ApiError('Host not found', 404);
        }
        return $h;
    }

    /** Public view - never expose secrets. */
    public static function public(array $h): array
    {
        return [
            'id'          => (int) $h['id'],
            'name'        => $h['name'],
            'hostname'    => $h['hostname'],
            'port'        => (int) $h['port'],
            'username'    => $h['username'],
            'auth_type'   => $h['auth_type'],
            'has_secret'  => !empty($h['secret_enc']),
            'use_sudo'    => (bool) $h['use_sudo'],
            'host_key_fp' => $h['host_key_fp'],
            'capabilities'=> $h['capabilities'] ? json_decode($h['capabilities'], true) : null,
            'tags'        => $h['tags'],
            'notes'       => $h['notes'],
        ];
    }

    public static function list(): array
    {
        return array_map(self::public(...), Db::all('SELECT * FROM hosts ORDER BY sort_order, name COLLATE NOCASE'));
    }

    public static function save(array $in): array
    {
        $id   = isset($in['id']) && $in['id'] !== '' ? (int) $in['id'] : null;
        $old  = $id ? self::get($id) : null;
        $name = trim((string) ($in['name'] ?? ''));
        $hn   = trim((string) ($in['hostname'] ?? ''));
        $user = trim((string) ($in['username'] ?? ''));
        $auth = ($in['auth_type'] ?? 'key') === 'password' ? 'password' : 'key';

        if ($name === '' || mb_strlen($name) > 64) {
            throw new ApiError('Display name is required (max 64 chars)');
        }
        if (!preg_match('/^[A-Za-z0-9.:\[\]_-]{1,253}$/', $hn)) {
            throw new ApiError('Invalid hostname / IP');
        }
        if (!preg_match('/^[A-Za-z0-9._-][A-Za-z0-9._@-]{0,63}$/', $user)) {
            throw new ApiError('Invalid SSH username');
        }
        $port = Shell::int($in['port'] ?? 22, 1, 65535, 'port');

        $secret = (string) ($in['secret'] ?? '');
        $pass   = (string) ($in['passphrase'] ?? '');
        $secretEnc = $secret !== '' ? Crypto::encrypt(str_replace("\r\n", "\n", $secret)) : ($old['secret_enc'] ?? null);
        $passEnc   = $pass !== '' ? Crypto::encrypt($pass) : ($old['passphrase_enc'] ?? null);
        if (!empty($in['clear_passphrase'])) {
            $passEnc = null;
        }
        if ($old && $old['auth_type'] !== $auth && $secret === '') {
            throw new ApiError('Authentication type changed - please provide the new ' . ($auth === 'key' ? 'private key' : 'password'));
        }
        if (!$secretEnc) {
            throw new ApiError($auth === 'key' ? 'Private key is required' : 'Password is required');
        }
        if ($auth === 'key' && $secret !== '') {
            try {
                \phpseclib3\Crypt\PublicKeyLoader::load($secret, $pass !== '' ? $pass : (Crypto::decrypt($passEnc) ?? false));
            } catch (\Throwable $e) {
                throw new ApiError('Private key could not be parsed: ' . $e->getMessage());
            }
        }

        $useSudo = !empty($in['use_sudo']) ? 1 : 0;
        $tags    = mb_substr(trim((string) ($in['tags'] ?? '')), 0, 200);
        $notes   = mb_substr((string) ($in['notes'] ?? ''), 0, 4000);

        // Connection details changed → forget pinned key and detected caps.
        $resetFp = $old && ($old['hostname'] !== $hn || (int) $old['port'] !== $port);

        if ($old) {
            Db::run(
                'UPDATE hosts SET name=?, hostname=?, port=?, username=?, auth_type=?, secret_enc=?, passphrase_enc=?, use_sudo=?, tags=?, notes=?,'
                . ' host_key_fp = CASE WHEN ? THEN NULL ELSE host_key_fp END,'
                . ' capabilities = NULL, updated_at = datetime(\'now\') WHERE id = ?',
                [$name, $hn, $port, $user, $auth, $secretEnc, $passEnc, $useSudo, $tags, $notes, $resetFp ? 1 : 0, $id]
            );
            Audit::log('host.update', ['id' => $id, 'name' => $name]);
        } else {
            Db::run(
                'INSERT INTO hosts (name, hostname, port, username, auth_type, secret_enc, passphrase_enc, use_sudo, tags, notes, sort_order)'
                . ' VALUES (?,?,?,?,?,?,?,?,?,?, (SELECT COALESCE(MAX(sort_order),0)+1 FROM hosts))',
                [$name, $hn, $port, $user, $auth, $secretEnc, $passEnc, $useSudo, $tags, $notes]
            );
            $id = Db::lastId();
            Audit::log('host.create', ['id' => $id, 'name' => $name]);
        }
        return self::public(self::get($id));
    }

    public static function delete(int $id): void
    {
        $h = self::get($id);
        Db::run('DELETE FROM hosts WHERE id = ?', [$id]);
        Audit::log('host.delete', $h);
    }

    public static function forgetHostKey(int $id): void
    {
        $h = self::get($id);
        Db::run('UPDATE hosts SET host_key_fp = NULL WHERE id = ?', [$id]);
        Audit::log('ssh.hostkey_reset', $h);
    }

    /**
     * Detect which container engines a host offers. Cached in hosts.capabilities.
     * @return array{docker:?string, lxc:?string, versions:array<string,string>}
     */
    public static function capabilities(array $host, bool $refresh = false): array
    {
        if (!$refresh && !empty($host['capabilities'])) {
            return json_decode($host['capabilities'], true);
        }
        $ssh = SshClient::for($host);
        $script = <<<'SH'
            has() { command -v "$1" >/dev/null 2>&1; }
            if has docker; then v=$(docker version --format '{{.Server.Version}}' 2>&1) && echo "docker=$v" || echo "docker_err=$(echo "$v" | tr '\n' ' ' | cut -c1-300)"; fi
            if has pct; then echo "pct=$(pveversion 2>/dev/null | head -1)"; fi
            if has incus; then v=$(incus version 2>/dev/null | awk -F': ' '/Server/{print $2}'); echo "incus=${v:-unknown}"; fi
            if has lxc && lxc list --format csv -c n >/dev/null 2>&1; then v=$(lxc version 2>/dev/null | awk -F': ' '/Server/{print $2}'); echo "lxd=${v:-unknown}"; fi
            if has lxc-ls; then echo "lxc=$(lxc-ls --version 2>/dev/null)"; fi
            true
            SH;
        $out  = $ssh->must($script, true, 30);
        $vals = [];
        foreach (Shell::lines($out) as $line) {
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $vals[$k] = trim($v);
        }
        $lxc = null;
        foreach (['pct', 'incus', 'lxd', 'lxc'] as $flavor) { // priority order
            if (isset($vals[$flavor])) {
                $lxc = $flavor;
                break;
            }
        }
        $caps = [
            'docker'      => isset($vals['docker']) ? 'docker' : null,
            'docker_error'=> $vals['docker_err'] ?? null,
            'lxc'         => $lxc,
            'versions'    => array_diff_key($vals, ['docker_err' => 1]),
            'detected_at' => date('c'),
        ];
        Db::run('UPDATE hosts SET capabilities = ? WHERE id = ?', [json_encode($caps), $host['id']]);
        return $caps;
    }

    /** @return array<string,ContainerDriver> keyed by engine ('docker' | 'lxc') */
    public static function drivers(array $host): array
    {
        $caps = self::capabilities($host);
        $ssh  = SshClient::for($host);
        $out  = [];
        if ($caps['docker']) {
            $out['docker'] = new DockerDriver($ssh);
        }
        $out['lxc'] = match ($caps['lxc']) {
            'pct'   => new ProxmoxDriver($ssh),
            'incus' => new IncusDriver($ssh, 'incus'),
            'lxd'   => new IncusDriver($ssh, 'lxc'),
            'lxc'   => new LxcClassicDriver($ssh),
            default => null,
        };
        return array_filter($out);
    }

    public static function driver(array $host, string $engine): ContainerDriver
    {
        $d = self::drivers($host)[$engine] ?? null;
        if (!$d) {
            throw new ApiError("Engine '$engine' is not available on {$host['name']}", 400);
        }
        return $d;
    }

    /** Host health metrics in one round-trip. */
    public static function info(array $host): array
    {
        $ssh = SshClient::for($host);
        $script = <<<'SH'
            echo @@HOST; hostname
            echo @@OS; ( . /etc/os-release 2>/dev/null && echo "$PRETTY_NAME" ) || uname -s
            echo @@KERNEL; uname -r
            echo @@ARCH; uname -m
            echo @@UPTIME; cat /proc/uptime
            echo @@LOAD; cat /proc/loadavg
            echo @@CPUS; nproc 2>/dev/null || grep -c ^processor /proc/cpuinfo
            echo @@MEM; grep -E '^(MemTotal|MemAvailable|SwapTotal|SwapFree):' /proc/meminfo
            echo @@DISK; df -P -B1 -x tmpfs -x devtmpfs -x overlay -x squashfs -x efivarfs 2>/dev/null | tail -n +2
            echo @@CPU1; head -1 /proc/stat; sleep 0.4; echo @@CPU2; head -1 /proc/stat
            echo @@END
            SH;
        $out = $ssh->must($script, false, 20);
        $sec = [];
        $cur = null;
        foreach (preg_split('/\r?\n/', $out) as $line) {
            if (preg_match('/^@@([A-Z0-9]+)$/', $line, $m)) {
                $cur = $m[1];
                $sec[$cur] = [];
            } elseif ($cur !== null && $line !== '') {
                $sec[$cur][] = $line;
            }
        }
        $mem = [];
        foreach ($sec['MEM'] ?? [] as $l) {
            if (preg_match('/^(\w+):\s+(\d+)/', $l, $m)) {
                $mem[$m[1]] = (int) $m[2] * 1024;
            }
        }
        $disks = [];
        foreach ($sec['DISK'] ?? [] as $l) {
            $p = preg_split('/\s+/', $l, 6);
            if (count($p) === 6 && is_numeric($p[1])) {
                $disks[] = ['fs' => $p[0], 'size' => (int) $p[1], 'used' => (int) $p[2], 'avail' => (int) $p[3], 'mount' => $p[5]];
            }
        }
        $cpu = null;
        $a = preg_split('/\s+/', trim($sec['CPU1'][0] ?? ''));
        $b = preg_split('/\s+/', trim($sec['CPU2'][0] ?? ''));
        if (count($a) > 4 && count($b) > 4) {
            $ta = array_sum(array_slice($a, 1)); $tb = array_sum(array_slice($b, 1));
            $ia = (int) $a[4] + (int) ($a[5] ?? 0); $ib = (int) $b[4] + (int) ($b[5] ?? 0);
            $cpu = ($tb - $ta) > 0 ? round(100 * (1 - ($ib - $ia) / ($tb - $ta)), 1) : 0.0;
        }
        $load = preg_split('/\s+/', $sec['LOAD'][0] ?? '');
        return [
            'hostname' => $sec['HOST'][0] ?? '',
            'os'       => $sec['OS'][0] ?? '',
            'kernel'   => $sec['KERNEL'][0] ?? '',
            'arch'     => $sec['ARCH'][0] ?? '',
            'uptime'   => (int) (float) explode(' ', $sec['UPTIME'][0] ?? '0')[0],
            'load'     => array_map('floatval', array_slice($load, 0, 3)),
            'cpus'     => (int) ($sec['CPUS'][0] ?? 0),
            'cpu_pct'  => $cpu,
            'mem'      => [
                'total' => $mem['MemTotal'] ?? 0,
                'avail' => $mem['MemAvailable'] ?? 0,
                'swap_total' => $mem['SwapTotal'] ?? 0,
                'swap_free'  => $mem['SwapFree'] ?? 0,
            ],
            'disks'    => $disks,
        ];
    }
}
