<?php
// Adventskalender Foto-Projekt – Single-file PHP App (komplette Version, Themes mit title/description)
// Features:
// - Zeitfenster 01.–24.12.2025
// - Teilnehmer-Identifikation via Token (?t=...)
// - Pro Tag & Teilnehmer ein Bild (Neuupload ersetzt altes)
// - Galerie aller Teilnehmer eines Tages: während 1.–24.12. erst nach eigenem Upload; ab 25.12. immer sichtbar
// - Lightbox
// - Dateien unter /content/dayXX/<token>.<ext>
// - Debug-Zeitbasis via ?d=YYYY-MM-DD[ HH:MM]
// - Token-Whitelist & Anzeigenamen (tokens.json)
// - Tagesthemen (themes.json) mit {title, description}
// - Auto-Resize & EXIF-Rotate (GD)
// - Admin-Ansicht & ZIP-Export (?admin=ADMIN_KEY)

// ================== CONFIG ==================
const TZ = 'Europe/Berlin';
const YEAR = 2025;
const START = '2025-12-01';
const END   = '2025-12-24';
const MAX_DAY = 24;
const TOKEN_PARAM = 't';
const DAY_PARAM   = 'day';
const DEBUG_PARAM = 'd';
const CONTENT_DIR = __DIR__ . '/content';
const TOKENS_JSON = __DIR__ . '/tokens.json'; // optional
const THEMES_JSON = __DIR__ . '/themes.json'; // optional
const ADMIN_KEY   = 'changeme';               // bitte anpassen

$ALLOWED_EXT = ['jpg','jpeg','png','gif','webp'];
$ALLOWED_MIME = [ 'image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp' ];
const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
const RESIZE_MAX_W = 1920;
const RESIZE_MAX_H = 1920;

// ================== HELPERS ==================
function nowBerlin(): DateTime {
  static $cached = null; if ($cached instanceof DateTime) return clone $cached;
  $tz = new DateTimeZone(TZ);
  $raw = isset($_GET[DEBUG_PARAM]) ? trim((string)$_GET[DEBUG_PARAM]) : '';
  if ($raw !== '') { try { $dt = new DateTime($raw, $tz); $dt->setTimezone($tz); $cached = $dt; return clone $cached; } catch (Throwable $e) {} }
  $cached = new DateTime('now', $tz); return clone $cached;
}
function d(string $ymd): DateTime { return new DateTime($ymd, new DateTimeZone(TZ)); }
function isBeforeStart(DateTime $now): bool { return $now < d(START); }
function isDuringWindow(DateTime $now): bool { return $now >= d(START) && $now <= d(END); }
function isAfterEnd(DateTime $now): bool { return $now > d(END); }
function maxVisibleDay(DateTime $now): int { if (isBeforeStart($now)) return 0; if (isDuringWindow($now)) return min((int)$now->format('d'), MAX_DAY); return MAX_DAY; }
function dayToDir(int $day): string { $p = str_pad((string)$day, 2, '0', STR_PAD_LEFT); return CONTENT_DIR . "/day$p"; }
function ensureDir(string $dir): void { if (!is_dir($dir)) @mkdir($dir, 0775, true); }
function sanitizeToken(string $t): string { return preg_replace('~[^a-zA-Z0-9_-]~', '_', $t); }
function participantImagePath(int $day, string $token): ?string { $dir = dayToDir($day); foreach (glob($dir . '/' . $token . '.*') as $file) return $file; return null; }
function listDayImages(int $day): array { $dir = dayToDir($day); if (!is_dir($dir)) return []; $files=[]; foreach (glob($dir.'/*') as $f) if (is_file($f)) $files[] = basename($f); sort($files, SORT_NATURAL|SORT_FLAG_CASE); return $files; }
function deleteExistingForToken(int $day, string $token): void { $dir = dayToDir($day); foreach (glob($dir . '/' . $token . '.*') as $f) @unlink($f); }
function extFromUpload(array $file, array $ALLOWED_MIME, array $ALLOWED_EXT): ?string { if (!empty($file['type']) && isset($ALLOWED_MIME[$file['type']])) return $ALLOWED_MIME[$file['type']]; $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION)); return in_array($ext, $ALLOWED_EXT, true) ? $ext : null; }
function loadJsonFile(string $path) { if (!is_file($path)) return null; $raw=@file_get_contents($path); if($raw===false) return null; $data=json_decode($raw,true); return is_array($data)?$data:null; }
function tokenAllowed(string $token, &$displayName = null): bool {
  $displayName = $token; $data = loadJsonFile(TOKENS_JSON); if ($data === null) return true;
  if (array_is_list($data)) return in_array($token, $data, true);
  if (isset($data[$token])) { $v=$data[$token]; $displayName = is_string($v)&&$v!=='' ? $v : $token; return true; }
  return false;
}
// Themes: supports string or {title, description}
function themeForDayRich(int $day): ?array {
  $themes = loadJsonFile(THEMES_JSON); if (!$themes) return null;
  $item = array_is_list($themes) ? ($themes[$day-1] ?? null) : ($themes[(string)$day] ?? ($themes[$day] ?? null));
  if ($item === null) return null;
  if (is_string($item)) return ['title'=>$item, 'description'=>null];
  if (is_array($item)) {
    $title = $item['title'] ?? ($item[0] ?? null);
    $desc  = $item['description'] ?? ($item[1] ?? null);
    if ($title === null && !empty($item)) { foreach ($item as $v) { if (is_scalar($v)) { $title=(string)$v; break; } } }
    if ($title === null) return null; return ['title'=>(string)$title, 'description'=>($desc!==null?(string)$desc:null)];
  }
  return null;
}

