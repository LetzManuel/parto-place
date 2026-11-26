import { afterEach, describe, expect, it, vi } from 'vitest';
import { defaultScenes, ServerClient } from './server';

afterEach(() => vi.unstubAllGlobals());

function stubFetch(status: number, body: unknown) {
  const fn = vi.fn(async () => new Response(JSON.stringify(body), { status }));
  vi.stubGlobal('fetch', fn);
  return fn;
}

describe('ServerClient', () => {
  it('sends the app key and groups devices by room', async () => {
    const fetch = stubFetch(200, {
      devices: [
        { id: 'nuki:1', vendor: 'nuki', kind: 'lock', name: 'Haustür', room: 'Vorraum' },
        { id: 'lg:w', vendor: 'lg', kind: 'appliance', name: 'Waschmaschine', room: '' },
      ],
      states: [],
      links: [],
    });
    const { home } = await new ServerClient({ url: 'https://place.example/', appKey: 'k' }).status(true);
    expect(fetch).toHaveBeenCalledWith('https://place.example/api.php?r=status&refresh=1', expect.objectContaining({ headers: expect.objectContaining({ Authorization: 'Bearer k' }) }));
    expect(home.rooms.map((r) => r.name)).toEqual(['Vorraum', 'Ohne Raum']);
    expect(home.devices[1].roomId).toBe('Ohne Raum');
  });

  it('turns server errors into readable messages', async () => {
    stubFetch(401, { error: 'Nicht angemeldet' });
    await expect(new ServerClient({ url: '', appKey: 'x' }).status()).rejects.toThrow('Nicht angemeldet');
  });

  it('reports a failed command with its reason', async () => {
    stubFetch(200, { status: 'failed', reason: 'zu spät, nicht ausgeführt' });
    const r = await new ServerClient({ url: '', appKey: 'k' }).execute({ deviceId: 'nuki:1', command: { type: 'lock' }, expiresAt: '2026-01-01T00:00:00Z' });
    expect(r).toEqual({ status: 'failed', reason: 'zu spät, nicht ausgeführt' });
  });
});

describe('defaultScenes', () => {
  it('locks every lock and pauses music, never unlocks', () => {
    const scenes = defaultScenes({
      id: 'h', name: 'H', rooms: [],
      devices: [
        { id: 'nuki:1', vendor: 'nuki', kind: 'lock', name: 'Haustür', roomId: 'x' },
        { id: 'spotify:player', vendor: 'spotify', kind: 'media', name: 'Spotify', roomId: 'x' },
      ],
    });
    expect(scenes.map((s) => s.name)).toEqual(['Haus verlassen', 'Gute Nacht']);
    expect(scenes[0].steps.map((s) => s.command.type)).toEqual(['lock', 'pause']);
    expect(scenes.flatMap((s) => s.steps).some((s) => s.command.type === 'unlock')).toBe(false);
  });
});
