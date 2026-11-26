import type { Device, DeviceState, Vendor } from './model';

/** Commands a fix or a scene step can ask for. Unlocking is deliberately absent
 * from fixes and scenes: it always needs an explicit, confirmed tap. */
export type Command =
  | { type: 'lock' }
  | { type: 'unlock'; confirmedWith: 'biometric' | 'pin' }
  | { type: 'setOn'; on: boolean }
  | { type: 'setPosition'; positionPct: number }
  | { type: 'setTarget'; targetC: number }
  | { type: 'pause' };

export interface CommandRequest {
  deviceId: string;
  command: Command;
  /** A command is dropped once this time has passed; never run late. */
  expiresAt: string;
}

export type CommandResult =
  | { status: 'done'; state: DeviceState }
  | { status: 'accepted' } // vendor acknowledged, confirmation follows (e.g. Nuki)
  | { status: 'failed'; reason: string };

export interface VendorAdapter {
  vendor: Vendor;
  discover(): Promise<Device[]>;
  readState(deviceIds: string[]): Promise<DeviceState[]>;
  execute(req: CommandRequest, now?: Date): Promise<CommandResult>;
}

export function isExpired(req: CommandRequest, now: Date): boolean {
  return new Date(req.expiresAt).getTime() <= now.getTime();
}