// ===== Bild-Processing (GD) =====
function gdAvailable(): bool { return extension_loaded('gd'); }
function orientJpegIfNeeded($im, string $tmpFile) { if (!function_exists('exif_read_data')) return $im; $ex=@exif_read_data($tmpFile); if(!$ex||empty($ex['Orientation'])) return $im; $o=(int)$ex['Orientation']; switch($o){case 3: return imagerotate($im,180,0); case 6: return imagerotate($im,-90,0); case 8: return imagerotate($im,90,0);} return $im; }
function loadImage(string $path, string $ext) { switch(strtolower($ext)){ case 'jpg':case 'jpeg': return @imagecreatefromjpeg($path); case 'png': return @imagecreatefrompng($path); case 'gif': return @imagecreatefromgif($path); case 'webp': return function_exists('imagecreatefromwebp')?@imagecreatefromwebp($path):false; } return false; }
function saveImage($im, string $path, string $ext): bool { switch(strtolower($ext)){ case 'jpg':case 'jpeg': return @imagejpeg($im,$path,85); case 'png': return @imagepng($im,$path,6); case 'gif': return @imagegif($im,$path); case 'webp': return function_exists('imagewebp')?@imagewebp($im,$path,85):false; } return false; }
function resizeIfNeeded($im) { $w=imagesx($im); $h=imagesy($im); if($w<=RESIZE_MAX_W && $h<=RESIZE_MAX_H) return $im; $scale=min(RESIZE_MAX_W/$w, RESIZE_MAX_H/$h); $nw=max(1,(int)floor($w*$scale)); $nh=max(1,(int)floor($h*$scale)); $dst=imagecreatetruecolor($nw,$nh); imagecopyresampled($dst,$im,0,0,0,0,$nw,$nh,$w,$h); imagedestroy($im); return $dst; }

// ================== INPUTS ==================
$now = nowBerlin();
$visibleMax = maxVisibleDay($now);
$token = isset($_GET[TOKEN_PARAM]) ? sanitizeToken($_GET[TOKEN_PARAM]) : '';
$selectedDay = isset($_GET[DAY_PARAM]) ? (int)$_GET[DAY_PARAM] : 0;
if ($selectedDay < 1 || $selectedDay > MAX_DAY) $selectedDay = $visibleMax > 0 ? $visibleMax : 1;

$errors = [];
$msg = '';
$displayName = $token;

// ================== ACCESS CONTROL ==================
if ($token === '') { http_response_code(403); echo '<!doctype html><meta charset="utf-8"><body style="font:16px system-ui;max-width:700px;margin:3rem auto;padding:1rem"><h1>Adventskalender Foto-Projekt</h1><p>Fehlender Teilnehmer-Link. Bitte verwende deinen persönlichen Link (Parameter <code>?t=DEIN_TOKEN</code>).</p></body>'; exit; }
if (!tokenAllowed($token, $displayName)) { http_response_code(403); echo '<!doctype html><meta charset="utf-8"><body style="font:16px system-ui;max-width:700px;margin:3rem auto;padding:1rem"><h1>Zugriff verweigert</h1><p>Der verwendete Token ist nicht freigeschaltet.</p></body>'; exit; }

