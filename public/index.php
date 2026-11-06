<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$v = static fn (string $f): string => $f . '?v=' . @filemtime(__DIR__ . '/' . $f);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LabAdmin</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%232f7d6d'/%3E%3Cpath d='M8 10h16v4H8zm0 8h16v4H8z' fill='%23fff'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="<?= $v('assets/vendor/uikit/uikit.min.css') ?>">
    <link rel="stylesheet" href="<?= $v('assets/app.css') ?>">
    <script src="<?= $v('assets/theme.js') ?>"></script>
</head>
<body>

<!-- ============ Auth ============ -->
<section id="auth" class="la-auth" hidden>
    <form id="auth-form" class="la-auth-card uk-card uk-card-default uk-card-body" autocomplete="on">
        <div class="la-brand la-brand-lg"><span class="la-logo"></span> LabAdmin</div>
        <p class="uk-text-muted uk-margin-small-top" id="auth-hint">Sign in to manage your containers.</p>
        <div class="uk-margin">
            <label class="uk-form-label" for="auth-user">Username</label>
            <input class="uk-input" id="auth-user" name="username" autocomplete="username" required>
        </div>
        <div class="uk-margin">
            <label class="uk-form-label" for="auth-pass">Password</label>
            <input class="uk-input" id="auth-pass" name="password" type="password" autocomplete="current-password" required>
        </div>
        <div class="uk-margin" id="auth-pass2-wrap" hidden>
            <label class="uk-form-label" for="auth-pass2">Repeat password</label>
            <input class="uk-input" id="auth-pass2" type="password" autocomplete="new-password">
        </div>
        <div class="la-auth-error uk-text-danger uk-text-small" id="auth-error"></div>
        <button class="uk-button uk-button-primary uk-width-1-1 uk-margin-top" id="auth-submit">Sign in</button>
    </form>
</section>

<!-- ============ App shell ============ -->
<div id="app" class="la-app" hidden>
    <header class="la-topbar">
        <button class="la-icon-btn la-only-mobile" id="nav-toggle" uk-icon="menu" aria-label="Menu"></button>
        <a class="la-brand" href="#/"><span class="la-logo"></span> LabAdmin</a>
        <div class="la-topbar-right">
            <label class="la-switch" title="Auto-refresh every 15 s">
                <input type="checkbox" id="autorefresh"> <span>Auto-refresh</span>
            </label>
            <button class="la-icon-btn" id="refresh-btn" uk-icon="refresh" title="Refresh (R)" aria-label="Refresh"></button>
            <button class="la-icon-btn" id="theme-btn" uk-icon="paint-bucket" title="Toggle theme" aria-label="Toggle theme"></button>
            <button class="la-user-btn" type="button"><span uk-icon="user"></span> <span id="user-name"></span></button>
            <div uk-dropdown="mode: click; pos: bottom-right">
                <ul class="uk-nav uk-dropdown-nav">
                    <li><a href="#" id="change-pw">Change password</a></li>
                    <li class="uk-nav-divider"></li>
                    <li><a href="#" id="logout">Sign out</a></li>
                </ul>
            </div>
        </div>
    </header>

    <div class="la-shell">
        <aside class="la-sidebar" id="sidebar">
            <nav>
                <a class="la-nav-item" href="#/" data-nav="overview"><span uk-icon="grid"></span> Overview</a>
                <a class="la-nav-item" href="#/audit" data-nav="audit"><span uk-icon="history"></span> Audit log</a>
            </nav>
            <div class="la-side-head">
                <span>Servers</span>
                <button class="la-icon-btn la-icon-sm" id="add-host" uk-icon="icon: plus; ratio: .8" title="Add server" aria-label="Add server"></button>
            </div>
            <div id="host-nav" class="la-host-nav"></div>
        </aside>
        <main class="la-main" id="view" tabindex="-1"></main>
    </div>
</div>

