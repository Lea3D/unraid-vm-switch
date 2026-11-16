// UI-Smoke-Test mit jsdom: rendert alle vier Seiten gegen einen simulierten Server.
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const JS = fs.readFileSync(path.join(__dirname, '../source/usr/local/emhttp/plugins/vmswitch/js/vmswitch.js'), 'utf8');
let fails = 0, total = 0;
const check = (c, m) => { total++; if (!c) { fails++; console.log('  FEHLER: ' + m); } else console.log('  ok: ' + m); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function until(fn, ms = 4000) { const t = Date.now(); while (Date.now() - t < ms) { if (fn()) return true; await sleep(50); } return false; }

const EVIL = '<img src=x onerror=alert(1)>';
const server = {
  libvirt: true,
  vms: [{ name: 'A', state: 'running', devices: [{ kind: 'GPU', addr: '0000:01:00.0', present: true, driver: 'vfio-pci', name: 'NVIDIA GeForce GTX 1080' }, { kind: 'USB-Controller', addr: '0000:00:14.0', present: false, driver: '', name: '' }], error: '' },
        { name: 'B', state: 'shut off', devices: [], error: '' },
        { name: EVIL, state: 'shut off', devices: [], error: '' }],
  containers: [{ name: 'plex', running: true }, { name: 'immich', running: false }],
  settings: { wait_default: 10, shutdown_timeout: 120, start_timeout: 60, health_secs: 20, agent_wait: 0, fallback: true, force_destroy: true, notify: true, vms: { B: { wait: 30, stop_containers: ['plex'] } } },
  job: { running: false, state: null },
  log: ['2026-11-14 12:00:00 [INFO] Wechsel zu B', '2026-11-14 12:00:05 [ERROR] Start von B fehlgeschlagen'],
};
const calls = [];

(async () => {
  const dom = new JSDOM(`<!doctype html><body>
    <div class="vms" data-page="switch" data-csrf="tok"></div><div class="vms" data-page="docker" data-csrf="tok"></div>
    <div class="vms" data-page="settings"></div><div class="vms" data-page="log"></div></body>`, { runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  // Das Token kommt wie in der Unraid-Doku aus data-csrf, nicht aus einer globalen Variable
  w.Element.prototype.getClientRects = function () { return [1]; };
  w.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
  w.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); this.dispatchEvent(new w.Event('close')); };
  w.confirm = () => true;
  w.fetch = async (url, opts) => {
    const fd = opts.body;
    const action = fd.get('action');
    const rec = { action }; for (const [k, v] of fd.entries()) rec[k] = v; calls.push(rec);
    let body = { ok: true };
    if (fd.get('csrf_token') !== 'tok') body = { ok: false, error: 'csrf' };
    else if (action === 'status') body = { ok: true, libvirt: server.libvirt, vms: server.vms.map((v) => ({ name: v.name, state: v.state })), job: server.job };
    else if (action === 'overview') body = { ok: true, libvirt: server.libvirt, vms: server.vms, containers: server.containers, settings: server.settings, job: server.job };
    else if (action === 'log') body = { ok: true, lines: server.log, running: false };
    else if (action === 'save_settings') body = { ok: true, settings: { ...server.settings, ...JSON.parse(fd.get('settings')) } };
    return { status: 200, json: async () => body };
  };
  w.eval(JS);
  w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
  const $ = (sel, root = w.document) => root.querySelector(sel);
  const $$ = (sel, root = w.document) => [...root.querySelectorAll(sel)];
  const page = (n) => $(`.vms[data-page=${n}]`);

  console.log('\n== VM-Wechsel-Seite');
  check(await until(() => $$('.vms-card', page('switch')).length === 3), 'drei VM-Karten werden gerendert');
  check($('.vms-now h2', page('switch')).textContent === 'A', 'aktive VM wird im Kopf angezeigt');
  check($$('.vms-dev li', page('switch')).length === 2 && page('switch').textContent.includes('GeForce GTX 1080'), 'Geräte der VM werden angezeigt');
  check(page('switch').textContent.includes('nicht gefunden'), 'fehlendes Gerät wird markiert');
  check(!$('img', page('switch')) && page('switch').textContent.includes(EVIL), 'Bösartiger VM-Name wird als Text dargestellt, nicht als HTML');
  const btnA = $$('.vms-card', page('switch'))[0].querySelector('button');
  check(btnA.disabled && btnA.textContent === 'Aktiv', 'Aktive VM hat deaktivierten Knopf "Aktiv"');

  $$('.vms-seg button', page('switch')).find((b) => b.textContent === '30 s').click();
  check($$('.vms-seg button', page('switch')).find((b) => b.textContent === '30 s').getAttribute('aria-pressed') === 'true', 'Wartezeit 30 s lässt sich wählen');
  $$('.vms-card', page('switch'))[1].querySelector('button').click();
  const dlg = $('dialog', page('switch'));
  check(!!dlg && dlg.textContent.includes('Diese VMs werden heruntergefahren: A'), 'Bestätigungsdialog nennt die zu stoppende VM');
  check(dlg.textContent.includes('30 s') && dlg.textContent.includes('automatisch wieder gestartet'), 'Dialog nennt Wartezeit und Fallback');
  server.job = { running: true, state: { id: 'x1', status: 'running', phase: 'wait', target: 'B', previous: ['A'], wait: 30, wait_left: 7, fallback: false, message: 'Wartezeit 30 s vor dem Start', started: 1, finished: null,
    steps: [{ phase: 'shutdown', lvl: 'INFO', msg: '' }, { phase: 'wait', lvl: 'INFO', msg: '' }] } };
  $$('button', dlg).find((b) => b.textContent === 'Wechseln').click();
  check(await until(() => calls.some((c) => c.action === 'switch')), 'switch wird an den Server geschickt');
  const sw = calls.find((c) => c.action === 'switch');
  check(sw.target === 'B' && sw.wait === '30' && sw.csrf_token === 'tok', 'Ziel, Wartezeit-Override und CSRF-Token werden mitgesendet');
  check(await until(() => $('.vms-job', page('switch'))), 'Ablaufanzeige erscheint');
  check($$('.vms-step', page('switch')).length === 5, 'fünf Ablaufschritte');
  check($('.vms-step[data-s=active] .vms-count', page('switch'))?.textContent === '7 s', 'Wartezeit-Countdown zeigt 7 s');
  check($('.vms-step[data-s=done]', page('switch')).textContent.includes('Herunterfahren'), 'Herunterfahren ist als erledigt markiert');
  check(!!$$('button', page('switch')).find((b) => b.textContent === 'Abbrechen'), 'Abbrechen-Knopf vorhanden');
  check($$('.vms-card button', page('switch')).every((b) => b.disabled), 'Während des Wechsels sind alle Wechseln-Knöpfe deaktiviert');

  server.job = { running: false, state: { id: 'x1', status: 'fallback_ok', phase: 'done', target: 'B', previous: ['A'], wait: 30, wait_left: 0, fallback: true, message: 'Ziel-VM B nicht gestartet (virsh start fehlgeschlagen). Vorherige VM(s) wurden wieder gestartet: A.', started: 1, finished: 1763120400,
    steps: [{ phase: 'shutdown', lvl: 'INFO' }, { phase: 'start', lvl: 'ERROR' }, { phase: 'fallback', lvl: 'WARN' }] } };
  check(await until(() => $('.vms-result[data-s=warn]', page('switch')), 8000), 'Fallback-Ergebnis wird als Warnung angezeigt');
  check($('.vms-result strong', page('switch')).textContent.includes('vorherige VM läuft wieder') && $('.vms-result', page('switch')).textContent.includes('Vorherige VM(s) wurden wieder gestartet'), 'Ergebnis nennt Titel und Meldung des Workers');
  $$('button', page('switch')).find((b) => b.textContent === 'Ausblenden').click();
  check(!$('.vms-result', page('switch')), 'Ergebnis lässt sich ausblenden');

  console.log('\n== Docker-Seite');
  check(await until(() => $$('tbody tr', page('docker')).length === 2), 'zwei Container in der Tabelle');
  const boxes = $$('tbody tr:first-child input[type=checkbox]', page('docker'));
  check(boxes.length === 3, 'je eine Spalte pro VM');
  check($$('tbody tr', page('docker'))[0].textContent.includes('plex') && $$('tbody tr:nth-child(1) button', page('docker'))[0].disabled, 'laufender Container: Start deaktiviert');
  check(boxes[1].checked === true, 'gespeichertes Profil (plex bei B) ist angehakt');
  boxes[0].checked = true; boxes[0].dispatchEvent(new w.Event('change'));
  $$('button', page('docker')).find((b) => b.textContent === 'Profile speichern').click();
  check(await until(() => calls.some((c) => c.action === 'save_settings' && c.settings.includes('stop_containers'))), 'Speichern sendet die Profile');
  const sv = JSON.parse(calls.filter((c) => c.action === 'save_settings').pop().settings);
  check(JSON.stringify(sv.vms.A.stop_containers) === '["plex"]' && JSON.stringify(sv.vms.B.stop_containers) === '["plex"]', 'Profil-Inhalt stimmt');

  console.log('\n== Einstellungen-Seite');
  check(await until(() => $('#vms-s-wait')), 'Formular erscheint');
  check($('#vms-s-wait').value === '10' && $('#vms-s-fb').checked === true, 'gespeicherte Werte sind eingetragen');
  check($$('.vms-seg button', page('settings')).length === 4, 'Schnellwahl 0/10/30/60 vorhanden');
  $$('.vms-seg button', page('settings')).find((b) => b.textContent === '30 s').click();
  check($('#vms-s-wait').value === '30', 'Schnellwahl setzt 30 s');
  $('#vms-s-fb').checked = false;
  $$('button', page('settings')).find((b) => b.textContent === 'Einstellungen speichern').click();
  check(await until(() => calls.some((c) => c.action === 'save_settings' && c.settings.includes('wait_default'))), 'Einstellungen werden gesendet');
  const st = JSON.parse(calls.filter((c) => c.action === 'save_settings' && c.settings.includes('wait_default')).pop().settings);
  check(st.wait_default === 30 && st.fallback === false && st.vms.B.wait === 30 && st.vms.A.wait === null, 'Werte stimmen (Fallback aus, B 30 s, A Standard)');

  console.log('\n== Log-Seite');
  check(await until(() => $$('.vms-log span', page('log')).length === 2), 'Logzeilen werden gerendert');
  check($('.vms-log .l-ERROR', page('log')) !== null, 'Fehlerzeile ist als ERROR markiert');

  console.log('\n== VM-Dienst gestoppt');
  server.libvirt = false; server.vms = []; server.job = { running: false, state: null };
  check(await until(() => page('switch').textContent.includes('VM-Dienst ist nicht erreichbar'), 9000), 'verständlicher Hinweis, wenn libvirt nicht läuft');
  console.log('\n== Fehler werden angezeigt statt verschluckt');
  async function bareDom(fetchImpl) {
    const d = new JSDOM('<!doctype html><body><div class="vms" data-page="switch"><p>Lade VMs …</p></div></body>', { runScripts: 'outside-only', pretendToBeVisual: true });
    d.window.csrf_token = 'tok';
    d.window.Element.prototype.getClientRects = function () { return [1]; };
    d.window.fetch = fetchImpl;
    d.window.eval(JS);
    d.window.document.dispatchEvent(new d.window.Event('DOMContentLoaded'));
    return d.window;
  }
  let w2 = await bareDom(() => new Promise(() => {}));
  await sleep(1200);
  check(w2.document.querySelector('.vms').textContent.includes('Lade VMs'), 'Ladehinweis bleibt stehen, solange der Server nicht antwortet');
  w2.close();
  w2 = await bareDom(async () => ({ status: 200, json: async () => ({ ok: true, libvirt: true, vms: null, containers: [], settings: {}, job: {} }) }));
  check(await until(() => w2.document.querySelector('.vms .vms-error'), 4000) && w2.document.querySelector('.vms').textContent.includes('Darstellungsfehler'), 'Fehler beim Seitenaufbau erscheint als roter Kasten');
  w2.close();
  w2 = await bareDom(async () => ({ status: 500, json: async () => { throw new Error('kein JSON'); } }));
  check(await until(() => w2.document.querySelector('.vms .vms-error'), 4000) && w2.document.querySelector('.vms').textContent.includes('HTTP 500'), 'HTTP-Fehler des Servers wird mit Statuscode angezeigt');
  w2.close();

  console.log('\n== Seitenbereiche, die später eingesetzt oder neu erzeugt werden');
  const okFetch = async (u, o) => {
    const a = o.body.get('action');
    const body = a === 'status' ? { ok: true, libvirt: true, vms: ov0.vms.map((v) => ({ name: v.name, state: v.state })), job: ov0.job } : ov0;
    return { status: 200, json: async () => body };
  };
  const ov0 = { ok: true, libvirt: true, vms: [{ name: 'A', state: 'shut off', devices: [], error: '' }], containers: [], settings: { wait_default: 10, fallback: true, vms: [] }, job: { running: false, state: null } };
  const d3 = new JSDOM('<!doctype html><body></body>', { runScripts: 'outside-only', pretendToBeVisual: true });
  d3.window.Element.prototype.getClientRects = function () { return [1]; };
  d3.window.fetch = okFetch;
  d3.window.eval(JS);
  d3.window.document.dispatchEvent(new d3.window.Event('DOMContentLoaded'));
  await sleep(700);
  d3.window.document.body.innerHTML = '<div class="vms" data-page="switch" data-csrf="tok"><p>Lade VMs …</p></div>';
  check(await until(() => d3.window.document.querySelectorAll('.vms-card').length === 1, 4000), 'Bereich, der erst nach dem Laden eingesetzt wird, wird aufgebaut');
  const old = d3.window.document.querySelector('.vms');
  const fresh = old.cloneNode(false); fresh.innerHTML = '<p>Lade VMs …</p>'; delete fresh.__vmsInit;
  old.replaceWith(fresh);
  check(await until(() => d3.window.document.querySelectorAll('.vms-card').length === 1 && d3.window.document.querySelector('.vms') === fresh, 4000), 'Wird der Bereich von Unraid neu erzeugt, wird er erneut aufgebaut');
  d3.window.close();

  console.log('\n== Selbstdiagnose der Hauptseite');
  const pageSrc = fs.readFileSync(path.join(__dirname, '../source/usr/local/emhttp/plugins/vmswitch/vmswitch.page'), 'utf8');
  const inline = /<script>([\s\S]*?)<\/script>/.exec(pageSrc.replace(/<script src[^>]*><\/script>/, ''))[1];
  const d4 = new JSDOM('<!doctype html><body><div class="vms" data-page="switch" data-csrf=""><p>Lade VMs …</p></div></body>', { runScripts: 'outside-only' });
  d4.window.Element.prototype.getClientRects = function () { return [1]; };
  d4.window.fetch = async (u, o) => ({ status: 200, text: async () => '{"ok":true,"action":"' + o.body.get('action') + '","devices":"' + (o.body.get('devices') || '') + '"}' });
  d4.window.eval(inline);
  await sleep(4600);
  const txt = d4.window.document.querySelector('.vms').textContent;
  check(txt.includes('Diagnose') && txt.includes('vmswitch.js geladen: NEIN') && txt.includes('Token: nein') && txt.includes('KEINE gestartet'), 'Ohne geladenes Skript zeigt die Seite nach 4 s eine Diagnose');
  check(txt.includes('Testanfrage status: HTTP 200') && txt.includes('Testanfrage overview: HTTP 200') && txt.includes('"devices":"1"'), 'Die Diagnose schickt echte Testanfragen (status und overview) und zeigt die Antwort');
  d4.window.close();

  console.log('\n' + (total - fails) + ' von ' + total + ' Prüfungen bestanden.');
  w.close();
  process.exit(fails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