// ================== ADMIN ==================
if (isset($_GET['admin']) && hash_equals((string)$_GET['admin'], ADMIN_KEY)) { adminHandle(); exit; }

// ================== UPLOAD HANDLER ==================
$allowedToUpload = false;
if (isDuringWindow($now))      $allowedToUpload = $selectedDay <= $visibleMax;
elseif (isAfterEnd($now))      $allowedToUpload = ($selectedDay >= 1 && $selectedDay <= MAX_DAY);
else                           $allowedToUpload = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
  if (!$allowedToUpload) {
    $errors[] = 'Für diesen Tag sind Uploads aktuell nicht erlaubt.';
  } else {
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
      $errors[] = 'Bitte wähle ein Bild aus (Upload-Fehler).';
    } else {
      if (($_FILES['photo']['size'] ?? 0) > MAX_UPLOAD_BYTES) {
        $errors[] = 'Die Datei ist zu groß (max. 20 MB).';
      } else {
        $ext = extFromUpload($_FILES['photo'], $ALLOWED_MIME, $ALLOWED_EXT);
        if ($ext === null) { $errors[] = 'Nur Bilder (JPEG, PNG, GIF, WEBP) sind erlaubt.'; }
        else {
          $dir = dayToDir($selectedDay); ensureDir($dir);
          $safeToken = $token; deleteExistingForToken($selectedDay, $safeToken);
          $target = $dir . '/' . $safeToken . '.' . $ext;
          $tmp = $_FILES['photo']['tmp_name']; $processed = false;
          if (gdAvailable()) { $im = loadImage($tmp, $ext); if ($im !== false) { if (in_array(strtolower($ext), ['jpg','jpeg'], true)) $im = orientJpegIfNeeded($im, $tmp); $im = resizeIfNeeded($im); $processed = saveImage($im, $target, $ext); imagedestroy($im); } }
          if (!$processed) { if (!move_uploaded_file($tmp, $target)) { $errors[]='Konnte Datei nicht speichern.'; } else { @chmod($target,0644); $msg='Upload erfolgreich! Dein Bild für Tag '.$selectedDay.' wurde gespeichert.'; } }
          else { @chmod($target,0644); $msg='Upload erfolgreich! (automatisch skaliert) Dein Bild für Tag '.$selectedDay.' wurde gespeichert.'; }
        }
      }
    }
  }
}

// ================== STATE FOR UI ==================
$hasOwnImage = participantImagePath($selectedDay, $token) !== null;
$showGallery = $hasOwnImage || isAfterEnd($now);
$dayImages = $showGallery ? listDayImages($selectedDay) : [];
$theme = themeForDayRich($selectedDay);
$missingLink = 'missing.php?' . http_build_query([TOKEN_PARAM => $token, DAY_PARAM => $selectedDay]);

