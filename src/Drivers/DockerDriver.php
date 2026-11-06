<?php
declare(strict_types=1);

namespace LabAdmin\Drivers;

use LabAdmin\ApiError;
use LabAdmin\Shell;

final class DockerDriver extends ContainerDriver
{
    public function engine(): string { return 'docker'; }
    public function flavor(): string { return 'docker'; }

    public function actions(): array
    {
        return ['start', 'stop', 'restart', 'pause', 'unpause', 'kill', 'delete'];
    }

    public function list(): array
    {
        $cmd = "docker ps -a --format '{{json .}}'; echo @@STATS; "
             . "timeout 8 docker stats --no-stream --format '{{json .}}' 2>/dev/null; true";
        $out = $this->ssh->must($cmd, true, 30);
        [$ps, $stats] = array_pad(explode('@@STATS', $out, 2), 2, '');

        $st = [];
        foreach (Shell::lines($stats) as $l) {
            $j = json_decode($l, true);
            if (is_array($j) && isset($j['Name'])) {
                $st[$j['Name']] = $j;
            }
        }

        $rows = [];
        foreach (Shell::lines($ps) as $l) {
            $j = json_decode($l, true);
            if (!is_array($j)) {
                continue;
            }
            $name   = explode(',', $j['Names'] ?? '')[0];
            $labels = [];
            foreach (explode(',', $j['Labels'] ?? '') as $kv) {
                [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
                if ($k !== '') {
                    $labels[$k] = $v;
                }
            }
            $s = $st[$name] ?? null;
            $rows[] = [
                'engine'  => 'docker',
                'flavor'  => 'docker',
                'id'      => $name,
                'name'    => $name,
                'short_id'=> substr($j['ID'] ?? '', 0, 12),
                'image'   => $j['Image'] ?? '',
                'state'   => self::normState($j['State'] ?? ''),
                'status'  => $j['Status'] ?? '',
                'ips'     => [],
                'ports'   => $j['Ports'] ?? '',
                'created' => $j['CreatedAt'] ?? '',
                'group'   => $labels['com.docker.compose.project'] ?? '',
                'cpu'     => $s['CPUPerc'] ?? null,
                'mem'     => $s['MemUsage'] ?? null,
                'extra'   => ['networks' => $j['Networks'] ?? '', 'command' => trim($j['Command'] ?? '', '"')],
            ];
        }
        return $rows;
    }

    protected function actionCommand(string $id, string $action): string
    {
        $id = Shell::q(Shell::name($id, 'container'));
        return match ($action) {
            'delete' => "docker rm -f $id",
            default  => "docker $action $id",
        };
    }

    public function logs(string $id, int $lines): string
    {
        return $this->ssh->must('docker logs --timestamps --tail ' . (int) $lines . ' ' . Shell::q(Shell::name($id)) . ' 2>&1', true, 30);
    }

    public function inspect(string $id): string
    {
        return $this->ssh->must('docker inspect ' . Shell::q(Shell::name($id)), true, 30);
    }

    protected function execCommand(string $id, string $cmd): string
    {
        return 'docker exec ' . Shell::q(Shell::name($id)) . ' sh -c ' . Shell::q($cmd);
    }

    public function images(): array
    {
        $out  = $this->ssh->must("docker images --format '{{json .}}'", true, 30);
        $rows = [];
        foreach (Shell::lines($out) as $l) {
            $j = json_decode($l, true);
            if (!is_array($j)) {
                continue;
            }
            $ref = ($j['Repository'] ?? '') === '<none>' ? ($j['ID'] ?? '') : ($j['Repository'] . ':' . $j['Tag']);
            $rows[] = [
                'ref'     => $ref,
                'name'    => $j['Repository'] ?? '',
                'tag'     => $j['Tag'] ?? '',
                'id'      => $j['ID'] ?? '',
                'size'    => $j['Size'] ?? '',
                'created' => $j['CreatedSince'] ?? '',
            ];
        }
        return $rows;
    }

    public function pullImage(string $ref, array $opts = []): string
    {
        $ref = Shell::imageRef($ref);
        return $this->audited('image.pull', "docker:$ref", 'docker pull ' . Shell::q($ref) . ' 2>&1', 900);
    }

    public function removeImage(string $ref): string
    {
        $ref = Shell::imageRef($ref);
        return $this->audited('image.remove', "docker:$ref", 'docker rmi ' . Shell::q($ref), 120);
    }

    public function createSchema(): array
    {
        return [
            'title'  => 'New Docker container',
            'fields' => [
                ['name' => 'image',   'label' => 'Image', 'type' => 'image', 'required' => true, 'placeholder' => 'nginx:alpine'],
                ['name' => 'name',    'label' => 'Container name', 'type' => 'text', 'required' => true, 'placeholder' => 'web'],
                ['name' => 'restart', 'label' => 'Restart policy', 'type' => 'select', 'options' => ['unless-stopped', 'always', 'on-failure', 'no'], 'default' => 'unless-stopped'],
                ['name' => 'ports',   'label' => 'Ports (one per line, host:container[/udp])', 'type' => 'lines', 'placeholder' => "8080:80\n8443:443"],
                ['name' => 'env',     'label' => 'Environment (KEY=value per line)', 'type' => 'lines', 'placeholder' => 'TZ=Europe/Copenhagen'],
                ['name' => 'volumes', 'label' => 'Volumes (host_path_or_volume:/path[:ro] per line)', 'type' => 'lines', 'placeholder' => '/srv/web:/usr/share/nginx/html:ro'],
                ['name' => 'network', 'label' => 'Network', 'type' => 'text', 'placeholder' => 'bridge (default)'],
                ['name' => 'memory',  'label' => 'Memory limit', 'type' => 'text', 'placeholder' => 'e.g. 512m'],
                ['name' => 'cpus',    'label' => 'CPU limit', 'type' => 'text', 'placeholder' => 'e.g. 1.5'],
                ['name' => 'command', 'label' => 'Command override', 'type' => 'text', 'placeholder' => 'optional'],
                ['name' => 'pull',    'label' => 'Always pull image first', 'type' => 'checkbox', 'default' => false],
            ],
        ];
    }

    public function create(array $p): string
    {
        $image = Shell::imageRef($p['image'] ?? '');
        $name  = Shell::name($p['name'] ?? '', 'container name');
        $args  = ['docker', 'run', '-d', '--name', $name];

        $restart = (string) ($p['restart'] ?? 'unless-stopped');
        if (!in_array($restart, ['unless-stopped', 'always', 'on-failure', 'no'], true)) {
            throw new ApiError('Invalid restart policy');
        }
        array_push($args, '--restart', $restart);

        foreach (Shell::lines($p['ports'] ?? '') as $port) {
            if (!preg_match('#^((\d{1,3}\.){3}\d{1,3}:|\[[0-9a-f:]+\]:)?(\d{1,5}(-\d{1,5})?:)?\d{1,5}(-\d{1,5})?(/(tcp|udp|sctp))?$#i', $port)) {
                throw new ApiError("Invalid port mapping: $port");
            }
            array_push($args, '-p', $port);
        }
        foreach (Shell::lines($p['env'] ?? '') as $env) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*=/', $env)) {
                throw new ApiError("Invalid env line (KEY=value): $env");
            }
            array_push($args, '-e', $env);
        }
        foreach (Shell::lines($p['volumes'] ?? '') as $vol) {
            if (!str_contains($vol, ':')) {
                throw new ApiError("Invalid volume: $vol");
            }
            array_push($args, '-v', $vol);
        }
        if (($net = trim((string) ($p['network'] ?? ''))) !== '') {
            array_push($args, '--network', Shell::name($net, 'network'));
        }
        if (($mem = trim((string) ($p['memory'] ?? ''))) !== '') {
            if (!preg_match('/^\d+(\.\d+)?[bkmg]?$/i', $mem)) {
                throw new ApiError('Invalid memory limit');
            }
            array_push($args, '--memory', $mem);
        }
        if (($cpus = trim((string) ($p['cpus'] ?? ''))) !== '') {
            if (!preg_match('/^\d+(\.\d+)?$/', $cpus)) {
                throw new ApiError('Invalid CPU limit');
            }
            array_push($args, '--cpus', $cpus);
        }
        if (!empty($p['pull'])) {
            array_push($args, '--pull', 'always');
        }
        $args[] = $image;
        if (($c = trim((string) ($p['command'] ?? ''))) !== '') {
            array_push($args, ...Shell::split($c));
        }
        return $this->audited('ct.create', "docker:$name", Shell::args($args) . ' 2>&1', 900);
    }
}
