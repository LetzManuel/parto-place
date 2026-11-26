// Client for the Parto Place server on parto.at (server/ folder, PHP).
// The app key is the only credential the app holds; vendor tokens are sent
// once when connecting and then live only on the server, encrypted.
import type { Command, CommandRequest, CommandResult } from '../core/adapter';
import type { Device, DeviceKind, DeviceState, Home, Vendor } from '../core/model';
import type { Scene } from '../core/scenes';

export interface ServerSettings {
  url: string; // e.g. https://place.parto.at (empty = same site)
  appKey: string;
}

export interface VendorLink {
  vendor: Vendor;
  status: 'ok' | 'error';
  error: string;
  updated_at: string;
}

interface StatusResponse {
  devices: { id: string; vendor: Vendor; kind: DeviceKind; name: string; room: string }[];
  states: DeviceState[];
  links: VendorLink[];
}

const SETTINGS_KEY = 'parto-place.server';

export function loadSettings(): ServerSettings | null {
  try {
    const raw = localStorage.getItem(SETTINGS_KEY);
    const s = raw ? (JSON.parse(raw) as ServerSettings) : null;
    return s && s.appKey ? s : null;
  } catch {
    return null;
  }
}

export function saveSettings(s: ServerSettings | null): void {
  try {
    if (s) localStorage.setItem(SETTINGS_KEY, JSON.stringify(s));
    else localStorage.removeItem(SETTINGS_KEY);
  } catch {
    /* private mode: settings last for this session only */
  }
}

export class ServerError extends Error {}

export class ServerClient {
  constructor(private settings: ServerSettings) {}

  private async call<T>(method: 'GET' | 'POST', route: string, body?: unknown): Promise<T> {
    const base = this.settings.url.replace(/\/$/, '');
    let res: Response;
    try {
      res = await fetch(`${base}/api.php?r=${route}`, {
        method,
        headers: { Authorization: `Bearer ${this.settings.appKey}`, ...(body ? { 'Content-Type': 'application/json' } : {}) },
        body: body ? JSON.stringify(body) : undefined,
      });
    } catch {
      throw new ServerError('Server nicht erreichbar');
    }
    const data = (await res.json().catch(() => ({}))) as T & { error?: string };
    if (!res.ok) throw new ServerError(data.error ?? `Serverfehler ${res.status}`);
    return data;
  }

  async status(refresh = false): Promise<{ home: Home; states: DeviceState[]; links: VendorLink[] }> {
    const s = await this.call<StatusResponse>('GET', `status${refresh ? '&refresh=1' : ''}`);
    const roomNames = [...new Set(s.devices.map((d) => d.room || 'Ohne Raum'))].sort((a, b) =>
      a === 'Ohne Raum' ? 1 : b === 'Ohne Raum' ? -1 : a.localeCompare(b, 'de'),
    );
    const rooms = roomNames.map((name) => ({ id: name, name }));
    const devices: Device[] = s.devices.map((d) => ({ id: d.id, vendor: d.vendor, kind: d.kind, name: d.name, roomId: d.room || 'Ohne Raum' }));
    return { home: { id: 'server', name: 'Mein Zuhause', rooms, devices }, states: s.states, links: s.links };
  }

  async execute(req: CommandRequest): Promise<CommandResult> {
    const r = await this.call<{ status: 'done' | 'accepted' | 'failed'; reason?: string }>('POST', 'command', req);
    if (r.status === 'failed') return { status: 'failed', reason: r.reason ?? 'fehlgeschlagen' };
    if (r.status === 'accepted') return { status: 'accepted' };
    return { status: 'done' }; // the fresh state follows with the next status()
  }

  connectToken(vendor: 'nuki' | 'lg', token: string, country?: string) {
    return this.call<{ devices: number }>('POST', 'connect', { vendor, token, country });
  }

  disconnect(vendor: Vendor) {
    return this.call<{ ok: true }>('POST', 'disconnect', { vendor });
  }

  async startSpotify(clientId: string): Promise<string> {
    return (await this.call<{ url: string }>('POST', 'spotify-start', { clientId })).url;
  }

  setRoom(deviceId: string, room: string) {
    return this.call<{ ok: true }>('POST', 'room', { deviceId, room });
  }
}

/** Built-in scenes for a real home, derived from what is connected. */
export function defaultScenes(home: Home): Scene[] {
  const locks = home.devices.filter((d) => d.kind === 'lock');
  const media = home.devices.filter((d) => d.kind === 'media');
  const lockSteps = locks.map((d) => ({ deviceId: d.id, command: { type: 'lock' } as Command, label: `${d.name} absperren` }));
  const pauseSteps = media.map((d) => ({ deviceId: d.id, command: { type: 'pause' } as Command, label: `${d.name} pausieren` }));
  const scenes: Scene[] = [];
  if (lockSteps.length + pauseSteps.length) scenes.push({ id: 'leave', name: 'Haus verlassen', icon: 'key', steps: [...lockSteps, ...pauseSteps] });
  if (lockSteps.length) scenes.push({ id: 'night', name: 'Gute Nacht', icon: 'moon', steps: [...lockSteps, ...pauseSteps] });
  return scenes;
}
