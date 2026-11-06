<?php
declare(strict_types=1);

namespace LabAdmin\Drivers;

use LabAdmin\ApiError;
use LabAdmin\Shell;

/** Classic LXC userspace tools: lxc-ls, lxc-start, lxc-attach, ... */
final class LxcClassicDriver extends ContainerDriver
{
    public function engine(): string { return 'lxc'; }
    public function flavor(): string { return 'lxc'; }

    public function actions(): array
    {
        return ['start', 'stop', 'restart', 'pause', 'unpause', 'kill', 'delete'];
    }

    public function list(): array
    {
        // IPV4 last: it may contain ", " separated values.
        $out  = $this->ssh->must('lxc-ls -f -F NAME,STATE,AUTOSTART,IPV4', true, 30);
        $rows = [];
        foreach (array_slice(Shell::lines($out), 1) as $l) {
            $p = preg_split('/\s+/', $l, 4);
            if (count($p) < 2) {
                continue;
            }
            $ips = array_values(array_filter(array_map('trim', explode(',', $p[3] ?? '')), fn ($x) => $x !== '' && $x !== '-'));
            $rows[] = [
                'engine' => 'lxc', 'flavor' => 'lxc',
                'id'     => $p[0], 'name' => $p[0], 'image' => '',
                'state'  => self::normState($p[1]),
                'status' => $p[1] . (($p[2] ?? '0') === '1' ? ' · autostart' : ''),
                'ips'    => $ips, 'ports' => '', 'created' => '', 'group' => '',
                'cpu'    => null, 'mem' => null, 'extra' => ['autostart' => ($p[2] ?? '0') === '1'],
            ];
        }
        return $rows;
    }

    protected function actionCommand(string $id, string $action): string
    {
        $n = Shell::q(Shell::name($id));
        return match ($action) {
            'start'   => "lxc-start -n $n -d",
            'stop'    => "lxc-stop -n $n",
            'restart' => "lxc-stop -n $n -r",
            'kill'    => "lxc-stop -n $n -k",
            'pause'   => "lxc-freeze -n $n",
            'unpause' => "lxc-unfreeze -n $n",
            'delete'  => "lxc-destroy -n $n -f",
        };
    }

    public function logs(string $id, int $lines): string
    {
        $n = Shell::name($id);
        $q = Shell::q($n);
        $k = (int) $lines;
        return $this->ssh->must(
            "lxc-info -n $q; echo; echo '### /var/log/lxc/$n.log'; tail -n $k /var/log/lxc/$n.log 2>&1; "
            . "if lxc-info -n $q -s | grep -q RUNNING; then echo; echo '### journal inside container'; "
            . "lxc-attach -n $q -- sh -c 'journalctl -n $k --no-pager 2>/dev/null || tail -n $k /var/log/syslog /var/log/messages 2>/dev/null'; fi; true",
            true, 30
        );
    }

    public function inspect(string $id): string
    {
        $q = Shell::q(Shell::name($id));
        return $this->ssh->must("lxc-info -n $q; echo; echo '# config'; cat \"\$(lxc-config lxc.lxcpath)\"/$q/config", true, 30);
    }

    protected function execCommand(string $id, string $cmd): string
    {
        return 'lxc-attach -n ' . Shell::q(Shell::name($id)) . ' -- sh -c ' . Shell::q($cmd);
    }

    public function createSchema(): array
    {
        return [
            'title'  => 'New LXC container (download template)',
            'fields' => [
                ['name' => 'name',    'label' => 'Container name', 'type' => 'text', 'required' => true, 'placeholder' => 'web01'],
                ['name' => 'dist',    'label' => 'Distribution', 'type' => 'text', 'required' => true, 'default' => 'debian'],
                ['name' => 'release', 'label' => 'Release', 'type' => 'text', 'required' => true, 'default' => 'bookworm'],
                ['name' => 'arch',    'label' => 'Architecture', 'type' => 'text', 'required' => true, 'default' => 'amd64'],
                ['name' => 'start',   'label' => 'Start after creation', 'type' => 'checkbox', 'default' => true],
            ],
        ];
    }

    public function create(array $p): string
    {
        $name = Shell::name($p['name'] ?? '', 'container name');
        $d = Shell::name($p['dist'] ?? '', 'distribution');
        $r = Shell::name($p['release'] ?? '', 'release');
        $a = Shell::name($p['arch'] ?? '', 'architecture');
        $cmd = Shell::args(['lxc-create', '-n', $name, '-t', 'download', '--', '-d', $d, '-r', $r, '-a', $a]) . ' 2>&1';
        if (!empty($p['start'])) {
            $cmd .= ' && lxc-start -n ' . Shell::q($name) . ' -d 2>&1';
        }
        return $this->audited('ct.create', "lxc:$name", $cmd, 900);
    }
}
