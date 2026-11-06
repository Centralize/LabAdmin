<?php
declare(strict_types=1);

namespace LabAdmin\Drivers;

use LabAdmin\ApiError;
use LabAdmin\Audit;
use LabAdmin\SshClient;

/**
 * Common contract for Docker and the LXC flavours.
 *
 * Unified container row:
 *  engine, flavor, id, name, image, state (running|stopped|paused|other), status,
 *  ips[], ports, created, group (compose project / pool), cpu, mem, extra{}
 */
abstract class ContainerDriver
{
    public function __construct(protected SshClient $ssh)
    {
    }

    abstract public function engine(): string;   // docker | lxc
    abstract public function flavor(): string;   // docker | pct | incus | lxd | lxc

    /** @return list<array<string,mixed>> */
    abstract public function list(): array;

    /** @return list<string> */
    abstract public function actions(): array;

    abstract protected function actionCommand(string $id, string $action): string;

    abstract public function logs(string $id, int $lines): string;

    abstract public function inspect(string $id): string;

    abstract protected function execCommand(string $id, string $cmd): string;

    /** Command that starts an interactive login shell inside the container (run with a PTY). */
    abstract public function terminalCommand(string $id): string;

    /** The full command that runs a shell snippet inside the container (used by the file editor). */
    public function wrapExec(string $id, string $cmd): string
    {
        return $this->execCommand($id, $cmd);
    }

    /** Shell snippet: prefer bash, fall back to sh; start in the home directory. */
    protected const LOGIN_SHELL = 'export TERM=xterm-256color; cd ~ 2>/dev/null || cd /; '
        . 'if command -v bash >/dev/null 2>&1; then exec bash -l; else exec sh -l; fi';

    /** @return list<array<string,mixed>> */
    public function images(): array
    {
        return [];
    }

    public function pullImage(string $ref, array $opts = []): string
    {
        throw new ApiError('Image pull is not supported for ' . $this->flavor());
    }

    public function removeImage(string $ref): string
    {
        throw new ApiError('Image removal is not supported for ' . $this->flavor());
    }

    /** Describes the create form for the UI. */
    abstract public function createSchema(): array;

    abstract public function create(array $p): string;

    // ---------------------------------------------------------------

    public function action(string $id, string $action): string
    {
        if (!in_array($action, $this->actions(), true)) {
            throw new ApiError("Action '$action' not supported for {$this->flavor()}");
        }
        $cmd = $this->actionCommand($id, $action);
        try {
            $out = $this->ssh->must($cmd, true, 180);
            Audit::log("ct.$action", $this->ssh->host(), "{$this->flavor()}:$id", true, null, null, $cmd);
            return $out;
        } catch (ApiError $e) {
            Audit::log("ct.$action", $this->ssh->host(), "{$this->flavor()}:$id", false, $e->getMessage(), null, $cmd);
            throw $e;
        }
    }

    /** @return array{out:string,err:string,code:int|null,timeout:bool} */
    public function exec(string $id, string $cmd, int $timeout = 60): array
    {
        $full = $this->execCommand($id, $cmd);
        $r = $this->ssh->run($full, true, $timeout);
        Audit::log('ct.exec', $this->ssh->host(), "{$this->flavor()}:$id", $r['code'] === 0, "exit {$r['code']}", null, $cmd);
        return $r;
    }

    protected function audited(string $action, string $target, string $cmd, int $timeout = 600, ?string $logCmd = null): string
    {
        $logCmd ??= $cmd;
        try {
            $r = $this->ssh->run($cmd, true, $timeout);
            if ($r['timeout']) {
                throw new ApiError(SshClient::timeoutMessage($r, $timeout), 504);
            }
            $out = trim($r['out'] . ($r['err'] !== '' ? "\n" . $r['err'] : ''));
            if ($r['code'] !== 0) {
                throw new ApiError($out !== '' ? $out : "Command failed (exit {$r['code']})", 500);
            }
            Audit::log($action, $this->ssh->host(), $target, true, null, null, $logCmd);
            return $out;
        } catch (ApiError $e) {
            Audit::log($action, $this->ssh->host(), $target, false, $e->getMessage(), null, $logCmd);
            throw $e;
        }
    }

    protected static function normState(string $s): string
    {
        $s = strtolower(trim($s));
        return match (true) {
            in_array($s, ['running', 'up', 'started'], true)                  => 'running',
            in_array($s, ['stopped', 'exited', 'created', 'dead'], true)      => 'stopped',
            in_array($s, ['paused', 'frozen', 'freezing'], true)              => 'paused',
            default                                                           => $s ?: 'other',
        };
    }
}
