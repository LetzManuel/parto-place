import type { Command, CommandResult, VendorAdapter } from './adapter';
import type { Home, Vendor } from './model';

export interface SceneStep {
  deviceId: string;
  command: Command;
  label: string;
}

export interface Scene {
  id: string;
  name: string;
  icon: string;
  steps: SceneStep[];
}

export interface SceneReport {
  done: number;
  total: number;
  pending: string[]; // accepted, not yet confirmed
  failed: { label: string; reason: string }[];
}

/** Commands from a scene are valid for this long; a late run is dropped. */
const COMMAND_TTL_MS = 60_000;

export async function runScene(
  scene: Scene,
  home: Home,
  adapters: Partial<Record<Vendor, VendorAdapter>>,
  now: Date = new Date(),
): Promise<SceneReport> {
  const report: SceneReport = { done: 0, total: scene.steps.length, pending: [], failed: [] };
  const expiresAt = new Date(now.getTime() + COMMAND_TTL_MS).toISOString();

  await Promise.all(
    scene.steps.map(async (step) => {
      if (step.command.type === 'unlock') {
        report.failed.push({ label: step.label, reason: 'Aufsperren ist in Szenen nicht erlaubt' });
        return;
      }
      const device = home.devices.find((d) => d.id === step.deviceId);
      const adapter = device && adapters[device.vendor];
      if (!device || !adapter) {
        report.failed.push({ label: step.label, reason: 'nicht verbunden' });
        return;
      }
      let result: CommandResult;
      try {
        result = await adapter.execute({ deviceId: step.deviceId, command: step.command, expiresAt }, now);
      } catch {
        result = { status: 'failed', reason: 'nicht erreichbar' };
      }
      if (result.status === 'done') report.done += 1;
      else if (result.status === 'accepted') report.pending.push(step.label);
      else report.failed.push({ label: step.label, reason: result.reason });
    }),
  );
  return report;
}

export function reportText(r: SceneReport): string {
  if (r.done === r.total) return `Alles erledigt (${r.total} von ${r.total})`;
  const parts = [`${r.done} von ${r.total} erledigt`];
  if (r.pending.length) parts.push(`${r.pending.length} wird bestätigt`);
  for (const f of r.failed) parts.push(`${f.label}: ${f.reason}`);
  return parts.join(', ');
}
