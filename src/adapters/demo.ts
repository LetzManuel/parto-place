// Demo adapter with a fictional home. No real devices, people or data.
import { isExpired, type CommandRequest, type CommandResult, type VendorAdapter } from '../core/adapter';
import type { Device, DeviceState, DeviceValues, Home } from '../core/model';
import type { Scene } from '../core/scenes';

export const demoHome: Home = {
  id: 'demo-home',
  name: 'Zuhause (Demo)',
  rooms: [
    { id: 'flur', name: 'Vorraum' },
    { id: 'wohnen', name: 'Wohnzimmer' },
    { id: 'kueche', name: 'Küche' },
    { id: 'bad', name: 'Bad' },
    { id: 'schlafen', name: 'Schlafzimmer' },
  ],
  devices: [
    { id: 'lock-front', vendor: 'demo', kind: 'lock', name: 'Haustür', roomId: 'flur' },
    { id: 'light-kitchen', vendor: 'demo', kind: 'light', name: 'Licht Küche', roomId: 'kueche', offWhenAway: true },
    { id: 'light-living', vendor: 'demo', kind: 'light', name: 'Licht Wohnzimmer', roomId: 'wohnen', offWhenAway: true },
    { id: 'plug-iron', vendor: 'demo', kind: 'plug', name: 'Steckdose Bügeleisen', roomId: 'schlafen', offWhenAway: true },
    { id: 'contact-bath', vendor: 'demo', kind: 'contact', name: 'Fenster Bad', roomId: 'bad' },
    { id: 'cover-roof', vendor: 'demo', kind: 'cover', name: 'Dachfenster', roomId: 'schlafen', isWindow: true },
    { id: 'blind-living', vendor: 'demo', kind: 'cover', name: 'Rollladen Wohnzimmer', roomId: 'wohnen' },
    { id: 'thermo-living', vendor: 'demo', kind: 'climate', name: 'Heizung Wohnzimmer', roomId: 'wohnen' },
    { id: 'washer', vendor: 'demo', kind: 'appliance', name: 'Waschmaschine', roomId: 'bad' },
    { id: 'speaker', vendor: 'demo', kind: 'media', name: 'Musik Wohnzimmer', roomId: 'wohnen' },
    { id: 'smoke-kitchen', vendor: 'demo', kind: 'sensor', name: 'Rauchmelder Küche', roomId: 'kueche' },
  ],
};

const initialValues: Record<string, DeviceValues> = {
  'lock-front': { kind: 'lock', lock: 'unlocked', door: 'closed' },
  'light-kitchen': { kind: 'light', on: true },
  'light-living': { kind: 'light', on: false },
  'plug-iron': { kind: 'plug', on: false },
  'contact-bath': { kind: 'contact', contact: 'tilted' },
  'cover-roof': { kind: 'cover', positionPct: 30 },
  'blind-living': { kind: 'cover', positionPct: 100 },
  'thermo-living': { kind: 'climate', currentC: 21.5, targetC: 21 },
  washer: { kind: 'appliance', state: 'finished' },
  speaker: { kind: 'media', playing: true, title: 'Radio' },
  'smoke-kitchen': { kind: 'sensor', alarm: false, label: 'smoke' },
};

