import { describe, expect, it } from 'vitest';
import { DemoAdapter, demoHome } from '../adapters/demo';
import { allOffFixes, evaluateHome } from './status';
import type { DeviceState } from './model';

const now = new Date('2026-11-26T18:00:00Z');
const ids = demoHome.devices.map((d) => d.id);

async function states(adapter: DemoAdapter): Promise<DeviceState[]> {
  return adapter.readState(ids);
}

describe('evaluateHome', () => {
  it('lists open issues of the demo home and is not ok', async () => {
    const status = evaluateHome(demoHome, await states(new DemoAdapter(() => now)), now);
    expect(status.ok).toBe(false);
    const texts = status.issues.map((i) => i.text);
    expect(texts).toContain('Haustür nicht abgesperrt');
    expect(texts).toContain('Licht Küche an');
    expect(texts).toContain('Fenster Bad gekippt');
    expect(texts).toContain('Dachfenster offen');
    expect(texts).toContain('Waschmaschine fertig');
  });

  it('never reports ok when a device does not answer', async () => {
    const adapter = new DemoAdapter(() => now);
    const all = await states(adapter);
    const withoutLock = all.filter((s) => s.deviceId !== 'lock-front');
    const status = evaluateHome(demoHome, withoutLock, now);
    expect(status.issues.find((i) => i.deviceId === 'lock-front')).toMatchObject({ stale: true, text: 'Haustür: keine Verbindung' });
  });

  it('treats an old state as no connection', async () => {
    const old = new Date(now.getTime() - 60 * 60000);
    const status = evaluateHome(demoHome, await states(new DemoAdapter(() => old)), now);
    expect(status.issues.every((i) => i.stale)).toBe(true);
    expect(status.ok).toBe(false);
  });

  it('is ok after "Alles aus" and closing the bath window by hand', async () => {
    const adapter = new DemoAdapter(() => now);
    const before = evaluateHome(demoHome, await states(adapter), now);
    for (const issue of allOffFixes(before)) {
      const res = await adapter.execute({ deviceId: issue.deviceId, command: issue.fix!.command, expiresAt: new Date(now.getTime() + 60000).toISOString() }, now);
      expect(res.status).toBe('done');
    }
    adapter.setValues('contact-bath', { kind: 'contact', contact: 'closed' });
    const after = evaluateHome(demoHome, await states(adapter), now);
    expect(after.issues.map((i) => i.text)).toEqual(['Waschmaschine fertig']);
    expect(after.ok).toBe(true);
  });

  it('sorts alarms first', async () => {
    const adapter = new DemoAdapter(() => now);
    adapter.setValues('smoke-kitchen', { kind: 'sensor', alarm: true, label: 'smoke' });
    const status = evaluateHome(demoHome, await states(adapter), now);
    expect(status.issues[0].text).toBe('Rauchalarm: Rauchmelder Küche');
  });

  it('never offers unlocking as a fix', async () => {
    const status = evaluateHome(demoHome, await states(new DemoAdapter(() => now)), now);
    expect(status.issues.some((i) => i.fix?.command.type === 'unlock')).toBe(false);
  });
});
