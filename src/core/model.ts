// Parto Place device model. UI-free: scenes and "Alles ok?" only know kinds
// and capabilities, never vendors.

export type Vendor = 'nuki' | 'bosch' | 'somfy' | 'velux' | 'lg' | 'spotify' | 'demo';

/** Where an adapter runs: the server (cloud APIs) or the app on the home network. */
export type Reach = 'cloud' | 'home-network';

export type DeviceKind =
  | 'lock'
  | 'light'
  | 'plug'
  | 'contact'
  | 'cover'
  | 'climate'
  | 'appliance'
  | 'media'
  | 'sensor';

export interface Room {
  id: string;
  name: string;
}

export interface Device {
  id: string;
  vendor: Vendor;
  kind: DeviceKind;
  name: string;
  roomId: string;
  /** Switch/plug/light should be off when nobody is home ("Alles aus" turns it off). */
  offWhenAway?: boolean;
  /** Cover that counts as a window (roof window): open is an issue. */
  isWindow?: boolean;
}

export type LockState = 'locked' | 'unlocked' | 'unlatched' | 'jammed';
export type DoorState = 'closed' | 'open' | 'unknown';
export type ContactState = 'closed' | 'open' | 'tilted';
export type ApplianceState = 'idle' | 'running' | 'finished';

export type DeviceValues =
  | { kind: 'lock'; lock: LockState; door: DoorState }
  | { kind: 'light' | 'plug'; on: boolean }
  | { kind: 'contact'; contact: ContactState }
  | { kind: 'cover'; positionPct: number } // 0 = closed, 100 = fully open
  | { kind: 'climate'; currentC: number; targetC: number }
  | { kind: 'appliance'; state: ApplianceState; remainingMin?: number }
  | { kind: 'media'; playing: boolean; title?: string }
  | { kind: 'sensor'; alarm: boolean; label: 'smoke' | 'water' };

export interface DeviceState {
  deviceId: string;
  values: DeviceValues;
  /** ISO time the state was observed at the vendor. */
  observedAt: string;
  source: 'webhook' | 'poll' | 'command' | 'demo';
  reachable: boolean;
}

export interface Home {
  id: string;
  name: string;
  rooms: Room[];
  devices: Device[];
}

/** How old a state may be before it no longer counts, per reach (minutes). */
export const MAX_AGE_MIN: Record<Reach, number> = {
  cloud: 30,
  'home-network': 10,
};

export const VENDOR_REACH: Record<Vendor, Reach> = {
  nuki: 'cloud',
  lg: 'cloud',
  spotify: 'cloud',
  bosch: 'home-network',
  somfy: 'home-network',
  velux: 'home-network',
  demo: 'cloud',
};
