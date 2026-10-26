<?php
/**
 * Portfolio admin: upload and manage the images shown in the portfolio gallery.
 *
 * Put this file in the same folder as index.html (the site root).
 * Needs PHP 7.3+. It creates and uses:
 *   images/portfolio/<category>/<sub-category>/   uploaded images
 *   data/portfolio.json                           read by index.html
 *   data/config.php                               admin password hash (never printed)
 */
declare(strict_types=1);

const DATA_DIR       = __DIR__ . '/data';
const DATA_FILE      = DATA_DIR . '/portfolio.json';
const CONF_FILE      = DATA_DIR . '/config.php';
const LOCK_FILE      = DATA_DIR . '/.lock';
const TRY_FILE       = DATA_DIR . '/login-attempts.json';
const UPLOAD_REL     = 'images/portfolio';
const UPLOAD_DIR     = __DIR__ . '/images/portfolio';
const MAX_FILE_BYTES = 25 * 1024 * 1024; // per image
const MAX_EDGE       = 2400;             // larger images are scaled down to this (long edge)
const MAX_FILES      = 40;               // per upload

$LAYOUTS = [
    'auto' => 'Auto (best fit)', '1x1' => 'Small', '2x1' => 'Wide', '1x2' => 'Tall', '2x2' => 'Large',
    '3x1' => 'Extra wide', '3x2' => 'Big wide', '2x3' => 'Tall feature', 'random' => 'Random',
];
$FILTER_KEYS = ['branding' => 'Branding', 'design' => 'Design', 'uiux' => 'UI/UX', 'photography' => 'Photography', 'motion' => 'Motion'];

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('pfadmin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => $secure, 'samesite' => 'Lax']);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* ------------------------------------------------------------------ helpers */

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function slugify(string $s): string
{
    $s = str_replace('&', ' and ', $s);
    $s = strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '-', $s));
    $s = trim($s, '-');
    return $s === '' ? 'item' : substr($s, 0, 60);
}

function clean_text($s, int $max): string
{
    $s = trim((string)$s);
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

function ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '') {
        return 0;
    }
    $n = (int)$v;
    $u = strtolower(substr($v, -1));
    if ($u === 'g') {
        $n *= 1024 * 1024 * 1024;
    } elseif ($u === 'm') {
        $n *= 1024 * 1024;
    } elseif ($u === 'k') {
        $n *= 1024;
    }
    return $n;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function go(): void
{
    header('Location: ' . basename(__FILE__));
    exit;
}

function month_names(): array
{
    return ['Not specified', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
}

function valid_year(int $y): bool
{
    return $y >= 1980 && $y <= (int)date('Y') + 1;
}

function year_options_html(int $selected): string
{
    $out = '';
    if ($selected === 0) {
        $out .= '<option value="" selected disabled>Select year...</option>';
    }
    for ($y = (int)date('Y') + 1; $y >= 1980; $y--) {
        $out .= '<option value="' . $y . '"' . ($y === $selected ? ' selected' : '') . '>' . $y . '</option>';
    }
    return $out;
}

function month_options_html(int $selected): string
{
    $out = '';
    foreach (month_names() as $i => $n) {
        $out .= '<option value="' . $i . '"' . ($i === $selected ? ' selected' : '') . '>' . h($n) . '</option>';
    }
    return $out;
}

function default_categories(): array
{
    return [
        ['name' => 'Corporate Branding', 'filter' => 'branding', 'subs' => ['Logos', 'Stationery Sets', 'Merchandise']],
        ['name' => '360 Campaigns', 'filter' => 'design', 'subs' => ['Integrated Campaigns']],
        ['name' => 'Advertising Campaigns', 'filter' => 'design', 'subs' => ['Print & Digital Ads']],
        ['name' => 'Social Media Campaigns', 'filter' => 'design', 'subs' => ['Social Content']],
        ['name' => 'UI/UX', 'filter' => 'uiux', 'subs' => ['Websites', 'Apps']],
        ['name' => 'Outdoors', 'filter' => 'design', 'subs' => ['Booths / Stalls', 'Hoardings / Standees / Signages', 'OOH']],
        ['name' => 'Corporate Designs / Presentations', 'filter' => 'design', 'subs' => ['Company Profiles', 'Orientation Sessions', 'Policies']],
        ['name' => 'Motion Graphics', 'filter' => 'motion', 'subs' => ['TVCs', 'Animations', 'Mix']],
        ['name' => 'Photography', 'filter' => 'photography', 'subs' => ['Photoshoots', 'Product Shoots']],
    ];
}

function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0755, true);
    }
    $ht = DATA_DIR . '/.htaccess';
    if (is_dir(DATA_DIR) && !is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n<FilesMatch \"^(login-attempts\\.json|\\.lock)$\">\n  Require all denied\n</FilesMatch>\n</IfModule>\n");
    }
}

function ensure_upload_dir(): void
{
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }
    $ht = UPLOAD_DIR . '/.htaccess';
    if (is_dir(UPLOAD_DIR) && !is_file($ht)) {
        // uploaded files must never be executed as scripts
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n<FilesMatch \"\\.(php[0-9]?|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n</IfModule>\n");
    }
}

