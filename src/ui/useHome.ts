import { useCallback, useEffect, useMemo, useState } from 'react';
import type { Command } from '../core/adapter';
import type { DeviceState } from '../core/model';
import { allOffFixes, evaluateHome } from '../core/status';
import { reportText, runScene, type Scene } from '../core/scenes';
import { DemoAdapter, demoHome, demoScenes } from '../adapters/demo';

const COMMAND_TTL_MS = 30_000;

export function useHome() {
  const [adapter, setAdapter] = useState(() => new DemoAdapter());
  const [states, setStates] = useState<DeviceState[]>([]);
  const [now, setNow] = useState(() => new Date());
  const [busy, setBusy] = useState(false);
  const [sceneReports, setSceneReports] = useState<Record<string, string>>({});
  const home = demoHome;

  const refresh = useCallback(async () => {
    setStates(await adapter.readState(home.devices.map((d) => d.id)));
    setNow(new Date());
  }, [adapter, home]);

  useEffect(() => {
    void refresh();
    const t = setInterval(() => void refresh(), 5000);
    return () => clearInterval(t);
  }, [refresh]);

  const status = useMemo(() => evaluateHome(home, states, now), [home, states, now]);

  const send = useCallback(
    async (deviceId: string, command: Command) => {
      const at = new Date();
      const res = await adapter.execute({ deviceId, command, expiresAt: new Date(at.getTime() + COMMAND_TTL_MS).toISOString() }, at);
      await refresh();
      return res;
    },
    [adapter, refresh],
  );

  const allOff = useCallback(async () => {
    setBusy(true);
    try {
      for (const issue of allOffFixes(status)) await send(issue.deviceId, issue.fix!.command);
    } finally {
      setBusy(false);
    }
  }, [send, status]);

  const playScene = useCallback(
    async (scene: Scene) => {
      setBusy(true);
      try {
        const report = await runScene(scene, home, { demo: adapter });
        setSceneReports((r) => ({ ...r, [scene.id]: reportText(report) }));
        await refresh();
      } finally {
        setBusy(false);
      }
    },
    [adapter, home, refresh],
  );

  const reset = useCallback(() => {
    setAdapter(new DemoAdapter());
    setSceneReports({});
  }, []);

  return { home, states, status, now, busy, send, allOff, scenes: demoScenes, playScene, sceneReports, adapter, refresh, reset };
}

export type HomeApi = ReturnType<typeof useHome>;
