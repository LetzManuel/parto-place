# Parto Place

One app to see and control every smart-home device of a home: "Alles ok?" at a glance, "Alles aus" in one tap. Part of the Parto private app line.

Without a server the app shows a **fictional demo home**. Connected to its server (PHP on parto.at), it shows the real devices.

## Run

```bash
npm install
npm run dev        # http://localhost:5173
npm test           # core logic (vitest)
npm run build      # static files in dist/
npm run test:server  # PHP server tests (php 8.2+ with sodium, pdo_sqlite)
npm run package    # release/parto-place.zip = server + built app
```

Installation on parto.at: [docs/INSTALL-hostprofis.md](docs/INSTALL-hostprofis.md) (German).

## Structure

- `src/core/` – UI-free logic: device model, "Alles ok?" evaluation, scenes, adapter interface. Unit-tested.
- `src/adapters/` – one adapter per vendor. Today only `demo.ts` (fictional home).
- `src/ui/` – React screens: Zuhause, Szenen, Geräte, Mehr.
- `public/brand/` – Parto Place logo line (logo, mark, app icon; light and dark).
- `server/` – PHP 8.2+ server for shared hosting: JSON API (`public/api.php`), encrypted vendor tokens in MySQL, cron poller (`cron.php`), adapters for Nuki, LG ThinQ and Spotify. Only `server/public/` is served.

## Planned connections

| Vendor | Path | Reach |
| --- | --- | --- |
| Nuki | Nuki Web API, personal API token | cloud (server) |
| LG ThinQ | ThinQ Connect, personal access token | cloud (server) |
| Spotify | Web API (Premium, development mode) | cloud (server) |
| Bosch Smart Home | local controller API (private use) | home Wi-Fi |
| Somfy TaHoma | Developer Mode local API | home Wi-Fi |
| Velux | HomeKit (iPhone/iPad) | home Wi-Fi |

## Rules

- Never commit tokens, passwords or keys. Vendor credentials are entered by the owner in the app and stay in the device keychain or the server's encrypted store.
- Demo and screenshot data is fictional.
- Unlocking is never part of a scene or "Alles aus" and always needs a confirmed tap.
- Commands that could not run in time are dropped, never run late.
