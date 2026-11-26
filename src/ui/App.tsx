import { useState } from 'react';
import type { Device } from '../core/model';
import { Icon, type IconName } from './icons';
import { DevicesScreen, HomeScreen, MoreScreen, ScenesScreen, UnlockSheet } from './screens';
import { useHome } from './useHome';

type Tab = 'home' | 'scenes' | 'devices' | 'more';
const tabs: { id: Tab; label: string; icon: IconName }[] = [
  { id: 'home', label: 'Zuhause', icon: 'home' },
  { id: 'scenes', label: 'Szenen', icon: 'scenes' },
  { id: 'devices', label: 'Geräte', icon: 'devices' },
  { id: 'more', label: 'Mehr', icon: 'more' },
];

export function App() {
  const api = useHome();
  const [tab, setTab] = useState<Tab>('home');
  const [unlocking, setUnlocking] = useState<Device | null>(null);

  return (
    <div className="app">
      <header className="top">
        <picture>
          <source srcSet="./brand/parto-place-logo-dark.svg" media="(prefers-color-scheme: dark)" />
          <img src="./brand/parto-place-logo-light.svg" alt="parto place" className="logo" />
        </picture>
        {api.mode === 'demo' && <span className="demo-badge">Demo</span>}
      </header>

      <main>
        {tab === 'home' && <HomeScreen api={api} />}
        {tab === 'scenes' && <ScenesScreen api={api} />}
        {tab === 'devices' && <DevicesScreen api={api} onUnlock={setUnlocking} />}
        {tab === 'more' && <MoreScreen api={api} />}
      </main>

      <nav className="tabbar" aria-label="Hauptnavigation">
        {tabs.map((t) => (
          <button key={t.id} className={`tab ${tab === t.id ? 'is-active' : ''}`} aria-current={tab === t.id ? 'page' : undefined} onClick={() => setTab(t.id)}>
            <Icon name={t.icon} />
            <span>{t.label}</span>
          </button>
        ))}
      </nav>

      {unlocking && <UnlockSheet device={unlocking} api={api} onClose={() => setUnlocking(null)} />}
    </div>
  );
}
