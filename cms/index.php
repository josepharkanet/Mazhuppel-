<?php
/**
 * Mazhuppel Chits — simple content dashboard.
 * ------------------------------------------------------------------
 * A tiny, self-contained CMS (no database, no framework) that lets you
 * add / edit / delete the three dynamic sections of the website:
 *     • New Chits      -> ../content/new-chits.json
 *     • Careers        -> ../content/careers.json
 *     • Gallery        -> ../content/gallery.json
 * Images are uploaded to ../uploads/ . The public website reads these
 * same JSON files at runtime, so your changes appear live — no rebuild.
 *
 * ------------------------------------------------------------------
 * SETUP (do this once, after uploading to public_html/cms/):
 *   1. Change CMS_PASSWORD below to your own strong password.
 *   2. Make sure ../content and ../uploads are writable by PHP
 *      (in cPanel File Manager set both folders to permission 755).
 *   3. Visit  https://mazhuppelchits.com/cms/  and log in.
 * ------------------------------------------------------------------
 */
declare(strict_types=1);

/* ===== 1. CHANGE THIS PASSWORD ===================================== */
const CMS_PASSWORD = '__SET_ON_SERVER__';
/* ================================================================== */

const CONTENT_DIR = __DIR__ . '/../content';
const UPLOAD_DIR  = __DIR__ . '/../uploads';
const UPLOAD_URL  = '/uploads';
const APP_DIR     = __DIR__ . '/../../applications'; // job applications store (outside web root)

/* Section definitions — field lists drive the forms below. */
$TYPES = [
    'hero' => [
        'label'  => 'Home Hero',
        'fields' => ['title'],
    ],
    'new-chits' => [
        'label'  => 'New Kuries',
        'fields' => ['title', 'href'],
    ],
    'careers' => [
        'label'  => 'Careers',
        'fields' => ['title', 'summary', 'locations'],
    ],
    'gallery' => [
        'label'  => 'Gallery',
        'fields' => ['title'],
    ],
];

/* ---------- session + helpers ------------------------------------- */
session_start();

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}
function csrf_ok(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], (string) $_POST['csrf']);
}

function is_authed(): bool { return !empty($_SESSION['cms_auth']); }

function redirect(string $qs = ''): void {
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . $qs);
    exit;
}

function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s !== '' ? $s : 'item';
}

function load_json(string $type): array {
    $f = CONTENT_DIR . "/$type.json";
    if (!is_file($f)) return [];
    $data = json_decode((string) file_get_contents($f), true);
    return is_array($data) ? $data : [];
}

function save_json(string $type, array $rows): bool {
    if (!is_dir(CONTENT_DIR)) { @mkdir(CONTENT_DIR, 0755, true); }
    $f    = CONTENT_DIR . "/$type.json";
    $json = json_encode(array_values($rows),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    $tmp = $f . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $f);
}

/** Handle an <input type=file name=image>. Returns a public URL or null. */
function handle_upload(string $field, ?string &$err): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // nothing uploaded
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) { $err = 'Upload failed (code ' . $f['error'] . ').'; return null; }
    if ($f['size'] > 8 * 1024 * 1024)  { $err = 'Image too large (max 8 MB).'; return null; }

    $info = @getimagesize($f['tmp_name']);
    $allow = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if ($info === false || !isset($allow[$info[2]])) { $err = 'Only JPG, PNG, GIF or WEBP images are allowed.'; return null; }

    if (!is_dir(UPLOAD_DIR)) { @mkdir(UPLOAD_DIR, 0755, true); }
    $base = slugify(pathinfo($f['name'], PATHINFO_FILENAME));
    $name = $base . '-' . bin2hex(random_bytes(3)) . '.' . $allow[$info[2]];
    $dest = UPLOAD_DIR . '/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $dest)) { $err = 'Could not save the uploaded image.'; return null; }
    @chmod($dest, 0644);
    return UPLOAD_URL . '/' . $name;
}

/** Handle a multi-file <input type=file name="photos[]" multiple>. Returns array of URLs. */
function handle_multi_upload(string $field, ?string &$err): array {
    $urls = [];
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) { return $urls; }
    $files = $_FILES[$field];
    $allow = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!is_dir(UPLOAD_DIR)) { @mkdir(UPLOAD_DIR, 0755, true); }
    for ($i = 0, $n = count($files['name']); $i < $n; $i++) {
        if ((int) $files['error'][$i] === UPLOAD_ERR_NO_FILE) { continue; }
        if ($files['error'][$i] !== UPLOAD_ERR_OK) { $err = 'One of the photos failed to upload.'; continue; }
        if ($files['size'][$i] > 8 * 1024 * 1024) { $err = 'A photo is too large (max 8 MB each).'; continue; }
        $info = @getimagesize($files['tmp_name'][$i]);
        if ($info === false || !isset($allow[$info[2]])) { $err = 'Only JPG, PNG, GIF or WEBP photos are allowed.'; continue; }
        $base = slugify(pathinfo($files['name'][$i], PATHINFO_FILENAME));
        $name = $base . '-' . bin2hex(random_bytes(3)) . '.' . $allow[$info[2]];
        $dest = UPLOAD_DIR . '/' . $name;
        if (move_uploaded_file($files['tmp_name'][$i], $dest)) { @chmod($dest, 0644); $urls[] = UPLOAD_URL . '/' . $name; }
    }
    return $urls;
}

