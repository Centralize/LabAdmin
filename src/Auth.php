<?php
declare(strict_types=1);

namespace LabAdmin;

/**
 * Single-admin session auth with CSRF protection and simple login throttling.
 */
final class Auth
{
    public static function start(): void
    {
        $cfg = $GLOBALS['LABADMIN_CONFIG'];
        session_name($cfg['session_name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();

        if (isset($_SESSION['uid'], $_SESSION['last'])
            && time() - $_SESSION['last'] > $cfg['session_lifetime']) {
            self::logout();
            session_start();
        }
        $_SESSION['last'] = time();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    public static function needsSetup(): bool
    {
        return (int) Db::one('SELECT COUNT(*) AS c FROM users')['c'] === 0;
    }

    public static function user(): ?string
    {
        return $_SESSION['user'] ?? null;
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(): bool
    {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf'], $sent);
    }

    public static function setup(string $username, string $password): void
    {
        if (!self::needsSetup()) {
            throw new ApiError('Setup already completed', 403);
        }
        self::validateCredentials($username, $password);
        Db::run('INSERT INTO users (username, password_hash) VALUES (?, ?)', [
            $username, password_hash($password, PASSWORD_DEFAULT),
        ]);
        self::login($username, $password);
    }

    public static function login(string $username, string $password): void
    {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $max = $GLOBALS['LABADMIN_CONFIG']['login_max_fails'];
        Db::run('DELETE FROM login_attempts WHERE ts < ?', [time() - 900]);
        $fails = (int) Db::one('SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ?', [$ip])['c'];
        if ($fails >= $max) {
            throw new ApiError('Too many failed logins. Try again in 15 minutes.', 429);
        }

        $row = Db::one('SELECT * FROM users WHERE username = ?', [$username]);
        if (!$row || !password_verify($password, $row['password_hash'])) {
            Db::run('INSERT INTO login_attempts (ip, ts) VALUES (?, ?)', [$ip, time()]);
            Audit::log('login', null, null, false, "Failed login for '$username' from $ip", $username);
            throw new ApiError('Invalid username or password', 401);
        }
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }
        Db::run('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
        session_regenerate_id(true);
        $_SESSION['uid']  = (int) $row['id'];
        $_SESSION['user'] = $row['username'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        Audit::log('login', null, null, true, "Login from $ip");
    }

    public static function changePassword(string $current, string $new): void
    {
        $row = Db::one('SELECT * FROM users WHERE id = ?', [$_SESSION['uid'] ?? 0]);
        if (!$row || !password_verify($current, $row['password_hash'])) {
            throw new ApiError('Current password is wrong', 400);
        }
        self::validateCredentials($row['username'], $new);
        Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $row['id']]);
        Audit::log('password.change');
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    private static function validateCredentials(string $username, string $password): void
    {
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
            throw new ApiError('Username: 3-32 chars, letters, digits . _ -', 400);
        }
        if (strlen($password) < 10) {
            throw new ApiError('Password must be at least 10 characters', 400);
        }
    }
}