function load_data(): array
{
    $d = null;
    if (is_file(DATA_FILE)) {
        $d = json_decode((string)file_get_contents(DATA_FILE), true);
    }
    if (!is_array($d)) {
        $d = [];
    }
    if (empty($d['categories']) || !is_array($d['categories'])) {
        $d['categories'] = default_categories();
    }
    if (!isset($d['projects']) || !is_array($d['projects'])) {
        $d['projects'] = [];
    }
    return $d;
}

function save_data(array $d): bool
{
    $json = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    $tmp = DATA_FILE . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0644);
    return rename($tmp, DATA_FILE);
}

/** Runs $fn(&$data) under a lock; saves the data when $fn returns ['ok' => true, ...]. */
function with_data(callable $fn): array
{
    ensure_data_dir();
    $lock = @fopen(LOCK_FILE, 'c');
    if (!$lock) {
        return ['ok' => false, 'msg' => 'Cannot write to the data folder. Check that the site folder is writable by PHP.'];
    }
    flock($lock, LOCK_EX);
    $data = load_data();
    $res = $fn($data);
    if (is_array($res) && !empty($res['ok'])) {
        if (!save_data($data)) {
            $res = ['ok' => false, 'msg' => 'Could not write data/portfolio.json. Check folder permissions.'];
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return is_array($res) ? $res : ['ok' => false, 'msg' => 'Unexpected error.'];
}

/* ---------------------------------------------------------------- auth bits */

function get_hash(): string
{
    if (!is_file(CONF_FILE)) {
        return '';
    }
    $c = include CONF_FILE;
    return (is_array($c) && !empty($c['hash'])) ? (string)$c['hash'] : '';
}

function client_key(): string
{
    return hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function read_attempts(): array
{
    if (!is_file(TRY_FILE)) {
        return [];
    }
    $a = json_decode((string)file_get_contents(TRY_FILE), true);
    return is_array($a) ? $a : [];
}

function recent_fails(): int
{
    $a = read_attempts();
    $now = time();
    $list = isset($a[client_key()]) && is_array($a[client_key()]) ? $a[client_key()] : [];
    $list = array_filter($list, function ($t) use ($now) {
        return $t > $now - 900;
    });
    return count($list);
}

function record_fail(): void
{
    ensure_data_dir();
    $a = read_attempts();
    $now = time();
    $k = client_key();
    $list = isset($a[$k]) && is_array($a[$k]) ? $a[$k] : [];
    $list = array_values(array_filter($list, function ($t) use ($now) {
        return $t > $now - 900;
    }));
    $list[] = $now;
    $a[$k] = $list;
    foreach ($a as $key => $v) { // drop stale clients
        if (!is_array($v) || !$v || max($v) < $now - 900) {
            unset($a[$key]);
        }
    }
    @file_put_contents(TRY_FILE, json_encode($a), LOCK_EX);
}

/* ------------------------------------------------------------ image helpers */

function upload_error_text(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'larger than the server upload limit (' . ini_get('upload_max_filesize') . ')';
        case UPLOAD_ERR_PARTIAL:
            return 'only partly uploaded, please try again';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'the server could not store the file';
        default:
            return 'upload failed (code ' . $code . ')';
    }
}

function maybe_resize(string $src, string $dst, string $mime, array $info): bool
{
    if (!extension_loaded('gd') || $mime === 'image/gif') {
        return false;
    }
    $w = (int)$info[0];
    $h = (int)$info[1];
    if ($w <= MAX_EDGE && $h <= MAX_EDGE) {
        return false;
    }
    $scale = MAX_EDGE / max($w, $h);
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $limit = ini_bytes((string)ini_get('memory_limit'));
    if ($limit > 0 && ($w * $h * 5 + $nw * $nh * 5 + memory_get_usage()) > $limit * 0.9) {
        return false; // not enough memory to resize safely: keep the original
    }
    $im = false;
    if ($mime === 'image/jpeg') {
        $im = @imagecreatefromjpeg($src);
    } elseif ($mime === 'image/png') {
        $im = @imagecreatefrompng($src);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $im = @imagecreatefromwebp($src);
    }
    if (!$im) {
        return false;
    }
    if ($mime === 'image/webp' && !function_exists('imagewebp')) {
        return false;
    }
    $out = imagecreatetruecolor($nw, $nh);
    if ($mime !== 'image/jpeg') {
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $clear = imagecolorallocatealpha($out, 0, 0, 0, 127);
        imagefilledrectangle($out, 0, 0, $nw, $nh, $clear);
    }
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $ex = @exif_read_data($src);
        $o = (int)($ex['Orientation'] ?? 1);
        $angles = [3 => 180, 6 => -90, 8 => 90];
        if (isset($angles[$o])) {
            $rot = imagerotate($out, $angles[$o], 0);
            if ($rot) {
                $out = $rot;
            }
        }
    }
    if ($mime === 'image/jpeg') {
        return imagejpeg($out, $dst, 85);
    }
    if ($mime === 'image/png') {
        return imagepng($out, $dst, 6);
    }
    return imagewebp($out, $dst, 85);
}

/** Validates and stores one uploaded image. Returns [relative path or null, error text]. */
function save_image(array $f, string $dirAbs, string $dirRel): array
{
    $label = (string)$f['name'];
    if ((int)$f['error'] !== UPLOAD_ERR_OK) {
        return [null, $label . ': ' . upload_error_text((int)$f['error'])];
    }
    if ((int)$f['size'] > MAX_FILE_BYTES) {
        return [null, $label . ': larger than ' . (MAX_FILE_BYTES / 1048576) . ' MB'];
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        return [null, $label . ': not a valid upload'];
    }
    $info = @getimagesize($f['tmp_name']);
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!$info || !isset($types[$info['mime']])) {
        return [null, $label . ': not a JPG, PNG, WebP or GIF image'];
    }
    $mime = $info['mime'];
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $real = $fi ? finfo_file($fi, $f['tmp_name']) : $mime;
        if ($real !== $mime) {
            return [null, $label . ': file content does not match an image type'];
        }
    }
    $base = slugify((string)pathinfo($label, PATHINFO_FILENAME));
    $base = substr($base, 0, 40);
    $name = $base . '-' . bin2hex(random_bytes(3)) . '.' . $types[$mime];
    $target = $dirAbs . '/' . $name;
    if (!maybe_resize($f['tmp_name'], $target, $mime, $info)) {
        if (!@move_uploaded_file($f['tmp_name'], $target)) {
            return [null, $label . ': could not be saved (check folder permissions)'];
        }
    }
    @chmod($target, 0644);
    return [$dirRel . '/' . $name, ''];
}

