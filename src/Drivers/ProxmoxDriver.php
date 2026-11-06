<?php
declare(strict_types=1);

namespace LabAdmin\Drivers;

use LabAdmin\ApiError;
use LabAdmin\Shell;

/** Proxmox VE containers via `pct` / `pvesh` / `pveam`. */
final class ProxmoxDriver extends ContainerDriver
{
    public function engine(): string { return 'lxc'; }
    public function flavor(): string { return 'pct'; }

    public function actions(): array
    {
        return ['start', 'stop', 'shutdown', 'restart', 'delete'];
    }

    private static function vmid(string $id): int
    {
        return Shell::int($id, 100, 999999999, 'VMID');
    }

    public function list(): array
    {
        $out  = $this->ssh->must('pvesh get /nodes/"$(hostname)"/lxc --output-format json 2>/dev/null || { echo @@PCT; pct list; }', true, 30);
        $rows = [];
        if (!str_contains($out, '@@PCT') && is_array($j = json_decode($out, true))) {
            foreach ($j as $c) {
                $rows[] = [
                    'engine' => 'lxc', 'flavor' => 'pct',
                    'id'     => (string) $c['vmid'],
                    'name'   => $c['name'] ?? ('CT' . $c['vmid']),
                    'image'  => '',
                    'state'  => self::normState($c['status'] ?? ''),
                    'status' => ($c['status'] ?? '') . (!empty($c['uptime']) ? ' · up ' . self::dur((int) $c['uptime']) : ''),
                    'ips'    => [],
                    'ports'  => '',
                    'created'=> '',
                    'group'  => $c['tags'] ?? '',
                    'cpu'    => isset($c['cpu']) ? round($c['cpu'] * 100, 1) . '%' : null,
                    'mem'    => isset($c['mem'], $c['maxmem']) ? self::bytes($c['mem']) . ' / ' . self::bytes($c['maxmem']) : null,
                    'extra'  => ['vmid' => (int) $c['vmid'], 'cpus' => $c['cpus'] ?? null, 'lock' => $c['lock'] ?? ''],
                ];
            }
        } else {
            foreach (array_slice(Shell::lines(substr($out, strpos($out, '@@PCT') + 5)), 1) as $l) {
                $p = preg_split('/\s+/', $l);
                if (!is_numeric($p[0] ?? null)) {
                    continue;
                }
                $rows[] = [
                    'engine' => 'lxc', 'flavor' => 'pct', 'id' => $p[0], 'name' => end($p),
                    'image' => '', 'state' => self::normState($p[1] ?? ''), 'status' => $p[1] ?? '',
                    'ips' => [], 'ports' => '', 'created' => '', 'group' => '', 'cpu' => null, 'mem' => null,
                    'extra' => ['vmid' => (int) $p[0], 'lock' => count($p) === 4 ? $p[2] : ''],
                ];
            }
        }
        // IPs of running containers (cheap: read from the container's network namespace).
        $running = array_filter($rows, fn ($r) => $r['state'] === 'running');
        if ($running) {
            $script = '';
            foreach ($running as $r) {
                $id = (int) $r['id'];
                $script .= "echo \"@@$id \$(pct exec $id -- hostname -I 2>/dev/null)\";";
            }
            $ipOut = $this->ssh->run($script, true, 20)['out'];
            foreach (Shell::lines($ipOut) as $l) {
                if (preg_match('/^@@(\d+)\s*(.*)$/', $l, $m)) {
                    foreach ($rows as &$r) {
                        if ($r['id'] === $m[1]) {
                            $r['ips'] = array_values(array_filter(explode(' ', trim($m[2]))));
                        }
                    }
                    unset($r);
                }
            }
        }
        return $rows;
    }

    protected function actionCommand(string $id, string $action): string
    {
        $v = self::vmid($id);
        return match ($action) {
            'start'    => "pct start $v",
            'stop'     => "pct stop $v",
            'shutdown' => "pct shutdown $v --timeout 60",
            'restart'  => "pct reboot $v",
            'delete'   => "pct stop $v >/dev/null 2>&1; pct destroy $v --purge",
        };
    }

    public function logs(string $id, int $lines): string
    {
        $v = self::vmid($id);
        $n = (int) $lines;
        return $this->ssh->must(
            "if pct status $v | grep -q running; then "
            . "echo '### journal inside container'; pct exec $v -- sh -c 'journalctl -n $n --no-pager 2>/dev/null || tail -n $n /var/log/syslog /var/log/messages 2>/dev/null'; "
            . "fi; echo; echo '### host: pve-container@$v'; journalctl -u pve-container@$v -n $n --no-pager 2>&1; true",
            true, 30
        );
    }

    public function inspect(string $id): string
    {
        $v = self::vmid($id);
        return $this->ssh->must("pct config $v; echo; echo '# status'; pct status $v --verbose 2>/dev/null || pct status $v", true, 30);
    }

    protected function execCommand(string $id, string $cmd): string
    {
        return 'pct exec ' . self::vmid($id) . ' -- sh -c ' . Shell::q($cmd);
    }

    public function images(): array
    {
        $out  = $this->ssh->must('for s in $(pvesm status --content vztmpl 2>/dev/null | awk \'NR>1{print $1}\'); do pveam list "$s" 2>/dev/null | tail -n +2; done', true, 30);
        $rows = [];
        foreach (Shell::lines($out) as $l) {
            $p = preg_split('/\s+/', $l);
            $rows[] = ['ref' => $p[0], 'name' => basename($p[0]), 'tag' => explode(':', $p[0])[0], 'id' => '', 'size' => $p[1] ?? '', 'created' => ''];
        }
        return $rows;
    }

