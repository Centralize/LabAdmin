<?php
declare(strict_types=1);

namespace LabAdmin;

final class Audit
{
    public static function log(
        string $action,
        ?array $host = null,
        ?string $target = null,
        bool $success = true,
        ?string $message = null,
        ?string $username = null,
        ?string $command = null
    ): void {
        try {
            Db::run(
                'INSERT INTO audit_log (username, host_id, host_name, action, target, command, success, message) VALUES (?,?,?,?,?,?,?,?)',
                [
                    $username ?? Auth::user(),
                    $host['id'] ?? null,
                    $host['name'] ?? null,
                    $action,
                    $target,
                    $command !== null ? mb_substr($command, 0, 2000) : null,
                    $success ? 1 : 0,
                    $message !== null ? mb_substr($message, 0, 2000) : null,
                ]
            );
            // Keep the log bounded.
            Db::run('DELETE FROM audit_log WHERE id < (SELECT MAX(id) - 20000 FROM audit_log)');
        } catch (\Throwable) {
            // Never let audit failures break the request.
        }
    }
}