function collect_files(): array
{
    $out = [];
    if (empty($_FILES['images']) || !is_array($_FILES['images']['name'])) {
        return $out;
    }
    $f = $_FILES['images'];
    foreach ($f['name'] as $i => $n) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

/** Deletes a stored image, only if it really lives inside images/portfolio. */
function delete_image_file(string $rel): void
{
    if (strpos($rel, UPLOAD_REL . '/') !== 0 || strpos($rel, '..') !== false) {
        return;
    }
    $abs = __DIR__ . '/' . $rel;
    $real = realpath($abs);
    $base = realpath(UPLOAD_DIR);
    if ($real && $base && strpos($real, $base . DIRECTORY_SEPARATOR) === 0 && is_file($real)) {
        @unlink($real);
        $dir = dirname($real);
        for ($i = 0; $i < 2; $i++) { // remove empty sub-category / category folders
            if ($dir !== $base && is_dir($dir) && count((array)scandir($dir)) <= 2) {
                @rmdir($dir);
                $dir = dirname($dir);
            }
        }
    }
}

/* ----------------------------------------------------------------- actions */

function action_upload(array $layouts): array
{
    $files = collect_files();
    if (!$files) {
        return ['ok' => false, 'msg' => 'Please choose at least one image.'];
    }
    if (count($files) > MAX_FILES) {
        return ['ok' => false, 'msg' => 'Please upload at most ' . MAX_FILES . ' images at a time.'];
    }
    $catIdx = (int)($_POST['category'] ?? -1);
    $subChoice = (string)($_POST['sub'] ?? '');
    $subNew = clean_text($_POST['sub_new'] ?? '', 60);
    $title = clean_text($_POST['title'] ?? '', 80);
    $caption = clean_text($_POST['caption'] ?? '', 120);
    $details = clean_text($_POST['details'] ?? '', 1200);
    $layout = (string)($_POST['layout'] ?? 'auto');
    if (!isset($layouts[$layout])) {
        $layout = 'auto';
    }

    $year = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    if (!valid_year($year)) {
        return ['ok' => false, 'msg' => 'Please choose the year of design. It is required.'];
    }
    if ($month < 0 || $month > 12) {
        $month = 0;
    }

    return with_data(function (array &$data) use ($files, $catIdx, $subChoice, $subNew, $title, $caption, $details, $layout, $year, $month) {
        if (!isset($data['categories'][$catIdx])) {
            return ['ok' => false, 'msg' => 'Please choose a category.'];
        }
        $cat = &$data['categories'][$catIdx];
        $subs = isset($cat['subs']) && is_array($cat['subs']) ? $cat['subs'] : [];
        if ($subChoice === '__new__') {
            if ($subNew === '') {
                return ['ok' => false, 'msg' => 'Please type the new sub-category name.'];
            }
            $sub = $subNew;
            foreach ($subs as $existing) {
                if (strcasecmp($existing, $subNew) === 0) {
                    $sub = $existing;
                }
            }
            if (!in_array($sub, $subs, true)) {
                $subs[] = $sub;
            }
        } else {
            if (!in_array($subChoice, $subs, true)) {
                return ['ok' => false, 'msg' => 'Please choose a sub-category.'];
            }
            $sub = $subChoice;
        }
        $cat['subs'] = $subs;

        ensure_data_dir();
        ensure_upload_dir();
        $dirRel = UPLOAD_REL . '/' . slugify((string)$cat['name']) . '/' . slugify($sub);
        $dirAbs = __DIR__ . '/' . $dirRel;
        if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0755, true)) {
            return ['ok' => false, 'msg' => 'Could not create the folder ' . $dirRel . '. Check folder permissions.'];
        }

        $saved = [];
        $errors = [];
        foreach ($files as $f) {
            list($path, $err) = save_image($f, $dirAbs, $dirRel);
            if ($path) {
                $saved[] = $path;
            } else {
                $errors[] = $err;
            }
        }
        if (!$saved) {
            return ['ok' => false, 'msg' => 'No images were saved. ' . implode('; ', $errors)];
        }
        $filter = isset($cat['filter']) ? (string)$cat['filter'] : 'design';
        $data['projects'][] = [
            'id' => 'p' . bin2hex(random_bytes(5)),
            'category' => (string)$cat['name'],
            'sub' => $sub,
            'filter' => $filter,
            'title' => $title !== '' ? $title : (string)$cat['name'],
            'caption' => $caption !== '' ? $caption : $sub,
            'details' => $details,
            'layout' => $layout,
            'year' => $year,
            'month' => $month,
            'images' => $saved,
            'created' => date('Y-m-d H:i'),
        ];
        $msg = count($saved) . ' image(s) saved to ' . $dirRel . '/';
        if ($errors) {
            $msg .= '. Skipped: ' . implode('; ', $errors);
        }
        return ['ok' => true, 'msg' => $msg];
    });
}

