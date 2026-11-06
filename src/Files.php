<?php
declare(strict_types=1);

namespace LabAdmin;

/**
 * Read / write text files on a host or inside a container, for the built-in editor.
 *
 * - Content travels base64-encoded, so any byte survives the shell.
 * - Writes go in chunks (a single shell argument is limited to 128 KiB on Linux).
 * - The target is rewritten in place (`cat tmp > file`), so owner, mode and inode stay as they were.
 * - Optional timestamped backup next to the file, and an mtime check that refuses to overwrite a
 *   file somebody changed after it was opened.
 */
final class Files
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    private const CHUNK = 60000; // base64 chars per command
    /** Content checksum used to detect edits made by someone else (mtime has 1 s resolution). */
    private const VERSION_CMD = ' (sha256sum < "$p" 2>/dev/null || cksum < "$p") | tr -d " -"';

    public static function path(mixed $p): string
    {
        $p = is_string($p) ? trim($p) : '';
        if ($p === '' || $p[0] !== '/' || strlen($p) > 4096 || preg_match('/[\x00-\x1f]/', $p)) {
            throw new ApiError('Give an absolute path, e.g. /etc/ssh/sshd_config');
        }
        return $p;
    }

    /** Builds a runner for the host itself or for a container on it. */
    private static function runner(array $host, ?string $engine, ?string $id): \Closure
    {
        $ssh = SshClient::for($host);
        $drv = ($engine && $id) ? Hosts::driver($host, $engine) : null;
        return static function (string $script, int $timeout = 60) use ($ssh, $drv, $id): array {
            $cmd = $drv ? $drv->wrapExec((string) $id, $script) : $script;
            return $ssh->run($cmd, true, $timeout);
        };
    }

    public static function read(array $host, ?string $engine, ?string $id, string $path): array
    {
        $run = self::runner($host, $engine, $id);
        $p = Shell::q($path);
        $max = self::MAX_BYTES;
        $script = "p=$p; if [ ! -e \"\$p\" ]; then echo @@NOFILE; exit 0; fi; "
            . "if [ ! -f \"\$p\" ]; then echo @@NOTFILE; exit 0; fi; "
            . "if [ ! -r \"\$p\" ]; then echo @@NOREAD; exit 0; fi; "
            . "s=\$(stat -c '%s %Y %U:%G %a' \"\$p\" 2>/dev/null || stat -c '%s %Y %u:%g %a' \"\$p\"); echo \"@@STAT \$s\"; "
            . "w=0; [ -w \"\$p\" ] && w=1; echo \"@@W \$w\"; "
            . "echo \"@@V \$(" . self::VERSION_CMD . ")\"; "
            . "if [ \"\${s%% *}\" -gt $max ]; then echo @@TOOBIG; exit 0; fi; echo @@DATA; base64 \"\$p\"";
        $r = $run($script, 60);
        if ($r['code'] !== 0) {
            throw new ApiError(trim($r['err'] ?: $r['out']) ?: 'Cannot read file', 500);
        }
        $out = $r['out'];
        if (str_starts_with($out, '@@NOFILE')) {
            return ['exists' => false, 'content' => '', 'eol' => 'lf', 'mtime' => null, 'size' => 0, 'owner' => '', 'mode' => '', 'writable' => true, 'version' => ''];
        }
        foreach (['@@NOTFILE' => 'That path is not a regular file', '@@NOREAD' => 'Permission denied (enable sudo for this server, or connect as root)'] as $k => $msg) {
            if (str_starts_with($out, $k)) {
                throw new ApiError($msg);
            }
        }
        if (!preg_match('/^@@STAT (\d+) (\d+) (\S+) (\d+)\n@@W ([01])\n@@V ([^\n]*)\n/', $out, $m)) {
            throw new ApiError('Unexpected response while reading the file');
        }
        if (str_contains($out, "\n@@TOOBIG")) {
            throw new ApiError('File is larger than ' . (self::MAX_BYTES / 1048576) . ' MB - use the terminal for that one');
        }
        $b64 = substr($out, (int) strpos($out, "@@DATA\n") + 7);
        $raw = base64_decode(preg_replace('/\s+/', '', $b64), true);
        if ($raw === false) {
            throw new ApiError('Could not decode the file contents');
        }
        if (str_contains($raw, "\0") || !mb_check_encoding($raw, 'UTF-8')) {
            throw new ApiError('This looks like a binary or non-UTF-8 file - the editor only handles text');
        }
        $eol = str_contains($raw, "\r\n") ? 'crlf' : 'lf';
        Audit::log('file.read', $host, self::target($engine, $id, $path));
        return [
            'exists' => true, 'content' => $eol === 'crlf' ? str_replace("\r\n", "\n", $raw) : $raw, 'eol' => $eol,
            'size' => (int) $m[1], 'mtime' => (int) $m[2], 'owner' => $m[3], 'mode' => $m[4], 'writable' => $m[5] === '1',
            'version' => trim($m[6]),
        ];
    }

    public static function write(array $host, ?string $engine, ?string $id, string $path, string $content, string $eol, ?string $expectedVersion, bool $backup, bool $force): array
    {
        if ($eol === 'crlf') {
            $content = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $content));
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new ApiError('Content is larger than ' . (self::MAX_BYTES / 1048576) . ' MB');
        }
        $run = self::runner($host, $engine, $id);
        $p = Shell::q($path);
        $target = self::target($engine, $id, $path);
        $name = '.labadmin-' . bin2hex(random_bytes(6));

        // Temp file: /tmp, else /var/tmp, else next to the target.
        $r = $run("umask 077; for d in /tmp /var/tmp \"\$(dirname $p)\"; do if [ -d \"\$d\" ] && : > \"\$d/$name.b64\" 2>/dev/null; then echo \"@@TMP \$d/$name\"; exit 0; fi; done; echo @@NOTMP", 30);
        if (!preg_match('/@@TMP (\S.*)/', $r['out'], $tm)) {
            throw new ApiError('Cannot create a temporary file on the target (/tmp, /var/tmp and the file\'s folder are not writable)', 500);
        }
        $t = Shell::q(trim($tm[1]));
        foreach (str_split(base64_encode($content), self::CHUNK) ?: [''] as $chunk) {
            if ($chunk === '') {
                continue;
            }
            $r = $run("printf '%s' '$chunk' >> $t.b64", 30);
            if ($r['code'] !== 0) {
                $run("rm -f $t.b64");
                throw new ApiError('Upload to the server failed: ' . trim($r['err'] ?: $r['out']), 500);
            }
        }
        $stamp = date('Ymd-His');
        $exp = $expectedVersion !== null ? preg_replace('/[^0-9a-f]/i', '', $expectedVersion) : '';
        $bk = $backup ? '1' : '0';
        $fc = $force ? '1' : '0';
        $script = "p=$p; t=$t; "
            . "if [ -e \"\$p\" ] && [ -n '$exp' ] && [ $fc = 0 ]; then cur=\$(" . self::VERSION_CMD . "); "
            .   "if [ \"\$cur\" != '$exp' ]; then rm -f \"\$t.b64\"; echo \"@@CHANGED \$cur\"; exit 0; fi; fi; "
            . "base64 -d \"\$t.b64\" > \"\$t.raw\" || { rm -f \"\$t.b64\" \"\$t.raw\"; echo @@DECODEFAIL; exit 1; }; "
            . "if [ $bk = 1 ] && [ -e \"\$p\" ]; then cp -p \"\$p\" \"\$p.labadmin-$stamp.bak\" || { rm -f \"\$t.b64\" \"\$t.raw\"; echo @@BACKUPFAIL; exit 1; }; echo \"@@BACKUP \$p.labadmin-$stamp.bak\"; fi; "
            . "cat \"\$t.raw\" > \"\$p\" || { rm -f \"\$t.b64\" \"\$t.raw\"; echo @@WRITEFAIL; exit 1; }; "
            . "rm -f \"\$t.b64\" \"\$t.raw\"; echo \"@@OK \$(stat -c '%s %Y' \"\$p\") \$(" . self::VERSION_CMD . ")\"";
        $r = $run($script, 60);
        $out = $r['out'];

        if (str_contains($out, '@@CHANGED')) {
            throw new ApiError('The file was changed on the server after you opened it. Reload it, or save anyway to overwrite.', 409, ['changed' => true]);
        }
        if (!preg_match('/@@OK (\d+) (\d+) ?(\S*)/', $out, $m)) {
            $why = match (true) {
                str_contains($out, '@@BACKUPFAIL') => 'Could not create the backup copy (is the directory writable?)',
                str_contains($out, '@@DECODEFAIL') => '`base64 -d` is missing or failed on the target',
                default => trim($r['err'] ?: $out) ?: 'Write failed',
            };
            if (str_contains($why, 'Permission denied')) {
                $why .= ' — enable sudo for this server, or connect as root.';
            }
            Audit::log('file.write', $host, $target, false, $why);
            throw new ApiError($why, 500);
        }
        preg_match('/@@BACKUP (\S+)/', $out, $b);
        Audit::log('file.write', $host, $target, true, strlen($content) . ' bytes' . (!empty($b[1]) ? ", backup {$b[1]}" : ''));
        return ['ok' => true, 'size' => (int) $m[1], 'mtime' => (int) $m[2], 'version' => $m[3], 'backup' => $b[1] ?? null];
    }

    private static function target(?string $engine, ?string $id, string $path): string
    {
        return ($engine && $id ? "$engine:$id:" : '') . $path;
    }
}
