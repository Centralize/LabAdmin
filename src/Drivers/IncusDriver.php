<?php
declare(strict_types=1);

namespace LabAdmin\Drivers;

use LabAdmin\ApiError;
use LabAdmin\Shell;

/** Incus (`incus`) and LXD (`lxc`) share the same CLI shape and JSON output. */
final class IncusDriver extends ContainerDriver
{
    public function __construct(\LabAdmin\SshClient $ssh, private string $bin = 'incus')
    {
        parent::__construct($ssh);
    }

    public function engine(): string { return 'lxc'; }
    public function flavor(): string { return $this->bin === 'incus' ? 'incus' : 'lxd'; }

    public function actions(): array
    {
        return ['start', 'stop', 'restart', 'pause', 'unpause', 'kill', 'delete'];
    }

    public function list(): array
    {
        $out = $this->ssh->must($this->bin . ' list --format json', true, 30);
        $j   = json_decode($out, true);
        if (!is_array($j)) {
            throw new ApiError('Unexpected output from ' . $this->bin . ' list');
        }
        $rows = [];
        foreach ($j as $c) {
            $ips = [];
            foreach (($c['state']['network'] ?? []) as $ifname => $if) {
                if ($ifname === 'lo') {
                    continue;
                }
                foreach ($if['addresses'] ?? [] as $a) {
                    if (($a['scope'] ?? '') === 'global') {
                        $ips[] = $a['address'];
                    }
                }
            }
            $mem = $c['state']['memory']['usage'] ?? null;
            $rows[] = [
                'engine'  => 'lxc',
                'flavor'  => $this->flavor(),
                'id'      => $c['name'],
                'name'    => $c['name'],
                'image'   => $c['config']['image.description'] ?? ($c['config']['image.os'] ?? ''),
                'state'   => self::normState($c['status'] ?? ''),
                'status'  => ($c['status'] ?? '') . (($c['type'] ?? '') === 'virtual-machine' ? ' (VM)' : ''),
                'ips'     => $ips,
                'ports'   => '',
                'created' => $c['created_at'] ?? '',
                'group'   => ($c['project'] ?? 'default') === 'default' ? '' : $c['project'],
                'cpu'     => null,
                'mem'     => $mem ? round($mem / 1048576) . 'MiB' : null,
                'extra'   => ['type' => $c['type'] ?? 'container', 'profiles' => $c['profiles'] ?? []],
            ];
        }
        return $rows;
    }

    protected function actionCommand(string $id, string $action): string
    {
        $n = Shell::q(Shell::name($id));
        $b = $this->bin;
        return match ($action) {
            'start'   => "$b start $n",
            'stop'    => "$b stop $n",
            'restart' => "$b restart $n",
            'kill'    => "$b stop --force $n",
            'pause'   => "$b pause $n",
            'unpause' => $b === 'incus' ? "incus resume $n" : "lxc start $n",
            'delete'  => "$b delete --force $n",
        };
    }

    public function logs(string $id, int $lines): string
    {
        $n = Shell::q(Shell::name($id));
        $k = (int) $lines;
        return $this->ssh->must(
            "{$this->bin} info $n --show-log 2>&1 | tail -n $k; echo; echo '### journal inside instance'; "
            . "{$this->bin} exec $n -- sh -c 'journalctl -n $k --no-pager 2>/dev/null || tail -n $k /var/log/syslog /var/log/messages 2>/dev/null' 2>&1; true",
            true, 30
        );
    }

    public function inspect(string $id): string
    {
        $n = Shell::q(Shell::name($id));
        return $this->ssh->must("{$this->bin} config show $n --expanded; echo; echo '# info'; {$this->bin} info $n", true, 30);
    }

    protected function execCommand(string $id, string $cmd): string
    {
        return $this->bin . ' exec ' . Shell::q(Shell::name($id)) . ' -- sh -c ' . Shell::q($cmd);
    }

    public function terminalCommand(string $id): string
    {
        return $this->bin . ' exec ' . Shell::q(Shell::name($id)) . ' --env TERM=xterm-256color -- sh -c ' . Shell::q(self::LOGIN_SHELL);
    }

    public function images(): array
    {
        $j = json_decode($this->ssh->must($this->bin . ' image list --format json', true, 30), true) ?: [];
        return array_map(fn ($i) => [
            'ref'     => $i['fingerprint'],
            'name'    => implode(', ', array_column($i['aliases'] ?? [], 'name')) ?: ($i['properties']['description'] ?? ''),
            'tag'     => $i['architecture'] ?? '',
            'id'      => substr($i['fingerprint'], 0, 12),
            'size'    => round(($i['size'] ?? 0) / 1048576) . 'MB',
            'created' => $i['uploaded_at'] ?? '',
        ], $j);
    }

