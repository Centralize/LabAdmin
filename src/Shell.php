<?php
declare(strict_types=1);

namespace LabAdmin;

/**
 * POSIX shell quoting + input validation helpers.
 * NOTE: PHP's escapeshellarg() behaves differently on Windows, and the commands
 * we build run on the *remote* Linux host - so we always quote POSIX-style.
 */
final class Shell
{
    public static function q(string|int $s): string
    {
        $s = (string) $s;
        if ($s !== '' && preg_match('#^[A-Za-z0-9_/.:=@%+,-]+$#', $s)) {
            return $s; // nothing the shell would interpret
        }
        return "'" . str_replace("'", "'\\''", (string) $s) . "'";
    }

    /** Quote a list of arguments and join them. */
    public static function args(array $args): string
    {
        return implode(' ', array_map(self::q(...), $args));
    }

    public static function name(mixed $v, string $what = 'name'): string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $v)) {
            throw new ApiError("Invalid $what: '$v'", 400);
        }
        return $v;
    }

    public static function imageRef(mixed $v): string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/:@+-]{0,254}$#', $v)) {
            throw new ApiError("Invalid image/template reference: '$v'", 400);
        }
        return $v;
    }

    public static function int(mixed $v, int $min, int $max, string $what): int
    {
        if (!is_numeric($v) || (int) $v != $v || $v < $min || $v > $max) {
            throw new ApiError("Invalid $what (expected $min-$max)", 400);
        }
        return (int) $v;
    }

    /** Split a command line into argv, honouring "double" and 'single' quotes. */
    public static function split(string $line): array
    {
        preg_match_all('/"((?:\\\\.|[^"\\\\])*)"|\'([^\']*)\'|(\S+)/', $line, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $t) {
            if (isset($t[3])) {
                $out[] = $t[3];
            } elseif (isset($t[2]) && $t[2] !== '') {
                $out[] = $t[2];
            } elseif ($t[1] !== '') {
                $out[] = stripcslashes($t[1]);
            } else {
                $out[] = '';
            }
        }
        return $out;
    }

    /** Lines of text → trimmed, non-empty list. */
    public static function lines(mixed $v): array
    {
        if (is_array($v)) {
            $v = implode("\n", array_map('strval', $v));
        }
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $v)), 'strlen'));
    }
}