/* ---------- routing ----------------------------------------------- */
$action = $_GET['action'] ?? '';
$type   = $_GET['type'] ?? '';
$notice = '';

/* login / logout */
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hash_equals(CMS_PASSWORD, (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['cms_auth'] = true;
        redirect();
    }
    usleep(600000); // slow down brute force
    $notice = 'Wrong password.';
}
if ($action === 'logout') { $_SESSION = []; session_destroy(); redirect(); }

/* everything past here needs auth */
if (!is_authed()) {
    render_login($notice);
    exit;
}

/* ---- Job applications inbox (stored outside public_html) --------- */
function load_apps(): array {
    $f = APP_DIR . '/applications.json';
    if (!is_file($f)) { return []; }
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function save_apps(array $rows): void {
    if (!is_dir(APP_DIR)) { @mkdir(APP_DIR, 0700, true); }
    @file_put_contents(APP_DIR . '/applications.json',
        json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/* secure CV download — served only to a logged-in dashboard user */
if ($action === 'cv') {
    $file = basename((string) ($_GET['file'] ?? ''));
    $path = APP_DIR . '/cv/' . $file;
    if ($file !== '' && is_file($path)) {
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Robots-Tag: noindex');
        readfile($path);
        exit;
    }
    http_response_code(404);
    exit('CV not found.');
}

/* delete one application (and its stored CV) */
if ($action === 'delapp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { http_response_code(400); exit('Bad request token.'); }
    $id   = (string) ($_POST['id'] ?? '');
    $rows = load_apps();
    foreach ($rows as $r) {
        if (($r['id'] ?? '') === $id && !empty($r['cv'])) { @unlink(APP_DIR . '/cv/' . basename((string) $r['cv'])); }
    }
    $rows = array_values(array_filter($rows, fn($r) => ($r['id'] ?? '') !== $id));
    save_apps($rows);
    redirect('?type=applications&msg=' . urlencode('Deleted.'));
}

if (!isset($TYPES[$type]) && !in_array($type, ['applications', 'agreements'], true)) { $type = 'new-chits'; }

/* save (add or edit) */
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { http_response_code(400); exit('Bad request token.'); }
    $rows   = load_json($type);
    $editId = trim((string) ($_POST['id'] ?? ''));
    $upErr  = null;

    // find existing (edit) to preserve images if none uploaded
    $existing = null;
    foreach ($rows as $r) { if (($r['id'] ?? '') === $editId) { $existing = $r; break; } }

    $row = ['id' => '', 'title' => trim((string) ($_POST['title'] ?? ''))];

    if ($type === 'new-chits') {
        // New Kuri: two modes — design (fields) or poster (uploaded image)
        $mode = (($_POST['mode'] ?? 'design') === 'poster') ? 'poster' : 'design';
        $row['type'] = $mode;
        if ($mode === 'design') {
            $row['amount']     = trim((string) ($_POST['amount'] ?? ''));
            $row['duration']   = trim((string) ($_POST['duration'] ?? ''));
            $row['commission'] = trim((string) ($_POST['commission'] ?? ''));
            $row['badge']      = trim((string) ($_POST['badge'] ?? ''));
            $lines = preg_split('/\r\n|\r|\n/', (string) ($_POST['features'] ?? '')) ?: [];
            $row['features'] = array_values(array_filter(array_map('trim', $lines)));
            // optional image shown on top of the card
            $img = handle_upload('image', $upErr);
            $imgVal = $img ?? ($existing['image'] ?? '');
            if ($imgVal !== '') { $row['image'] = $imgVal; }
        } else {
            $img    = handle_upload('image', $upErr);
            $imgMob = $upErr ? null : handle_upload('imageMobile', $upErr);
            $row['image'] = $img ?? ($existing['image'] ?? '');
            $mob = $imgMob ?? ($existing['imageMobile'] ?? '');
            if ($mob !== '') { $row['imageMobile'] = $mob; }
        }
    } elseif ($type === 'hero') {
        // Home hero slide: background image + eyebrow + title + subtitle
        $row['eyebrow']  = trim((string) ($_POST['eyebrow'] ?? ''));
        $row['subtitle'] = trim((string) ($_POST['subtitle'] ?? ''));
        $img = handle_upload('image', $upErr);
        $row['image'] = $img ?? ($existing['image'] ?? '');
    } elseif ($type === 'careers') {
        // Careers: two modes — design (job post fields) or poster (uploaded image)
        $mode = (($_POST['mode'] ?? 'design') === 'poster') ? 'poster' : 'design';
        $row['type'] = $mode;
        if ($mode === 'design') {
            $row['employmentType'] = trim((string) ($_POST['employmentType'] ?? ''));
            $row['locations'] = array_values(array_filter(array_map('trim',
                explode(',', (string) ($_POST['locations'] ?? '')))));
            $row['summary'] = trim((string) ($_POST['summary'] ?? ''));
            // optional image shown beside the job post
            $img = handle_upload('image', $upErr);
            $imgVal = $img ?? ($existing['image'] ?? '');
            if ($imgVal !== '') { $row['image'] = $imgVal; }
        } else {
            $img    = handle_upload('image', $upErr);
            $imgMob = $upErr ? null : handle_upload('imageMobile', $upErr);
            $row['image'] = $img ?? ($existing['image'] ?? '');
            $mob = $imgMob ?? ($existing['imageMobile'] ?? '');
            if ($mob !== '') { $row['imageMobile'] = $mob; }
        }
    } elseif ($type === 'gallery') {
        // Event album: title, date, description, featured image + many photos
        $row['date'] = trim((string) ($_POST['date'] ?? ''));
        $row['description'] = trim((string) ($_POST['description'] ?? ''));
        // featured image (single)
        $feat = handle_upload('image', $upErr);
        $row['image'] = $feat ?? ($existing['image'] ?? '');
        // photos: keep existing (minus any ticked for removal) + newly uploaded
        $removed = $_POST['remove_photos'] ?? [];
        if (!is_array($removed)) { $removed = []; }
        $keep = [];
        foreach (($existing['photos'] ?? []) as $p) {
            if (!in_array($p, $removed, true)) { $keep[] = $p; }
        }
        if (!$upErr) { $keep = array_merge($keep, handle_multi_upload('photos', $upErr)); }
        $row['photos'] = array_values($keep);
        // if no featured image set, use the first album photo
        if ($row['image'] === '' && !empty($row['photos'])) { $row['image'] = $row['photos'][0]; }
    } else {
        // generic fallback
        $image = handle_upload('image', $upErr);
        if (in_array('summary', $TYPES[$type]['fields'], true)) {
            $row['summary'] = trim((string) ($_POST['summary'] ?? ''));
        }
        if (in_array('href', $TYPES[$type]['fields'], true)) {
            $href = trim((string) ($_POST['href'] ?? ''));
            $row['href'] = $href !== '' ? $href : '/contact';
        }
        if (in_array('locations', $TYPES[$type]['fields'], true)) {
            $locs = array_values(array_filter(array_map('trim',
                explode(',', (string) ($_POST['locations'] ?? '')))));
            $row['locations'] = $locs;
        }
        $row['image'] = $image ?? ($existing['image'] ?? '');
    }

    if ($upErr) {
        $notice = $upErr;
    } else {
        // id: keep on edit, else slug from title (unique)
        if ($editId !== '' && $existing) {
            $row['id'] = $editId;
        } else {
            $base = slugify($row['title'] !== '' ? $row['title'] : 'item');
            $id = $base; $n = 2;
            $taken = array_column($rows, 'id');
            while (in_array($id, $taken, true)) { $id = $base . '-' . $n++; }
            $row['id'] = $id;
        }

        // order fields consistently
        $order = ['id', 'type', 'title', 'eyebrow', 'subtitle', 'employmentType', 'date', 'image', 'imageMobile', 'photos', 'amount', 'duration', 'commission', 'features', 'badge', 'summary', 'description', 'href', 'locations'];
        $ordered = [];
        foreach ($order as $k) { if (array_key_exists($k, $row)) { $ordered[$k] = $row[$k]; } }
        foreach ($row as $k => $v) { if (!array_key_exists($k, $ordered)) { $ordered[$k] = $v; } }

        // upsert
        $done = false;
        foreach ($rows as $i => $r) {
            if (($r['id'] ?? '') === $ordered['id']) { $rows[$i] = $ordered; $done = true; break; }
        }
        if (!$done) { $rows[] = $ordered; }

        $notice = save_json($type, $rows) ? 'Saved.' : 'Could not write file. Check folder permissions (755) on /content.';
    }
    redirect('?type=' . urlencode($type) . '&msg=' . urlencode($notice));
}

/* delete */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { http_response_code(400); exit('Bad request token.'); }
    $id   = (string) ($_POST['id'] ?? '');
    $rows = array_values(array_filter(load_json($type), fn($r) => ($r['id'] ?? '') !== $id));
    $notice = save_json($type, $rows) ? 'Deleted.' : 'Could not write file.';
    redirect('?type=' . urlencode($type) . '&msg=' . urlencode($notice));
}

/* reorder (move up / down) */
if ($action === 'move' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { http_response_code(400); exit('Bad request token.'); }
    $id   = (string) ($_POST['id'] ?? '');
    $dir  = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
    $rows = load_json($type);
    foreach ($rows as $i => $r) {
        if (($r['id'] ?? '') === $id) {
            $j = $i + $dir;
            if ($j >= 0 && $j < count($rows)) { [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]]; }
            break;
        }
    }
    save_json($type, $rows);
    redirect('?type=' . urlencode($type));
}