    public function pullImage(string $ref, array $opts = []): string
    {
        $ref = Shell::imageRef($ref); // e.g. images:debian/12
        if (!str_contains($ref, ':')) {
            $ref = 'images:' . $ref;
        }
        $alias = substr($ref, strpos($ref, ':') + 1);
        return $this->audited('image.pull', "{$this->flavor()}:$ref",
            $this->bin . ' image copy ' . Shell::q($ref) . ' local: --alias ' . Shell::q($alias) . ' 2>&1', 900);
    }

    public function removeImage(string $ref): string
    {
        $ref = Shell::imageRef($ref);
        return $this->audited('image.remove', "{$this->flavor()}:$ref", $this->bin . ' image delete ' . Shell::q($ref) . ' 2>&1', 120);
    }

    /** Profile names defined on the server, in the server's order. */
    public function profiles(): array
    {
        $out = $this->ssh->run($this->bin . ' profile list --format csv', true, 30);
        if ($out['code'] !== 0) {
            return [];
        }
        $names = [];
        foreach (Shell::lines($out['out']) as $line) {
            $n = trim(str_getcsv($line, ',', '"', '\\')[0] ?? '');
            if ($n !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $n)) {
                $names[] = $n;
            }
        }
        return $names;
    }

    public function createSchema(): array
    {
        $profiles = $this->profiles();
        return [
            'title'  => 'New ' . ($this->bin === 'incus' ? 'Incus' : 'LXD') . ' instance',
            'fields' => [
                ['name' => 'image',   'label' => 'Image', 'type' => 'image', 'required' => true, 'placeholder' => $this->bin === 'incus' ? 'images:debian/12' : 'ubuntu:24.04'],
                ['name' => 'name',    'label' => 'Instance name', 'type' => 'text', 'required' => true, 'placeholder' => 'web01'],
                ['name' => 'cpus',    'label' => 'CPU limit (cores)', 'type' => 'number', 'placeholder' => 'unlimited'],
                ['name' => 'memory',  'label' => 'Memory limit', 'type' => 'text', 'placeholder' => 'e.g. 1GiB'],
                $profiles
                    ? ['name' => 'profiles', 'label' => 'Profiles (applied in the order you tick them)', 'type' => 'profiles', 'options' => $profiles, 'default' => in_array('default', $profiles, true) ? ['default'] : []]
                    : ['name' => 'profiles', 'label' => 'Profiles (comma-separated, in order)', 'type' => 'text', 'default' => 'default'],
                ['name' => 'vm',      'label' => 'Virtual machine instead of container', 'type' => 'checkbox', 'default' => false],
                ['name' => 'nesting', 'label' => 'Enable nesting (Docker inside)', 'type' => 'checkbox', 'default' => false],
            ],
        ];
    }

    public function create(array $p): string
    {
        $img  = Shell::imageRef($p['image'] ?? '');
        $name = Shell::name($p['name'] ?? '', 'instance name');
        $args = [$this->bin, 'launch', $img, $name];

        // Profiles: array (from the checkbox list) or comma-separated text; order is kept.
        $raw = $p['profiles'] ?? ($p['profile'] ?? 'default');
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);
        $profiles = [];
        foreach ($list as $prof) {
            $prof = trim((string) $prof);
            if ($prof !== '' && !in_array($prof, $profiles, true)) {
                $profiles[] = Shell::name($prof, 'profile');
            }
        }
        if (!$profiles) {
            throw new ApiError('Select at least one profile');
        }
        foreach ($profiles as $prof) {
            array_push($args, '--profile', $prof);
        }
        if (($c = trim((string) ($p['cpus'] ?? ''))) !== '') {
            array_push($args, '-c', 'limits.cpu=' . Shell::int($c, 1, 1024, 'CPU limit'));
        }
        if (($m = trim((string) ($p['memory'] ?? ''))) !== '') {
            if (!preg_match('/^\d+(\.\d+)?(B|kB|MB|GB|TB|KiB|MiB|GiB|TiB|%)$/', $m)) {
                throw new ApiError('Memory limit like 512MiB, 2GiB or 50%');
            }
            array_push($args, '-c', "limits.memory=$m");
        }
        if (!empty($p['nesting'])) {
            array_push($args, '-c', 'security.nesting=true');
        }
        if (!empty($p['vm'])) {
            $args[] = '--vm';
        }
        return $this->audited('ct.create', "{$this->flavor()}:$name", Shell::args($args) . ' 2>&1', 900);
    }
}
