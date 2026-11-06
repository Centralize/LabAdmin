// LabAdmin front-end. Vanilla ES modules + UIkit 3. No build step.
/* global UIkit */

// Custom icon (UIkit has no terminal glyph).
UIkit.icon.add({
    terminal: '<svg width="20" height="20" viewBox="0 0 20 20"><rect x="1.5" y="3.5" width="17" height="13" rx="1.5" fill="none" stroke="#000"/><polyline points="5 8 8 10.5 5 13" fill="none" stroke="#000" stroke-width="1.2"/><line x1="9.5" y1="13" x2="14.5" y2="13" stroke="#000" stroke-width="1.2"/></svg>',
});

// ---------------------------------------------------------------- helpers
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ESC[c]);
const icon = (name, ratio = 0.9) => `<span uk-icon="icon: ${name}; ratio: ${ratio}"></span>`;

const fmtBytes = (b) => {
    if (!b && b !== 0) return '–';
    const u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return `${b.toFixed(b >= 100 || i === 0 ? 0 : 1)} ${u[i]}`;
};
const fmtDur = (s) => {
    const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
    return d ? `${d}d ${h}h` : h ? `${h}h ${m}m` : `${m}m`;
};
const pct = (used, total) => (total > 0 ? Math.round((used / total) * 100) : 0);
const bar = (p) => `<div class="la-bar ${p >= 90 ? 'bad' : p >= 75 ? 'warn' : ''}"><i style="width:${Math.min(100, Math.max(0, p))}%"></i></div>`;
const utcToLocal = (ts) => {
    const d = new Date(ts.replace(' ', 'T') + 'Z');
    return isNaN(d) ? ts : d.toLocaleString();
};
const toast = (msg, status = 'primary') =>
    UIkit.notification({ message: esc(msg), status, pos: 'bottom-right', timeout: status === 'danger' ? 8000 : 3500 });

const store = {
    get(k, d = null) { try { const v = localStorage.getItem('labadmin.' + k); return v === null ? d : JSON.parse(v); } catch { return d; } },
    set(k, v) { try { localStorage.setItem('labadmin.' + k, JSON.stringify(v)); } catch { /* ignore */ } },
};

// ---------------------------------------------------------------- state
const S = {
    csrf: '',
    user: null,
    hosts: [],
    status: {},          // hostId -> {info, caps, error, cts, engines, ctErrors, loading}
    refresh: null,       // current view's refresh function
    timer: null,
    view: null,
};

// ---------------------------------------------------------------- API
class ApiErr extends Error {
    constructor(msg, status, data) { super(msg); this.status = status; this.data = data; }
}
async function api(action, data = {}) {
    let res;
    try {
        res = await fetch('api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': S.csrf },
            body: JSON.stringify({ action, ...data }),
        });
    } catch (e) {
        throw new ApiErr('Network error - is the LabAdmin server running?', 0, {});
    }
    let body = {};
    try { body = await res.json(); } catch { body = { error: `HTTP ${res.status}` }; }
    if (!res.ok) {
        if (res.status === 401 && !action.startsWith('auth.') && body.error === 'Not logged in') {
            showAuth(false);
        }
        throw new ApiErr(body.error || `HTTP ${res.status}`, res.status, body);
    }
    return body;
}

// ---------------------------------------------------------------- auth
async function boot() {
    const st = await api('auth.status');
    S.csrf = st.csrf;
    if (st.user) {
        S.user = st.user;
        startApp();
    } else {
        showAuth(st.setup);
    }
}

function showAuth(setup) {
    stopTimer();
    $('#app').hidden = true;
    $('#auth').hidden = false;
    const f = $('#auth-form');
    f.dataset.mode = setup ? 'setup' : 'login';
    $('#auth-hint').textContent = setup
        ? 'First run: create the administrator account.'
        : 'Sign in to manage your containers.';
    $('#auth-submit').textContent = setup ? 'Create account' : 'Sign in';
    $('#auth-pass2-wrap').hidden = !setup;
    $('#auth-pass').autocomplete = setup ? 'new-password' : 'current-password';
    $('#auth-error').textContent = '';
    setTimeout(() => $('#auth-user').focus(), 50);
}

$('#auth-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const setup = e.target.dataset.mode === 'setup';
    const username = $('#auth-user').value.trim();
    const password = $('#auth-pass').value;
    if (setup && password !== $('#auth-pass2').value) {
        $('#auth-error').textContent = 'Passwords do not match';
        return;
    }
    $('#auth-submit').disabled = true;
    try {
        const r = await api(setup ? 'auth.setup' : 'auth.login', { username, password });
        S.csrf = r.csrf;
        S.user = r.user;
        $('#auth-pass').value = $('#auth-pass2').value = '';
        startApp();
    } catch (err) {
        $('#auth-error').textContent = err.message;
    } finally {
        $('#auth-submit').disabled = false;
    }
});

$('#logout').addEventListener('click', async (e) => {
    e.preventDefault();
    try { await api('auth.logout'); } catch { /* ignore */ }
    location.hash = '#/';
    location.reload();
});

$('#change-pw').addEventListener('click', (e) => {
    e.preventDefault();
    $('#pw-form').reset();
    $('#pw-error').textContent = '';
    UIkit.modal('#modal-pw').show();
});
$('#pw-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    if (f.new.value !== f.new2.value) { $('#pw-error').textContent = 'Passwords do not match'; return; }
    try {
        await api('auth.password', { current: f.current.value, new: f.new.value });
        UIkit.modal('#modal-pw').hide();
        toast('Password changed', 'success');
    } catch (err) { $('#pw-error').textContent = err.message; }
});

// ---------------------------------------------------------------- shell
async function startApp() {
    $('#auth').hidden = true;
    $('#app').hidden = false;
    $('#user-name').textContent = S.user;
    $('#autorefresh').checked = store.get('autorefresh', false);
    await loadHosts();
    route();
    startTimer();
}

async function loadHosts() {
    const r = await api('hosts.list');
    S.hosts = r.hosts;
    renderNav();
}

const hostById = (id) => S.hosts.find((h) => h.id === Number(id));
const st = (id) => (S.status[id] ??= {});

function hostDotClass(id) {
    const s = S.status[id];
    if (!s) return '';
    if (s.loading && !s.info && !s.error) return 'loading';
    if (s.error) return 'bad';
    if (s.info) return 'ok';
    return '';
}