?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Adventskalender Foto-Projekt 2025</title>
  <style>
    :root { --bg:#0f172a; --card:#111827; --muted:#94a3b8; --accent:#e2e8f0; }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body { margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Arial, "Apple Color Emoji", "Segoe UI Emoji"; background: url("wallpaper-p.jpg") center center / cover no-repeat fixed; color: #e5e7eb; }
    @media (orientation: landscape) {  body {   background: url("wallpaper-l.jpg") center center / cover no-repeat fixed;}}
    .wrap { max-width: 1100px; margin: 0 auto; padding: 16px; }
    header { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom: 16px; flex-wrap: wrap; }
    h1 { font-size: clamp(27px, 4vw, 32px); margin: 0; letter-spacing: .3px; color: #424242;}
    .token { color: var(--muted); font-size: 14px; color: #424242;}

    .grid { display:grid; grid-template-columns: 280px 1fr; gap: 16px; align-items: start; }
    .card { background: rgba(17,24,39,.66); border: 1px solid rgba(255,255,255,.06); border-radius: 16px; padding: 16px; box-shadow: 0 10px 30px rgba(0,0,0,.25); backdrop-filter: blur(4px); }

    .days { display:grid; grid-template-columns: repeat(auto-fill, minmax(60px,1fr)); gap: 6px; }
    .daylink { display:block; text-align:center; padding:10px 0; border-radius:10px; background:#0b1020; border:1px solid rgba(255,255,255,.2); color:#e5e7eb; text-decoration:none; font-weight:600; min-height:44px; }
    .daylink.active { outline:2px solid #93c5fd; background:#0c1633; }
    .daylink.disabled { opacity:.35; pointer-events:none; }

    .hint { font-size: 14px; color: var(--muted); }
    .status { margin: 8px 0 12px; padding: 10px 12px; border-radius: 10px; background: rgba(147,197,253,.1); border:1px solid rgba(147,197,253,.25); }
    .error { background: rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.35); }

    .upload { display:flex; flex-direction: column; gap: 12px; }
    input[type=file] { padding:12px; background:#0b1020; color:#e5e7eb; border:1px dashed rgba(255,255,255,.18); border-radius:12px; width:100%; }
    .btn { background:#1f2937; color:#e5e7eb; border:1px solid rgba(255,255,255,.12); padding:12px 14px; border-radius:12px; cursor:pointer; font-weight:600; width:100%; }
    .btn:disabled { opacity:.4; cursor:not-allowed; }

    .gallery { display:grid; grid-template-columns: repeat(auto-fill, minmax(140px,1fr)); gap:10px; }
    .thumb { position:relative; border-radius:12px; overflow:hidden; border:1px solid rgba(255,255,255,.1); background:#0b1020; cursor:pointer; }
    .thumb img { width:100%; height:auto; aspect-ratio: 4/3; object-fit:cover; display:block; }
    .thumb .cap { position:absolute; left:0; bottom:0; width:100%; background:linear-gradient(180deg, rgba(0,0,0,0), rgba(0,0,0,.65)); color:#cbd5e1; font-size:12px; padding:6px 8px; }

    .modal { position:fixed; inset:0; background:rgba(0,0,0,.7); display:none; align-items:center; justify-content:center; padding:20px; }
    .modal.open { display:flex; }
    .modal img { max-width: min(100%, 1200px); max-height: 85vh; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,.6); }
    .modal .close { position:absolute; top:18px; right:18px; background:#111827; border:1px solid rgba(255,255,255,.2); color:#e5e7eb; padding:10px 14px; border-radius:10px; cursor:pointer; }

    footer { margin-top:24px; font-size:12px; color:var(--muted); text-align:center; }
    .pill { display:inline-block; padding:4px 8px; border-radius:999px; border:1px solid rgba(255,255,255,.18); background:#0b1020; font-size:12px; color:#cbd5e1; }

    @media (max-width: 900px) { .wrap { padding: 12px; } .grid { grid-template-columns: 1fr; } aside.card { order: 2; } main.card { order: 1; } }
    @media (max-width: 600px) { header { gap: 8px; } .days { display: grid; } .gallery { grid-template-columns: repeat(auto-fill, minmax(120px,1fr)); gap: 8px; } }
  </style>
</head>
<body>
<div class="wrap">
  <header>
    <div>
      <h1>Adventskalender Foto-Projekt <br/><span style="opacity:.6; font-weight:400;font-size:20px;color:#000;">(1.–24.&nbsp;Dez&nbsp;2025)</span></h1>
      <div class="token">Teilnehmer: <span class="pill"><?php $tmp=$displayName; echo htmlspecialchars($tmp); ?></span> <a class="pill" style="text-decoration:none;" href="<?=$missingLink?>">Tage-Uebersicht</a></div>
    </div>
    <div class="hint">
      <strong><?=$now->format('d.m.Y, H:i')?> Uhr</strong>
      <?php if (!empty($_GET[DEBUG_PARAM])): ?>
        <div class="hint">Simuliert per <code>?<?=DEBUG_PARAM?>=<?=htmlspecialchars($_GET[DEBUG_PARAM])?></code></div>
      <?php endif; ?>
    </div>
  </header>

  <?php if (isBeforeStart($now)): ?>
    <div class="card">
      <h3>Es geht am 1.12.2025 los 🎄</h3>
      <p class="hint">Bis zum Start werden keine Tage angezeigt und keine Uploads akzeptiert.</p>
    </div>
  <?php else: ?>
  <div class="grid">
    <aside class="card">
      <h3 style="margin-top:0">Tage</h3>
      <div class="days">
        <?php for ($d=1; $d<=MAX_DAY; $d++): $visible = $d <= $visibleMax; $isActive = ($d === $selectedDay); if(!$visible && isDuringWindow($now)) continue; ?>
          <a class="daylink <?=$isActive?'active':''?> <?=$visible?'':'disabled'?>" href="?<?=TOKEN_PARAM?>=<?=urlencode($token)?>&<?=DAY_PARAM?>=<?=$d?><?php if(!empty($_GET[DEBUG_PARAM])) echo '&'.DEBUG_PARAM.'='.urlencode($_GET[DEBUG_PARAM]); ?>">Tag <?=$d?></a>
        <?php endfor; ?>
      </div>
      <p class="hint" style="margin-top:10px">
        <?php if (isDuringWindow($now)): ?>Nur vergangene und heutige Tage werden angezeigt. Zukünftige Tage bleiben verborgen.<?php else: ?>Alle 24 Tage sind sichtbar und bearbeitbar.<?php endif; ?>
      </p>
    </aside>

    <main class="card">
      <h3 style="margin-top:0">Tag <?=$selectedDay?><?php if ($theme && !empty($theme['title'])): ?> – <span class="hint" style="font-weight:600;color:#e5e7eb;"><?=htmlspecialchars($theme['title'])?></span><?php endif; ?></h3>
      <?php if ($theme && !empty($theme['description'])): ?>
        <p class="hint" style="margin:-6px 0 10px 0; line-height:1.4;"><?=htmlspecialchars($theme['description'])?></p>
      <?php endif; ?>

      <?php if ($msg): ?><div class="status"><?=$msg?></div><?php endif; ?>
      <?php if ($errors): ?><div class="status error"><?php foreach ($errors as $e) echo '<div>'.htmlspecialchars($e).'</div>'; ?></div><?php endif; ?>

      <?php if ($allowedToUpload): ?>
        <div class="upload">
          <?php if (!$hasOwnImage): ?>
            <p class="hint">Du hast für diesen Tag noch kein Bild. Lade jetzt eines hoch:</p>
          <?php else: ?>
            <p class="hint">Du hast bereits ein Bild für diesen Tag. Ein neuer Upload ersetzt dein bisheriges Bild.</p>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="<?=DAY_PARAM?>" value="<?=$selectedDay?>">
            <input type="hidden" name="action" value="upload">
            <input type="file" name="photo" accept="image/*" required>
            <button class="btn">Bild hochladen</button>
          </form>
        </div>
      <?php else: ?>
        <p class="hint">Uploads sind für diesen Tag derzeit nicht möglich.</p>
      <?php endif; ?>

      <hr style="margin:16px -16px; border:none; border-top:1px solid rgba(255,255,255,.08)">

      <?php if ($showGallery): ?>
        <h4>Galerie aller Teilnehmer für Tag <?=$selectedDay?></h4>
        <?php if (!$dayImages): ?>
          <p class="hint">Noch keine Bilder vorhanden.</p>
        <?php else: ?>
          <div class="gallery">
            <?php foreach ($dayImages as $file): $src = 'content/day' . str_pad((string)$selectedDay,2,'0',STR_PAD_LEFT) . '/' . rawurlencode($file); $whoToken = explode('.', $file)[0]; $whoDisplay = $whoToken; $tmp=null; if (tokenAllowed($whoToken, $tmp)) $whoDisplay = $tmp; ?>
              <div class="thumb" data-src="<?=$src?>">
                <img src="<?=$src?>" alt="Bild von <?=$whoDisplay?>" loading="lazy" decoding="async" sizes="(max-width:600px) 50vw, 150px">
                <div class="cap">Teilnehmer: <code><?=htmlspecialchars($whoDisplay)?></code></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <p class="hint">Sobald du ein Bild für diesen Tag hochgeladen hast, siehst du hier die Galerie aller Teilnehmer.</p>
      <?php endif; ?>
    </main>
  </div>
  <?php endif; ?>

  <footer>
    <p>Ein neuer Upload ersetzt das bestehende Bild desselben Teilnehmers pro Tag. <?php if (gdAvailable()): ?>| Bildverarbeitung aktiv (max <?=RESIZE_MAX_W?>×<?=RESIZE_MAX_H?>)<?php else: ?>| Bildverarbeitung nicht verfügbar (GD fehlt)<?php endif; ?></p>
  </footer>
</div>

<div class="modal" id="modal">
  <button class="close" id="closeModal" aria-label="Schließen">Schließen ✕</button>
  <img id="modalImg" alt="Vorschau">
</div>

<script>
  // Simple lightbox for gallery
  const modal = document.getElementById('modal');
  const modalImg = document.getElementById('modalImg');
  const closeBtn = document.getElementById('closeModal');
  document.addEventListener('click', (e) => {
    const t = e.target.closest('.thumb');
    if (t) { modalImg.src = t.getAttribute('data-src'); modal.classList.add('open'); }
  });
  closeBtn.addEventListener('click', () => modal.classList.remove('open'));
  modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.remove('open'); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') modal.classList.remove('open'); });
</script>
</body>
</html>
<?php
// ================== ADMIN (Übersicht & ZIP) ==================
function adminHandle(): void {
  header('Content-Type: text/html; charset=utf-8');
  $action = $_GET['action'] ?? '';
  if ($action === 'zip') { adminZip(); return; }
  echo '<!doctype html><meta charset="utf-8"><style>body{font:14px system-ui;max-width:980px;margin:20px auto;padding:20px;background:#0e1526;color:#e5e7eb} a{color:#93c5fd} table{width:100%;border-collapse:collapse;margin-top:12px} td,th{border:1px solid rgba(255,255,255,.15);padding:6px 8px} .pill{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.18);background:#0b1020}</style>';
  echo '<h1>Admin – Adventskalender</h1>';
  $now = nowBerlin(); echo '<p>Serverzeit: <strong>'.$now->format('d.m.Y H:i').'</strong></p>';
  echo '<h2>Tage 1–24</h2><table><tr><th>Tag</th><th>Dateipfad</th><th>Anzahl Bilder</th><th>Aktionen</th></tr>';
  for ($day=1; $day<=MAX_DAY; $day++) { $dir = dayToDir($day); ensureDir($dir); $files = listDayImages($day); $cnt = count($files); $linkZipDay = adminLink(['action'=>'zip','day'=>$day]); echo '<tr><td>'.$day.'</td><td><code>'.htmlspecialchars($dir).'</code></td><td>'.$cnt.'</td><td><a href="'.$linkZipDay.'">ZIP Tag '.$day.'</a></td></tr>'; }
  echo '</table>';
  echo '<h2>Teilnehmer (aus vorhandenen Dateien abgeleitet)</h2>';
  $participants = []; for ($day=1; $day<=MAX_DAY; $day++) foreach (listDayImages($day) as $f) { $tok = strtok($f, '.'); $participants[$tok] = true; }
  $tokensData = loadJsonFile(TOKENS_JSON);
  echo '<table><tr><th>Token</th><th>Anzeigename</th></tr>';
  foreach (array_keys($participants) as $tok) { $display = $tok; if (is_array($tokensData)) { if (!array_is_list($tokensData) && isset($tokensData[$tok]) && is_string($tokensData[$tok])) $display = $tokensData[$tok]; } echo '<tr><td><code>'.htmlspecialchars($tok).'</code></td><td>'.htmlspecialchars($display).'</td></tr>'; }
  echo '</table>';
  $linkZipAll = adminLink(['action'=>'zip','day'=>'all']); echo '<p><a href="'.$linkZipAll.'">ZIP – Alle Tage</a></p><p class="pill">Tipp: ZIP-Export benötigt <code>ZipArchive</code> PHP-Extension.</p>';
}
function adminLink(array $params): string { $base = strtok($_SERVER['REQUEST_URI'], '?'); $params['admin'] = ADMIN_KEY; $qs = http_build_query($params); return htmlspecialchars($base.'?'.$qs, ENT_QUOTES); }
function adminZip(): void { if (!class_exists('ZipArchive')) { echo '<p>ZipArchive nicht verfügbar.</p>'; return; } $day = $_GET['day'] ?? 'all'; $zip = new ZipArchive(); $tmp = tempnam(sys_get_temp_dir(), 'advzip_'); $zip->open($tmp, ZipArchive::OVERWRITE); $addDay = function(int $d) use ($zip) { $dir = dayToDir($d); if (!is_dir($dir)) return; foreach (glob($dir.'/*') as $file) { if (!is_file($file)) continue; $local = 'day'.str_pad((string)$d,2,'0',STR_PAD_LEFT).'/'.basename($file); $zip->addFile($file, $local); } }; if ($day === 'all') { for ($d=1;$d<=MAX_DAY;$d++) $addDay($d); $zipName='advent_alle_tage.zip'; } else { $d=(int)$day; $addDay($d); $zipName='advent_tag_'.str_pad((string)$d,2,'0',STR_PAD_LEFT).'.zip'; } $zip->close(); header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.$zipName.'"'); header('Content-Length: '.filesize($tmp)); readfile($tmp); @unlink($tmp); }
?>
