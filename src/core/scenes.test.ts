import { describe, expect, it } from 'vitest';
import { DemoAdapter, demoHome, demoScenes } from '../adapters/demo';
import { reportText, runScene, type Scene } from './scenes';

const now = new Date('2026-11-26T18:00:00Z');

describe('runScene', () => {
  it('runs "Haus verlassen" completely on the demo home', async () => {
    const adapter = new DemoAdapter(() => now);
    const report = await runScene(demoScenes[0], demoHome, { demo: adapter }, now);
    expect(report).toMatchObject({ done: 6, total: 6, failed: [] });
    expect(reportText(report)).toBe('Alles erledigt (6 von 6)');
  });

  it('reports devices that do not answer', async () => {
    const adapter = new DemoAdapter(() => now);
    adapter.setOffline('cover-roof', true);
    const report = await runScene(demoScenes[0], demoHome, { demo: adapter }, now);
    expect(reportText(report)).toBe('5 von 6 erledigt, Dachfenster schließen: nicht erreichbar');
  });

  it('refuses unlock steps', async () => {
    const scene: Scene = {
      id: 'x',
      name: 'X',
      icon: '',
      steps: [{ deviceId: 'lock-front', command: { type: 'unlock', confirmedWith: 'pin' }, label: 'Aufsperren' }],
    };
    const report = await runScene(scene, demoHome, { demo: new DemoAdapter(() => now) }, now);
    expect(report.done).toBe(0);
    expect(report.failed[0].reason).toBe('Aufsperren ist in Szenen nicht erlaubt');
  });

  it('drops expired commands instead of running them late', async () => {
    const adapter = new DemoAdapter(() => now);
    const res = await adapter.execute({ deviceId: 'lock-front', command: { type: 'lock' }, expiresAt: new Date(now.getTime() - 1).toISOString() }, now);
    expect(res).toEqual({ status: 'failed', reason: 'zu spät, nicht ausgeführt' });
  });
});