function renderNav() {
    const cur = location.hash;
    const nav = $('#host-nav');
    if (!S.hosts.length) {
        nav.innerHTML = '<div class="la-empty">No servers yet.</div>';
    } else {
        nav.innerHTML = S.hosts.map((h) => {
            const caps = h.capabilities;
            const eng = caps ? [caps.docker && 'docker', caps.lxc].filter(Boolean).join(' · ') : '';
            return `<a class="la-host-item ${cur.startsWith(`#/host/${h.id}/`) || cur === `#/host/${h.id}` ? 'is-active' : ''}" href="#/host/${h.id}/containers">
                <span class="la-dot ${hostDotClass(h.id)}"></span>
                <span class="la-host-label">${esc(h.name)}<br><small>${esc(eng || h.hostname)}</small></span></a>`;
        }).join('');
    }
    $$('.la-nav-item').forEach((a) => {
        const n = a.dataset.nav;
        a.classList.toggle('is-active', (n === 'overview' && (cur === '' || cur === '#/')) || (n === 'audit' && cur.startsWith('#/audit')));
    });
}

function startTimer() {
    stopTimer();
    if (!$('#autorefresh').checked) return;
    S.timer = setInterval(() => {
        if (document.hidden || $('.uk-modal.uk-open')) return; // don't disturb open dialogs
        S.refresh?.(true);
    }, 15000);
}
function stopTimer() { if (S.timer) clearInterval(S.timer); S.timer = null; }

$('#autorefresh').addEventListener('change', (e) => { store.set('autorefresh', e.target.checked); startTimer(); });
$('#refresh-btn').addEventListener('click', () => S.refresh?.(false));
$('#theme-btn').addEventListener('click', () => {
    const root = document.documentElement;
    const isDark = root.dataset.theme
        ? root.dataset.theme === 'dark'
        : matchMedia('(prefers-color-scheme: dark)').matches;
    root.dataset.theme = isDark ? 'light' : 'dark';
    store.set('theme', root.dataset.theme);
    try { localStorage.setItem('labadmin.theme', root.dataset.theme); } catch { /* ignore */ }
});
$('#nav-toggle').addEventListener('click', () => $('#sidebar').classList.toggle('is-open'));
$('#sidebar').addEventListener('click', (e) => { if (e.target.closest('a')) $('#sidebar').classList.remove('is-open'); });
document.addEventListener('keydown', (e) => {
    if (e.key === 'r' && !e.ctrlKey && !e.metaKey && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) && !$('.uk-modal.uk-open')) {
        S.refresh?.(false);
    }
});
window.addEventListener('hashchange', route);

function route() {
    const h = location.hash || '#/';
    window.scrollTo(0, 0);
    const m = h.match(/^#\/host\/(\d+)(?:\/(\w+))?/);
    renderNav();
    if (m && hostById(m[1])) return viewHost(Number(m[1]), m[2] || 'containers');
    if (h.startsWith('#/audit')) return viewAudit();
    return viewOverview();
}

const view = $('#view');

// ---------------------------------------------------------------- data loaders
async function loadHostStatus(id, { info = true, cts = true, refreshCaps = false } = {}) {
    const s = st(id);
    s.loading = true;
    renderNav();
    const jobs = [];
    if (info) {
        jobs.push(api('hosts.info', { host: id, refresh: refreshCaps }).then((r) => {
            s.info = r.info; s.caps = r.capabilities; s.error = null;
            const h = hostById(id);
            if (h) { h.capabilities = r.capabilities; h.host_key_fp = r.host_key_fp; }
        }).catch((e) => { s.error = e.message; s.errorData = e.data; s.info = null; }));
    }
    if (cts) {
        jobs.push(api('ct.list', { host: id }).then((r) => {
            s.cts = r.containers; s.engines = r.engines; s.ctErrors = r.errors; s.ctError = null;
        }).catch((e) => { s.ctError = e.message; s.cts = null; }));
    }
    await Promise.all(jobs);
    s.loading = false;
    s.at = Date.now();
    renderNav();
    return s;
}

// ---------------------------------------------------------------- Overview
function viewOverview() {
    S.view = 'overview';
    document.title = 'Overview · LabAdmin';
    if (!S.hosts.length) {
        view.innerHTML = `<div class="la-empty-state">
            <h2>Welcome to LabAdmin</h2>
            <p>Add your first server to manage its Docker and LXC containers over SSH.</p>
            <button class="uk-button uk-button-primary" data-act="add-host">${icon('plus')} Add server</button></div>`;
        S.refresh = null;
        return;
    }
    view.innerHTML = `<div class="la-page-head"><div><h1>Overview</h1>
        <div class="la-sub">${S.hosts.length} server${S.hosts.length === 1 ? '' : 's'}</div></div>
        <div class="la-toolbar"><button class="uk-button uk-button-default" data-act="add-host">${icon('plus')} Add server</button></div></div>
        <div class="la-cards" id="cards">${S.hosts.map((h) => `<div class="uk-card uk-card-default la-host-card" data-host="${h.id}"></div>`).join('')}</div>`;
    S.hosts.forEach((h) => renderCard(h.id));

    S.refresh = () => S.hosts.forEach((h) => loadHostStatus(h.id).then(() => S.view === 'overview' && renderCard(h.id)));
    S.refresh();
}

function renderCard(id) {
    const el = $(`.la-host-card[data-host="${id}"]`);
    const h = hostById(id);
    if (!el || !h) return;
    const s = S.status[id] || {};
    const head = `<h3><span class="la-dot ${hostDotClass(id)}"></span>${esc(h.name)}
        ${s.loading ? '<span class="la-spinner" style="margin-left:auto"></span>' : ''}</h3>
        <div class="la-meta">${esc(h.username)}@${esc(h.hostname)}${h.port !== 22 ? ':' + h.port : ''}${s.info ? ' · ' + esc(s.info.os) : ''}</div>`;

    if (s.error) {
        el.innerHTML = head + `<div class="la-card-error">${esc(s.error)}</div>`;
        return;
    }
    if (!s.info) {
        el.innerHTML = head + '<div class="la-meta">Connecting…</div>';
        return;
    }
    const i = s.info;
    const memUsed = i.mem.total - i.mem.avail;
    const root = i.disks.find((d) => d.mount === '/') || i.disks[0];
    const counts = { docker: [0, 0], lxc: [0, 0] };
    (s.cts || []).forEach((c) => { counts[c.engine][1]++; if (c.state === 'running') counts[c.engine][0]++; });
    const engines = s.engines || {};
    el.innerHTML = head + `
        <div class="la-metric"><span>CPU</span>${bar(i.cpu_pct ?? 0)}<span>${i.cpu_pct ?? '–'}% · ${i.cpus}c</span></div>
        <div class="la-metric"><span>RAM</span>${bar(pct(memUsed, i.mem.total))}<span>${fmtBytes(memUsed)} / ${fmtBytes(i.mem.total)}</span></div>
        ${root ? `<div class="la-metric"><span>Disk</span>${bar(pct(root.used, root.size))}<span>${pct(root.used, root.size)}% of ${fmtBytes(root.size)}</span></div>` : ''}
        <div class="la-counts">
            ${engines.docker ? `<div class="la-count"><b>${counts.docker[0]}</b>/${counts.docker[1]}<small>Docker running</small></div>` : ''}
            ${engines.lxc ? `<div class="la-count"><b>${counts.lxc[0]}</b>/${counts.lxc[1]}<small>${esc(engines.lxc.flavor.toUpperCase())} running</small></div>` : ''}
            ${!engines.docker && !engines.lxc && s.cts ? '<div class="la-meta">No Docker or LXC detected</div>' : ''}
        </div>
        <div class="la-meta">Up ${fmtDur(i.uptime)} · load ${i.load.map((x) => x.toFixed(2)).join(' ')}</div>
        ${s.ctError ? `<div class="la-card-error">${esc(s.ctError)}</div>` : ''}`;
}

// ---------------------------------------------------------------- Host view
const TABS = [['containers', 'Containers', 'server'], ['images', 'Images & templates', 'album'], ['create', 'Create', 'plus-circle'], ['info', 'System', 'info']];

function viewHost(id, tab) {
    const h = hostById(id);
    S.view = `host:${id}:${tab}`;
    document.title = `${h.name} · LabAdmin`;
    const s = st(id);
    view.innerHTML = `
        <div class="la-page-head">
            <div><h1>${esc(h.name)}</h1>
                <div class="la-sub"><span>${icon('link', 0.75)} ${esc(h.username)}@${esc(h.hostname)}:${h.port}</span>
                <span id="host-engines"></span>${h.use_sudo ? '<span class="la-badge">sudo</span>' : ''}
                ${h.tags ? h.tags.split(',').map((t) => t.trim()).filter(Boolean).map((t) => `<span class="uk-label">${esc(t)}</span>`).join(' ') : ''}</div>
            </div>
            <div class="la-toolbar">
                <button class="uk-button uk-button-primary" data-act="host-term" title="Interactive terminal on the host">${icon('terminal')} Terminal</button>
                <button class="uk-button uk-button-default" data-act="host-edit" title="Edit a file on the host">${icon('file-edit')} Edit file</button>
                <button class="uk-button uk-button-default" data-act="host-shell" title="Run a single command and see its output">${icon('play-circle')} Run command</button>
                <button class="uk-button uk-button-default" data-act="edit-host">${icon('cog')} Settings</button>
            </div>
        </div>
        <div id="host-banner"></div>
        <ul class="uk-subnav uk-subnav-pill uk-margin-bottom">
            ${TABS.map(([k, label, ic]) => `<li class="${k === tab ? 'uk-active' : ''}"><a href="#/host/${id}/${k}">${icon(ic, 0.8)} ${label}</a></li>`).join('')}
        </ul>
        <div id="tab"></div>`;
    view.dataset.host = id;
    renderEngines(id);
    ({ containers: tabContainers, images: tabImages, create: tabCreate, info: tabInfo }[tab] || tabContainers)(id);
    if (!s.info && !s.loading) loadHostStatus(id, { cts: false }).then(() => isHostView(id) && (renderEngines(id), renderBanner(id)));
}

const isHostView = (id, tab) => S.view?.startsWith(`host:${id}:`) && (!tab || S.view.endsWith(':' + tab));

function renderEngines(id) {
    const el = $('#host-engines');
    if (!el) return;
    const c = hostById(id)?.capabilities;
    el.innerHTML = c ? [c.docker && `<span class="la-badge docker">Docker ${esc(c.versions?.docker || '')}</span>`,
        c.lxc && `<span class="la-badge ${esc(c.lxc)}">${esc(c.lxc.toUpperCase())}</span>`].filter(Boolean).join(' ') || '<span class="la-badge">no engines</span>' : '';
}

function renderBanner(id) {
    const s = S.status[id];
    const el = $('#host-banner');
    if (!el) return;
    let html = '';
    if (s?.error) {
        html += `<div class="la-banner">${icon('warning')} ${esc(s.error)}
            ${s.errorData?.hostKeyMismatch ? ` <button class="uk-button uk-button-small uk-button-danger uk-margin-small-left" data-act="forget-key">Trust new host key</button>` : ''}</div>`;
    }
    if (s?.caps?.docker_error) html += `<div class="la-banner warn">Docker CLI found but not usable: ${esc(s.caps.docker_error)}</div>`;
    Object.entries(s?.ctErrors || {}).forEach(([eng, msg]) => { html += `<div class="la-banner warn"><b>${esc(eng)}:</b> ${esc(msg)}</div>`; });
    el.innerHTML = html;
}

// ---- Containers tab
function tabContainers(id) {
    const f = store.get('ctFilter', { q: '', engine: '', state: '' });
    $('#tab').innerHTML = `
        <div class="la-toolbar uk-margin-small-bottom">
            <input class="uk-input la-grow" id="ct-q" type="search" placeholder="Filter by name, image, IP…" value="${esc(f.q)}">
            <select class="uk-select" id="ct-engine" style="width:auto">
                <option value="">All engines</option><option value="docker">Docker</option><option value="lxc">LXC</option></select>
            <select class="uk-select" id="ct-state" style="width:auto">
                <option value="">Any state</option><option value="running">Running</option><option value="stopped">Stopped</option><option value="paused">Paused</option></select>
            <span class="uk-text-meta" id="ct-meta"></span>
        </div>
        <div class="la-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small">
            <thead><tr><th>Name</th><th>Engine</th><th class="la-hide-sm">Image</th><th>Status</th><th class="la-hide-sm">Network</th><th class="la-hide-sm">CPU</th><th class="la-hide-sm">Memory</th><th></th></tr></thead>
            <tbody id="ct-body"><tr><td colspan="8" class="uk-text-muted"><span class="la-spinner"></span> Loading containers…</td></tr></tbody>
        </table></div>`;
    $('#ct-engine').value = f.engine;
    $('#ct-state').value = f.state;
    const onFilter = () => {
        store.set('ctFilter', { q: $('#ct-q').value, engine: $('#ct-engine').value, state: $('#ct-state').value });
        renderCtRows(id);
    };
    $('#ct-q').addEventListener('input', onFilter);
    $('#ct-engine').addEventListener('change', onFilter);
    $('#ct-state').addEventListener('change', onFilter);

    if (S.status[id]?.cts) renderCtRows(id);
    S.refresh = async (silent) => {
        await loadHostStatus(id, { info: !silent || !S.status[id]?.info });
        if (isHostView(id, 'containers')) { renderCtRows(id); renderBanner(id); renderEngines(id); }
    };
    S.refresh();
}

function renderCtRows(id) {
    const s = S.status[id] || {};
    const body = $('#ct-body');
    if (!body) return;
    if (s.ctError) { body.innerHTML = `<tr><td colspan="8" class="uk-text-danger">${esc(s.ctError)}</td></tr>`; return; }
    if (!s.cts) return;
    const q = $('#ct-q').value.toLowerCase().trim();
    const eng = $('#ct-engine').value, stf = $('#ct-state').value;
    const list = s.cts.filter((c) =>
        (!eng || c.engine === eng) && (!stf || c.state === stf) &&
        (!q || [c.name, c.id, c.image, c.group, c.status, c.ports, ...(c.ips || [])].join(' ').toLowerCase().includes(q)))
        .sort((a, b) => (a.engine + a.group).localeCompare(b.engine + b.group) || a.name.localeCompare(b.name, undefined, { numeric: true }));

    const running = s.cts.filter((c) => c.state === 'running').length;
    $('#ct-meta').textContent = `${list.length} shown · ${running}/${s.cts.length} running · updated ${new Date(s.at).toLocaleTimeString()}`;

    if (!s.cts.length) {
        const any = Object.keys(s.engines || {}).length;
        body.innerHTML = `<tr><td colspan="8" class="uk-text-muted">${any ? 'No containers on this host yet. Use the Create tab to add one.' : 'Neither Docker nor LXC was detected on this host.'}</td></tr>`;
        return;
    }
    if (!list.length) { body.innerHTML = '<tr><td colspan="8" class="uk-text-muted">No containers match the filter.</td></tr>'; return; }

    let lastGroup = null;
    body.innerHTML = list.map((c) => {
        const gkey = c.engine + '|' + c.group;
        let groupRow = '';
        if (c.group && gkey !== lastGroup) {
            groupRow = `<tr class="la-group-row"><td colspan="8">${c.engine === 'docker' ? 'compose: ' : ''}${esc(c.group)}</td></tr>`;
        }
        lastGroup = gkey;
        const acts = s.engines?.[c.engine]?.actions || [];
        const has = (a) => acts.includes(a);
        const run = c.state === 'running', paused = c.state === 'paused';
        const net = [...(c.ips || []), c.ports].filter(Boolean).join('  ');
        const k = `data-engine="${esc(c.engine)}" data-flavor="${esc(c.flavor)}" data-id="${esc(c.id)}" data-name="${esc(c.name)}"`;
        return groupRow + `<tr ${k}>
            <td><div class="la-name">${esc(c.name)}<small>${esc(c.flavor === 'docker' ? c.short_id : (c.id !== c.name ? c.id : ''))}</small></div></td>
            <td><span class="la-badge ${esc(c.flavor)}">${esc(c.flavor === 'docker' ? 'Docker' : c.flavor.toUpperCase())}</span></td>
            <td class="la-hide-sm"><span class="la-cell-sub" style="font-size:.82rem">${esc(c.image) || '–'}</span></td>
            <td><span class="la-state"><span class="la-dot ${esc(c.state)}"></span>${esc(c.status || c.state)}</span></td>
            <td class="la-hide-sm la-ports">${esc(net) || '–'}</td>
            <td class="la-hide-sm la-num">${esc(c.cpu ?? '–')}</td>
            <td class="la-hide-sm la-num">${esc(c.mem ?? '–')}</td>
            <td class="la-actions">
                ${!run && !paused ? `<button class="la-icon-btn go" data-op="start" title="Start">${icon('play')}</button>` : ''}
                ${paused && has('unpause') ? `<button class="la-icon-btn go" data-op="unpause" title="Resume">${icon('play')}</button>` : ''}
                ${run ? `<button class="la-icon-btn halt" data-op="${has('shutdown') ? 'shutdown' : 'stop'}" title="${has('shutdown') ? 'Shutdown' : 'Stop'}">${icon('ban')}</button>` : ''}
                ${run ? `<button class="la-icon-btn" data-op="restart" title="Restart">${icon('refresh')}</button>` : ''}
                <button class="la-icon-btn" data-ct="logs" title="Logs">${icon('file-text')}</button>
                <button class="la-icon-btn" data-ct="term" title="Terminal" ${run ? '' : 'disabled style="opacity:.35"'}>${icon('terminal')}</button>
                <button class="la-icon-btn" title="More">${icon('more-vertical')}</button>
                <div uk-dropdown="mode: click; pos: bottom-right; animation: false">
                    <ul class="uk-nav uk-dropdown-nav">
                        <li><a href="#" data-ct="inspect">${icon('code', 0.8)} Inspect / config</a></li>
                        ${run ? `<li><a href="#" data-ct="edit">${icon('file-edit', 0.8)} Edit file…</a></li>
                        <li><a href="#" data-ct="exec">${icon('play-circle', 0.8)} Run command…</a></li>` : ''}
                        ${run && has('pause') ? `<li><a href="#" data-op="pause">${icon('clock', 0.8)} Pause / freeze</a></li>` : ''}
                        ${run && has('shutdown') ? `<li><a href="#" data-op="stop">${icon('bolt', 0.8)} Force stop</a></li>` : ''}
                        ${run && has('kill') ? `<li><a href="#" data-op="kill">${icon('bolt', 0.8)} Kill</a></li>` : ''}
                        <li class="uk-nav-divider"></li>
                        <li><a href="#" class="la-danger" data-op="delete">${icon('trash', 0.8)} Delete…</a></li>
                    </ul>
                </div>
            </td></tr>`;
    }).join('');
}

// Container actions (event delegation)
view.addEventListener('click', async (e) => {
    const t = e.target.closest('[data-op],[data-ct],[data-act]');
    if (!t) {
        const card = e.target.closest('.la-host-card[data-host]');
        if (card) location.hash = `#/host/${card.dataset.host}/containers`;
        return;
    }
    if (t.disabled) return;
    const hostId = Number(view.dataset.host);
    if (t.dataset.act) {
        e.preventDefault();
        return onAct(t.dataset.act, hostId, t);
    }
    const tr = t.closest('tr[data-id]');
    if (!tr) return;
    e.preventDefault();
    const c = { engine: tr.dataset.engine, flavor: tr.dataset.flavor, id: tr.dataset.id, name: tr.dataset.name };
    const dd = t.closest('.uk-dropdown');
    if (dd) UIkit.dropdown(dd).hide(false);
    if (t.dataset.ct === 'logs') return openLogs(hostId, c);
    if (t.dataset.ct === 'inspect') return openInspect(hostId, c);
    if (t.dataset.ct === 'exec') return openExec(hostId, c);
    if (t.dataset.ct === 'term') return openTerminal(hostId, c);
    if (t.dataset.ct === 'edit') return openEditor(hostId, c);
    if (t.dataset.op) return ctAction(hostId, c, t.dataset.op, tr);
});

async function ctAction(hostId, c, op, tr) {
    if (op === 'delete') {
        try {
            const typed = await UIkit.modal.prompt(`<b>Permanently delete ${esc(c.name)}?</b><br><span class="uk-text-meta">This removes the container and its non-persistent data. Type the name to confirm.</span>`, '');
            if (typed === null) return;
            if (typed.trim() !== c.name && typed.trim() !== c.id) { toast('Name did not match - nothing deleted', 'warning'); return; }
        } catch { return; }
    } else if (['stop', 'kill', 'restart', 'shutdown'].includes(op)) {
        try { await UIkit.modal.confirm(`${esc(op[0].toUpperCase() + op.slice(1))} <b>${esc(c.name)}</b>?`); } catch { return; }
    }
    tr?.classList.add('is-busy');
    try {
        await api('ct.action', { host: hostId, engine: c.engine, id: c.id, op });
        toast(`${c.name}: ${op} OK`, 'success');
    } catch (err) {
        toast(`${c.name}: ${err.message}`, 'danger');
    }
    await S.refresh?.(true);
}

async function onAct(act, hostId, el) {
    switch (act) {
        case 'add-host': return openHostForm(null);
        case 'edit-host': return openHostForm(hostById(hostId));
        case 'host-shell': return openExec(hostId, null);
        case 'host-term': return openTerminal(hostId, null);
        case 'host-edit': return openEditor(hostId, null);
        case 'forget-key': {
            try { await UIkit.modal.confirm('Forget the pinned host key and trust the key the server presents now? Only do this if you know the server was reinstalled or its keys changed.'); } catch { return; }
            await api('hosts.forgetKey', { id: hostId });
            S.status[hostId] = {};
            await loadHosts();
            return route();
        }
        case 'redetect': {
            el.disabled = true;
            await loadHostStatus(hostId, { refreshCaps: true });
            await loadHosts();
            return route();
        }
        case 'pull': return pullImage(hostId);
        case 'rm-image': return removeImage(hostId, el.dataset.engine, el.dataset.ref);
        case 'pick-template': {
            const inp = $('#pull-ref');
            if (inp) { inp.value = el.dataset.ref; inp.focus(); }
            return;
        }
        case 'browse-templates': return browseTemplates(hostId);
        case 'audit-more': return S.auditMore?.();
    }
}

// ---- Output modal (logs / inspect)
let outTimer = null;
UIkit.util.on('#modal-output', 'hidden', () => { clearInterval(outTimer); outTimer = null; });

function openOutput(title, toolbarHtml) {
    $('#out-title').innerHTML = title;
    $('#out-toolbar').innerHTML = toolbarHtml;
    const pre = $('#out-pre');
    pre.textContent = '';
    pre.classList.toggle('nowrap', !store.get('wrap', true));
    UIkit.modal('#modal-output').show();
    return pre;
}

function openLogs(hostId, c) {
    const lines = store.get('logLines', 200);
    const pre = openOutput(`${icon('file-text')} Logs · ${esc(c.name)}`, `
        <select class="uk-select" id="log-lines">${[50, 200, 500, 1000, 5000].map((n) => `<option ${n === lines ? 'selected' : ''}>${n}</option>`).join('')}</select>
        <input class="uk-input la-grow" id="log-grep" type="search" placeholder="Highlight / filter…">
        <label class="la-switch"><input type="checkbox" id="log-follow"> <span>Follow (3 s)</span></label>
        <label class="la-switch"><input type="checkbox" id="log-wrap" ${store.get('wrap', true) ? 'checked' : ''}> <span>Wrap</span></label>
        <button class="uk-button uk-button-default uk-button-small" id="log-reload">${icon('refresh', 0.8)} Reload</button>
        <button class="uk-button uk-button-default uk-button-small" id="log-copy">${icon('copy', 0.8)} Copy</button>`);
    let raw = '';
    const paint = () => {
        const g = $('#log-grep').value.trim().toLowerCase();
        const txt = g ? raw.split('\n').filter((l) => l.toLowerCase().includes(g)).join('\n') : raw;
        const atBottom = pre.scrollHeight - pre.scrollTop - pre.clientHeight < 40;
        pre.textContent = txt || '(no output)';
        if (atBottom || !pre.dataset.loaded) pre.scrollTop = pre.scrollHeight;
        pre.dataset.loaded = '1';
    };
    const load = async () => {
        try {
            const r = await api('ct.logs', { host: hostId, engine: c.engine, id: c.id, lines: Number($('#log-lines').value) });
            raw = r.output;
        } catch (e) { raw = `Error: ${e.message}`; }
        paint();
    };
    delete pre.dataset.loaded;
    pre.textContent = 'Loading…';
    $('#log-lines').onchange = () => { store.set('logLines', Number($('#log-lines').value)); load(); };
    $('#log-grep').oninput = paint;
    $('#log-reload').onclick = load;
    $('#log-copy').onclick = () => navigator.clipboard?.writeText(pre.textContent).then(() => toast('Copied'));
    $('#log-wrap').onchange = (e) => { store.set('wrap', e.target.checked); pre.classList.toggle('nowrap', !e.target.checked); };
    $('#log-follow').onchange = (e) => {
        clearInterval(outTimer);
        outTimer = e.target.checked ? setInterval(load, 3000) : null;
    };
    load();
}

async function openInspect(hostId, c) {
    const pre = openOutput(`${icon('code')} Inspect · ${esc(c.name)}`,
        `<button class="uk-button uk-button-default uk-button-small" id="ins-copy">${icon('copy', 0.8)} Copy</button>`);
    $('#ins-copy').onclick = () => navigator.clipboard?.writeText(pre.textContent).then(() => toast('Copied'));
    pre.textContent = 'Loading…';
    try {
        const r = await api('ct.inspect', { host: hostId, engine: c.engine, id: c.id });
        let out = r.output;
        try { out = JSON.stringify(JSON.parse(out), null, 2); } catch { /* not JSON */ }
        pre.textContent = out;
    } catch (e) { pre.textContent = `Error: ${e.message}`; }
}

// ---- Exec console (container or host)
const execCtx = { hostId: null, c: null, hist: [], pos: 0 };
function openExec(hostId, c) {
    const h = hostById(hostId);
    Object.assign(execCtx, { hostId, c, hist: store.get('execHist', []), pos: -1 });
    $('#exec-title').innerHTML = c ? `${icon('terminal')} ${esc(c.name)} <span class="uk-text-meta">on ${esc(h.name)}</span>` : `${icon('terminal')} Host shell · ${esc(h.name)}`;
    $('#exec-prompt').textContent = c ? `${c.name} $` : `${h.username}@${h.name} $`;
    $('#exec-sudo-wrap').hidden = !!c || !h.use_sudo;
    $('#exec-out').innerHTML = `<span class="c-meta"># ${c ? `${esc(c.flavor || c.engine)} exec into ${esc(c.name)}` : `ssh ${esc(h.username)}@${esc(h.hostname)}`}</span>\n`;
    UIkit.modal('#modal-exec').show();
    setTimeout(() => $('#exec-input').focus(), 150);
}
$('#exec-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const inp = $('#exec-input');
    const cmd = inp.value.trim();
    if (!cmd) return;
    const out = $('#exec-out');
    if (cmd === 'clear') { out.innerHTML = ''; inp.value = ''; return; }
    // Full-screen / interactive programs need a real terminal (TTY) - offer it instead of hanging.
    const prog = cmd.replace(/^(sudo\s+(-\S+\s+)*)/, '').split(/\s+/)[0].replace(/^.*\//, '');
    const interactive = ['vim', 'vi', 'nvim', 'nano', 'pico', 'emacs', 'mcedit', 'mc', 'top', 'htop', 'btop', 'atop', 'less', 'more', 'man',
        'tmux', 'screen', 'ssh', 'su', 'bash', 'sh', 'zsh', 'mysql', 'mariadb', 'psql', 'sqlite3', 'redis-cli', 'python', 'python3', 'php', 'node',
        'watch', 'passwd', 'visudo', 'crontab', 'dpkg-reconfigure', 'nmtui', 'alsamixer', 'iftop', 'nethogs', 'ncdu'];
    const needsTty = interactive.includes(prog) && !(prog === 'crontab' && /\s-l\b/.test(cmd)) && !/\s(--version|-V|-v|--help)\b/.test(cmd)
        && !(['sh', 'bash', 'zsh', 'python', 'python3', 'php', 'node'].includes(prog) && /\s-c\s|\s\S+\.(sh|py|php|js)\b/.test(cmd));
    if (needsTty) {
        out.insertAdjacentHTML('beforeend', `<span class="c-cmd">${esc($('#exec-prompt').textContent)} ${esc(cmd)}</span>\n`
            + `<span class="c-err">'${esc(prog)}' is interactive and needs a real terminal - it would hang here.</span>\n`);
        try {
            await UIkit.modal.confirm(`<b>${esc(prog)}</b> needs an interactive terminal. Open the Terminal for ${esc(execCtx.c ? execCtx.c.name : hostById(execCtx.hostId).name)} instead?`, { labels: { ok: 'Open Terminal', cancel: 'Cancel' } });
            const { hostId, c } = execCtx;
            UIkit.modal('#modal-exec').hide();
            openTerminal(hostId, c);
        } catch { inp.focus(); }
        return;
    }
    execCtx.hist = [cmd, ...execCtx.hist.filter((x) => x !== cmd)].slice(0, 50);
    store.set('execHist', execCtx.hist);
    execCtx.pos = -1;
    inp.value = '';
    inp.disabled = true;
    out.insertAdjacentHTML('beforeend', `<span class="c-cmd">${esc($('#exec-prompt').textContent)} ${esc(cmd)}</span>\n`);
    out.scrollTop = out.scrollHeight;
    const t0 = performance.now();
    try {
        const { c, hostId } = execCtx;
        const r = c
            ? await api('ct.exec', { host: hostId, engine: c.engine, id: c.id, cmd })
            : await api('hosts.exec', { host: hostId, cmd, sudo: $('#exec-sudo').checked });
        out.insertAdjacentHTML('beforeend', esc(r.out) + (r.err ? `<span class="c-err">${esc(r.err)}</span>` : '')
            + `<span class="c-meta">[exit ${r.code ?? '?'}${r.timeout ? ', TIMED OUT' : ''} · ${Math.round(performance.now() - t0)} ms]</span>\n`);
    } catch (err) {
        out.insertAdjacentHTML('beforeend', `<span class="c-err">${esc(err.message)}</span>\n`);
    }
    inp.disabled = false;
    inp.focus();
    out.scrollTop = out.scrollHeight;
});
$('#exec-input').addEventListener('keydown', (e) => {
    const h = execCtx.hist;
    if (e.key === 'ArrowUp' && h.length) { execCtx.pos = Math.min(h.length - 1, execCtx.pos + 1); e.target.value = h[execCtx.pos]; e.preventDefault(); }
    if (e.key === 'ArrowDown') { execCtx.pos = Math.max(-1, execCtx.pos - 1); e.target.value = execCtx.pos >= 0 ? h[execCtx.pos] : ''; e.preventDefault(); }
});

// ---- Images tab
function tabImages(id) {
    $('#tab').innerHTML = `
        <div class="uk-card uk-card-default la-panel uk-margin-bottom">
            <h3>Pull image / download template</h3>
            <form class="la-toolbar" id="pull-form">
                <select class="uk-select" id="pull-engine" style="width:auto"></select>
                <input class="uk-input la-grow la-mono" id="pull-ref" required placeholder="">
                <input class="uk-input" id="pull-storage" placeholder="storage" value="local" style="width:120px" hidden>
                <button class="uk-button uk-button-default" type="button" data-act="browse-templates" id="browse-btn" hidden>${icon('search', 0.8)} Browse</button>
                <button class="uk-button uk-button-primary" data-act="pull">${icon('download', 0.8)} Pull</button>
            </form>
            <div id="pull-hint" class="uk-text-meta uk-margin-small-top"></div>
            <pre class="la-console la-console-inline uk-margin-small-top" id="pull-out" hidden></pre>
            <div id="tpl-list"></div>
        </div>
        <div class="la-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small">
            <thead><tr><th>Engine</th><th>Name / alias</th><th>Tag / storage</th><th class="la-hide-sm">ID</th><th>Size</th><th class="la-hide-sm">Created</th><th></th></tr></thead>
            <tbody id="img-body"><tr><td colspan="7" class="uk-text-muted"><span class="la-spinner"></span> Loading…</td></tr></tbody></table></div>`;
    $('#pull-form').addEventListener('submit', (e) => { e.preventDefault(); pullImage(id); });

    const setupPull = () => {
        const engines = S.status[id]?.engines || {};
        const sel = $('#pull-engine');
        if (!sel) return;
        const opts = Object.entries(engines).filter(([, v]) => v.flavor !== 'lxc');
        sel.innerHTML = opts.map(([k, v]) => `<option value="${k}">${v.flavor === 'docker' ? 'Docker' : v.flavor.toUpperCase()}</option>`).join('');
        $('#pull-form').hidden = !opts.length;
        const upd = () => {
            const fl = engines[sel.value]?.flavor;
            $('#pull-ref').placeholder = { docker: 'nginx:alpine', pct: 'debian-12-standard_12.7-1_amd64.tar.zst', incus: 'images:debian/12', lxd: 'ubuntu:24.04' }[fl] || '';
            $('#pull-storage').hidden = fl !== 'pct';
            $('#browse-btn').hidden = fl !== 'pct';
            $('#pull-hint').textContent = fl === 'pct' ? 'Downloads a template with pveam into the given storage. Use Browse to pick from the official catalogue.'
                : fl === 'docker' ? 'Pulls from the registry configured on the host (Docker Hub by default).'
                    : fl ? 'Copies an image from a remote (e.g. images:) into the local image store.' : 'Classic LXC uses the download template at create time; nothing to pull.';
            $('#tpl-list').innerHTML = '';
        };
        sel.onchange = upd;
        upd();
        if (!opts.length) $('#pull-hint').textContent = engines.lxc?.flavor === 'lxc' ? 'Classic LXC downloads images at create time – nothing to pull here.' : 'No image-capable engine detected.';
    };

    S.refresh = async () => {
        if (!S.status[id]?.engines) await loadHostStatus(id, { info: false });
        if (!isHostView(id, 'images')) return;
        setupPull();
        try {
            const r = await api('img.list', { host: id });
            if (!isHostView(id, 'images')) return;
            const errs = Object.entries(r.errors).map(([k, v]) => `<tr><td colspan="7" class="uk-text-danger">${esc(k)}: ${esc(v)}</td></tr>`).join('');
            $('#img-body').innerHTML = errs + (r.images.length ? r.images.map((i) => `<tr>
                <td><span class="la-badge ${esc(i.flavor)}">${esc(i.flavor === 'docker' ? 'Docker' : i.flavor.toUpperCase())}</span></td>
                <td class="la-name">${esc(i.name)}</td><td>${esc(i.tag)}</td>
                <td class="la-hide-sm la-mono uk-text-small">${esc(i.id)}</td><td class="la-num">${esc(i.size)}</td>
                <td class="la-hide-sm uk-text-small">${esc(i.created)}</td>
                <td class="la-actions"><button class="la-icon-btn halt" data-act="rm-image" data-engine="${esc(i.engine)}" data-ref="${esc(i.ref)}" title="Remove">${icon('trash')}</button></td></tr>`).join('')
                : '<tr><td colspan="7" class="uk-text-muted">No images or templates stored on this host.</td></tr>');
        } catch (e) {
            $('#img-body').innerHTML = `<tr><td colspan="7" class="uk-text-danger">${esc(e.message)}</td></tr>`;
        }
    };
    S.refresh();
}

async function pullImage(hostId) {
    const ref = $('#pull-ref').value.trim();
    if (!ref) return;
    const engine = $('#pull-engine').value;
    const out = $('#pull-out');
    const btn = $('#pull-form [data-act="pull"]');
    out.hidden = false;
    out.textContent = `Pulling ${ref} … (this can take a while)`;
    btn.disabled = true;
    try {
        const r = await api('img.pull', { host: hostId, engine, ref, storage: $('#pull-storage').value || 'local' });
        out.textContent = r.output || 'Done.';
        toast(`Pulled ${ref}`, 'success');
        S.refresh?.();
    } catch (e) {
        out.textContent = `Error: ${e.message}`;
    } finally { btn.disabled = false; }
}

async function removeImage(hostId, engine, ref) {
    try { await UIkit.modal.confirm(`Remove image/template <b>${esc(ref)}</b>?`); } catch { return; }
    try {
        await api('img.remove', { host: hostId, engine, ref });
        toast('Removed', 'success');
    } catch (e) { toast(e.message, 'danger'); }
    S.refresh?.();
}

async function browseTemplates(hostId) {
    const box = $('#tpl-list');
    box.innerHTML = '<p class="uk-text-meta"><span class="la-spinner"></span> Updating template catalogue (pveam update)…</p>';
    try {
        const r = await api('img.available', { host: hostId });
        box.innerHTML = `<div class="uk-margin-small-top" style="max-height:260px;overflow:auto">
            ${r.templates.map((t) => `<button type="button" class="uk-button uk-button-default uk-button-small uk-margin-small-right uk-margin-small-bottom la-mono" data-act="pick-template" data-ref="${esc(t)}">${esc(t)}</button>`).join('')}</div>`;
    } catch (e) { box.innerHTML = `<p class="uk-text-danger">${esc(e.message)}</p>`; }
}

// ---- Create tab
function tabCreate(id) {
    $('#tab').innerHTML = `<div class="uk-card uk-card-default la-panel">
        <div class="la-toolbar uk-margin-bottom" id="create-engines"></div>
        <form id="create-form"><p class="uk-text-muted"><span class="la-spinner"></span> Loading…</p></form>
        <pre class="la-console la-console-inline uk-margin-top" id="create-out" hidden></pre></div>`;
    S.refresh = null;

    (async () => {
        if (!S.status[id]?.engines) await loadHostStatus(id, { info: false });
        if (!isHostView(id, 'create')) return;
        const engines = Object.entries(S.status[id]?.engines || {});
        if (!engines.length) { $('#create-form').innerHTML = '<p class="uk-text-muted">No container engine detected on this host.</p>'; return; }
        const pick = store.get('createEngine', engines[0][0]);
        $('#create-engines').innerHTML = engines.length > 1
            ? `<ul class="uk-subnav uk-subnav-pill uk-margin-remove">${engines.map(([k, v]) => `<li class="${k === pick ? 'uk-active' : ''}"><a href="#" data-eng="${k}">${v.flavor === 'docker' ? 'Docker' : v.flavor.toUpperCase()}</a></li>`).join('')}</ul>` : '';
        $$('#create-engines [data-eng]').forEach((a) => a.addEventListener('click', (e) => {
            e.preventDefault();
            store.set('createEngine', a.dataset.eng);
            $$('#create-engines li').forEach((li) => li.classList.toggle('uk-active', li.contains(a)));
            loadSchema(id, a.dataset.eng);
        }));
        loadSchema(id, engines.some(([k]) => k === pick) ? pick : engines[0][0]);
    })();
}

async function loadSchema(hostId, engine) {
    const form = $('#create-form');
    form.innerHTML = '<p class="uk-text-muted"><span class="la-spinner"></span> Loading…</p>';
    let schema;
    let images = [];
    try {
        const [r, im] = await Promise.all([api('ct.schema', { host: hostId, engine }), api('img.list', { host: hostId }).catch(() => ({ images: [] }))]);
        schema = r.schema;
        images = im.images.filter((i) => i.engine === engine).map((i) => (i.flavor === 'incus' || i.flavor === 'lxd') ? (i.name.split(', ')[0] || i.ref) : i.ref);
    } catch (e) { form.innerHTML = `<p class="uk-text-danger">${esc(e.message)}</p>`; return; }

    const field = (f) => {
        const req = f.required ? 'required' : '';
        const ph = esc(f.placeholder || '');
        const dv = f.default ?? '';
        const lbl = `<label class="uk-form-label">${esc(f.label)}${f.required ? ' *' : ''}</label>`;
        switch (f.type) {
            case 'select': return `<div>${lbl}<select class="uk-select" name="${f.name}">${f.options.map((o) => `<option ${o === dv ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select></div>`;
            case 'checkbox': return `<div class="la-span"><label><input class="uk-checkbox" type="checkbox" name="${f.name}" ${dv ? 'checked' : ''}> ${esc(f.label)}</label></div>`;
            case 'profiles': return `<div class="la-span">${lbl}<div class="la-profiles" data-field="${f.name}">
                ${f.options.map((o) => `<label class="la-profile"><input class="uk-checkbox" type="checkbox" value="${esc(o)}"> <span>${esc(o)}</span> <b class="la-order"></b></label>`).join('')}</div>
                <div class="uk-text-meta uk-margin-small-top" data-profile-cmd></div></div>`;
            case 'lines': return `<div class="la-span">${lbl}<textarea class="uk-textarea la-mono" rows="3" name="${f.name}" placeholder="${ph}"></textarea></div>`;
            case 'image': return `<div class="la-span">${lbl}<input class="uk-input la-mono" name="${f.name}" ${req} placeholder="${ph}" list="dl-images" value="${esc(dv)}">
                <datalist id="dl-images">${images.map((i) => `<option value="${esc(i)}">`).join('')}</datalist></div>`;
            default: return `<div>${lbl}<input class="uk-input" type="${f.type === 'number' ? 'number' : f.type === 'password' ? 'password' : 'text'}" name="${f.name}" ${req} placeholder="${ph}" value="${esc(dv)}" ${f.type === 'password' ? 'autocomplete="new-password"' : ''}></div>`;
        }
    };
    form.innerHTML = `<h3>${esc(schema.title)}</h3><div class="la-form-grid">${schema.fields.map(field).join('')}</div>
        <div class="uk-margin-top"><button class="uk-button uk-button-primary" id="create-btn">${icon('plus-circle', 0.8)} Create</button></div>`;
    // Profile pickers: remember the tick order (later profiles override earlier ones in LXD/Incus).
    const profileOrder = {};
    schema.fields.filter((f) => f.type === 'profiles').forEach((f) => {
        const box = form.querySelector(`.la-profiles[data-field="${f.name}"]`);
        const saved = store.get(`profiles.${hostId}.${engine}`, null);
        let order = (saved || f.default || []).filter((p) => f.options.includes(p));
        const paint = () => {
            box.querySelectorAll('.la-profile').forEach((lab) => {
                const cb = lab.querySelector('input');
                const i = order.indexOf(cb.value);
                cb.checked = i >= 0;
                lab.classList.toggle('is-on', i >= 0);
                lab.querySelector('.la-order').textContent = i >= 0 && order.length > 1 ? i + 1 : '';
            });
            const hint = form.querySelector('[data-profile-cmd]');
            if (hint) hint.textContent = order.length ? order.map((p) => `--profile ${p}`).join(' ') : 'Select at least one profile';
        };
        box.addEventListener('change', (e) => {
            const v = e.target.value;
            order = e.target.checked ? [...order.filter((p) => p !== v), v] : order.filter((p) => p !== v);
            paint();
        });
        profileOrder[f.name] = () => order;
        paint();
    });

    form.onsubmit = async (e) => {
        e.preventDefault();
        const params = {};
        schema.fields.forEach((f) => {
            if (f.type === 'profiles') { params[f.name] = profileOrder[f.name](); return; }
            const el = form.elements[f.name];
            params[f.name] = f.type === 'checkbox' ? el.checked : el.value;
        });
        if (params.profiles && Array.isArray(params.profiles)) {
            if (!params.profiles.length) { toast('Select at least one profile', 'warning'); return; }
            store.set(`profiles.${hostId}.${engine}`, params.profiles);
        }
        const out = $('#create-out');
        out.hidden = false;
        out.textContent = 'Creating… (image downloads can take a few minutes)';
        $('#create-btn').disabled = true;
        try {
            const r = await api('ct.create', { host: hostId, engine, params });
            out.textContent = r.output || 'Created.';
            toast('Container created', 'success');
            S.status[hostId].cts = null;
        } catch (err) {
            out.textContent = `Error: ${err.message}`;
        } finally { $('#create-btn').disabled = false; }
    };
}

// ---- System tab
function tabInfo(id) {
    const paint = () => {
        const s = S.status[id] || {};
        const h = hostById(id);
        const i = s.info;
        const caps = s.caps || h.capabilities;
        $('#tab').innerHTML = `<div class="la-grid-2">
            <div class="uk-card uk-card-default la-panel"><h3>System</h3>
                ${i ? `<dl class="la-kv">
                    <dt>Hostname</dt><dd>${esc(i.hostname)}</dd><dt>OS</dt><dd>${esc(i.os)}</dd>
                    <dt>Kernel</dt><dd>${esc(i.kernel)} (${esc(i.arch)})</dd><dt>Uptime</dt><dd>${fmtDur(i.uptime)}</dd>
                    <dt>CPU</dt><dd>${i.cpus} cores · ${i.cpu_pct ?? '–'}% · load ${i.load.join(' / ')}</dd>
                    <dt>Memory</dt><dd>${fmtBytes(i.mem.total - i.mem.avail)} used of ${fmtBytes(i.mem.total)}</dd>
                    <dt>Swap</dt><dd>${i.mem.swap_total ? `${fmtBytes(i.mem.swap_total - i.mem.swap_free)} of ${fmtBytes(i.mem.swap_total)}` : 'none'}</dd>
                </dl>` : `<p class="${s.error ? 'uk-text-danger' : 'uk-text-muted'}">${esc(s.error || 'Loading…')}</p>`}
            </div>
            <div class="uk-card uk-card-default la-panel"><h3>Connection & engines</h3>
                <dl class="la-kv">
                    <dt>SSH</dt><dd>${esc(h.username)}@${esc(h.hostname)}:${h.port} (${h.auth_type})</dd>
                    <dt>Host key</dt><dd class="la-mono uk-text-small">${esc(h.host_key_fp || 'not pinned yet')}</dd>
                    <dt>sudo</dt><dd>${h.use_sudo ? 'yes (sudo -n)' : 'no'}</dd>
                    ${caps ? Object.entries(caps.versions || {}).map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('') : ''}
                    <dt>Detected</dt><dd>${caps?.detected_at ? new Date(caps.detected_at).toLocaleString() : '–'}</dd>
                </dl>
                <button class="uk-button uk-button-default uk-button-small uk-margin-top" data-act="redetect">${icon('search', 0.8)} Re-detect engines</button>
                ${h.notes ? `<h3 class="uk-margin-top">Notes</h3><p style="white-space:pre-wrap">${esc(h.notes)}</p>` : ''}
            </div>
            ${i ? `<div class="uk-card uk-card-default la-panel" style="grid-column:1/-1"><h3>Filesystems</h3>
                <table class="uk-table uk-table-small uk-table-divider uk-margin-remove"><thead><tr><th>Mount</th><th class="la-hide-sm">Device</th><th style="width:35%">Usage</th><th>Used</th><th>Size</th></tr></thead><tbody>
                ${i.disks.map((d) => `<tr><td class="la-mono">${esc(d.mount)}</td><td class="la-hide-sm la-mono uk-text-small">${esc(d.fs)}</td><td>${bar(pct(d.used, d.size))}</td><td class="la-num">${pct(d.used, d.size)}%</td><td class="la-num">${fmtBytes(d.size)}</td></tr>`).join('')}
                </tbody></table></div>` : ''}
        </div>`;
    };
    paint();
    S.refresh = async () => { await loadHostStatus(id, { cts: false }); if (isHostView(id, 'info')) { paint(); renderBanner(id); } };
    S.refresh();
}

// ---------------------------------------------------------------- Host form
const hostForm = $('#host-form');
function setAuthVisibility() {
    const t = hostForm.auth_type.value;
    $$('[data-auth]', hostForm).forEach((el) => { el.hidden = el.dataset.auth !== t; });
}
hostForm.auth_type.addEventListener('change', setAuthVisibility);
$('#key-file').addEventListener('change', async (e) => {
    const f = e.target.files[0];
    if (f) hostForm.secret_key.value = await f.text();
    e.target.value = '';
});
$('#add-host').addEventListener('click', () => openHostForm(null));

function openHostForm(h) {
    hostForm.reset();
    $('#host-form-error').textContent = '';
    $('#host-form-title').textContent = h ? `Edit ${h.name}` : 'Add server';
    $('#host-delete').hidden = !h;
    $('#host-forget-key').hidden = !h || !h.host_key_fp;
    hostForm.id.value = h?.id ?? '';
    if (h) {
        ['name', 'hostname', 'port', 'username', 'auth_type', 'tags', 'notes'].forEach((k) => { hostForm[k].value = h[k] ?? ''; });
        hostForm.use_sudo.checked = h.use_sudo;
    }
    $$('[data-secret-hint]', hostForm).forEach((el) => { el.textContent = h?.has_secret ? 'A secret is stored – leave empty to keep it.' : ''; });
    setAuthVisibility();
    UIkit.modal('#modal-host').show();
    setTimeout(() => hostForm.name.focus(), 150);
}

hostForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = hostForm;
    const isKey = f.auth_type.value === 'key';
    const payload = {
        id: f.id.value, name: f.name.value, hostname: f.hostname.value, port: f.port.value,
        username: f.username.value, auth_type: f.auth_type.value,
        secret: isKey ? f.secret_key.value : f.secret_password.value,
        passphrase: isKey ? f.passphrase.value : '',
        use_sudo: f.use_sudo.checked, tags: f.tags.value, notes: f.notes.value,
    };
    const isNew = !payload.id;
    const btn = $('#host-save');
    btn.disabled = true;
    btn.innerHTML = '<span class="la-spinner"></span> Saving…';
    $('#host-form-error').textContent = '';
    try {
        const { host } = await api('hosts.save', { host: payload });
        try {
            const t = await api('hosts.test', { host: host.id });
            const c = t.capabilities;
            toast(`Connected to ${host.name} in ${t.ms} ms · ${[c.docker && 'Docker', c.lxc && c.lxc.toUpperCase()].filter(Boolean).join(' + ') || 'no engines found'}`, 'success');
            UIkit.modal('#modal-host').hide();
            if (isNew) location.hash = `#/host/${host.id}/containers`;
        } catch (err) {
            // Saved, but test failed - keep the dialog open so the user can fix it.
            $('#host-form-error').textContent = `Saved, but connection test failed: ${err.message}`;
            f.id.value = host.id;
            $('#host-delete').hidden = false;
        }
        S.status[host.id] = {};
        await loadHosts();
        route();
    } catch (err) {
        $('#host-form-error').textContent = err.message;
    } finally {
        btn.disabled = false;
        btn.textContent = 'Save & test';
    }
});

$('#host-delete').addEventListener('click', async () => {
    const id = Number(hostForm.id.value);
    const h = hostById(id);
    try { await UIkit.modal.confirm(`Remove <b>${esc(h?.name)}</b> from LabAdmin? (Nothing on the server is changed.)`); } catch { return; }
    await api('hosts.delete', { id });
    delete S.status[id];
    UIkit.modal('#modal-host').hide();
    await loadHosts();
    location.hash = '#/';
    route();
});
$('#host-forget-key').addEventListener('click', async () => {
    const id = Number(hostForm.id.value);
    try { await UIkit.modal.confirm('Forget the pinned SSH host key? The next connection will pin whatever key the server presents.'); } catch { return; }
    await api('hosts.forgetKey', { id });
    toast('Host key forgotten – it will be re-pinned on next connect');
    $('#host-forget-key').hidden = true;
    await loadHosts();
});

// ---------------------------------------------------------------- Audit
function viewAudit() {
    S.view = 'audit';
    document.title = 'Audit log · LabAdmin';
    delete view.dataset.host;
    view.innerHTML = `<div class="la-page-head"><div><h1>Audit log</h1><div class="la-sub">Every action performed through LabAdmin</div></div></div>
        <div class="la-toolbar uk-margin-small-bottom">
            <input class="uk-input la-grow" type="search" id="au-q" placeholder="Search action, target, command…">
            <select class="uk-select" id="au-host" style="width:auto"><option value="">All servers</option>${S.hosts.map((h) => `<option value="${h.id}">${esc(h.name)}</option>`).join('')}</select>
        </div>
        <div class="la-table-wrap"><table class="uk-table uk-table-divider uk-table-small uk-table-hover">
            <thead><tr><th>Time</th><th>User</th><th>Server</th><th>Action</th><th>Target</th><th class="la-hide-sm">Details</th></tr></thead>
            <tbody id="au-body"></tbody></table></div>
        <p class="uk-text-center"><button class="uk-button uk-button-default" data-act="audit-more" id="au-more" hidden>Load more</button></p>`;
    let offset = 0;
    const PAGE = 100;
    const load = async (append) => {
        if (!append) offset = 0;
        const r = await api('audit.list', { limit: PAGE, offset, q: $('#au-q').value, host: $('#au-host').value || null });
        const rows = r.entries.map((e) => `<tr>
            <td class="la-num uk-text-small">${esc(utcToLocal(e.ts))}</td><td>${esc(e.username || '')}</td><td>${esc(e.host_name || '')}</td>
            <td><span class="la-state"><span class="la-dot ${e.success ? 'ok' : 'bad'}"></span>${esc(e.action)}</span></td>
            <td class="la-mono uk-text-small">${esc(e.target || '')}</td>
            <td class="la-hide-sm la-ports">${esc(e.command || '')}${e.message ? `<div class="${e.success ? '' : 'uk-text-danger'}">${esc(e.message)}</div>` : ''}</td></tr>`).join('');
        const body = $('#au-body');
        if (!body) return;
        body.innerHTML = (append ? body.innerHTML : '') + rows || '<tr><td colspan="6" class="uk-text-muted">No entries.</td></tr>';
        offset += r.entries.length;
        $('#au-more').hidden = r.entries.length < PAGE;
    };
    let deb;
    $('#au-q').addEventListener('input', () => { clearTimeout(deb); deb = setTimeout(() => load(false), 250); });
    $('#au-host').addEventListener('change', () => load(false));
    S.auditMore = () => load(true);
    S.refresh = () => load(false);
    load(false);
}

// ---------------------------------------------------------------- Interactive terminal
// xterm.js is loaded on first use; the session runs through bin/terminal-server.php (WebSocket).
let xtermReady = null;
function loadXterm() {
    if (xtermReady) return xtermReady;
    const load = (src) => new Promise((res, rej) => {
        const el = document.createElement('script');
        el.src = src;
        el.onload = res;
        el.onerror = () => rej(new Error(`Cannot load ${src}`));
        document.head.appendChild(el);
    });
    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = 'assets/vendor/xterm/xterm.css';
    document.head.appendChild(css);
    xtermReady = load('assets/vendor/xterm/xterm.js').then(() => load('assets/vendor/xterm/addon-fit.js'))
        .catch((e) => { xtermReady = null; throw e; });
    return xtermReady;
}

const T = { term: null, fit: null, ws: null, ctx: null, ro: null, ended: false };

function setTermStatus(text, cls = '') {
    const el = $('#term-status');
    el.innerHTML = `<span class="la-dot ${cls}"></span> ${esc(text)}`;
}

function disposeTerm() {
    if (T.ws) { const ws = T.ws; T.ws = null; try { ws.close(); } catch { /* ignore */ } }
    T.ro?.disconnect();
    T.ro = null;
    T.term?.dispose();
    T.term = null;
}

async function openTerminal(hostId, c) {
    try { await loadXterm(); } catch (e) { toast(e.message, 'danger'); return; }
    const h = hostById(hostId);
    T.ctx = { hostId, c };
    $('#term-title').innerHTML = c
        ? `${icon('terminal')} <b>${esc(c.name)}</b> <span class="uk-text-meta">${esc(c.flavor || c.engine)} on ${esc(h.name)}</span>`
        : `${icon('terminal')} <b>${esc(h.username)}@${esc(h.name)}</b> <span class="uk-text-meta">${esc(h.hostname)}</span>`;
    disposeTerm();
    UIkit.modal('#modal-term').show();
    /* global Terminal, FitAddon */
    T.term = new Terminal({
        fontFamily: '"Cascadia Mono", "JetBrains Mono", ui-monospace, Consolas, Menlo, monospace',
        fontSize: store.get('termFont', 14),
        cursorBlink: true,
        scrollback: 5000,
        macOptionIsMeta: true,
        theme: {
            background: '#0b0d0c', foreground: '#d7e2dc', cursor: '#4fb39c', cursorAccent: '#0b0d0c',
            selectionBackground: '#2c4a43',
            black: '#1b1f1d', red: '#ef6b5b', green: '#4cc27a', yellow: '#e0a84a', blue: '#6fb0ff', magenta: '#c78bf0', cyan: '#4fb39c', white: '#d7e2dc',
            brightBlack: '#5e6862', brightRed: '#ff8f80', brightGreen: '#79dba0', brightYellow: '#f3c77a', brightBlue: '#9ccaff', brightMagenta: '#dcb0f7', brightCyan: '#7fd6c2', brightWhite: '#ffffff',
        },
    });
    T.fit = new FitAddon.FitAddon();
    T.term.loadAddon(T.fit);
    T.term.open($('#term-body'));
    T.term.onData((d) => { if (T.ws?.readyState === 1) T.ws.send(JSON.stringify({ t: 'd', d })); });
    T.term.onResize(({ cols, rows }) => { if (T.ws?.readyState === 1) T.ws.send(JSON.stringify({ t: 'r', c: cols, r: rows })); });
    T.ro = new ResizeObserver(() => { try { T.fit?.fit(); } catch { /* not visible yet */ } });
    T.ro.observe($('#term-body'));
    setTimeout(() => { try { T.fit.fit(); } catch { /* ignore */ } connectTerm(); T.term?.focus(); }, 120);
}

async function connectTerm() {
    if (!T.term || !T.ctx) return;
    const { hostId, c } = T.ctx;
    T.ended = false;
    $('#term-reconnect').hidden = true;
    setTermStatus('Connecting…', 'loading');
    let r;
    try {
        r = await api('term.open', c ? { host: hostId, engine: c.engine, id: c.id } : { host: hostId });
    } catch (e) {
        T.term.write(`\r\n\x1b[31m${e.message}\x1b[0m\r\n`);
        setTermStatus('Not connected', 'bad');
        $('#term-reconnect').hidden = false;
        return;
    }
    const base = r.url || `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.hostname}:${r.port}/`;
    const url = `${base}${base.includes('?') ? '&' : '?'}token=${r.token}&cols=${T.term.cols}&rows=${T.term.rows}`;
    const ws = new WebSocket(url);
    ws.binaryType = 'arraybuffer';
    T.ws = ws;
    let opened = false;
    ws.onopen = () => { opened = true; setTermStatus('Connected', 'ok'); T.term?.focus(); };
    ws.onmessage = (ev) => {
        if (typeof ev.data !== 'string') { T.term?.write(new Uint8Array(ev.data)); return; }
        try {
            const m = JSON.parse(ev.data);
            if (m.t === 'x') {
                T.ended = true;
                setTermStatus(m.error ? 'Failed' : 'Session ended', m.error ? 'bad' : '');
                $('#term-reconnect').hidden = false;
            }
        } catch { /* ignore */ }
    };
    ws.onclose = () => {
        if (T.ws !== ws) return;
        if (!opened) {
            T.term?.write(`\r\n\x1b[31mCannot reach the terminal service at ${base}\x1b[0m\r\n`
                + `\x1b[2mIs bin/terminal-server.php running, and is port ${r.port} open in the firewall of the machine running LabAdmin?\x1b[0m\r\n`);
            setTermStatus('Not connected', 'bad');
        } else if (!T.ended) {
            T.term?.write('\r\n\x1b[2m# Disconnected\x1b[0m\r\n');
            setTermStatus('Disconnected', 'bad');
        }
        $('#term-reconnect').hidden = false;
    };
}

$('#term-close').addEventListener('click', () => {
    if (T.ws?.readyState === 1 && !T.ended) {
        UIkit.modal.confirm('Close the terminal? Programs still running in it (vim, top, …) will be terminated.')
            .then(() => { disposeTerm(); UIkit.modal('#modal-term').hide(); }, () => T.term?.focus());
        return;
    }
    disposeTerm();
    UIkit.modal('#modal-term').hide();
});
$('#term-reconnect').addEventListener('click', () => { T.term?.reset(); connectTerm(); });
const termFont = (d) => {
    if (!T.term) return;
    const sz = Math.max(9, Math.min(28, (T.term.options.fontSize || 14) + d));
    T.term.options.fontSize = sz;
    store.set('termFont', sz);
    try { T.fit.fit(); } catch { /* ignore */ }
    T.term.focus();
};
$('#term-font-up').addEventListener('click', () => termFont(1));
$('#term-font-down').addEventListener('click', () => termFont(-1));
UIkit.util.on('#modal-term', 'hidden', disposeTerm);
window.addEventListener('beforeunload', (e) => { if (T.ws?.readyState === 1 && !T.ended) { e.preventDefault(); e.returnValue = ''; } });

// ---------------------------------------------------------------- File editor
const COMMON_FILES = ['/etc/ssh/sshd_config', '/etc/hosts', '/etc/hostname', '/etc/resolv.conf', '/etc/fstab',
    '/etc/network/interfaces', '/etc/netplan/50-cloud-init.yaml', '/etc/crontab', '/etc/environment', '/etc/sudoers.d/labadmin'];
const E = { hostId: null, c: null, path: '', eol: 'lf', version: '', exists: false, orig: '', loaded: false, busy: false };
const area = $('#edit-area');

function editorDirty() { return E.loaded && area.value !== E.orig; }

function openEditor(hostId, c, path = '') {
    const h = hostById(hostId);
    Object.assign(E, { hostId, c, path: '', loaded: false, orig: '', version: '' });
    $('#edit-title').innerHTML = `${icon('file-edit')} Edit file <span class="uk-text-meta">${c ? `in ${esc(c.name)} on ` : 'on '}${esc(h.name)}</span>`;
    area.value = '';
    area.disabled = true;
    $('#edit-save').disabled = true;
    $('#edit-info').textContent = '';
    $('#edit-pos').textContent = '';
    $('#edit-backup').checked = store.get('editBackup', true);
    const recent = store.get('editRecent', []);
    $('#edit-recent').innerHTML = [...new Set([...recent, ...COMMON_FILES])].map((p) => `<option value="${esc(p)}">`).join('');
    $('#edit-path').value = path;
    renderGutter();
    UIkit.modal('#modal-edit').show();
    setTimeout(() => (path ? loadFile(path) : $('#edit-path').focus()), 150);
}

async function loadFile(path) {
    if (editorDirty()) {
        try { await UIkit.modal.confirm('Discard your unsaved changes?'); } catch { return; }
    }
    E.busy = true;
    $('#edit-info').innerHTML = '<span class="la-spinner"></span> Loading…';
    try {
        const { hostId, c } = E;
        const r = await api('file.read', { host: hostId, path, ...(c ? { engine: c.engine, id: c.id } : {}) });
        Object.assign(E, { path, eol: r.eol, version: r.version, exists: r.exists, orig: r.content, loaded: true });
        area.value = r.content;
        area.disabled = false;
        area.readOnly = r.exists && !r.writable;
        area.scrollTop = 0;
        area.setSelectionRange(0, 0);
        const recent = store.get('editRecent', []);
        store.set('editRecent', [path, ...recent.filter((p) => p !== path)].slice(0, 15));
        renderGutter();
        updateEditInfo();
        area.focus();
    } catch (e) {
        $('#edit-info').innerHTML = `<span class="uk-text-danger">${esc(e.message)}</span>`;
    } finally { E.busy = false; }
}

function updateEditInfo(extra = '') {
    if (!E.loaded) return;
    const bits = [];
    if (!E.exists) bits.push('new file – it will be created on save');
    else {
        if (area.readOnly) bits.push('<b class="uk-text-danger">read-only</b> (enable sudo for this server, or connect as root)');
        if (E.eol === 'crlf') bits.push('Windows line endings (kept)');
    }
    if (editorDirty()) bits.unshift('<b>modified</b>');
    $('#edit-info').innerHTML = (extra ? extra + ' · ' : '') + bits.join(' · ');
    $('#edit-save').disabled = !editorDirty() || area.readOnly;
    $('#edit-title').querySelector('.la-dirty')?.remove();
    if (editorDirty()) $('#edit-title').insertAdjacentHTML('beforeend', '<span class="la-dirty" title="Unsaved changes">●</span>');
}

function renderGutter() {
    const n = area.value.split('\n').length;
    const g = $('#edit-gutter');
    if (Number(g.dataset.n) !== n) {
        g.dataset.n = n;
        g.textContent = Array.from({ length: n }, (_, i) => i + 1).join('\n');
    }
    g.scrollTop = area.scrollTop;
}

function updatePos() {
    const before = area.value.slice(0, area.selectionStart);
    const line = before.split('\n').length;
    const col = before.length - before.lastIndexOf('\n');
    $('#edit-pos').textContent = E.loaded ? `Ln ${line}, Col ${col}` : '';
}

async function saveFile(force = false) {
    if (!E.loaded || E.busy || area.readOnly) return;
    E.busy = true;
    $('#edit-save').disabled = true;
    const content = area.value;
    store.set('editBackup', $('#edit-backup').checked);
    try {
        const { hostId, c } = E;
        const r = await api('file.write', {
            host: hostId, path: E.path, content, eol: E.eol, version: E.exists ? E.version : '',
            backup: $('#edit-backup').checked, force, ...(c ? { engine: c.engine, id: c.id } : {}),
        });
        Object.assign(E, { version: r.version, orig: content, exists: true });
        updateEditInfo(`<span class="uk-text-success">Saved ${new Date().toLocaleTimeString()}</span>${r.backup ? ` · backup: <code>${esc(r.backup)}</code>` : ''}`);
        toast(`Saved ${E.path}`, 'success');
    } catch (e) {
        E.busy = false;
        if (e.data?.changed) {
            try {
                await UIkit.modal.confirm('<b>This file was changed on the server after you opened it.</b><br>Overwrite those changes with your version?', { labels: { ok: 'Overwrite', cancel: 'Cancel' } });
                return saveFile(true);
            } catch { updateEditInfo(); return; }
        }
        $('#edit-info').innerHTML = `<span class="uk-text-danger">${esc(e.message)}</span>`;
        $('#edit-save').disabled = false;
    } finally { E.busy = false; }
}

$('#edit-open').addEventListener('submit', (e) => {
    e.preventDefault();
    const p = $('#edit-path').value.trim();
    if (p) loadFile(p);
});
$('#edit-save').addEventListener('click', () => saveFile(false));
$('#edit-close').addEventListener('click', async () => {
    if (editorDirty()) {
        try { await UIkit.modal.confirm('Close without saving your changes?', { labels: { ok: 'Discard changes', cancel: 'Keep editing' } }); } catch { return; }
    }
    E.loaded = false;
    UIkit.modal('#modal-edit').hide();
});
area.addEventListener('input', () => { renderGutter(); updateEditInfo(); updatePos(); });
area.addEventListener('scroll', () => { $('#edit-gutter').scrollTop = area.scrollTop; });
['keyup', 'click'].forEach((ev) => area.addEventListener(ev, updatePos));
area.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); saveFile(false); return; }
    if (e.key === 'Tab' && !e.ctrlKey && !e.altKey && !area.readOnly) {
        e.preventDefault();
        // YAML forbids tabs – use two spaces there.
        const ins = /\.ya?ml$/i.test(E.path) ? '  ' : '\t';
        document.execCommand('insertText', false, ins) || area.setRangeText(ins, area.selectionStart, area.selectionEnd, 'end');
        renderGutter(); updateEditInfo();
    }
});
document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's' && $('#modal-edit').classList.contains('uk-open')) { e.preventDefault(); saveFile(false); }
});
window.addEventListener('beforeunload', (e) => { if (editorDirty()) { e.preventDefault(); e.returnValue = ''; } });

// ---------------------------------------------------------------- go
boot().catch((e) => {
    document.body.innerHTML = `<div class="la-auth"><div class="uk-card uk-card-default uk-card-body"><h3>LabAdmin could not start</h3><p class="uk-text-danger">${esc(e.message)}</p></div></div>`;
});