<!-- ============ Host editor ============ -->
<div id="modal-host" uk-modal="bg-close: false">
    <div class="uk-modal-dialog">
        <button class="uk-modal-close-default" type="button" uk-close></button>
        <form id="host-form" autocomplete="off">
            <div class="uk-modal-header"><h2 class="uk-modal-title" id="host-form-title">Add server</h2></div>
            <div class="uk-modal-body">
                <input type="hidden" name="id">
                <div class="uk-grid-small" uk-grid>
                    <div class="uk-width-1-1">
                        <label class="uk-form-label">Display name</label>
                        <input class="uk-input" name="name" required maxlength="64" placeholder="pve01">
                    </div>
                    <div class="uk-width-2-3@s">
                        <label class="uk-form-label">Hostname / IP</label>
                        <input class="uk-input" name="hostname" required placeholder="192.168.1.10">
                    </div>
                    <div class="uk-width-1-3@s">
                        <label class="uk-form-label">SSH port</label>
                        <input class="uk-input" name="port" type="number" min="1" max="65535" value="22">
                    </div>
                    <div class="uk-width-1-2@s">
                        <label class="uk-form-label">SSH user</label>
                        <input class="uk-input" name="username" required value="root">
                    </div>
                    <div class="uk-width-1-2@s">
                        <label class="uk-form-label">Authentication</label>
                        <select class="uk-select" name="auth_type">
                            <option value="key">Private key</option>
                            <option value="password">Password</option>
                        </select>
                    </div>
                    <div class="uk-width-1-1" data-auth="key">
                        <label class="uk-form-label">Private key (OpenSSH / PEM / PuTTY)</label>
                        <textarea class="uk-textarea la-mono" name="secret_key" rows="5" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea>
                        <div class="uk-flex uk-flex-middle uk-margin-small-top la-gap">
                            <label class="uk-button uk-button-default uk-button-small la-file-btn">Load key file… <input type="file" id="key-file" hidden></label>
                            <span class="uk-text-meta" data-secret-hint></span>
                        </div>
                        <label class="uk-form-label uk-margin-small-top">Key passphrase (optional)</label>
                        <input class="uk-input" name="passphrase" type="password" autocomplete="new-password">
                    </div>
                    <div class="uk-width-1-1" data-auth="password" hidden>
                        <label class="uk-form-label">Password</label>
                        <input class="uk-input" name="secret_password" type="password" autocomplete="new-password">
                        <span class="uk-text-meta" data-secret-hint></span>
                    </div>
                    <div class="uk-width-1-1">
                        <label><input class="uk-checkbox" type="checkbox" name="use_sudo"> Run container tools with <code>sudo -n</code> (needs NOPASSWD sudo)</label>
                    </div>
                    <div class="uk-width-1-1">
                        <label class="uk-form-label">Tags</label>
                        <input class="uk-input" name="tags" placeholder="proxmox, garage">
                    </div>
                    <div class="uk-width-1-1">
                        <label class="uk-form-label">Notes</label>
                        <textarea class="uk-textarea" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="uk-text-danger uk-text-small uk-margin-small-top" id="host-form-error"></div>
            </div>
            <div class="uk-modal-footer uk-flex uk-flex-between uk-flex-wrap la-gap">
                <div>
                    <button type="button" class="uk-button uk-button-danger uk-button-small" id="host-delete" hidden>Delete</button>
                    <button type="button" class="uk-button uk-button-default uk-button-small" id="host-forget-key" hidden title="Forget the pinned host key and trust the next one presented">Trust new host key</button>
                </div>
                <div>
                    <button type="button" class="uk-button uk-button-default uk-modal-close">Cancel</button>
                    <button class="uk-button uk-button-primary" id="host-save">Save &amp; test</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============ Output (logs / inspect) ============ -->
<div id="modal-output" class="uk-modal-container" uk-modal>
    <div class="uk-modal-dialog">
        <button class="uk-modal-close-default" type="button" uk-close></button>
        <div class="uk-modal-header">
            <h2 class="uk-modal-title" id="out-title">Output</h2>
            <div class="la-toolbar uk-margin-small-top" id="out-toolbar"></div>
        </div>
        <div class="uk-modal-body la-out-body"><pre class="la-console" id="out-pre"></pre></div>
    </div>
</div>

<!-- ============ Exec console ============ -->
<div id="modal-exec" class="uk-modal-container" uk-modal="bg-close: false">
    <div class="uk-modal-dialog">
        <button class="uk-modal-close-default" type="button" uk-close></button>
        <div class="uk-modal-header">
            <h2 class="uk-modal-title" id="exec-title">Run command</h2>
            <p class="uk-text-meta uk-margin-remove">Each command runs in a fresh <code>sh -c</code> (non-interactive; no TTY). Up/Down for history.</p>
        </div>
        <div class="uk-modal-body la-out-body"><pre class="la-console" id="exec-out"></pre></div>
        <div class="uk-modal-footer">
            <form id="exec-form" class="uk-flex la-gap">
                <span class="la-prompt" id="exec-prompt">$</span>
                <input class="uk-input la-mono" id="exec-input" autocomplete="off" spellcheck="false" placeholder="df -h">
                <label class="la-switch" id="exec-sudo-wrap" hidden><input type="checkbox" id="exec-sudo"> <span>sudo</span></label>
                <button class="uk-button uk-button-primary">Run</button>
            </form>
        </div>
    </div>
</div>

<!-- ============ Change password ============ -->
<div id="modal-pw" uk-modal>
    <div class="uk-modal-dialog uk-modal-body">
        <h2 class="uk-modal-title">Change password</h2>
        <form id="pw-form">
            <input class="uk-input uk-margin-small" type="password" name="current" placeholder="Current password" autocomplete="current-password" required>
            <input class="uk-input uk-margin-small" type="password" name="new" placeholder="New password (min. 10 chars)" autocomplete="new-password" required minlength="10">
            <input class="uk-input uk-margin-small" type="password" name="new2" placeholder="Repeat new password" autocomplete="new-password" required>
            <div class="uk-text-danger uk-text-small" id="pw-error"></div>
            <p class="uk-text-right">
                <button class="uk-button uk-button-default uk-modal-close" type="button">Cancel</button>
                <button class="uk-button uk-button-primary">Change</button>
            </p>
        </form>
    </div>
</div>

<script src="<?= $v('assets/vendor/uikit/uikit.min.js') ?>"></script>
<script src="<?= $v('assets/vendor/uikit/uikit-icons.min.js') ?>"></script>
<script src="<?= $v('assets/app.js') ?>" type="module"></script>
</body>
</html>
