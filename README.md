# Adventskalender Foto-Projekt 2025

Dieses Projekt ist eine einfache, single-file PHP-Webapp für einen Foto-Adventskalender. Teilnehmer laden über einen persönlichen Token pro Tag ein Bild hoch. Nach dem Upload sehen sie die Galerie aller Teilnehmer für diesen Tag. Ab 25.12. sind alle Galerien dauerhaft sichtbar.

## Hauptfunktionen
- Teilnehmer-Authentifizierung über Token (`?t=TOKEN`, `tokens.json` für Namen)
- Tagesauswahl über URL-Parameter `day` oder Liste im UI (1–24)
- Upload pro Tag/Teilnehmer, neuer Upload ersetzt den alten
- Galerie aller Bilder eines Tages (Lightbox)
- Tagesthemen aus `themes.json` (Titel & Beschreibung)
- GD-basierte Bildverarbeitung (EXIF-Rotation, Resize)
- Admin-Übersicht & ZIP-Export (Parameter `?admin=ADMIN_KEY`)
- Debug-Zeitbasis via `?d=YYYY-MM-DD[ HH:MM]`

## Dateistruktur
- `index.php` – Hauptanwendung (Upload, Galerie, Admin)
- `missing.php` – Teilnehmer-Übersicht über alle Tage inkl. Status, Thema, Bild-Count; Links zur Detailansicht
- `tokens.json` – Token→Namen-Mapping
- `themes.json` – Tagesthemen (Array oder Map)
- `content/dayXX/` – Bilderablage (`<token>.<ext>`)
- `wallpaper-*.jpg` – Hintergründe

## Nutzung
1) Rufe `index.php?t=DEIN_TOKEN` auf. Optional: `&day=N` für direkten Sprung.
2) Während 1.–24.12.: Galerie eines Tages erst sichtbar, nachdem du dein Bild für den Tag hochgeladen hast. Ab 25.12. sind alle sichtbar.
3) Upload ersetzt bestehendes Bild desselben Tokens für den Tag.
4) Teilnehmer-Übersicht: `missing.php?t=DEIN_TOKEN` zeigt alle Tage mit Bildstatus, Thema und Gesamtanzahl der Fotos je Tag.
5) Admin: `index.php?admin=ADMIN_KEY` (bitte `ADMIN_KEY` in `index.php` setzen).

## Konfiguration
- `ADMIN_KEY` in `index.php` anpassen.
- Upload-Grenzen und Bildgrößen in `index.php` (Konstanten `MAX_UPLOAD_BYTES`, `RESIZE_MAX_W/H`).
- Tokens und Themen über JSON-Dateien pflegen.

## Anforderungen
- PHP mit GD-Extension (für Resize/Rotation) und optional ZipArchive (für Admin-Export).
- Schreibrechte im `content/`-Verzeichnis.

## Hinweise
- Dateinamen werden nach Token gesucht; mehrere Endungen erlaubt (jpg/png/gif/webp).
- Debug-Zeit (`?d=`) hilft beim Testen vor oder nach dem eigentlichen Zeitraum.
- Sicherheit: Token validieren sich gegen `tokens.json` (oder sind frei, falls die Datei fehlt).
