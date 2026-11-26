import { useCallback, useEffect, useMemo, useState } from 'react';
import type { Command, CommandResult, VendorAdapter } from '../core/adapter';
import type { DeviceState, Home, Vendor } from '../core/model';
import { allOffFixes, evaluateHome } from '../core/status';
import { reportText, runScene, type Scene } from '../core/scenes';
import { DemoAdapter, demoHome, demoScenes } from '../adapters/demo';
import { defaultScenes, loadSettings, saveSettings, ServerClient, type ServerSettings, type VendorLink } from '../adapters/server';

const COMMAND_TTL_MS = 30_000;
const REFRESH_MS = 15_000;
const EMPTY_HOME: Home = { id: 'server', name: 'Mein Zuhause', rooms: [], devices: [] };

export function useHome() {
  const [settings, setSettingsState] = useState<ServerSettings | null>(() => loadSettings());
  const [demoAdapter, setDemoAdapter] = useState(() => new DemoAdapter());
  const client = useMemo(() => (settings ? new ServerClient(settings) : null), [settings]);
  const mode: 'demo' | 'server' = client ? 'server' : 'demo';

  const [serverHome, setServerHome] = useState<Home>(EMPTY_HOME);
  const [links, setLinks] = useState<VendorLink[]>([]);
  const [states, setStates] = useState<DeviceState[]>([]);
  const [serverError, setServerError] = useState<string | null>(null);
  const [now, setNow] = useState(() => new Date());
  const [busy, setBusy] = useState(false);
  const [sceneReports, setSceneReports] = useState<Record<string, string>>({});

  const home = mode === 'server' ? serverHome : demoHome;

  const refresh = useCallback(
    async (fromVendors = false) => {
      if (client) {
        try {
          const s = await client.status(fromVendors);
          setServerHome(s.home);
          setStates(s.states);
          setLinks(s.links);
          setServerError(null);
        } catch (e) {
          setServerError((e as Error).message);
        }
      } else {
        setStates(await demoAdapter.readState(demoHome.devices.map((d) => d.id)));
      }
      setNow(new Date());
    },
    [client, demoAdapter],
  );

  useEffect(() => {
    void refresh(true); // opening the app asks the vendors once
    const t = setInterval(() => void refresh(), REFRESH_MS);
    return () => clearInterval(t);
  }, [refresh]);

  const status = useMemo(() => {
    const s = evaluateHome(home, states, now);
    // Without a reachable server, nothing is "ok".
    return serverError && mode === 'server' ? { ...s, ok: false } : s;
  }, [home, states, now, serverError, mode]);

  /** One adapter facade per vendor, so scenes work the same in demo and server mode. */
  const adapters = useMemo((): Partial<Record<Vendor, VendorAdapter>> => {
    if (!client) return { demo: demoAdapter };
    const facade: VendorAdapter = {
      vendor: 'nuki',
      discover: async () => [],
      readState: async () => [],
      execute: (req) => client.execute(req).catch((e: Error) => ({ status: 'failed', reason: e.message }) as CommandResult),
    };
    return { nuki: facade, lg: facade, spotify: facade, bosch: facade, somfy: facade, velux: facade };
  }, [client, demoAdapter]);

  const send = useCallback(
    async (deviceId: string, command: Command): Promise<CommandResult> => {
      const device = home.devices.find((d) => d.id === deviceId);
      const adapter = device && adapters[device.vendor];
      if (!adapter) return { status: 'failed', reason: 'nicht verbunden' };
      const res = await adapter.execute({ deviceId, command, expiresAt: new Date(Date.now() + COMMAND_TTL_MS).toISOString() });
      await refresh(res.status === 'accepted');
      return res;
    },
    [adapters, home, refresh],
  );

  const allOff = useCallback(async () => {
    setBusy(true);
    try {
      for (const issue of allOffFixes(status)) await send(issue.deviceId, issue.fix!.command);
    } finally {
      setBusy(false);
    }
  }, [send, status]);

  const scenes: Scene[] = useMemo(() => (mode === 'server' ? defaultScenes(serverHome) : demoScenes), [mode, serverHome]);

  const playScene = useCallback(
    async (scene: Scene) => {
      setBusy(true);
      try {
        const report = await runScene(scene, home, adapters);
        setSceneReports((r) => ({ ...r, [scene.id]: reportText(report) }));
        await refresh(report.pending.length > 0);
      } finally {
        setBusy(false);
      }
    },
    [adapters, home, refresh],
  );

  const resetDemo = useCallback(() => {
    setDemoAdapter(new DemoAdapter());
    setSceneReports({});
  }, []);

  const setSettings = useCallback((s: ServerSettings | null) => {
    saveSettings(s);
    setSettingsState(s);
    setStates([]);
    setSceneReports({});
  }, []);

  return {
    mode, home, states, status, now, busy, send, allOff, scenes, playScene, sceneReports,
    demoAdapter, resetDemo, refresh, settings, setSettings, client, links, serverError,
  };
}

export type HomeApi = ReturnType<typeof useHome>;
