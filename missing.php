<?php
// Uebersicht pro Teilnehmer: listet alle Tage mit vorhandenem Foto oder dem Hinweis "fehlt".

const TOKEN_PARAM  = 't';
const MAX_DAY      = 24;
const CONTENT_DIR  = __DIR__ . '/content';
const TOKENS_JSON  = __DIR__ . '/tokens.json';

function sanitizeToken(string $t): string {
    return preg_replace('~[^a-zA-Z0-9_-]~', '_', $t);
}

function dayToDir(int $day): string {
    $p = str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    return CONTENT_DIR . '/day' . $p;
}

function participantImagePath(int $day, string $token): ?string {
    $dir = dayToDir($day);
    foreach (glob($dir . '/' . $token . '.*') as $file) {
        return $file;
    }
    return null;
}

function loadJsonFile(string $path) {
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function tokenAllowed(string $token, &$displayName = null): bool {
    $displayName = $token;
    $data = loadJsonFile(TOKENS_JSON);
    if ($data === null) {
        return true;
    }
    if (array_is_list($data)) {
        return in_array($token, $data, true);
    }
    if (isset($data[$token])) {
        $v = $data[$token];
        $displayName = is_string($v) && $v !== '' ? $v : $token;
        return true;
    }
    return false;
}

$token = isset($_GET[TOKEN_PARAM]) ? sanitizeToken((string) $_GET[TOKEN_PARAM]) : '';
if ($token === '') {
    http_response_code(400);
    echo '<!doctype html><meta charset="utf-8"><body style="font:15px system-ui;max-width:720px;margin:40px auto;padding:16px"><h1>Teilnehmer-Token fehlt</h1><p>Bitte rufen Sie die Seite mit Ihrem persoenlichen Link auf: <code>?t=DEIN_TOKEN</code>.</p></body>';
    exit;
}

$displayName = $token;
if (!tokenAllowed($token, $displayName)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body style="font:15px system-ui;max-width:720px;margin:40px auto;padding:16px"><h1>Zugriff verweigert</h1><p>Der verwendete Token ist nicht freigeschaltet.</p></body>';
    exit;
}

$days = [];
for ($d = 1; $d <= MAX_DAY; $d++) {
    $path = participantImagePath($d, $token);
    $days[] = [
        'day' => $d,
        'exists' => $path !== null,
        'src' => $path ? 'content/day' . str_pad((string) $d, 2, '0', STR_PAD_LEFT) . '/' . basename($path) : null,
    ];
}

$missingCount = count(array_filter($days, static fn($item) => !$item['exists']));
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Adventskalender - Uebersicht fuer <?=$token?></title>
  <style>
    :root { --bg:#0f172a; --card:#111827; --muted:#94a3b8; --ok:#22c55e; --missing:#ef4444; }
    * { box-sizing:border-box; }
    body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color:#e5e7eb; }
    .wrap { max-width: 980px; margin: 0 auto; padding: 20px; }
    h1 { margin: 0 0 4px 0; font-size: clamp(22px, 4vw, 28px); }
    .card { background: rgba(17,24,39,.85); border:1px solid rgba(255,255,255,.08); border-radius:14px; padding:16px; margin-top:14px; box-shadow:0 10px 30px rgba(0,0,0,.25); }
    .grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:12px; }
    .day { border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden; background:#0b1020; }
    .daylink { color:inherit; text-decoration:none; display:block; height:100%; }
    .day header { display:flex; justify-content:space-between; padding:10px 12px; align-items:center; font-weight:600; }
    .badge { display:inline-block; padding:4px 8px; border-radius:999px; font-size:12px; border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.05); }
    .badge.ok { border-color:rgba(34,197,94,.4); color:#bbf7d0; }
    .badge.missing { border-color:rgba(239,68,68,.5); color:#fecdd3; }
    .thumb { background:#0b1020; display:block; }
    .thumb img { width:100%; height:auto; display:block; aspect-ratio:4/3; object-fit:cover; }
    .missing-note { padding:24px 12px; text-align:center; color: var(--muted); }
    .summary { color: var(--muted); margin-top:8px; }
    @media (max-width: 640px) { .grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); } }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Fotos fuer <?=$displayName?></h1>
      <div class="summary">
        <?php if ($missingCount === 0): ?>
          Alle Tage sind befuellt.
        <?php else: ?>
          <?=$missingCount?> von <?=MAX_DAY?> Tagen fehlen noch.
        <?php endif; ?>
      </div>
    </div>

    <div class="grid">
      <?php foreach ($days as $info): $detailLink = 'index.php?'.http_build_query(['t'=>$token, 'day'=>$info['day']]); ?>
        <div class="day">
          <a class="daylink" href="<?=$detailLink?>">
            <header>
              <span>Tag <?=$info['day']?></span>
              <?php if ($info['exists']): ?>
                <span class="badge ok">vorhanden</span>
              <?php else: ?>
                <span class="badge missing">fehlt</span>
              <?php endif; ?>
            </header>
            <?php if ($info['exists']): ?>
              <div class="thumb">
                <img src="<?=$info['src']?>" alt="Foto Tag <?=$info['day']?> von <?=$displayName?>" loading="lazy">
              </div>
            <?php else: ?>
              <div class="missing-note">Kein Foto hochgeladen.</div>
            <?php endif; ?>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</body>
</html>