    /** Templates available for download (pveam available). */
    public function available(): array
    {
        $out = $this->ssh->must('pveam update >/dev/null 2>&1; pveam available --section system', true, 120);
        return array_values(array_map(fn ($l) => preg_split('/\s+/', $l)[1] ?? $l, Shell::lines($out)));
    }

    public function pullImage(string $ref, array $opts = []): string
    {
        $ref     = Shell::imageRef($ref);
        $storage = Shell::name($opts['storage'] ?? 'local', 'storage');
        return $this->audited('image.pull', "pct:$ref", 'pveam download ' . Shell::q($storage) . ' ' . Shell::q($ref) . ' 2>&1', 900);
    }

    public function removeImage(string $ref): string
    {
        $ref = Shell::imageRef($ref);
        return $this->audited('image.remove', "pct:$ref", 'pveam remove ' . Shell::q($ref) . ' 2>&1', 120);
    }

    public function createSchema(): array
    {
        return [
            'title'  => 'New Proxmox container',
            'fields' => [
                ['name' => 'template', 'label' => 'Template', 'type' => 'image', 'required' => true, 'placeholder' => 'local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst'],
                ['name' => 'hostname', 'label' => 'Hostname', 'type' => 'text', 'required' => true, 'placeholder' => 'ct-web'],
                ['name' => 'vmid',     'label' => 'VMID', 'type' => 'number', 'placeholder' => 'auto (next free)'],
                ['name' => 'cores',    'label' => 'CPU cores', 'type' => 'number', 'default' => 1],
                ['name' => 'memory',   'label' => 'Memory (MiB)', 'type' => 'number', 'default' => 512],
                ['name' => 'swap',     'label' => 'Swap (MiB)', 'type' => 'number', 'default' => 512],
                ['name' => 'storage',  'label' => 'Root disk storage', 'type' => 'text', 'default' => 'local-lvm'],
                ['name' => 'disk',     'label' => 'Root disk size (GiB)', 'type' => 'number', 'default' => 8],
                ['name' => 'bridge',   'label' => 'Network bridge', 'type' => 'text', 'default' => 'vmbr0'],
                ['name' => 'ip',       'label' => 'IPv4 (CIDR) or dhcp', 'type' => 'text', 'default' => 'dhcp'],
                ['name' => 'gw',       'label' => 'Gateway (if static)', 'type' => 'text'],
                ['name' => 'password', 'label' => 'Root password (optional)', 'type' => 'password'],
                ['name' => 'unprivileged', 'label' => 'Unprivileged container', 'type' => 'checkbox', 'default' => true],
                ['name' => 'nesting',  'label' => 'Enable nesting (Docker inside)', 'type' => 'checkbox', 'default' => false],
                ['name' => 'start',    'label' => 'Start after creation', 'type' => 'checkbox', 'default' => true],
            ],
        ];
    }

    public function create(array $p): string
    {
        $tpl  = Shell::imageRef($p['template'] ?? '');
        $hn   = Shell::name($p['hostname'] ?? '', 'hostname');
        $vmid = ($p['vmid'] ?? '') !== '' ? self::vmid((string) $p['vmid']) : (int) trim($this->ssh->must('pvesh get /cluster/nextid', true, 20));
        $cores = Shell::int($p['cores'] ?? 1, 1, 512, 'cores');
        $mem   = Shell::int($p['memory'] ?? 512, 16, 1048576, 'memory');
        $swap  = Shell::int($p['swap'] ?? 512, 0, 1048576, 'swap');
        $disk  = Shell::int($p['disk'] ?? 8, 1, 65536, 'disk size');
        $store = Shell::name($p['storage'] ?? 'local-lvm', 'storage');
        $br    = Shell::name($p['bridge'] ?? 'vmbr0', 'bridge');
        $ip    = trim((string) ($p['ip'] ?? 'dhcp')) ?: 'dhcp';
        if ($ip !== 'dhcp' && !preg_match('#^(\d{1,3}\.){3}\d{1,3}/\d{1,2}$#', $ip)) {
            throw new ApiError('IPv4 must be CIDR (e.g. 192.168.1.50/24) or dhcp');
        }
        $net = "name=eth0,bridge=$br,ip=$ip";
        if (($gw = trim((string) ($p['gw'] ?? ''))) !== '') {
            if (!filter_var($gw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new ApiError('Invalid gateway');
            }
            $net .= ",gw=$gw";
        }
        $args = ['pct', 'create', (string) $vmid, $tpl,
            '--hostname', $hn, '--cores', (string) $cores, '--memory', (string) $mem, '--swap', (string) $swap,
            '--rootfs', "$store:$disk", '--net0', $net,
            '--unprivileged', !empty($p['unprivileged']) ? '1' : '0'];
        if (!empty($p['nesting'])) {
            array_push($args, '--features', 'nesting=1');
        }
        $logArgs = $args;
        if (($pw = (string) ($p['password'] ?? '')) !== '') {
            array_push($args, '--password', $pw);
            array_push($logArgs, '--password', '********');
        }
        if (!empty($p['start'])) {
            array_push($args, '--start', '1');
            array_push($logArgs, '--start', '1');
        }
        $cmd = Shell::args($args) . ' 2>&1';
        $log = Shell::args($logArgs) . ' 2>&1';
        $res = $this->audited('ct.create', "pct:$vmid", $cmd, 900, $log);
        return "Created CT $vmid ($hn)\n" . $res;
    }

    private static function bytes(int|float $b): string
    {
        $u = ['B', 'K', 'M', 'G', 'T'];
        $i = 0;
        while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
        return round($b, 1) . $u[$i];
    }

    private static function dur(int $s): string
    {
        return $s >= 86400 ? intdiv($s, 86400) . 'd' : ($s >= 3600 ? intdiv($s, 3600) . 'h' : intdiv($s, 60) . 'm');
    }
}
