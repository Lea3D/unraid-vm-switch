/*
 * VM Switch – Oberfläche.
 * Alle Inhalte aus dem Server werden ausschließlich per textContent eingefügt (kein innerHTML),
 * damit VM- oder Containernamen keinen Code einschleusen können.
 */
(() => {
  'use strict';
  if (window.__vmswitchLoaded) return;
  window.__vmswitchLoaded = true;

  const API = '/plugins/vmswitch/include/api.php';

  // ---------- Hilfsfunktionen ----------
  const el = (tag, props = {}, ...kids) => {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(props)) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') n.className = v;
      else if (k === 'text') n.textContent = v;
      else if (k.startsWith('on') && typeof v === 'function') n.addEventListener(k.slice(2), v);
      else n.setAttribute(k, v === true ? '' : String(v));
    }
    for (const c of kids.flat()) {
      if (c === null || c === undefined || c === false) continue;
      n.append(c.nodeType ? c : document.createTextNode(String(c)));
    }
    return n;
  };

  const errNote = (msg) => el('div', { class: 'vms-note vms-error', role: 'alert' },
    el('strong', { text: 'Fehler: ' }), msg, el('br'), el('span', { class: 'vms-muted', text: 'Details: Browser-Konsole (F12) und Log-Seite des Plugins.' }));

  async function api(action, data = {}) {
    // Wie $.post in Unraid: application/x-www-form-urlencoded. Token wie in der Doku per PHP in die Seite
    // geschrieben (data-csrf), Rückfall: globale Variable.
    const holder = document.querySelector('.vms[data-csrf]');
    const body = new URLSearchParams();
    const token = (holder && holder.dataset.csrf) || window.csrf_token || '';
    body.append('csrf_token', token);
    body.append('vms_token', token);
    body.append('action', action);
    for (const [k, v] of Object.entries(data)) body.append(k, v);
    const info = { action, start: Date.now(), state: 'läuft', ms: 0, note: '' };
    window.__vmswitchLast = info; // für die Selbstdiagnose der Seite
    let res;
    const ctl = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = ctl ? setTimeout(() => ctl.abort(), 30000) : null;
    try {
      res = await fetch(API, { method: 'POST', body, credentials: 'same-origin', signal: ctl ? ctl.signal : undefined });
    } catch (e) {
      info.state = 'Fehler'; info.ms = Date.now() - info.start; info.note = String(e && e.name);
      throw new Error(e && e.name === 'AbortError' ? 'Der Server antwortet nicht (Zeitüberschreitung nach 30 s).' : 'Keine Verbindung zum Server.');
    } finally {
      if (timer) clearTimeout(timer);
    }
    info.ms = Date.now() - info.start;
    let j;
    try {
      j = await res.json();
    } catch (e) {
      info.state = 'Fehler'; info.note = 'HTTP ' + res.status + ', keine gültige JSON-Antwort';
      throw new Error('Unerwartete Antwort vom Server (HTTP ' + res.status + ').');
    }
    info.state = j.ok ? 'ok' : 'Fehler'; info.note = 'HTTP ' + res.status;
    if (!j.ok) throw new Error((j.error || 'Unbekannter Fehler.') + (j.debug ? ' [' + JSON.stringify(j.debug) + ']' : ''));
    return j;
  }

  function toast(root, msg, isError = false) {
    let t = root.querySelector('.vms-toast');
    if (t) t.remove();
    t = el('div', { class: 'vms-toast' + (isError ? ' vms-error' : ''), role: isError ? 'alert' : 'status', text: msg });
    root.append(t);
    clearTimeout(root.__toastTimer);
    root.__toastTimer = setTimeout(() => t.remove(), isError ? 12000 : Math.max(4500, msg.length * 60));
  }

  // Logo-URL nur akzeptieren, wenn sie ein Pfad auf demselben Server ist (kein Protokoll, keine Sonderzeichen)
  const safeIcon = (u) => typeof u === 'string' && /^\/(?!\/)[^\s"'<>\\]*$/.test(u);
  const logo = (u) => (safeIcon(u)
    ? el('img', { class: 'vms-logo', src: u, alt: '', width: 36, height: 36, loading: 'lazy', onerror: (e) => e.target.remove() })
    : null);
  // Firmenzusätze weglassen, damit Gerätenamen kürzer sind (voller Name steht im Tooltip)
  const shortName = (n) => String(n || '').replace(/\b(Corporation|Corp|Inc|Electronics|Technologies|Ltd|GmbH)\b\.?,?/gi, '').replace(/\s+/g, ' ').trim();
  const visible = (root) => root.isConnected && root.getClientRects().length > 0;
  const stateLabel = {
    running: 'läuft', 'shut off': 'aus', paused: 'pausiert', 'in shutdown': 'fährt herunter',
    crashed: 'abgestürzt', pmsuspended: 'im Standby', blocked: 'blockiert', unknown: 'unbekannt',
  };
  const pill = (s) => el('span', { class: 'vms-pill', 'data-s': s, text: stateLabel[s] || s });
  const clock = (t) => (t ? new Date(t * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) : '');

  // ---------- Seite: VM-Wechsel ----------
  const PHASES = [
    ['shutdown', 'Herunterfahren'], ['containers', 'Container'], ['wait', 'Wartezeit'],
    ['start', 'Starten'], ['health', 'Prüfen'],
  ];
  const RESULT = {
    ok: ['ok', (j) => 'Wechsel zu ' + j.target + ' abgeschlossen'],
    fallback_ok: ['warn', () => 'Ziel-VM nicht gestartet, vorherige VM läuft wieder'],
    cancelled: ['warn', () => 'Wechsel abgebrochen'],
    failed: ['bad', () => 'Wechsel fehlgeschlagen'],
    fallback_failed: ['bad', () => 'Wechsel und Fallback fehlgeschlagen'],
  };

  function initSwitch(root) {
    const view = el('div', {}, el('p', { class: 'vms-muted', text: 'Lade VMs …' }));
    root.replaceChildren(view);
    let ov = null, job = null, waitSel = 'default', dismissed = null, lastSig = '', wasRunning = false, error = '';

    function stepList(j) {
      const finished = j.status !== 'running';
      const seen = new Set(j.steps.map((s) => s.phase));
      const bad = new Set(j.steps.filter((s) => s.lvl === 'ERROR').map((s) => s.phase));
      const list = PHASES.map(([p, label]) => {
        let s = 'pending', d = '';
        if (bad.has(p)) { s = 'bad'; d = 'fehlgeschlagen'; }
        else if (!finished && j.phase === p) { s = 'active'; d = p === 'wait' && j.wait_left > 0 ? j.wait_left + ' s' : 'läuft'; }
        else if (seen.has(p)) { s = 'done'; d = 'erledigt'; }
        else if (finished) { d = 'übersprungen'; }
        return [label, s, d];
      });
      if (j.fallback) {
        const fb = j.status === 'fallback_failed' ? 'bad' : (finished ? 'fallback' : 'active');
        list.push(['Fallback', fb, !finished && j.wait_left > 0 ? j.wait_left + ' s' : (fb === 'bad' ? 'fehlgeschlagen' : 'vorherige VM')]);
      }
      return list;
    }

    function render() {
      const sig = JSON.stringify([ov, job, waitSel, dismissed, error]);
      if (sig === lastSig) return;
      lastSig = sig;
      const focused = document.activeElement && root.contains(document.activeElement) ? document.activeElement.dataset.key : null;
      const nodes = [];

      if (error) nodes.push(el('div', { class: 'vms-note vms-error', role: 'alert', text: error }));
      if (ov && !ov.libvirt) {
        nodes.push(el('div', { class: 'vms-note vms-error', role: 'alert' },
          el('strong', { text: 'Der VM-Dienst ist nicht erreichbar. ' }),
          'Starte den VM-Manager unter Einstellungen > VM-Manager und lade die Seite neu.'));
      }
      if (ov && ov.libvirt) {
        const running = ov.vms.filter((v) => v.state === 'running').map((v) => v.name);
        const busy = !!(job && job.running);
        const def = ov.settings.wait_default;

        nodes.push(el('div', { class: 'vms-head' },
          el('div', { class: 'vms-now' },
            el('span', { class: 'vms-muted', text: running.length > 1 ? 'Aktive VMs' : 'Aktive VM' }),
            el('div', { class: 'vms-now-row' },
              ov.vms.filter((v) => v.state === 'running').map((v) => logo(v.icon)),
              el('h2', { text: running.length ? running.join(', ') : 'Keine VM läuft' }))),
          el('div', { class: 'vms-seg', role: 'group', 'aria-label': 'Wartezeit vor dem Start' },
            el('span', { class: 'vms-seg-label', text: 'Wartezeit vor dem Start' }),
            ...[['default', 'Standard (' + def + ' s)'], ['0', '0 s'], ['10', '10 s'], ['30', '30 s'], ['60', '60 s']].map(([v, l]) =>
              el('button', {
                type: 'button', 'data-key': 'w' + v, 'aria-pressed': String(waitSel === v), disabled: busy, title: busy ? 'Während eines Wechsels lässt sich die Wartezeit nicht ändern.' : null, text: l,
                onclick: () => { waitSel = v; render(); },
              })))));

        if (busy && job.state) {
          const j = job.state;
          nodes.push(el('div', { class: 'vms-job', role: 'group', 'aria-label': 'Wechsel läuft' },
            el('div', { class: 'vms-job-top' },
              el('h3', { text: 'Wechsel zu ' + j.target }),
              el('button', { type: 'button', class: 'vms-btn vms-danger vms-small', 'data-key': 'cancel', text: 'Abbrechen', onclick: cancelJob })),
            el('ol', { class: 'vms-steps' }, stepList(j).map(([label, s, d]) =>
              el('li', { class: 'vms-step', 'data-s': s },
                el('strong', { text: label }),
                s === 'active' && /^\d+ s$/.test(d) ? el('span', { class: 'vms-count', text: d }) : el('span', { text: d })))),
            el('p', { class: 'vms-job-msg', role: 'status', text: j.message })));
        } else if (job && job.state && job.state.status !== 'running' && dismissed !== job.state.id && RESULT[job.state.status]) {
          const j = job.state;
          const [sev, title] = RESULT[j.status];
          nodes.push(el('div', { class: 'vms-result', 'data-s': sev, role: 'status' },
            el('div', {}, el('strong', { text: title(j) }), el('p', { class: 'vms-muted', text: j.message + ' (' + clock(j.finished) + ' Uhr)' })),
            el('button', { type: 'button', class: 'vms-btn vms-small', 'data-key': 'dismiss', text: 'Ausblenden', onclick: () => { dismissed = j.id; render(); } })));
        }

        nodes.push(el('div', { class: 'vms-grid' }, ov.vms.map((v) => card(v, busy, running))));
        if (!ov.vms.length) nodes.push(el('p', { class: 'vms-muted', text: 'Es sind keine VMs angelegt. Lege zuerst unter VMs eine VM an.' }));
      }

      view.replaceChildren(...nodes);
      if (focused) {
        const f = [...view.querySelectorAll('[data-key]')].find((n) => n.dataset.key === focused);
        if (f && !f.disabled) f.focus();
      }
    }

    function card(v, busy, running) {
      const active = v.state !== 'shut off' && v.state !== 'unknown';
      const devs = v.devices.length
        ? el('ul', { class: 'vms-dev' }, v.devices.map((d) => el('li', {},
          el('span', { class: 'vms-kind', text: d.kind }),
          el('span', { class: 'vms-devname', title: d.name || null },
            d.name ? el('span', { class: 'vms-devtitle', text: shortName(d.name) }) : null,
            el('code', { class: 'vms-addr', text: d.addr })),
          d.present ? null : el('span', { class: 'vms-bad', text: 'Gerät im System nicht gefunden' }))))
        : el('span', { class: 'vms-none', text: v.error ? 'Konfiguration nicht lesbar' : 'Keine Geräte durchgereicht' });
      const keep = !!((ov.settings.vms || {})[v.name] || {}).keep_running;
      const shared = (v.shared || []).map((sh) => el('p', { class: 'vms-warn', text: 'Teilt die Festplatte ' + sh.disks.join(', ') + ' mit ' + sh.vm + '. Beide VMs laufen deshalb nie gleichzeitig.' }));
      return el('div', { class: 'vms-card', role: 'group', 'aria-label': v.name, 'data-active': String(v.state === 'running') },
        el('div', { class: 'vms-card-top' }, el('div', { class: 'vms-title' }, logo(v.icon), el('h3', { text: v.name })), pill(v.state)),
        el('div', { class: 'vms-card-body' }, devs, shared),
        el('div', { class: 'vms-card-foot' },
          el('label', { class: 'vms-check vms-keep' },
            el('input', { type: 'checkbox', checked: keep, 'data-key': 'keep-' + v.name, onchange: (e) => setKeep(v.name, e.target.checked) }),
            el('span', { text: 'Beim Wechseln nie stoppen' })),
          el('button', {
            type: 'button', class: 'vms-btn' + (active ? '' : ' vms-primary'), 'data-key': 'sw-' + v.name,
            title: busy ? 'Ein Wechsel läuft gerade. Warte, bis er fertig ist.' : (v.state === 'running' && running.length === 1 ? 'Diese VM läuft bereits und ist die einzige aktive VM.' : null),
            disabled: busy || (v.state === 'running' && running.length === 1),
            text: v.state === 'running' && running.length === 1 ? 'Aktiv' : 'Wechseln',
            onclick: () => confirmSwitch(v.name),
          })));
    }

    async function setKeep(name, checked) {
      try {
        const r = await api('save_settings', { settings: JSON.stringify({ vms: { [name]: { keep_running: checked } } }) });
        ov.settings = r.settings;
        toast(root, name + (checked ? ' wird bei Wechseln nie gestoppt.' : ' kann bei Wechseln gestoppt werden.'));
      } catch (e) { toast(root, e.message, true); }
      lastSig = '';
      render();
    }

    async function confirmSwitch(target) {
      let plan;
      try { plan = (await api('plan', { target })).plan; } catch (e) { toast(root, e.message, true); return; }
      const w = waitSel === 'default' ? ov.settings.wait_default : Number(waitSel);
      const dlg = el('dialog', { class: 'vms-dialog', 'aria-labelledby': 'vms-dlg-t' });
      const close = () => { dlg.close(); dlg.remove(); };
      if (plan.blocked.length) {
        const b = plan.blocked[0];
        dlg.append(
          el('h3', { id: 'vms-dlg-t', text: 'Wechsel zu ' + target + ' nicht möglich' }),
          el('p', { text: b.text }),
          el('div', { class: 'vms-bar' }, el('button', { type: 'button', class: 'vms-btn', text: 'Schließen', onclick: close })));
      } else {
        dlg.append(
          el('h3', { id: 'vms-dlg-t', text: 'Zu ' + target + ' wechseln?' }),
          el('p', { text: plan.stop.length
            ? 'Diese VMs werden heruntergefahren: ' + plan.stop.map((n) => (plan.reasons && plan.reasons[n] ? n + ' (' + plan.reasons[n] + ')' : n)).join(', ') + '.'
            : 'Keine laufende VM muss heruntergefahren werden.' }),
          plan.stay.length ? el('p', { text: 'Diese VMs laufen weiter: ' + plan.stay.join(', ') + '. Sie teilen weder ein Gerät noch eine Festplatte mit ' + target + '.' }) : null,
          el('p', { class: 'vms-muted', text: plan.stop.length
            ? 'Wartezeit vor dem Start: ' + w + ' s. ' + (ov.settings.fallback ? 'Startet ' + target + ' nicht, wird ' + plan.stop.join(', ') + ' automatisch wieder gestartet.' : 'Der Fallback ist ausgeschaltet.')
            : 'Die Wartezeit entfällt.' }),
          el('div', { class: 'vms-bar' },
            el('button', { type: 'button', class: 'vms-btn', text: 'Abbrechen', onclick: close }),
            el('button', { type: 'button', class: 'vms-btn vms-primary', text: 'Wechseln', onclick: async () => {
              close();
              try {
                await api('switch', { target, wait: waitSel === 'default' ? '' : waitSel });
                dismissed = null;
                await refresh(true);
              } catch (e) { toast(root, e.message, true); }
            } })));
      }
      dlg.addEventListener('close', () => dlg.remove());
      root.append(dlg);
      if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
    }

    async function cancelJob() {
      try { await api('cancel'); toast(root, 'Abbruch angefordert. Er greift vor dem Start der Ziel-VM.'); } catch (e) { toast(root, e.message, true); }
    }

    async function refresh(full) {
      try {
        error = '';
        if (full || !ov) {
          ov = await api('overview', { devices: '1' });
          job = ov.job;
        } else {
          const s = await api('status');
          const names = s.vms.map((v) => v.name).join('\n');
          if (names !== ov.vms.map((v) => v.name).join('\n') || s.libvirt !== ov.libvirt) {
            ov = await api('overview', { devices: '1' });
            job = ov.job;
          } else {
            ov.vms.forEach((v, i) => { v.state = s.vms[i].state; });
            job = s.job;
          }
        }
        const nowRunning = !!(job && job.running);
        if (wasRunning && !nowRunning) { ov = await api('overview', { devices: '1' }); job = ov.job; }
        wasRunning = nowRunning;
      } catch (e) { error = e.message; }
      try {
        render();
      } catch (e) {
        console.error('VM Switch: Darstellungsfehler', e);
        lastSig = '';
        view.replaceChildren(errNote('Darstellungsfehler: ' + (e && e.message ? e.message : e)));
      }
    }

    return { tick: () => refresh(false), interval: () => (job && job.running ? 1000 : 5000) };
  }

  // ---------- Seite: Docker ----------
  function initDocker(root) {
    const view = el('div');
    root.replaceChildren(view);
    let ov = null, sel = {}, dirty = false, namesSig = '';
    const refs = {};

    function build() {
      refs.rows = {};
      const nodes = [];
      if (!ov.containers) {
        nodes.push(el('div', { class: 'vms-note vms-error', role: 'alert', text: 'Docker ist nicht erreichbar. Starte den Docker-Dienst unter Einstellungen > Docker und lade die Seite neu.' }));
        view.replaceChildren(...nodes);
        return;
      }
      const vms = ov.vms.map((v) => v.name);
      sel = {};
      vms.forEach((n) => { sel[n] = new Set((ov.settings.vms[n] || {}).stop_containers || []); });

      nodes.push(el('div', { class: 'vms-section' },
        el('h2', { text: 'Container und VM-Profile' }),
        el('p', { class: 'vms-muted', text: 'Ein Häkchen bedeutet: Der Container wird gestoppt, wenn auf diese VM gewechselt wird, und wieder gestartet, sobald eine andere VM aktiv wird. Das ist sinnvoll für Container, die dieselbe GPU nutzen.' })));
      if (!ov.containers.length) {
        nodes.push(el('p', { class: 'vms-muted', text: 'Docker meldet keine Container.' }));
      } else {
        const head = el('tr', {}, el('th', { text: 'Container' }), el('th', { text: 'Status' }), el('th', { text: 'Aktion' }),
          vms.map((n) => el('th', { class: 'vms-c', title: 'Stoppen, wenn ' + n + ' läuft', text: 'Aus bei ' + n })));
        const rows = ov.containers.map((c) => {
          const status = el('span');
          const bStart = el('button', { type: 'button', class: 'vms-btn vms-small', text: 'Start', onclick: () => op(c.name, 'start') });
          const bStop = el('button', { type: 'button', class: 'vms-btn vms-small', text: 'Stopp', onclick: () => op(c.name, 'stop') });
          const bRe = el('button', { type: 'button', class: 'vms-btn vms-small', text: 'Neustart', onclick: () => op(c.name, 'restart') });
          refs.rows[c.name] = { status, bStart, bStop, bRe };
          return el('tr', {},
            el('td', { text: c.name }), el('td', {}, status), el('td', {}, el('div', { class: 'vms-actions' }, bStart, bStop, bRe)),
            vms.map((n) => el('td', { class: 'vms-c' }, el('input', {
              type: 'checkbox', 'aria-label': c.name + ' stoppen, wenn ' + n + ' läuft', checked: sel[n].has(c.name),
              onchange: (e) => { if (e.target.checked) sel[n].add(c.name); else sel[n].delete(c.name); dirty = true; },
            }))));
        });
        nodes.push(el('div', { class: 'vms-tablewrap' }, el('table', { class: 'vms-table' }, el('thead', {}, head), el('tbody', {}, rows))));
        nodes.push(el('div', { class: 'vms-bar', style: 'margin-top:16px' },
          el('button', { type: 'button', class: 'vms-btn vms-primary', text: 'Profile speichern', onclick: save })));
      }
      view.replaceChildren(...nodes);
      update();
    }

    function update() {
      for (const c of ov.containers || []) {
        const r = refs.rows[c.name];
        if (!r) continue;
        r.status.replaceChildren(pill(c.running ? 'running' : 'shut off'));
        r.status.firstChild.textContent = c.running ? 'läuft' : 'gestoppt';
        r.bStart.disabled = c.running;
        r.bStop.disabled = !c.running;
        r.bRe.disabled = !c.running;
      }
    }

    async function op(name, action) {
      try { await api('container_op', { name, op: action }); toast(root, name + ': ' + { start: 'gestartet', stop: 'gestoppt', restart: 'neu gestartet' }[action] + '.'); await refresh(false); }
      catch (e) { toast(root, e.message, true); }
    }

    async function save() {
      const vms = {};
      for (const [n, set] of Object.entries(sel)) vms[n] = { stop_containers: [...set] };
      try { const r = await api('save_settings', { settings: JSON.stringify({ vms }) }); ov.settings = r.settings; dirty = false; toast(root, 'Profile gespeichert.' + (r.notes && r.notes.length ? ' Hinweis: ' + r.notes.join(' ') : '')); }
      catch (e) { toast(root, e.message, true); }
    }

    async function refresh(full) {
      try {
        const next = await api('overview');
        const sig = JSON.stringify([(next.containers || []).map((c) => c.name), next.vms.map((v) => v.name), next.containers === null]);
        const first = !ov;
        ov = next;
        if (first || (sig !== namesSig && !dirty)) { namesSig = sig; build(); } else { update(); }
      } catch (e) { view.replaceChildren(el('div', { class: 'vms-note vms-error', role: 'alert', text: e.message })); ov = null; }
    }

    return { tick: () => refresh(false), interval: () => 5000 };
  }

  // ---------- Seite: Einstellungen ----------
  function initSettings(root) {
    const view = el('div');
    root.replaceChildren(view);
    let loaded = false;

    function numField(id, label, help, val, min, max) {
      const inp = el('input', { id, class: 'vms-in', type: 'number', min, max, value: val, inputmode: 'numeric' });
      return [el('div', { class: 'vms-field' }, el('label', { for: id, text: label }),
        el('div', { class: 'vms-inline' }, inp, el('span', { class: 'vms-muted', text: 'Sekunden' })),
        el('p', { class: 'vms-help', text: help })), inp];
    }
    function check(id, label, help, val) {
      const inp = el('input', { id, type: 'checkbox', checked: val });
      return [el('label', { class: 'vms-check', for: id }, inp, el('span', {}, el('strong', { text: label }), el('br'), el('span', { class: 'vms-muted', text: help }))), inp];
    }

    async function build() {
      const ov = await api('overview');
      const s = ov.settings;
      const [fWait, iWait] = numField('vms-s-wait', 'Wartezeit vor dem Start', 'Pause zwischen dem Herunterfahren der alten VM und dem Start der neuen. Gib durchgereichter Hardware (GPU, USB-Controller) Zeit, sich zurückzusetzen.', s.wait_default, 0, 300);
      const quick = el('div', { class: 'vms-seg' }, [0, 10, 30, 60].map((n) => el('button', { type: 'button', text: n + ' s', onclick: () => { iWait.value = n; } })));
      const [fShut, iShut] = numField('vms-s-shut', 'Zeit zum Herunterfahren', 'So lange wartet das Plugin auf den ACPI-Shutdown, bevor es eingreift.', s.shutdown_timeout, 10, 600);
      const [fStart, iStart] = numField('vms-s-start', 'Zeit für den Start', 'So lange darf der Startbefehl der Ziel-VM dauern. Bei viel RAM oder Hugepages kann das länger sein.', s.start_timeout, 10, 300);
      const [fHealth, iHealth] = numField('vms-s-health', 'Stabilitätsprüfung', 'So lange muss die Ziel-VM nach dem Start ohne Absturz laufen, bevor der Wechsel als gelungen gilt.', s.health_secs, 0, 120);
      const [fAgent, iAgent] = numField('vms-s-agent', 'Auf Guest-Agent warten', 'Nur für VMs mit QEMU-Guest-Agent. 0 schaltet die Prüfung aus. Ohne Antwort in dieser Zeit gilt der Start als fehlgeschlagen.', s.agent_wait, 0, 300);
      const modeSel = el('select', { id: 'vms-s-mode', class: 'vms-in', style: 'max-width:100%' },
        el('option', { value: 'conflict', text: 'Nur VMs stoppen, die Geräte mit der Ziel-VM teilen (empfohlen)', selected: s.stop_mode !== 'all' }),
        el('option', { value: 'all', text: 'Alle laufenden VMs stoppen', selected: s.stop_mode === 'all' }));
      const fMode = el('div', { class: 'vms-field' }, el('label', { for: 'vms-s-mode', text: 'Welche VMs werden gestoppt?' }), modeSel,
        el('p', { class: 'vms-help', text: 'Im empfohlenen Modus bleibt eine VM, die nur ein USB-Gerät bekommt (zum Beispiel Home Assistant), unberührt, solange sie kein Gerät mit der Ziel-VM teilt. VMs, die in der Wechsel-Ansicht als „nie stoppen" markiert sind, laufen in beiden Modi weiter.' }));
      const [cFall, iFall] = check('vms-s-fb', 'Fallback aktivieren', 'Startet die Ziel-VM nicht oder stürzt sie ab, wird die vorherige VM wieder gestartet.', s.fallback);
      const [cForce, iForce] = check('vms-s-force', 'VM nach Zeitüberschreitung hart beenden', 'Reagiert eine VM nicht auf das Herunterfahren, wird sie mit destroy beendet. Das entspricht dem Ziehen des Netzsteckers.', s.force_destroy);
      const [cNot, iNot] = check('vms-s-not', 'Benachrichtigung senden', 'Meldet Ergebnis und Fallback über die Unraid-Benachrichtigungen.', s.notify);

      const perVm = {};
      const perKeep = {};
      const vmRows = ov.vms.map((v) => {
        const cur = (s.vms[v.name] || {}).wait;
        const inp = el('input', { class: 'vms-in', type: 'number', min: 0, max: 300, value: cur === null || cur === undefined ? '' : cur, placeholder: 'Standard', 'aria-label': 'Wartezeit für ' + v.name });
        perVm[v.name] = inp;
        const keepBox = el('input', { type: 'checkbox', checked: !!((s.vms || {})[v.name] || {}).keep_running, 'aria-label': v.name + ' bei Wechseln nie stoppen' });
        perKeep[v.name] = keepBox;
        return el('tr', {}, el('td', { text: v.name }), el('td', {}, el('div', { class: 'vms-inline' }, inp, el('span', { class: 'vms-muted', text: 's' }))), el('td', { class: 'vms-c' }, keepBox));
      });

      const save = async () => {
        const vms = {};
        for (const [n, inp] of Object.entries(perVm)) vms[n] = { wait: inp.value === '' ? null : Number(inp.value), keep_running: perKeep[n].checked };
        const body = {
          stop_mode: modeSel.value, wait_default: Number(iWait.value), shutdown_timeout: Number(iShut.value), start_timeout: Number(iStart.value),
          health_secs: Number(iHealth.value), agent_wait: Number(iAgent.value),
          fallback: iFall.checked, force_destroy: iForce.checked, notify: iNot.checked, vms,
        };
        try {
          const r = await api('save_settings', { settings: JSON.stringify(body) });
          const msg = 'Einstellungen gespeichert.' + (r.notes && r.notes.length ? ' Hinweis: ' + r.notes.join(' ') : '');
          await build();
          toast(root, msg);
        }
        catch (e) { toast(root, e.message, true); }
      };

      view.replaceChildren(el('div', { class: 'vms-form' },
        el('div', { class: 'vms-section' }, el('h2', { text: 'Ablauf eines Wechsels' }), fMode, fWait, el('div', { class: 'vms-field' }, el('span', { class: 'vms-lab', text: 'Schnellwahl' }), quick),
          fShut, fStart, fHealth, fAgent),
        el('div', { class: 'vms-section' }, el('h2', { text: 'Verhalten bei Problemen' }), cFall, cForce, cNot),
        ov.vms.length ? el('div', { class: 'vms-section' }, el('h2', { text: 'Wartezeit pro VM' }),
          el('p', { class: 'vms-muted', text: 'Leer lassen, um die Standard-Wartezeit zu verwenden. Eine Auswahl im Kopf der Wechsel-Seite gilt nur für diesen einen Wechsel.' }),
          el('div', { class: 'vms-tablewrap' }, el('table', { class: 'vms-table' }, el('thead', {}, el('tr', {}, el('th', { text: 'VM' }), el('th', { text: 'Wartezeit vor dem Start dieser VM' }), el('th', { class: 'vms-c', text: 'Nie stoppen' }))), el('tbody', {}, vmRows)))) : null,
        el('div', { class: 'vms-bar' }, el('button', { type: 'button', class: 'vms-btn vms-primary', text: 'Einstellungen speichern', onclick: save }))));
      loaded = true;
    }

    return {
      tick: async () => { if (loaded) return; try { await build(); } catch (e) { view.replaceChildren(el('div', { class: 'vms-note vms-error', role: 'alert', text: e.message })); } },
      interval: () => 4000,
    };
  }

  // ---------- Seite: Log ----------
  function initLog(root) {
    const pre = el('pre', { class: 'vms-log', tabindex: '0', 'aria-label': 'Log des VM-Wechsels' });
    const auto = el('input', { type: 'checkbox', id: 'vms-log-auto', checked: true });
    const view = el('div', {},
      el('div', { class: 'vms-bar', style: 'margin-bottom:14px' },
        el('button', { type: 'button', class: 'vms-btn', text: 'Aktualisieren', onclick: () => load() }),
        el('button', { type: 'button', class: 'vms-btn vms-danger', text: 'Log löschen', onclick: async () => {
          if (!window.confirm('Log wirklich löschen?')) return;
          try { await api('clear_log'); await load(); } catch (e) { toast(root, e.message, true); }
        } }),
        el('label', { class: 'vms-check', for: 'vms-log-auto' }, auto, el('span', { text: 'Automatisch aktualisieren' }))),
      pre);
    root.replaceChildren(view);

    async function load() {
      try {
        const r = await api('log', { lines: 300 });
        const atEnd = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 20;
        pre.replaceChildren(...(r.lines.length ? r.lines : ['Noch keine Einträge.']).flatMap((l) => {
          const m = /^(\S+ \S+) \[(\w+)\] (.*)$/.exec(l);
          return [m ? el('span', { class: 'l-' + m[2], text: l }) : l, '\n'];
        }));
        if (atEnd) pre.scrollTop = pre.scrollHeight;
      } catch (e) { pre.textContent = e.message; }
    }
    return { tick: () => (auto.checked ? load() : Promise.resolve()), interval: () => 3000 };
  }

  // ---------- Start ----------
  // Unraid rendert alle Tabs in ein Dokument und kann Inhalte nachträglich einsetzen oder neu erzeugen.
  // Darum werden die Seitenbereiche regelmäßig gesucht, nicht nur einmal beim Laden.
  window.__vmswitchBuild = 'scan-3';
  const INIT = { switch: initSwitch, docker: initDocker, settings: initSettings, log: initLog };
  const pages = [];

  function scan() {
    for (let i = pages.length - 1; i >= 0; i--) if (!pages[i].root.isConnected) pages.splice(i, 1);
    document.querySelectorAll('.vms[data-page]').forEach((root) => {
      if (root.__vmsInit) return;
      root.__vmsInit = true;
      const f = INIT[root.dataset.page];
      if (!f) return;
      try {
        pages.push({ root, inst: f(root), next: 0, busy: false });
      } catch (e) {
        console.error('VM Switch: Start von Seite ' + root.dataset.page + ' fehlgeschlagen', e);
        root.replaceChildren(errNote('Seite konnte nicht gestartet werden: ' + (e && e.message ? e.message : e)));
      }
    });
  }

  async function loop() {
    scan();
    const now = Date.now();
    for (const p of pages) {
      if (p.busy || now < p.next || !visible(p.root)) continue;
      p.busy = true;
      try {
        await p.inst.tick();
      } catch (e) {
        console.error('VM Switch:', e);
        if (!p.root.querySelector('.vms-note')) p.root.prepend(errNote(e && e.message ? e.message : String(e)));
      }
      p.next = Date.now() + p.inst.interval();
      p.busy = false;
    }
  }

  function start() {
    scan();
    setInterval(loop, 500);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
