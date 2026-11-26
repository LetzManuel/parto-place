# Parto Place auf parto.at installieren (Hostprofis, Paket StartUP)

Dauer: etwa 30 Minuten. Du brauchst den Zugang zum Hostprofis-Kundencenter und SSH.
Gib Passwörter, Tokens und den App-Schlüssel nie in einen Chat ein.

## 1. Paket herunterladen

1. Auf GitHub im Repo `LetzManuel/parto-place` → **Actions** → den letzten grünen Lauf von **CI** auf `main` öffnen.
2. Unten unter **Artifacts** `parto-place` herunterladen und entpacken. Darin liegt `parto-place.zip` mit dem Ordner `place/`.

## 2. Subdomain, SSL und PHP im Kundencenter

1. Subdomain **place.parto.at** anlegen.
2. Als Zielordner (Document Root) **`place/public`** wählen. Nur dieser Ordner darf aus dem Internet erreichbar sein.
3. Für die Subdomain **Let's Encrypt** aktivieren.
4. Als PHP-Version **8.3** wählen (8.2 bis 8.5 funktionieren).

## 3. Datenbank anlegen

Im Kundencenter eine neue **MySQL-Datenbank** anlegen. Datenbankname, Benutzer und Passwort notieren; du brauchst sie gleich einmal.

## 4. Dateien hochladen

Den Ordner `place/` aus der Zip-Datei in dein Webspace-Hauptverzeichnis hochladen (z. B. mit FileZilla über FTP), sodass es `place/public/`, `place/src/` usw. gibt.

## 5. Einrichten über SSH

```bash
ssh DEIN-BENUTZER@parto.at
cd place
php tools/init.php
```

Das Skript fragt nach App-Adresse (`https://place.parto.at`), MySQL-Host (meist `localhost`), Datenbankname, Benutzer und Passwort. Es legt die Tabellen an, erzeugt `config.php` mit neuen Schlüsseln und zeigt **einmal** deinen **App-Schlüssel** an.

Den App-Schlüssel nur in Parto Place eintragen (Schritt 7), sonst nirgends speichern. Wenn er verloren geht: `config.php` löschen und `php tools/init.php` erneut ausführen; danach alle Marken neu verbinden.

## 6. Cronjob

Im Kundencenter einen Cronjob anlegen:

- Befehl: `php /PFAD/ZU/place/cron.php` (den vollen Pfad zeigt `pwd` im Ordner `place`)
- Intervall: **alle 5 Minuten**, oder kürzer, wenn das Paket es erlaubt

Der Cronjob fragt Nuki, LG und Spotify ab. Beim Öffnen der App wird zusätzlich sofort abgefragt.

## 7. App verbinden

1. **https://place.parto.at** öffnen (am iPhone: Teilen → „Zum Home-Bildschirm“).
2. **Mehr** → Server-Adresse `https://place.parto.at` und den App-Schlüssel eintragen → **Verbinden**.

## 8. Marken verbinden (Mehr → Verbindungen)

| Marke | Was du brauchst |
| --- | --- |
| Nuki | web.nuki.io → Menü → API → Nutzungsbedingungen akzeptieren, API aktivieren, **API-Token** erstellen. In Parto Place einfügen. |
| LG ThinQ | connect-pat.lgthinq.com → mit ThinQ-Konto anmelden → Token erstellen, alle Berechtigungen auswählen. In Parto Place einfügen. |
| Spotify | developer.spotify.com/dashboard → **Create app** → Redirect URI genau `https://place.parto.at/spotify-callback.php`, API „Web API“ ankreuzen → speichern. Die **Client ID** in Parto Place einfügen und bei Spotify anmelden. Braucht Spotify Premium. |

Tokens werden verschlüsselt auf deinem Server gespeichert und nie wieder angezeigt. „Trennen“ löscht sie sofort.

## Updates

Neues Paket herunterladen (Schritt 1) und alles außer `config.php` überschreiben. Die Datenbank bleibt erhalten.

## Wenn etwas nicht geht

- **„Nicht angemeldet“** in der App: App-Schlüssel falsch. Neu einrichten, siehe Schritt 5.
- **„Serverfehler“**: Im Kundencenter das PHP-Fehlerprotokoll ansehen. Einträge beginnen mit `parto-place:` und enthalten nie Tokens.
- **Marke zeigt „Zugang abgelehnt“**: Token in der App unter „Trennen“ entfernen und neu verbinden.