function find_project(array $data, string $id): int
{
    foreach ($data['projects'] as $i => $p) {
        if (isset($p['id']) && $p['id'] === $id) {
            return (int)$i;
        }
    }
    return -1;
}

/* ------------------------------------------------------------------- router */

$hash = get_hash();
$authed = !empty($_SESSION['auth']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && empty($_POST) && empty($_FILES)) {
        flash('err', 'That upload is larger than the server allows (post_max_size is ' . ini_get('post_max_size') . '). Upload fewer or smaller images at a time.');
        go();
    }
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        flash('err', 'Your session expired. Please try again.');
        go();
    }
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'setup' && $hash === '') {
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        if (strlen($p1) < 10) {
            flash('err', 'Use a password of at least 10 characters.');
        } elseif ($p1 !== $p2) {
            flash('err', 'The two passwords do not match.');
        } else {
            ensure_data_dir();
            $code = "<?php\nreturn " . var_export(['hash' => password_hash($p1, PASSWORD_DEFAULT)], true) . ";\n";
            if (@file_put_contents(CONF_FILE, $code, LOCK_EX) === false) {
                flash('err', 'Could not create data/config.php. Make the site folder writable by PHP and try again.');
            } else {
                @chmod(CONF_FILE, 0640);
                session_regenerate_id(true);
                $_SESSION['auth'] = true;
                flash('ok', 'Password set. You are signed in.');
            }
        }
        go();
    }

    if ($act === 'login' && $hash !== '') {
        if (recent_fails() >= 5) {
            flash('err', 'Too many failed attempts. Please wait 15 minutes.');
        } elseif (password_verify((string)($_POST['password'] ?? ''), $hash)) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
        } else {
            record_fail();
            sleep(1);
            flash('err', 'Wrong password.');
        }
        go();
    }

    if (!$authed) {
        go();
    }

    if ($act === 'logout') {
        $_SESSION = [];
        session_destroy();
        go();
    }

    if ($act === 'upload') {
        $r = action_upload($LAYOUTS);
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)$r['msg']);
        go();
    }

    if ($act === 'delete' || $act === 'update' || $act === 'up' || $act === 'down') {
        $id = (string)($_POST['id'] ?? '');
        $r = with_data(function (array &$data) use ($act, $id, $LAYOUTS) {
            $i = find_project($data, $id);
            if ($i < 0) {
                return ['ok' => false, 'msg' => 'Project not found.'];
            }
            if ($act === 'delete') {
                foreach ((array)$data['projects'][$i]['images'] as $img) {
                    delete_image_file((string)$img);
                }
                array_splice($data['projects'], $i, 1);
                return ['ok' => true, 'msg' => 'Project and its images were deleted.'];
            }
            if ($act === 'update') {
                $yr = (int)($_POST['year'] ?? 0);
                $mo = (int)($_POST['month'] ?? 0);
                if (!valid_year($yr)) {
                    return ['ok' => false, 'msg' => 'Please choose the year of design. It is required.'];
                }
                $data['projects'][$i]['year'] = $yr;
                $data['projects'][$i]['month'] = ($mo >= 0 && $mo <= 12) ? $mo : 0;
                $lay = (string)($_POST['layout'] ?? 'auto');
                $data['projects'][$i]['layout'] = isset($LAYOUTS[$lay]) ? $lay : 'auto';
                $t = clean_text($_POST['title'] ?? '', 80);
                $c = clean_text($_POST['caption'] ?? '', 120);
                if ($t !== '') {
                    $data['projects'][$i]['title'] = $t;
                }
                if ($c !== '') {
                    $data['projects'][$i]['caption'] = $c;
                }
                $data['projects'][$i]['details'] = clean_text($_POST['details'] ?? '', 1200);
                return ['ok' => true, 'msg' => 'Changes saved.'];
            }
            $j = $act === 'up' ? $i - 1 : $i + 1;
            if ($j < 0 || $j >= count($data['projects'])) {
                return ['ok' => false, 'msg' => 'Already at the edge of the list.'];
            }
            $tmp = $data['projects'][$i];
            $data['projects'][$i] = $data['projects'][$j];
            $data['projects'][$j] = $tmp;
            return ['ok' => true, 'msg' => 'Order updated.'];
        });
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)$r['msg']);
        go();
    }
    go();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = (string)$_SESSION['csrf'];

