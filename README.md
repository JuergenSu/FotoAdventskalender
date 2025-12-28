# Adventskalender Foto-Projekt 2025

Dieses Projekt ist eine einfache, single-file PHP-Webapp fbs einen Foto-Adventskalender. Teilnehmer laden ber einen persfnlichen Token pro Tag ein Bild hoch. Nach dem Upload sehen sie die Galerie aller Teilnehmer fbsen Tag. Ab 25.12. sind alle Galerien dauerhaft sichtbar.

## Hauptfunktionen
- Teilnehmer-Authentifizierung ber Token (`?t=TOKEN`, `tokens.json` fbr Namen)
- Tagesauswahl ber URL-Parameter `day` oder Liste im UI (124)
- Upload pro Tag/Teilnehmer, neuer Upload ersetzt den alten
- Galerie aller Bilder eines Tages (Lightbox)
- Tagesthemen aus `themes.json` (Titel & Beschreibung)
- GD-basierte Bildverarbeitung (EXIF-Rotation, Resize)
- Admin-bersicht & ZIP-Export (Parameter `?admin=ADMIN_KEY`)
- Debug-Zeitbasis via `?d=YYYY-MM-DD[ HH:MM]`

## Datei3struktur
- `index.php`  Hauptanwendung (Upload, Galerie, Admin)
- `missing.php`  Teilnehmer-bersicht ber alle Tage inkl. Status, Thema, Bild-Count; Links zur Detailansicht
- `tokens.json`  Token0Namen-Mapping
- `themes.json`  Tagesthemen (Array oder Map)
- `content/dayXX/`  Bilderablage (`<token>.<ext>`)
- `wallpaper-*.jpg`  Hintergrfde

## Nutzung
1) Rufe `index.php?t=DEIN_TOKEN` auf. Optional: `&day=N` fbr direkten Sprung.
2) Whrend 1.24.12.: Galerie eines Tages erst sichtbar, nachdem du dein Bild fr den Tag hochgeladen hast. Ab 25.12. sind alle sichtbar.
3) Upload ersetzt bestehendes Bild desselben Tokens fr den Tag.
4) Teilnehmer-bersicht: `missing.php?t=DEIN_TOKEN` zeigt alle Tage mit Bildstatus, Thema und Gesamtanzahl der Fotos je Tag.
5) Admin: `index.php?admin=ADMIN_KEY` (bitte `ADMIN_KEY` in `index.php` setzen).

## Konfiguration
- `ADMIN_KEY` in `index.php` anpassen.
- Upload-Grenzen und Bildgrößen in `index.php` (Konstanten `MAX_UPLOAD_BYTES`, `RESIZE_MAX_W/H`).
- Tokens und Themen ber JSON-Dateien pflegen.

## Anforderungen
- PHP mit GD-Extension (fr Resize/Rotation) und optional ZipArchive (fr Admin-Export).
- Schreibrechte im `content/`-Verzeichnis.

## Hinweise
- Dateinamen werden nach Token gesucht; mehrere Endungen erlaubt (jpg/png/gif/webp).
- Debug-Zeit (`?d=`) hilft beim Testen vor oder nach dem eigentlichen Zeitraum.
- Sicherheit: Token validieren sich gegen `tokens.json` (oder sind frei, falls die Datei fehlt).
