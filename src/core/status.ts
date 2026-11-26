import type { Command } from './adapter';
import { MAX_AGE_MIN, VENDOR_REACH, type Device, type DeviceState, type Home } from './model';

export type IssueLevel = 'alarm' | 'warn' | 'info';

export interface Issue {
  deviceId: string;
  level: IssueLevel;
  /** Short German text, e.g. "Haustür offen". */
  text: string;
  roomName: string;
  /** One-tap fix, if Place can fix it. */
  fix?: { label: string; command: Command };
  /** True when the device did not answer or the state is too old. */
  stale?: boolean;
}

export interface HomeStatus {
  ok: boolean;
  issues: Issue[];
  /** Oldest observation among reachable devices, for "Stand vor X Min.". */
  oldestObservedAt?: string;
}

export function ageMinutes(observedAt: string, now: Date): number {
  return Math.max(0, Math.floor((now.getTime() - new Date(observedAt).getTime()) / 60000));
}

function issueFor(device: Device, state: DeviceState, roomName: string): Issue | undefined {
  const v = state.values;
  const base = { deviceId: device.id, roomName };
  switch (v.kind) {
    case 'lock':
      if (v.lock === 'jammed') return { ...base, level: 'alarm', text: `${device.name} blockiert` };
      if (v.door === 'open') return { ...base, level: 'warn', text: `${device.name}: Tür offen` };
      if (v.lock !== 'locked')
        return { ...base, level: 'warn', text: `${device.name} nicht abgesperrt`, fix: { label: 'Absperren', command: { type: 'lock' } } };
      return undefined;
    case 'light':
    case 'plug':
      if (v.on && device.offWhenAway)
        return { ...base, level: 'warn', text: `${device.name} an`, fix: { label: 'Ausschalten', command: { type: 'setOn', on: false } } };
      return undefined;
    case 'contact':
      if (v.contact === 'open') return { ...base, level: 'warn', text: `${device.name} offen` };
      if (v.contact === 'tilted') return { ...base, level: 'warn', text: `${device.name} gekippt` };
      return undefined;
    case 'cover':
      if (device.isWindow && v.positionPct > 0)
        return { ...base, level: 'warn', text: `${device.name} offen`, fix: { label: 'Schließen', command: { type: 'setPosition', positionPct: 0 } } };
      return undefined;
    case 'appliance':
      if (v.state === 'finished') return { ...base, level: 'info', text: `${device.name} fertig` };
      if (v.state === 'running')
        return { ...base, level: 'info', text: `${device.name} läuft${v.remainingMin ? ` (noch ${v.remainingMin} Min.)` : ''}` };
      return undefined;
    case 'media':
      if (v.playing)
        return { ...base, level: 'info', text: `${device.name} spielt`, fix: { label: 'Pausieren', command: { type: 'pause' } } };
      return undefined;
    case 'sensor':
      if (v.alarm)
        return { ...base, level: 'alarm', text: v.label === 'smoke' ? `Rauchalarm: ${device.name}` : `Wasseralarm: ${device.name}` };
      return undefined;
    case 'climate':
      return undefined;
  }
}

/**
 * "Alles ok?" for one home. Never reports ok on missing or stale data:
 * a device without a fresh state is an issue of its own.
 */
export function evaluateHome(home: Home, states: DeviceState[], now: Date): HomeStatus {
  const byId = new Map(states.map((s) => [s.deviceId, s]));
  const rooms = new Map(home.rooms.map((r) => [r.id, r.name]));
  const issues: Issue[] = [];
  let oldest: string | undefined;

  for (const device of home.devices) {
    const roomName = rooms.get(device.roomId) ?? '';
    const state = byId.get(device.id);
    const maxAge = MAX_AGE_MIN[VENDOR_REACH[device.vendor]];
    if (!state || !state.reachable || ageMinutes(state.observedAt, now) > maxAge) {
      issues.push({ deviceId: device.id, level: 'warn', text: `${device.name}: keine Verbindung`, roomName, stale: true });
      continue;
    }
    if (!oldest || state.observedAt < oldest) oldest = state.observedAt;
    const issue = issueFor(device, state, roomName);
    if (issue) issues.push(issue);
  }

  const order: Record<IssueLevel, number> = { alarm: 0, warn: 1, info: 2 };
  issues.sort((a, b) => order[a.level] - order[b.level]);
  return { ok: issues.every((i) => i.level === 'info' && !i.fix), issues, oldestObservedAt: oldest };
}

/** The fixes "Alles aus" runs: every fixable issue on a fresh device. */
export function allOffFixes(status: HomeStatus): Issue[] {
  return status.issues.filter((i) => i.fix && !i.stale);
}