function page_head(string $title): void
{
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?></title>
<style>
:root{--acc:#d6246e;--bg:#0f0f12;--card:#17171c;--line:rgba(255,255,255,.09);--mut:#8a8a93}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:#e8e8ec;font:15px/1.5 -apple-system,"Segoe UI",Roboto,Arial,sans-serif}
a{color:var(--acc)}
.wrap{max-width:1100px;margin:0 auto;padding:24px 20px 80px}
.top{display:flex;align-items:center;gap:14px;margin-bottom:22px;flex-wrap:wrap}
.top h1{margin:0;font-size:18px;letter-spacing:3px;text-transform:uppercase;font-weight:800;flex:1}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:22px;margin-bottom:26px}
.card h2{margin:0 0 16px;font-size:14px;letter-spacing:2px;text-transform:uppercase}
label.f{display:block;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:var(--mut);margin:0 0 6px}
input[type=text],input[type=password],select,textarea{width:100%;background:#0f0f12;color:#fff;border:1px solid var(--line);border-radius:6px;padding:10px 12px;font:inherit}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--acc)}
textarea{min-height:84px;resize:vertical}
.row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.field{margin-bottom:16px}
.hint{font-size:12px;color:var(--mut);margin-top:6px}
.btn{background:var(--acc);color:#000;border:0;border-radius:6px;padding:11px 20px;font:inherit;font-weight:700;cursor:pointer}
.btn:hover{filter:brightness(1.1)}
.btn.ghost{background:none;color:#ddd;border:1px solid var(--line);font-weight:500;padding:8px 14px}
.btn.ghost:hover{border-color:var(--acc);color:var(--acc)}
.btn.danger:hover{border-color:#ff5a5a;color:#ff5a5a}
.btn[disabled]{opacity:.5;cursor:wait}
.flash{padding:12px 16px;border-radius:8px;margin-bottom:22px;font-size:14px}
.flash.ok{background:rgba(60,180,110,.14);border:1px solid rgba(60,180,110,.5)}
.flash.err{background:rgba(255,90,90,.12);border:1px solid rgba(255,90,90,.5)}
.drop{border:2px dashed rgba(255,255,255,.2);border-radius:10px;padding:26px;text-align:center;color:var(--mut);cursor:pointer;transition:.2s}
.drop.over,.drop:hover{border-color:var(--acc);color:#fff}
.drop input{display:none}
.thumbs{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;margin-top:14px}
.thumb{position:relative;aspect-ratio:1;border-radius:6px;overflow:hidden;background:#000}
.thumb img{width:100%;height:100%;object-fit:cover;display:block}
.thumb button{position:absolute;top:4px;right:4px;width:22px;height:22px;border-radius:50%;border:0;background:rgba(0,0,0,.7);color:#fff;cursor:pointer;line-height:1}
.thumb button:hover{background:var(--acc);color:#000}
.layouts{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px}
.lay{position:relative;cursor:pointer}
.lay input{position:absolute;opacity:0}
.lay .box{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;height:104px;border:1px solid var(--line);border-radius:8px;background:#0f0f12;font-size:12px;color:#bbb;transition:.15s;text-align:center}
.lay .shape{display:block;background:#444;border-radius:2px}
.lay .q{font-size:26px;line-height:26px;color:#888;font-weight:700}
.lay input:checked + .box{border-color:var(--acc);color:#fff;background:rgba(214,36,110,.1)}
.lay input:checked + .box .shape{background:var(--acc)}
.lay input:focus-visible + .box{outline:2px solid var(--acc)}
.path{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;color:#bbb;background:#0f0f12;border:1px solid var(--line);border-radius:6px;padding:8px 10px;margin-top:8px;word-break:break-all}
.proj{display:grid;grid-template-columns:1fr auto;gap:14px;padding:16px 0;border-top:1px solid var(--line)}
.proj:first-of-type{border-top:0}
.proj h3{margin:0;font-size:15px}
.proj .meta{font-size:12px;color:var(--mut);margin:2px 0 10px}
.strip{display:flex;gap:6px;flex-wrap:wrap}
.strip img{width:64px;height:64px;object-fit:cover;border-radius:4px;background:#000}
.tag{display:inline-block;font-size:11px;background:var(--acc);color:#000;font-weight:700;border-radius:8px;padding:0 8px;line-height:17px;margin-left:6px;vertical-align:middle}
.acts{display:flex;flex-direction:column;gap:6px;align-items:stretch}
.acts form{margin:0}
.acts .btn{width:100%}
details.edit{grid-column:1/-1}
details.edit summary{cursor:pointer;color:var(--acc);font-size:13px}
details.edit .box{margin-top:12px;padding:14px;background:#0f0f12;border-radius:8px;border:1px solid var(--line)}
.login{max-width:380px;margin:12vh auto}
@media(max-width:700px){.row{grid-template-columns:1fr}.proj{grid-template-columns:1fr}.acts{flex-direction:row;flex-wrap:wrap}.acts form{flex:1 1 90px}.wrap{padding:16px 12px 60px}.card{padding:16px}input[type=text],input[type=password],select,textarea{font-size:16px}.btn{padding:13px 20px}.btn.ghost{padding:11px 14px}.layouts{grid-template-columns:repeat(auto-fill,minmax(96px,1fr))}.thumbs{grid-template-columns:repeat(auto-fill,minmax(88px,1fr))}.top h1{font-size:15px}}
</style>
</head>
<body>
<?php
}

/* ------------------------------------------------------------- login screen */

if (!$authed) {
    page_head('Portfolio admin');
    ?>
<div class="wrap login">
  <div class="card">
    <h2><?= $hash === '' ? 'Create admin password' : 'Portfolio admin' ?></h2>
    <?php if ($flash) : ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <?php if ($hash === '') : ?>
        <input type="hidden" name="action" value="setup">
        <p class="hint" style="margin-top:0">First visit: choose the password you will use to sign in. Do this right away, before anyone else opens this page.</p>
        <div class="field"><label class="f">New password (10+ characters)</label><input type="password" name="password" required minlength="10"></div>
        <div class="field"><label class="f">Repeat password</label><input type="password" name="password2" required minlength="10"></div>
        <button class="btn" type="submit">Save password</button>
      <?php else : ?>
        <input type="hidden" name="action" value="login">
        <div class="field"><label class="f">Password</label><input type="password" name="password" required autofocus></div>
        <button class="btn" type="submit">Sign in</button>
      <?php endif; ?>
    </form>
  </div>
</div>
</body></html>
<?php
    exit;
}

/* ------------------------------------------------------------ admin screen */

$data = load_data();
$cats = $data['categories'];
$projects = $data['projects'];
$catsJs = [];
foreach ($cats as $i => $c) {
    $catsJs[] = ['i' => $i, 'name' => (string)$c['name'], 'subs' => array_values((array)($c['subs'] ?? []))];
}
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
page_head('Portfolio admin');
?>
<div class="wrap">
  <div class="top">
    <h1>Portfolio admin</h1>
    <a class="btn ghost" href="index.html" target="_blank" rel="noopener">View site</a>
    <form method="post" style="margin:0">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="logout">
      <button class="btn ghost" type="submit">Sign out</button>
    </form>
  </div>

  <?php if ($flash) : ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div><?php endif; ?>

  <div class="card">
    <h2>Upload images</h2>
    <form method="post" enctype="multipart/form-data" id="upForm">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="upload">

      <div class="field">
        <label class="f">1. Images</label>
        <div class="drop" id="drop">
          <div>Drag images here or <strong style="color:var(--acc)">click to choose</strong></div>
          <div class="hint">JPG, PNG, WebP or GIF. Up to <?= (int)(MAX_FILE_BYTES / 1048576) ?> MB each, <?= MAX_FILES ?> per upload. Large images are scaled down to <?= MAX_EDGE ?>px.</div>
          <input type="file" id="files" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
        </div>
        <div class="thumbs" id="thumbs"></div>
        <div class="hint">Server limits: <?= h(ini_get('upload_max_filesize')) ?> per file, <?= h(ini_get('post_max_size')) ?> per upload in total.</div>
      </div>

      <div class="row">
        <div>
          <label class="f" for="cat">2. Category</label>
          <select id="cat" name="category" required></select>
        </div>
        <div>
          <label class="f" for="sub">3. Sub-category</label>
          <select id="sub" name="sub" required></select>
          <input type="text" id="subNew" name="sub_new" maxlength="60" placeholder="New sub-category name" style="display:none;margin-top:8px">
        </div>
      </div>
      <div class="field">
        <label class="f">Saved in folder</label>
        <div class="path" id="path">images/portfolio/</div>
      </div>

      <div class="row">
        <div>
          <label class="f" for="title">Heading (shown in the popup)</label>
          <input type="text" id="title" name="title" maxlength="80" placeholder="Defaults to the category name">
        </div>
        <div>
          <label class="f" for="caption">Caption</label>
          <input type="text" id="caption" name="caption" maxlength="120" placeholder="Defaults to the sub-category name">
        </div>
      </div>
      <div class="field">
        <label class="f" for="details">Details</label>
        <textarea id="details" name="details" maxlength="1200" placeholder="A short description of the project"></textarea>
      </div>

      <div class="row">
        <div>
          <label class="f" for="year">4. Year of design (required)</label>
          <select id="year" name="year" required><?= year_options_html(0) ?></select>
        </div>
        <div>
          <label class="f" for="month">Month (optional)</label>
          <select id="month" name="month"><?= month_options_html(0) ?></select>
        </div>
      </div>

      <div class="field">
        <label class="f">5. Tile size on the portfolio page (columns x rows)</label>
        <div class="layouts">
          <?php foreach ($LAYOUTS as $k => $name) : ?>
            <label class="lay">
              <input type="radio" name="layout" value="<?= h($k) ?>" <?= $k === 'auto' ? 'checked' : '' ?>>
              <span class="box">
                <?php if ($k === 'random' || $k === 'auto') : ?>
                  <span class="q"><?= $k === 'auto' ? 'A' : '?' ?></span>
                <?php else : ?>
                  <?php list($lc, $lr) = array_map('intval', explode('x', $k)); ?>
                  <span class="shape" style="width:<?= 14 * $lc ?>px;height:<?= 14 * $lr ?>px"></span>
                <?php endif; ?>
                <span><?= h(preg_match('/^\d/', $k) ? $k . ' ' . $name : $name) ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="hint"><strong>Auto</strong> (recommended) lets the portfolio page choose the size that fits best with the other tiles, so the grid stays neatly filled and re-fits itself when you filter or resize. <strong>Random</strong> picks a different size (1x1, 2x1, 1x2 or 2x2) each time the page is opened. On small screens, tiles are never wider than the screen's columns.</div>
      </div>

      <button class="btn" type="submit" id="send">Upload</button>
    </form>
  </div>

  <div class="card" id="manage">
    <h2>Gallery (<?= count($projects) ?> project<?= count($projects) === 1 ? '' : 's' ?>)</h2>
    <?php if ($projects) : ?>
      <p class="hint" style="margin:0 0 6px">Uploads to the same category and sub-category are shown together as one tile on the portfolio page, with a badge showing the total number of images. Visitors can sort the portfolio by the year of design.</p>
    <?php endif; ?>
    <?php if (!$projects) : ?>
      <p class="hint" style="margin:0">Nothing uploaded yet. Until you upload your first project, the portfolio page shows demo tiles.</p>
    <?php endif; ?>
    <?php foreach ($projects as $i => $p) :
        $imgs = (array)($p['images'] ?? []);
        $lay = (string)($p['layout'] ?? 'auto');
        $yr = (int)($p['year'] ?? 0);
        $mo = (int)($p['month'] ?? 0);
        $mn = month_names();
        ?>
      <div class="proj">
        <div>
          <h3><?= h($p['title'] ?? '') ?><?php if (count($imgs) > 1) : ?><span class="tag"><?= count($imgs) ?></span><?php endif; ?></h3>
          <div class="meta"><?= h($p['category'] ?? '') ?> &rsaquo; <?= h($p['sub'] ?? '') ?> &middot; <?php if ($yr) : ?><?= h(($mo ? $mn[$mo] . ' ' : '') . $yr) ?><?php else : ?><span style="color:#ff7a7a">year missing: open Edit and choose it</span><?php endif; ?> &middot; size <?= h($lay) ?> &middot; <?= h($p['created'] ?? '') ?></div>
          <div class="strip">
            <?php foreach ($imgs as $im) : ?><img loading="lazy" src="<?= h($im) ?>" alt=""><?php endforeach; ?>
          </div>
        </div>
        <div class="acts">
          <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($p['id'] ?? '') ?>"><input type="hidden" name="action" value="up"><button class="btn ghost" type="submit" <?= $i === 0 ? 'disabled' : '' ?>>Move up</button></form>
          <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($p['id'] ?? '') ?>"><input type="hidden" name="action" value="down"><button class="btn ghost" type="submit" <?= $i === count($projects) - 1 ? 'disabled' : '' ?>>Move down</button></form>
          <form method="post" onsubmit="return confirm('Delete this project and all of its images?')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($p['id'] ?? '') ?>"><input type="hidden" name="action" value="delete"><button class="btn ghost danger" type="submit">Delete</button></form>
        </div>
        <details class="edit">
          <summary>Edit heading, caption, details, size</summary>
          <form method="post" class="box">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($p['id'] ?? '') ?>"><input type="hidden" name="action" value="update">
            <div class="row">
              <div><label class="f">Heading</label><input type="text" name="title" maxlength="80" value="<?= h($p['title'] ?? '') ?>"></div>
              <div><label class="f">Caption</label><input type="text" name="caption" maxlength="120" value="<?= h($p['caption'] ?? '') ?>"></div>
            </div>
            <div class="field"><label class="f">Details</label><textarea name="details" maxlength="1200"><?= h($p['details'] ?? '') ?></textarea></div>
            <div class="row">
              <div><label class="f">Year of design (required)</label><select name="year" required><?= year_options_html($yr) ?></select></div>
              <div><label class="f">Month (optional)</label><select name="month"><?= month_options_html($mo) ?></select></div>
            </div>
            <div class="field" style="max-width:260px"><label class="f">Tile size</label>
              <select name="layout">
                <?php foreach ($LAYOUTS as $k => $name) : ?>
                  <option value="<?= h($k) ?>" <?= $k === $lay ? 'selected' : '' ?>><?= h(preg_match('/^\d/', $k) ? $k . ' ' . $name : $name) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn" type="submit">Save changes</button>
          </form>
        </details>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
(function () {
  var CATS = <?= json_encode($catsJs, $jsonFlags) ?>;
  var MAXB = <?= (int)MAX_FILE_BYTES ?>, MAXN = <?= (int)MAX_FILES ?>;
  var cat = document.getElementById('cat'), sub = document.getElementById('sub'), subNew = document.getElementById('subNew');
  var pathEl = document.getElementById('path'), input = document.getElementById('files'), drop = document.getElementById('drop');
  var thumbs = document.getElementById('thumbs'), form = document.getElementById('upForm'), send = document.getElementById('send');
  var dt = new DataTransfer();

  function slug(s) { s = s.replace(/&/g, ' and ').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); return s ? s.slice(0, 60) : 'item'; }

  CATS.forEach(function (c) { var o = document.createElement('option'); o.value = c.i; o.textContent = c.name; cat.appendChild(o); });
  function fillSubs() {
    var c = CATS.filter(function (x) { return String(x.i) === cat.value; })[0];
    sub.innerHTML = '';
    ((c && c.subs) || []).forEach(function (s) { var o = document.createElement('option'); o.value = s; o.textContent = s; sub.appendChild(o); });
    var n = document.createElement('option'); n.value = '__new__'; n.textContent = '+ New sub-category...'; sub.appendChild(n);
    showPath();
  }
  function showPath() {
    var c = CATS.filter(function (x) { return String(x.i) === cat.value; })[0];
    var isNew = sub.value === '__new__';
    subNew.style.display = isNew ? 'block' : 'none';
    subNew.required = isNew;
    var s = isNew ? (subNew.value || '...') : sub.value;
    pathEl.textContent = 'images/portfolio/' + (c ? slug(c.name) : '') + '/' + (isNew && !subNew.value ? '...' : slug(s)) + '/';
  }
  cat.addEventListener('change', fillSubs);
  sub.addEventListener('change', showPath);
  subNew.addEventListener('input', showPath);
  fillSubs();

  function render() {
    thumbs.innerHTML = '';
    Array.prototype.forEach.call(dt.files, function (f, i) {
      var d = document.createElement('div'); d.className = 'thumb';
      var im = document.createElement('img'); im.src = URL.createObjectURL(f); im.alt = f.name; d.appendChild(im);
      var b = document.createElement('button'); b.type = 'button'; b.textContent = '\u00d7'; b.title = 'Remove';
      b.addEventListener('click', function (e) { e.stopPropagation(); removeAt(i); });
      d.appendChild(b); thumbs.appendChild(d);
    });
    send.textContent = dt.files.length ? 'Upload ' + dt.files.length + ' image' + (dt.files.length > 1 ? 's' : '') : 'Upload';
  }
  function removeAt(i) {
    var n = new DataTransfer();
    Array.prototype.forEach.call(dt.files, function (f, k) { if (k !== i) n.items.add(f); });
    dt = n; input.files = dt.files; render();
  }
  function addFiles(list) {
    var skipped = [];
    Array.prototype.forEach.call(list, function (f) {
      if (!/^image\/(jpeg|png|webp|gif)$/.test(f.type)) { skipped.push(f.name + ' (not a supported image)'); return; }
      if (f.size > MAXB) { skipped.push(f.name + ' (too large)'); return; }
      var dup = Array.prototype.some.call(dt.files, function (x) { return x.name === f.name && x.size === f.size && x.lastModified === f.lastModified; });
      if (dup) return;
      if (dt.files.length >= MAXN) { skipped.push(f.name + ' (limit of ' + MAXN + ' per upload)'); return; }
      dt.items.add(f);
    });
    input.files = dt.files; render();
    if (skipped.length) alert('Skipped:\n' + skipped.join('\n'));
  }
  drop.addEventListener('click', function () { input.click(); });
  input.addEventListener('click', function (e) { e.stopPropagation(); });
  input.addEventListener('change', function () { var chosen = Array.prototype.slice.call(input.files); addFiles(chosen); });
  ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
  drop.addEventListener('drop', function (e) { addFiles(e.dataTransfer.files); });
  form.addEventListener('submit', function (e) {
    if (!dt.files.length) { e.preventDefault(); alert('Please choose at least one image.'); return; }
    send.disabled = true; send.textContent = 'Uploading...';
  });
})();
</script>
</body></html>