export const demoScenes: Scene[] = [
  {
    id: 'leave',
    name: 'Haus verlassen',
    icon: 'key',
    steps: [
      { deviceId: 'lock-front', command: { type: 'lock' }, label: 'Haustür absperren' },
      { deviceId: 'light-kitchen', command: { type: 'setOn', on: false }, label: 'Licht Küche aus' },
      { deviceId: 'light-living', command: { type: 'setOn', on: false }, label: 'Licht Wohnzimmer aus' },
      { deviceId: 'plug-iron', command: { type: 'setOn', on: false }, label: 'Bügeleisen aus' },
      { deviceId: 'cover-roof', command: { type: 'setPosition', positionPct: 0 }, label: 'Dachfenster schließen' },
      { deviceId: 'speaker', command: { type: 'pause' }, label: 'Musik pausieren' },
    ],
  },
  {
    id: 'night',
    name: 'Gute Nacht',
    icon: 'moon',
    steps: [
      { deviceId: 'lock-front', command: { type: 'lock' }, label: 'Haustür absperren' },
      { deviceId: 'light-kitchen', command: { type: 'setOn', on: false }, label: 'Licht Küche aus' },
      { deviceId: 'light-living', command: { type: 'setOn', on: false }, label: 'Licht Wohnzimmer aus' },
      { deviceId: 'blind-living', command: { type: 'setPosition', positionPct: 0 }, label: 'Rollladen runter' },
      { deviceId: 'thermo-living', command: { type: 'setTarget', targetC: 18 }, label: 'Heizung auf 18 °C' },
    ],
  },
  {
    id: 'arrive',
    name: 'Ankommen',
    icon: 'home',
    steps: [
      { deviceId: 'light-living', command: { type: 'setOn', on: true }, label: 'Licht Wohnzimmer an' },
      { deviceId: 'blind-living', command: { type: 'setPosition', positionPct: 100 }, label: 'Rollladen hoch' },
      { deviceId: 'thermo-living', command: { type: 'setTarget', targetC: 21 }, label: 'Heizung auf 21 °C' },
    ],
  },
];

/** In-memory demo devices. `offline` lists device ids that do not answer. */
export class DemoAdapter implements VendorAdapter {
  readonly vendor = 'demo' as const;
  private values = new Map<string, DeviceValues>();
  private observedAt = new Map<string, string>();

  constructor(
    private clock: () => Date = () => new Date(),
    private offline: Set<string> = new Set(),
  ) {
    const now = this.clock().toISOString();
    for (const [id, v] of Object.entries(initialValues)) {
      this.values.set(id, structuredClone(v));
      this.observedAt.set(id, now);
    }
  }

  async discover(): Promise<Device[]> {
    return demoHome.devices;
  }

  async readState(deviceIds: string[]): Promise<DeviceState[]> {
    return deviceIds.flatMap((id) => {
      const values = this.values.get(id);
      if (!values) return [];
      return [{ deviceId: id, values: structuredClone(values), observedAt: this.observedAt.get(id)!, source: 'demo', reachable: !this.offline.has(id) }];
    });
  }

  async execute(req: CommandRequest, now: Date = this.clock()): Promise<CommandResult> {
    if (isExpired(req, now)) return { status: 'failed', reason: 'zu spät, nicht ausgeführt' };
    if (this.offline.has(req.deviceId)) return { status: 'failed', reason: 'nicht erreichbar' };
    const v = this.values.get(req.deviceId);
    if (!v) return { status: 'failed', reason: 'unbekanntes Gerät' };
    const c = req.command;
    if (v.kind === 'lock' && c.type === 'lock') v.lock = 'locked';
    else if (v.kind === 'lock' && c.type === 'unlock') v.lock = 'unlocked';
    else if ((v.kind === 'light' || v.kind === 'plug') && c.type === 'setOn') v.on = c.on;
    else if (v.kind === 'cover' && c.type === 'setPosition') v.positionPct = c.positionPct;
    else if (v.kind === 'climate' && c.type === 'setTarget') v.targetC = c.targetC;
    else if (v.kind === 'media' && c.type === 'pause') v.playing = false;
    else return { status: 'failed', reason: 'Befehl passt nicht zum Gerät' };
    this.observedAt.set(req.deviceId, now.toISOString());
    const [state] = await this.readState([req.deviceId]);
    return { status: 'done', state };
  }

  /** Demo only: change a value from the UI (e.g. "Bad-Fenster schließen" by hand). */
  setValues(deviceId: string, values: DeviceValues): void {
    this.values.set(deviceId, values);
    this.observedAt.set(deviceId, this.clock().toISOString());
  }

  setOffline(deviceId: string, offline: boolean): void {
    if (offline) this.offline.add(deviceId);
    else this.offline.delete(deviceId);
  }
}
