// Simple stroke icons (24px grid). Always shown next to a text label.
type Props = { name: IconName; size?: number };
export type IconName = 'home' | 'scenes' | 'devices' | 'more' | 'check' | 'alert' | 'key' | 'moon' | 'arrive' | 'lock' | 'bulb' | 'window' | 'appliance' | 'music' | 'heat' | 'plug' | 'blind' | 'smoke' | 'wifi' | 'cloud';

const paths: Record<IconName, string> = {
  home: 'M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z',
  scenes: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
  devices: 'M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM11 18h2',
  more: 'M5 12h.01M12 12h.01M19 12h.01',
  check: 'M5 12.5l4.5 4.5L19 7.5',
  alert: 'M12 8v5M12 16.5h.01M10.3 3.9L2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
  key: 'M14.5 9.5a4 4 0 1 1-1.2 2.9L4 21.7M7 18.7l2 2M9.5 16.2l2 2',
  moon: 'M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z',
  arrive: 'M3 11l9-7 9 7M6 9.5V20h12V9.5M10 20v-5h4v5',
  lock: 'M6 11h12v9H6zM8.5 11V8a3.5 3.5 0 0 1 7 0v3',
  bulb: 'M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9V16h7v-2.1A6 6 0 0 0 12 3z',
  window: 'M5 3h14v18H5zM5 12h14M12 3v18',
  appliance: 'M5 3h14v18H5zM5 7h14M12 15m-4 0a4 4 0 1 0 8 0a4 4 0 1 0-8 0',
  music: 'M9 18V5l11-2v13M9 18a3 3 0 1 1-6 0a3 3 0 0 1 6 0zM20 16a3 3 0 1 1-6 0a3 3 0 0 1 6 0z',
  heat: 'M14 14.8V4a2 2 0 0 0-4 0v10.8a4 4 0 1 0 4 0z',
  plug: 'M9 3v5M15 3v5M6 8h12v3a6 6 0 0 1-12 0zM12 17v4',
  blind: 'M4 4h16M5 4v10M19 4v10M5 8h14M5 11h14M5 14h14M12 14v5',
  smoke: 'M12 12m-8 0a8 8 0 1 0 16 0a8 8 0 1 0-16 0M12 12m-2 0a2 2 0 1 0 4 0a2 2 0 1 0-4 0',
  wifi: 'M2 8.5a15 15 0 0 1 20 0M5 12a10 10 0 0 1 14 0M8.5 15.5a5 5 0 0 1 7 0M12 19h.01',
  cloud: 'M7 18a4.5 4.5 0 0 1-.5-9A6 6 0 0 1 18 9.5a4.3 4.3 0 0 1-.5 8.5z',
};

export function Icon({ name, size = 24 }: Props) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d={paths[name]} />
    </svg>
  );
}