if (isset($_GET['msg'])) { $notice = (string) $_GET['msg']; }

/* edit view data */
$editRow = null;
if ($action === 'edit') {
    $eid = (string) ($_GET['id'] ?? '');
    foreach (load_json($type) as $r) { if (($r['id'] ?? '') === $eid) { $editRow = $r; break; } }
}
$isNew = ($action === 'new');

render_page($TYPES, $type, $notice, $editRow, $isNew);


/* ================= views ========================================== */
function page_head(string $title): void { ?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($title) ?> — Mazhuppel Chits CMS</title>
<style>
  :root{--navy:#08215e;--navy2:#05163f;--gold:#ef8a1e;--line:#e6e9f0;--muted:#5b6376;}
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#16203a;background:#f4f6fb}
  a{color:var(--navy)}
  header.top{background:var(--navy);color:#fff;padding:14px 20px;display:flex;align-items:center;justify-content:space-between}
  header.top b{font-size:18px;letter-spacing:.3px}
  header.top a{color:#cdd7f0;text-decoration:none;font-size:14px}
  .wrap{max-width:920px;margin:24px auto;padding:0 16px}
  .tabs{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
  .tabs a{padding:9px 16px;border-radius:999px;background:#fff;border:1px solid var(--line);text-decoration:none;font-weight:600;font-size:14px}
  .tabs a.on{background:var(--navy);color:#fff;border-color:var(--navy)}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:14px}
  .row{display:flex;gap:14px;align-items:center}
  .row img{width:74px;height:74px;object-fit:cover;border-radius:10px;background:#eef1f7;flex:none}
  .row .body{flex:1;min-width:0}
  .row .body b{display:block;font-size:15px}
  .row .body span{color:var(--muted);font-size:13px;display:block;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .row .acts{display:flex;gap:6px;align-items:center;flex:none}
  .btn{display:inline-block;padding:8px 14px;border-radius:8px;border:1px solid var(--line);background:#fff;
       text-decoration:none;font-size:13px;font-weight:600;cursor:pointer;color:#16203a}
  .btn.primary{background:var(--gold);border-color:var(--gold);color:#fff}
  .btn.navy{background:var(--navy);border-color:var(--navy);color:#fff}
  .btn.small{padding:6px 9px;font-size:12px}
  .btn.danger{color:#b91c1c;border-color:#f0d3d3}
  form.inline{display:inline}
  label{display:block;font-weight:600;font-size:13px;margin:14px 0 5px}
  input[type=text],input[type=url],textarea{width:100%;padding:10px 12px;border:1px solid var(--line);
       border-radius:9px;font:inherit;background:#fff}
  textarea{min-height:110px;resize:vertical}
  .hint{color:var(--muted);font-size:12px;margin-top:4px}
  .notice{background:#eafaf0;border:1px solid #bfe6cd;color:#166534;padding:10px 14px;border-radius:9px;margin-bottom:16px;font-size:14px}
  .head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
  .head h1{font-size:20px;margin:0}
  .thumb{width:120px;height:90px;object-fit:cover;border-radius:8px;background:#eef1f7;margin-top:6px;border:1px solid var(--line)}
  .login{max-width:360px;margin:12vh auto;background:#fff;border:1px solid var(--line);border-radius:16px;padding:28px}
  .login h1{font-size:20px;margin:0 0 4px}
  .login p{color:var(--muted);font-size:13px;margin:0 0 18px}
  .err{background:#fdecec;border:1px solid #f5c2c2;color:#b91c1c;padding:9px 12px;border-radius:9px;font-size:13px;margin-bottom:12px}
</style></head><body>
<?php }

function render_login(string $notice): void {
    page_head('Login'); ?>
<div class="login">
  <h1>Mazhuppel Chits</h1>
  <p>Content dashboard — please sign in.</p>
  <?php if ($notice): ?><div class="err"><?= h($notice) ?></div><?php endif; ?>
  <form method="post" action="?action=login">
    <label for="pw">Password</label>
    <input type="password" id="pw" name="password" autofocus style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:9px;font:inherit">
    <button class="btn navy" style="margin-top:16px;width:100%" type="submit">Sign in</button>
  </form>
</div></body></html>
<?php }

function render_page(array $TYPES, string $type, string $notice, ?array $editRow, bool $isNew): void {
    $csrf  = csrf_token();
    $extraLabels = ['applications' => 'Applications', 'agreements' => 'Filled Forms'];
    $label = $extraLabels[$type] ?? $TYPES[$type]['label'];
    page_head($label); ?>
<header class="top">
  <b>Mazhuppel Chits · CMS</b>
  <span><a href="/" target="_blank">View site ↗</a> &nbsp;·&nbsp; <a href="?action=logout">Log out</a></span>
</header>
<div class="wrap">
  <div class="tabs">
    <?php foreach ($TYPES as $k => $t): ?>
      <a class="<?= $k === $type ? 'on' : '' ?>" href="?type=<?= h($k) ?>"><?= h($t['label']) ?></a>
    <?php endforeach; ?>
    <a class="<?= $type === 'applications' ? 'on' : '' ?>" href="?type=applications">Applications</a>
    <a class="<?= $type === 'agreements' ? 'on' : '' ?>" href="?type=agreements">Filled Forms</a>
  </div>

  <?php if ($notice): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>

  <?php
    if ($type === 'applications') { render_apps($csrf, 'application'); }
    elseif ($type === 'agreements') { render_apps($csrf, 'agreement'); }
    elseif ($isNew || $editRow !== null) { render_form($TYPES, $type, $editRow, $csrf); }
    else { render_list($TYPES, $type, $csrf); }
  ?>
</div></body></html>
<?php }

function render_apps(string $csrf, string $kind = 'application'): void {
    $apps  = array_values(array_filter(load_apps(), fn($a) => ($a['kind'] ?? 'application') === $kind));
    $apps  = array_reverse($apps); // newest first
    $isAgr = $kind === 'agreement';
    ?>
  <div class="head">
    <h1><?= $isAgr ? 'Filled Forms' : 'Applications' ?> <span style="color:var(--muted);font-weight:400">(<?= count($apps) ?>)</span></h1>
  </div>
  <?php if (!$apps): ?>
    <div class="card" style="color:var(--muted)"><?= $isAgr ? 'No filled forms uploaded yet. They appear here when someone submits a completed agreement (a copy is also emailed to the office).' : 'No job applications yet. They appear here the moment someone applies (a copy is also emailed to HR).' ?></div>
  <?php endif; ?>
  <?php foreach ($apps as $a):
      $role  = trim(preg_replace('/^(Job Application|Filled):\s*/', '', (string) ($a['role'] ?? '')) ?? '');
      $phone = preg_replace('/\s+/', '', (string) ($a['phone'] ?? '')); ?>
    <div class="card">
      <div class="row">
        <div class="body">
          <b><?= h($a['name'] ?? '(no name)') ?><?php if ($role !== ''): ?> <span style="font-size:11px;font-weight:600;color:var(--gold)">[<?= h($role) ?>]</span><?php endif; ?></b>
          <span><?= h($a['date'] ?? '') ?></span>
          <span>Phone: <a href="tel:<?= h($phone) ?>"><?= h($a['phone'] ?? '') ?></a><?php if (!empty($a['email'])): ?> &nbsp;·&nbsp; Email: <a href="mailto:<?= h($a['email']) ?>"><?= h($a['email']) ?></a><?php endif; ?></span>
          <?php if (!empty($a['message'])): ?><span style="white-space:normal;color:#16203a"><?= h($a['message']) ?></span><?php endif; ?>
        </div>
        <div class="acts">
          <?php if (!empty($a['cv'])): ?>
            <a class="btn small primary" href="?action=cv&file=<?= urlencode((string) $a['cv']) ?>" target="_blank" rel="noreferrer"><?= $isAgr ? 'View / Download' : 'Download CV' ?></a>
          <?php else: ?>
            <span style="font-size:12px;color:var(--muted)">No file</span>
          <?php endif; ?>
          <form class="inline" method="post" action="?action=delapp" onsubmit="return confirm('Delete this entry?')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($a['id'] ?? '') ?>">
            <button class="btn small danger" type="submit">Delete</button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach;
}

function render_list(array $TYPES, string $type, string $csrf): void {
    $rows = load_json($type); ?>
  <div class="head">
    <h1><?= h($TYPES[$type]['label']) ?> <span style="color:var(--muted);font-weight:400">(<?= count($rows) ?>)</span></h1>
    <a class="btn primary" href="?action=new&type=<?= h($type) ?>">+ Add new</a>
  </div>
  <?php if (!$rows): ?>
    <div class="card" style="color:var(--muted)">Nothing here yet. Click “Add new”.</div>
  <?php endif; ?>
  <?php foreach ($rows as $i => $r): ?>
    <div class="card">
      <div class="row">
        <img src="<?= h($r['image'] ?? '') ?>" alt="" onerror="this.style.visibility='hidden'">
        <div class="body">
          <b><?= h($r['title'] ?? '(untitled)') ?><?php if (!empty($r['type'])): ?> <span style="font-size:11px;font-weight:600;color:var(--gold)">[<?= h($r['type']) ?>]</span><?php endif; ?></b>
          <?php if (!empty($r['date'])): ?><span><?= h($r['date']) ?></span><?php endif; ?>
          <?php if (!empty($r['amount'])): ?><span><?= h($r['amount']) ?><?= !empty($r['duration']) ? ' · ' . h($r['duration']) : '' ?></span><?php endif; ?>
          <?php if (!empty($r['locations'])): ?><span><?= h(implode(', ', $r['locations'])) ?></span><?php endif; ?>
          <?php if (!empty($r['photos'])): $album = array_unique(array_filter(array_merge([$r['image'] ?? ''], (array) $r['photos']))); ?><span><?= count($album) ?> photos</span><?php endif; ?>
          <?php if (!empty($r['subtitle'])): ?><span><?= h($r['subtitle']) ?></span><?php endif; ?>
          <?php if (!empty($r['summary'])): ?><span><?= h($r['summary']) ?></span><?php endif; ?>
        </div>
        <div class="acts">
          <form class="inline" method="post" action="?action=move&type=<?= h($type) ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($r['id']) ?>">
            <button class="btn small" name="dir" value="up" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
            <button class="btn small" name="dir" value="down" <?= $i === count($rows) - 1 ? 'disabled' : '' ?>>↓</button>
          </form>
          <a class="btn small" href="?action=edit&type=<?= h($type) ?>&id=<?= h($r['id']) ?>">Edit</a>
          <form class="inline" method="post" action="?action=delete&type=<?= h($type) ?>" onsubmit="return confirm('Delete this item?')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($r['id']) ?>">
            <button class="btn small danger" type="submit">Delete</button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach;
}

function render_form(array $TYPES, string $type, ?array $r, string $csrf): void {
    if ($type === 'hero')      { render_hero_form($type, $r, $csrf); return; }
    if ($type === 'new-chits') { render_kuri_form($type, $r, $csrf); return; }
    if ($type === 'careers')   { render_job_form($type, $r, $csrf); return; }
    if ($type === 'gallery')   { render_event_form($type, $r, $csrf); return; }
    $fields = $TYPES[$type]['fields'];
    $val = fn(string $k) => h($r[$k] ?? '');
    ?>
  <div class="head">
    <h1><?= $r ? 'Edit' : 'Add' ?> — <?= h($TYPES[$type]['label']) ?></h1>
    <a class="btn" href="?type=<?= h($type) ?>">← Back</a>
  </div>
  <div class="card">
    <form method="post" action="?action=save&type=<?= h($type) ?>" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= h($r['id'] ?? '') ?>">

      <label for="title">Title <?= $type === 'gallery' ? '<span style="font-weight:400;color:var(--muted)">(used as caption / alt text)</span>' : '' ?></label>
      <input type="text" id="title" name="title" value="<?= $val('title') ?>" required>

      <?php if (in_array('summary', $fields, true)): ?>
        <label for="summary">Description</label>
        <textarea id="summary" name="summary"><?= $val('summary') ?></textarea>
      <?php endif; ?>

      <?php if (in_array('locations', $fields, true)): ?>
        <label for="locations">Locations <span style="font-weight:400;color:var(--muted)">(comma separated)</span></label>
        <input type="text" id="locations" name="locations" value="<?= h(!empty($r['locations']) ? implode(', ', $r['locations']) : '') ?>" placeholder="Payyavoor, Kuthooparamba, Ulikkal">
      <?php endif; ?>

      <?php if (in_array('href', $fields, true)): ?>
        <label for="href">Button link <span style="font-weight:400;color:var(--muted)">(optional — defaults to /contact)</span></label>
        <input type="text" id="href" name="href" value="<?= $val('href') ?>" placeholder="/contact">
      <?php endif; ?>

      <label for="image">Image <?= $r ? '<span style="font-weight:400;color:var(--muted)">(leave empty to keep current)</span>' : '' ?></label>
      <input type="file" id="image" name="image" accept="image/*">
      <?php if (!empty($r['image'])): ?><img class="thumb" src="<?= h($r['image']) ?>" alt=""><?php endif; ?>
      <div class="hint">JPG, PNG, WEBP or GIF — up to 8 MB. Landscape images look best.</div>

      <div style="margin-top:20px;display:flex;gap:10px">
        <button class="btn primary" type="submit">Save</button>
        <a class="btn" href="?type=<?= h($type) ?>">Cancel</a>
      </div>
    </form>
  </div>
<?php }

function render_kuri_form(string $type, ?array $r, string $csrf): void {
    $val = fn(string $k) => h($r[$k] ?? '');
    $mode = (($r['type'] ?? 'design') === 'poster') ? 'poster' : 'design';
    $features = (!empty($r['features']) && is_array($r['features'])) ? implode("\n", $r['features']) : '';
    ?>
  <div class="head">
    <h1><?= $r ? 'Edit' : 'Add' ?> — New Kuri</h1>
    <a class="btn" href="?type=<?= h($type) ?>">← Back</a>
  </div>
  <div class="card">
    <form method="post" action="?action=save&type=<?= h($type) ?>" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= h($r['id'] ?? '') ?>">

      <label>How do you want to add this kuri?</label>
      <div style="display:flex;gap:18px;margin-top:2px">
        <label style="font-weight:400;display:flex;gap:6px;align-items:center;margin:0;cursor:pointer">
          <input type="radio" name="mode" value="design" <?= $mode !== 'poster' ? 'checked' : '' ?> onclick="kuriMode('design')"> Design a card
        </label>
        <label style="font-weight:400;display:flex;gap:6px;align-items:center;margin:0;cursor:pointer">
          <input type="radio" name="mode" value="poster" <?= $mode === 'poster' ? 'checked' : '' ?> onclick="kuriMode('poster')"> Upload a poster
        </label>
      </div>

      <label for="title">Title</label>
      <input type="text" id="title" name="title" value="<?= $val('title') ?>" required placeholder="e.g. Monthly Chitty">

      <div id="mode-design">
        <label for="amount">Amount</label>
        <input type="text" id="amount" name="amount" value="<?= $val('amount') ?>" placeholder="₹1 Lakh to ₹10 Lakh">
        <div style="display:flex;gap:12px">
          <div style="flex:1">
            <label for="duration">Duration</label>
            <input type="text" id="duration" name="duration" value="<?= $val('duration') ?>" placeholder="20 to 40 months">
          </div>
          <div style="flex:1">
            <label for="commission">Commission</label>
            <input type="text" id="commission" name="commission" value="<?= $val('commission') ?>" placeholder="5%">
          </div>
        </div>
        <label for="features">Features <span style="font-weight:400;color:var(--muted)">(one per line)</span></label>
        <textarea id="features" name="features" placeholder="Government-registered &amp; ISO certified&#10;Easy monthly installments&#10;Ideal for long-term goals"><?= h($features) ?></textarea>
        <label for="badge">Badge <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
        <input type="text" id="badge" name="badge" value="<?= $val('badge') ?>" placeholder="Popular">
        <label for="dImage">Image <span style="font-weight:400;color:var(--muted)">(optional, shows on top of the card)</span></label>
        <input type="file" id="dImage" name="image" accept="image/*">
        <?php if (!empty($r['image']) && (($r['type'] ?? 'design') !== 'poster')): ?><img class="thumb" src="<?= h($r['image']) ?>" alt=""><?php endif; ?>
      </div>

      <div id="mode-poster">
        <label for="image">Desktop poster (wide)<?= $r ? ' <span style="font-weight:400;color:var(--muted)">(leave empty to keep current)</span>' : '' ?></label>
        <input type="file" id="image" name="image" accept="image/*">
        <?php if (!empty($r['image'])): ?><img class="thumb" src="<?= h($r['image']) ?>" alt=""><?php endif; ?>
        <label for="imageMobile">Mobile poster (portrait) <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
        <input type="file" id="imageMobile" name="imageMobile" accept="image/*">
        <?php if (!empty($r['imageMobile'])): ?><img class="thumb" src="<?= h($r['imageMobile']) ?>" alt=""><?php endif; ?>
        <div class="hint">JPG, PNG, WEBP or GIF, up to 8 MB. Wide banner for desktop, portrait for mobile.</div>
      </div>

      <div class="hint" style="margin-top:14px">Visitors join by clicking the card, which opens the “I Want to Join” enquiry form.</div>

      <div style="margin-top:20px;display:flex;gap:10px">
        <button class="btn primary" type="submit">Save</button>
        <a class="btn" href="?type=<?= h($type) ?>">Cancel</a>
      </div>
    </form>
  </div>
  <script>
    function kuriMode(m){
      var d = document.getElementById('mode-design');
      var p = document.getElementById('mode-poster');
      d.style.display = (m === 'design') ? 'block' : 'none';
      p.style.display = (m === 'poster') ? 'block' : 'none';
      d.querySelectorAll('input,textarea').forEach(function(el){ el.disabled = (m !== 'design'); });
      p.querySelectorAll('input,textarea').forEach(function(el){ el.disabled = (m !== 'poster'); });
    }
    kuriMode(<?= json_encode($mode) ?>);
  </script>
<?php }

function render_job_form(string $type, ?array $r, string $csrf): void {
    $val = fn(string $k) => h($r[$k] ?? '');
    $mode = (($r['type'] ?? 'design') === 'poster') ? 'poster' : 'design';
    ?>
  <div class="head">
    <h1><?= $r ? 'Edit' : 'Add' ?> — Job</h1>
    <a class="btn" href="?type=<?= h($type) ?>">← Back</a>
  </div>
  <div class="card">
    <form method="post" action="?action=save&type=<?= h($type) ?>" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= h($r['id'] ?? '') ?>">

      <label>How do you want to add this job?</label>
      <div style="display:flex;gap:18px;margin-top:2px">
        <label style="font-weight:400;display:flex;gap:6px;align-items:center;margin:0;cursor:pointer">
          <input type="radio" name="mode" value="design" <?= $mode !== 'poster' ? 'checked' : '' ?> onclick="jobMode('design')"> Write a job post
        </label>
        <label style="font-weight:400;display:flex;gap:6px;align-items:center;margin:0;cursor:pointer">
          <input type="radio" name="mode" value="poster" <?= $mode === 'poster' ? 'checked' : '' ?> onclick="jobMode('poster')"> Upload a poster
        </label>
      </div>

      <label for="title">Job title</label>
      <input type="text" id="title" name="title" value="<?= $val('title') ?>" required placeholder="e.g. Marketing Manager">

      <div id="mode-design">
        <label for="employmentType">Employment type <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
        <input type="text" id="employmentType" name="employmentType" value="<?= $val('employmentType') ?>" placeholder="Full Time">
        <label for="locations">Locations <span style="font-weight:400;color:var(--muted)">(comma separated)</span></label>
        <input type="text" id="locations" name="locations" value="<?= h(!empty($r['locations']) ? implode(', ', $r['locations']) : '') ?>" placeholder="Payyavoor, Kuthooparamba, Ulikkal">
        <label for="summary">Description</label>
        <textarea id="summary" name="summary" placeholder="Role overview, responsibilities, requirements…"><?= $val('summary') ?></textarea>
        <div class="hint">Leave a blank line between sections. Start a line with • for a bullet.</div>
        <label for="dImage">Image <span style="font-weight:400;color:var(--muted)">(optional, shows beside the job)</span></label>
        <input type="file" id="dImage" name="image" accept="image/*">
        <?php if (!empty($r['image']) && (($r['type'] ?? 'design') !== 'poster')): ?><img class="thumb" src="<?= h($r['image']) ?>" alt=""><?php endif; ?>
      </div>

      <div id="mode-poster">
        <label for="image">Desktop poster (wide)<?= $r ? ' <span style="font-weight:400;color:var(--muted)">(leave empty to keep current)</span>' : '' ?></label>
        <input type="file" id="image" name="image" accept="image/*">
        <?php if (!empty($r['image'])): ?><img class="thumb" src="<?= h($r['image']) ?>" alt=""><?php endif; ?>
        <label for="imageMobile">Mobile poster (portrait) <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
        <input type="file" id="imageMobile" name="imageMobile" accept="image/*">
        <?php if (!empty($r['imageMobile'])): ?><img class="thumb" src="<?= h($r['imageMobile']) ?>" alt=""><?php endif; ?>
        <div class="hint">A “We are hiring” poster — wide for desktop, portrait for mobile.</div>
      </div>

      <div style="margin-top:20px;display:flex;gap:10px">
        <button class="btn primary" type="submit">Save</button>
        <a class="btn" href="?type=<?= h($type) ?>">Cancel</a>
      </div>
    </form>
  </div>
  <script>
    function jobMode(m){
      var d = document.getElementById('mode-design');
      var p = document.getElementById('mode-poster');
      d.style.display = (m === 'design') ? 'block' : 'none';
      p.style.display = (m === 'poster') ? 'block' : 'none';
      d.querySelectorAll('input,textarea').forEach(function(el){ el.disabled = (m !== 'design'); });
      p.querySelectorAll('input,textarea').forEach(function(el){ el.disabled = (m !== 'poster'); });
    }
    jobMode(<?= json_encode($mode) ?>);
  </script>
<?php }

function render_event_form(string $type, ?array $r, string $csrf): void {
    $val = fn(string $k) => h($r[$k] ?? '');
    $photos = (!empty($r['photos']) && is_array($r['photos'])) ? $r['photos'] : [];
    ?>
  <div class="head">
    <h1><?= $r ? 'Edit' : 'Add' ?> — Event</h1>
    <a class="btn" href="?type=<?= h($type) ?>">← Back</a>
  </div>
  <div class="card">
    <form method="post" action="?action=save&type=<?= h($type) ?>" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= h($r['id'] ?? '') ?>">

      <label for="title">Event title</label>
      <input type="text" id="title" name="title" value="<?= $val('title') ?>" required placeholder="e.g. Onam Celebration 2025">

      <label for="date">Date <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
      <input type="text" id="date" name="date" value="<?= $val('date') ?>" placeholder="September 2025">

      <label for="description">Description <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
      <textarea id="description" name="description" placeholder="A short note about this event…"><?= $val('description') ?></textarea>

      <label for="image">Featured image <?= $r ? '<span style="font-weight:400;color:var(--muted)">(leave empty to keep current)</span>' : '' ?></label>
      <input type="file" id="image" name="image" accept="image/*">
      <?php if (!empty($r['image'])): ?><img class="thumb" src="<?= h($r['image']) ?>" alt=""><?php endif; ?>
      <div class="hint">Shown on the gallery card. If left empty, the first photo below is used.</div>

      <?php if ($photos): ?>
        <label>Current photos <span style="font-weight:400;color:var(--muted)">(tick to remove)</span></label>
        <div style="display:flex;flex-wrap:wrap;gap:12px">
          <?php foreach ($photos as $p): ?>
            <label style="cursor:pointer;font-weight:400;margin:0;text-align:center">
              <img class="thumb" src="<?= h($p) ?>" alt="" style="margin:0;display:block">
              <span style="display:inline-flex;gap:4px;align-items:center;margin-top:4px;font-size:12px;color:var(--muted)">
                <input type="checkbox" name="remove_photos[]" value="<?= h($p) ?>"> remove
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <label for="photos">Add photos <span style="font-weight:400;color:var(--muted)">(you can select several at once)</span></label>
      <input type="file" id="photos" name="photos[]" accept="image/*" multiple>
      <div class="hint">JPG, PNG, WEBP or GIF, up to 8 MB each. These appear when a visitor opens the event.</div>

      <div style="margin-top:20px;display:flex;gap:10px">
        <button class="btn primary" type="submit">Save</button>
        <a class="btn" href="?type=<?= h($type) ?>">Cancel</a>
      </div>
    </form>
  </div>
<?php }

function render_hero_form(string $type, ?array $r, string $csrf): void {
    $val = fn(string $k) => h($r[$k] ?? '');
    ?>
  <div class="head">
    <h1><?= $r ? 'Edit' : 'Add' ?> — Hero Slide</h1>
    <a class="btn" href="?type=<?= h($type) ?>">← Back</a>
  </div>
  <div class="card">
    <div class="hint" style="margin-bottom:14px">These are the sliding banners at the very top of the home page. Add a few and they auto-rotate. Drag order with the ↑ ↓ buttons on the list.</div>
    <form method="post" action="?action=save&type=<?= h($type) ?>" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= h($r['id'] ?? '') ?>">

      <label for="eyebrow">Eyebrow <span style="font-weight:400;color:var(--muted)">(small pill above the title, optional)</span></label>
      <input type="text" id="eyebrow" name="eyebrow" value="<?= $val('eyebrow') ?>" placeholder="Kerala Government Registered Chits">

      <label for="title">Title <span style="font-weight:400;color:var(--muted)">(main heading)</span></label>
      <input type="text" id="title" name="title" value="<?= $val('title') ?>" required placeholder="നല്ല നാളേക്കായ് കരുതിവെക്കാം.">

      <label for="subtitle">Subtitle <span style="font-weight:400;color:var(--muted)">(supporting line, optional)</span></label>
      <textarea id="subtitle" name="subtitle" placeholder="Short line shown under the title"><?= $val('subtitle') ?></textarea>

      <label for="image">Background image <?= $r ? '<span style="font-weight:400;color:var(--muted)">(leave empty to keep current)</span>' : '' ?></label>
      <input type="file" id="image" name="image" accept="image/*">
      <?php if (!empty($r['image'])): ?><img class="thumb" src="<?= h($r['image']) ?>" alt=""><?php endif; ?>
      <div class="hint">Wide landscape photo works best — it fills the banner behind the text. JPG, PNG or WEBP, up to 8 MB.</div>

      <div style="margin-top:20px;display:flex;gap:10px">
        <button class="btn primary" type="submit">Save</button>
        <a class="btn" href="?type=<?= h($type) ?>">Cancel</a>
      </div>
    </form>
  </div>
<?php }
