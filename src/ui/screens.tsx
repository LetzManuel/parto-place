import { useRef, useState } from 'react';
import type { Device, DeviceKind, DeviceState, Vendor } from '../core/model';
import { VENDOR_REACH } from '../core/model';
import { ageMinutes } from '../core/status';
import { Icon, type IconName } from './icons';
import type { HomeApi } from './useHome';

function ageText(observedAt: string | undefined, now: Date): string {
  if (!observedAt) return 'kein Stand';
  const m = ageMinutes(observedAt, now);
  return m === 0 ? 'gerade eben' : `vor ${m} Min.`;
}

/* ---------- Zuhause ---------- */

export function HomeScreen({ api }: { api: HomeApi }) {
  const { status, now, busy, allOff, send, home } = api;
  const fixable = status.issues.filter((i) => i.fix && !i.stale).length;
  const open = status.issues.filter((i) => i.level !== 'info' || i.fix);
  const infos = status.issues.filter((i) => i.level === 'info' && !i.fix);
  return (
    <section className="screen">
      <div className={`status-card ${status.ok ? 'is-ok' : 'is-open'}`} role="status">
        <div className="status-icon">
          <Icon name={status.ok ? 'check' : 'alert'} size={40} />
        </div>
        <div>
          <p className="status-home">{home.name}</p>
          <h1 className="status-title">{status.ok ? 'Alles ok' : open.length === 1 ? '1 Sache offen' : `${open.length} Dinge offen`}</h1>
          <p className="status-age">Stand {ageText(status.oldestObservedAt, now)}</p>
        </div>
      </div>

      {open.length > 0 && (
        <ul className="issue-list">
          {open.map((i) => (
            <li key={i.deviceId} className={`issue level-${i.level}`}>
              <div className="issue-text">
                <strong>{i.text}</strong>
                <span>{i.roomName}{!i.fix && !i.stale ? ' · bitte selbst prüfen' : ''}</span>
              </div>
              {i.fix && !i.stale && (
                <button className="pill pill-small" disabled={busy} onClick={() => void send(i.deviceId, i.fix!.command)}>
                  {i.fix.label}
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      {infos.length > 0 && (
        <ul className="info-list">
          {infos.map((i) => (
            <li key={i.deviceId}>
              {i.text} <span>· {i.roomName}</span>
            </li>
          ))}
        </ul>
      )}

      <div className="bottom-action">
        <button className="pill pill-big" disabled={busy || fixable === 0} onClick={() => void allOff()}>
          {fixable === 0 ? 'Nichts auszuschalten' : `Alles aus (${fixable})`}
        </button>
      </div>
    </section>
  );
}

/* ---------- Szenen ---------- */

const sceneIcon: Record<string, IconName> = { key: 'key', moon: 'moon', home: 'arrive' };

export function ScenesScreen({ api }: { api: HomeApi }) {
  const { scenes, playScene, sceneReports, busy } = api;
  return (
    <section className="screen">
      <h1 className="screen-title">Szenen</h1>
      <div className="scene-grid">
        {scenes.map((s) => (
          <button key={s.id} className="scene-tile" disabled={busy} onClick={() => void playScene(s)}>
            <Icon name={sceneIcon[s.icon] ?? 'scenes'} size={32} />
            <span className="scene-name">{s.name}</span>
            <span className="scene-steps">{s.steps.length} Schritte</span>
            {sceneReports[s.id] && <span className="scene-report">{sceneReports[s.id]}</span>}
          </button>
        ))}
      </div>
    </section>
  );
}

/* ---------- Geräte ---------- */

const kindIcon: Record<DeviceKind, IconName> = {
  lock: 'lock', light: 'bulb', plug: 'plug', contact: 'window', cover: 'blind', climate: 'heat', appliance: 'appliance', media: 'music', sensor: 'smoke',
};

const filters: { id: string; label: string; kinds: DeviceKind[] }[] = [
  { id: 'all', label: 'Alle', kinds: [] },
  { id: 'locks', label: 'Schlösser', kinds: ['lock'] },
  { id: 'light', label: 'Licht', kinds: ['light', 'plug'] },
  { id: 'windows', label: 'Fenster', kinds: ['contact', 'cover'] },
  { id: 'appliances', label: 'Geräte', kinds: ['appliance', 'media', 'climate', 'sensor'] },
];

function stateWords(s: DeviceState | undefined): string {
  if (!s || !s.reachable) return 'keine Verbindung';
  const v = s.values;
  switch (v.kind) {
    case 'lock': return `${v.lock === 'locked' ? 'Abgesperrt' : v.lock === 'jammed' ? 'Blockiert' : 'Aufgesperrt'}${v.door === 'open' ? ', Tür offen' : ''}`;
    case 'light':
    case 'plug': return v.on ? 'An' : 'Aus';
    case 'contact': return v.contact === 'closed' ? 'Zu' : v.contact === 'tilted' ? 'Gekippt' : 'Offen';
    case 'cover': return v.positionPct === 0 ? 'Zu' : v.positionPct === 100 ? 'Ganz offen' : `${v.positionPct} % offen`;
    case 'climate': return `${v.currentC.toFixed(1)} °C, Ziel ${v.targetC} °C`;
    case 'appliance': return v.state === 'running' ? 'Läuft' : v.state === 'finished' ? 'Fertig' : 'Aus';
    case 'media': return v.playing ? `Spielt${v.title ? `: ${v.title}` : ''}` : 'Pausiert';
    case 'sensor': return v.alarm ? 'ALARM' : 'Ruhig';
  }
}

export function DevicesScreen({ api, onUnlock }: { api: HomeApi; onUnlock: (d: Device) => void }) {
  const { home, states, send, now } = api;
  const [filter, setFilter] = useState('all');
  const kinds = filters.find((f) => f.id === filter)!.kinds;
  const byId = new Map(states.map((s) => [s.deviceId, s]));
  return (
    <section className="screen">
      <h1 className="screen-title">Geräte</h1>
      <div className="chips" role="tablist">
        {filters.map((f) => (
          <button key={f.id} role="tab" aria-selected={filter === f.id} className={`chip ${filter === f.id ? 'is-active' : ''}`} onClick={() => setFilter(f.id)}>
            {f.label}
          </button>
        ))}
      </div>
      {home.rooms.map((room) => {
        const devices = home.devices.filter((d) => d.roomId === room.id && (kinds.length === 0 || kinds.includes(d.kind)));
        if (devices.length === 0) return null;
        return (
          <div key={room.id} className="room">
            <h2>{room.name}</h2>
            <ul>
              {devices.map((d) => {
                const s = byId.get(d.id);
                return (
                  <li key={d.id} className="device-row">
                    <span className="device-icon"><Icon name={kindIcon[d.kind]} /></span>
                    <div className="device-text">
                      <strong>{d.name}</strong>
                      <span>{stateWords(s)} · {ageText(s?.observedAt, now)}</span>
                    </div>
                    <DeviceAction device={d} state={s} send={send} onUnlock={onUnlock} />
                  </li>
                );
              })}
            </ul>
          </div>
        );
      })}
    </section>
  );
}

function DeviceAction({ device, state, send, onUnlock }: { device: Device; state?: DeviceState; send: HomeApi['send']; onUnlock: (d: Device) => void }) {
  if (!state || !state.reachable) return null;
  const v = state.values;
  if (v.kind === 'lock')
    return v.lock === 'locked' ? (
      <button className="pill pill-small pill-quiet" onClick={() => onUnlock(device)}>Aufsperren</button>
    ) : (
      <button className="pill pill-small" onClick={() => void send(device.id, { type: 'lock' })}>Absperren</button>
    );
  if (v.kind === 'light' || v.kind === 'plug')
    return (
      <button className="switch" role="switch" aria-checked={v.on} aria-label={`${device.name} ${v.on ? 'ausschalten' : 'einschalten'}`} onClick={() => void send(device.id, { type: 'setOn', on: !v.on })}>
        <span />
      </button>
    );
  if (v.kind === 'cover')
    return (
      <button className="pill pill-small pill-quiet" onClick={() => void send(device.id, { type: 'setPosition', positionPct: v.positionPct > 0 ? 0 : 100 })}>
        {v.positionPct > 0 ? 'Schließen' : 'Öffnen'}
      </button>
    );
  if (v.kind === 'media' && v.playing)
    return <button className="pill pill-small pill-quiet" onClick={() => void send(device.id, { type: 'pause' })}>Pause</button>;
  return null;
}

/* ---------- Aufsperren (gedrückt halten) ---------- */

export function UnlockSheet({ device, api, onClose }: { device: Device; api: HomeApi; onClose: () => void }) {
  const [progress, setProgress] = useState(0);
  const timer = useRef<number | null>(null);
  const HOLD_MS = 1500;

  const start = () => {
    const t0 = performance.now();
    const tick = () => {
      const p = Math.min(1, (performance.now() - t0) / HOLD_MS);
      setProgress(p);
      if (p >= 1) {
        // A real build asks for biometric/PIN here before sending.
        void api.send(device.id, { type: 'unlock', confirmedWith: 'pin' }).then(onClose);
        return;
      }
      timer.current = requestAnimationFrame(tick);
    };
    timer.current = requestAnimationFrame(tick);
  };
  const stop = () => {
    if (timer.current) cancelAnimationFrame(timer.current);
    if (progress < 1) setProgress(0);
  };

  return (
    <div className="sheet-backdrop" onClick={onClose}>
      <div className="sheet" role="dialog" aria-modal="true" aria-labelledby="unlock-title" onClick={(e) => e.stopPropagation()}>
        <h2 id="unlock-title">{device.name} aufsperren?</h2>
        <p>Zum Aufsperren gedrückt halten. In der echten App bestätigst du zusätzlich mit Fingerabdruck oder PIN.</p>
        <button
          className="hold-button"
          onPointerDown={start}
          onPointerUp={stop}
          onPointerLeave={stop}
          style={{ ['--p' as string]: progress }}
        >
          <span>{progress >= 1 ? 'Wird aufgesperrt …' : 'Gedrückt halten'}</span>
        </button>
        <button className="pill pill-quiet" onClick={onClose}>Abbrechen</button>
      </div>
    </div>
  );
}

/* ---------- Mehr ---------- */

const vendors: { id: Vendor; name: string; how: string }[] = [
  { id: 'nuki', name: 'Nuki', how: 'API-Token aus web.nuki.io' },
  { id: 'bosch', name: 'Bosch Smart Home', how: 'Controller im WLAN koppeln' },
  { id: 'somfy', name: 'Somfy TaHoma', how: 'Developer Mode, Token im WLAN' },
  { id: 'velux', name: 'Velux', how: 'über Apple Home (iPhone/iPad)' },
  { id: 'lg', name: 'LG ThinQ', how: 'persönlicher Zugriffstoken' },
  { id: 'spotify', name: 'Spotify', how: 'Spotify-Anmeldung (Premium)' },
];

export function MoreScreen({ api }: { api: HomeApi }) {
  const { home, adapter, refresh, reset } = api;
  const [offline, setOffline] = useState<Set<string>>(new Set());
  const toggleOffline = (id: string) => {
    const next = new Set(offline);
    if (next.has(id)) next.delete(id); else next.add(id);
    adapter.setOffline(id, next.has(id));
    setOffline(next);
    void refresh();
  };
  return (
    <section className="screen">
      <h1 className="screen-title">Mehr</h1>
      <h2 className="section-title">Verbindungen</h2>
      <ul className="vendor-list">
        {vendors.map((v) => (
          <li key={v.id}>
            <span className="device-icon"><Icon name={VENDOR_REACH[v.id] === 'cloud' ? 'cloud' : 'wifi'} /></span>
            <div className="device-text">
              <strong>{v.name}</strong>
              <span>{VENDOR_REACH[v.id] === 'cloud' ? 'Cloud, überall' : 'nur im WLAN zuhause'} · {v.how}</span>
            </div>
            <span className="badge">bald</span>
          </li>
        ))}
      </ul>

      <h2 className="section-title">Demo</h2>
      <p className="hint">Alle Geräte hier sind erfunden. Zum Ausprobieren kannst du einzelne Geräte „offline“ schalten.</p>
      <ul className="vendor-list">
        {home.devices.map((d) => (
          <li key={d.id}>
            <div className="device-text"><strong>{d.name}</strong></div>
            <button className="switch" role="switch" aria-checked={!offline.has(d.id)} aria-label={`${d.name} erreichbar`} onClick={() => toggleOffline(d.id)}>
              <span />
            </button>
          </li>
        ))}
      </ul>
      <button className="pill pill-quiet" onClick={() => { setOffline(new Set()); reset(); }}>Demo zurücksetzen</button>
    </section>
  );
}
