<?php
// Load .env file if present
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($k, $v) = explode('=', $line, 2);
                $k = trim($k);
                $v = trim(trim($v), "\"'");
                if (!array_key_exists($k, $_SERVER) && !array_key_exists($k, $_ENV)) {
                    putenv("$k=$v");
                    $_ENV[$k] = $v;
                    $_SERVER[$k] = $v;
                }
            }
        }
    }
}

// Serverless environments (e.g. Vercel) where standard session storage is read-only
if (getenv('VERCEL') || !empty($_ENV['VERCEL'])) {
    $tmpDir = sys_get_temp_dir();
    if (is_dir($tmpDir) && is_writable($tmpDir)) {
        ini_set('session.save_path', $tmpDir);
    }
}

// =====================================================
//  SETTINGS -- environment variables win over defaults
// =====================================================
$dbDriver = 'mysql';
$dbHost = getenv('DB_HOST') ?: (getenv('MYSQL_HOST') ?: 'localhost');
$dbPort = getenv('DB_PORT') ?: (getenv('MYSQL_PORT') ?: '3306');
$dbName = getenv('DB_NAME') ?: (getenv('MYSQL_DATABASE') ?: 'kap_salon');
$dbUser = getenv('DB_USER') ?: (getenv('MYSQL_USER') ?: 'root');
$dbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : (getenv('MYSQL_PASSWORD') !== false ? getenv('MYSQL_PASSWORD') : '');
$dbSslCa = getenv('DB_SSL_CA') ?: null;

// Support full connection URLs from cloud providers (e.g. Supabase, Aiven, Render, Heroku)
$dbUrl = getenv('DATABASE_URL') ?: (getenv('MYSQL_URL') ?: getenv('DIRECT_URL'));
if ($dbUrl) {
    $parts = parse_url($dbUrl);
    if ($parts) {
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme === 'postgres' || $scheme === 'postgresql') {
            $dbDriver = 'pgsql';
        }
        if (!empty($parts['host'])) $dbHost = $parts['host'];
        if (!empty($parts['port'])) $dbPort = $parts['port'];
        if (!empty($parts['user'])) $dbUser = urldecode($parts['user']);
        if (isset($parts['pass']))  $dbPass = urldecode($parts['pass']);
        if (!empty($parts['path'])) $dbName = ltrim($parts['path'], '/');
    }
} elseif (getenv('DB_DRIVER')) {
    $dbDriver = strtolower(getenv('DB_DRIVER'));
} elseif (strpos($dbHost, 'supabase.co') !== false || $dbPort == 5432 || $dbPort == 6543) {
    $dbDriver = 'pgsql';
}

define('DB_DRIVER', $dbDriver);
define('DB_HOST', $dbHost);
define('DB_PORT', $dbPort);
define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);

define('MAIL_ENABLED',   getenv('MAIL_ENABLED') ? (getenv('MAIL_ENABLED') === 'true' || getenv('MAIL_ENABLED') === '1') : false);
define('GMAIL_USER',     getenv('GMAIL_USER') ?: 'your@gmail.com');
define('GMAIL_APP_PASS', getenv('GMAIL_APP_PASS') ?: 'xxxx xxxx xxxx xxxx');
define('ADMIN_EMAIL',    getenv('ADMIN_EMAIL') ?: 'your@gmail.com');

define('BRAND',          getenv('BRAND') ?: 'Glow Smart Salon');

// ---- Business rules ----
define('REMINDER_HOURS', (int)(getenv('REMINDER_HOURS') ?: 24));
define('CRON_KEY',       getenv('CRON_KEY') ?: 'change-this-key');

// ---- SMS defaults (PhilSMS / Semaphore) ----
define('SMS_PROVIDER',   getenv('SMS_PROVIDER') ?: 'philsms'); // 'philsms' or 'semaphore'
define('SMS_ENABLED',    getenv('SMS_ENABLED') ? (getenv('SMS_ENABLED') === 'true' || getenv('SMS_ENABLED') === '1') : false);
define('SMS_API_KEY',    getenv('SMS_API_KEY') ?: (getenv('PHILSMS_API_KEY') ?: (getenv('SEMAPHORE_API_KEY') ?: 'your-philsms-api-token')));
define('SMS_SENDER',     getenv('SMS_SENDER') ?: (getenv('PHILSMS_SENDER_ID') ?: 'PhilSMS'));
define('SMS_URL',        getenv('SMS_URL') ?: '');
// =====================================================

date_default_timezone_set('Asia/Manila');
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

try {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    if (DB_DRIVER === 'pgsql') {
        $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";sslmode=require";
        // Required for PgBouncer / Supabase transaction pooler:
        $options[PDO::ATTR_EMULATE_PREPARES] = true;
    } else {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        // SSL support for Cloud MySQL (e.g. Aiven)
        $isCloudDb = (strpos(DB_HOST, 'aivencloud.com') !== false) || getenv('DB_SSL') === 'true' || getenv('DB_SSL') === '1';
        if ($isCloudDb || !empty($dbSslCa)) {
            if (!empty($dbSslCa)) {
                if (file_exists($dbSslCa)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $dbSslCa;
                } else {
                    $caFile = sys_get_temp_dir() . '/aiven-ca.pem';
                    @file_put_contents($caFile, $dbSslCa);
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $caFile;
                }
            } elseif (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
        }
    }

    $db = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (Exception $x) {
    die('Database connection failed: ' . htmlspecialchars($x->getMessage()) . '<br><br>Tip: Check DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS or DATABASE_URL in your environment / .env file.');
}

// Driver compatibility helper
function db_is_pg(PDO $db): bool {
    static $isPg = null;
    if ($isPg === null) {
        $isPg = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql');
    }
    return $isPg;
}

function db_last_id(PDO $db, string $table = '', string $col = '') {
    if (db_is_pg($db) && $table !== '' && $col !== '') {
        try {
            return $db->lastInsertId("{$table}_{$col}_seq");
        } catch (Exception $e) {
            return $db->lastInsertId();
        }
    }
    return $db->lastInsertId();
}

function db_ts_expr(PDO $db, string $dCol, string $tCol): string {
    return db_is_pg($db) ? "({$dCol} + {$tCol})" : "TIMESTAMP({$dCol}, {$tCol})";
}

// Keep clock in same timezone
if (db_is_pg($db)) {
    $db->exec("SET TIME ZONE 'Asia/Manila'");
} else {
    $db->exec("SET time_zone = '" . (new DateTime('now'))->format('P') . "'");
}

// Seed a default admin account on first run.
if (!$db->query("SELECT COUNT(*) FROM account WHERE role='admin'")->fetchColumn()) {
    $db->prepare("INSERT INTO account(username,password,role) VALUES('admin',?,'admin')")
       ->execute([password_hash('admin123', PASSWORD_DEFAULT)]);
}

// =====================================================
//  SMALL HELPERS
// =====================================================

/** Escape a value for safe HTML output. */
function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** CSRF token field - include this inside every <form>. */
function tok() {
    if (empty($_SESSION['t'])) {
        $_SESSION['t'] = bin2hex(random_bytes(16));
    }
    return "<input type=hidden name=t value='{$_SESSION['t']}'>";
}

/** Call at the top of every POST handler to verify the CSRF token. */
function chk() {
    if (!hash_equals($_SESSION['t'] ?? '', $_POST['t'] ?? '')) {
        die('Invalid request. Please refresh the page.');
    }
}

/** Store or retrieve a one-time flash message shown after a redirect. */
function flash($m = null) {
    if ($m !== null) {
        $_SESSION['f'] = $m;
        return;
    }
    $f = $_SESSION['f'] ?? '';
    unset($_SESSION['f']);
    return $f;
}

/** Redirect to the login page unless the logged-in user has this role. */
function need($role) {
    if (($_SESSION['u']['role'] ?? '') !== $role) {
        header('Location: ' . ($role === 'customer' ? 'login.php' : 'admin-login.php'));
        exit;
    }
}

/** Format a number as Philippine peso currency. */
function money($n) {
    return '₱' . number_format((float)$n, 2);
}

/** Turn a full name into 1-2 letter initials for the avatar badge. */
function initials($s) {
    $s = trim((string)$s);
    if ($s === '') {
        return '?';
    }
    $p = preg_split('/\s+/', $s);
    $i = strtoupper(substr($p[0], 0, 1));
    if (count($p) > 1) {
        $i .= strtoupper(substr(end($p), 0, 1));
    }
    return $i;
}

/**
 * Send an email, or just log it when MAIL_ENABLED is false (offline mode).
 * Every email is always appended to emails.log regardless of mode.
 */
function send_mail($to, $sub, $body) {
    $logFile = __DIR__ . '/emails.log';
    if (!is_writable(__DIR__)) {
        $logFile = sys_get_temp_dir() . '/emails.log';
    }
    @file_put_contents($logFile, date('c') . " TO:$to | $sub\n$body\n\n", FILE_APPEND);

    if (!MAIL_ENABLED || strpos(GMAIL_USER, 'your@') === 0) {
        return false;
    }

    $f = @stream_socket_client('ssl://smtp.gmail.com:465', $en, $es, 15);
    if (!$f) {
        return false;
    }

    $r = function ($c = null) use ($f) {
        if ($c !== null) {
            fwrite($f, $c . "\r\n");
        }
        $o = '';
        while ($l = fgets($f, 515)) {
            $o .= $l;
            if (substr($l, 3, 1) == ' ') break;
        }
        return $o;
    };

    $r();
    $r('EHLO localhost');
    $r('AUTH LOGIN');
    $r(base64_encode(GMAIL_USER));
    $r(base64_encode(str_replace(' ', '', GMAIL_APP_PASS)));
    $r('MAIL FROM:<' . GMAIL_USER . '>');
    $r("RCPT TO:<$to>");
    $r('DATA');
    fwrite($f,
        "From: " . BRAND . " <" . GMAIL_USER . ">\r\n" .
        "To: $to\r\n" .
        "Subject: =?UTF-8?B?" . base64_encode($sub) . "?=\r\n" .
        "MIME-Version: 1.0\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n\r\n" .
        str_replace("\n.", "\n..", $body) . "\r\n.\r\n"
    );
    $x = $r();
    $r('QUIT');
    fclose($f);
    return strpos($x, '250') === 0;
}

/**
 * Save an uploaded image file under /uploads/<subdir>/ with a random name.
 * Returns the stored filename on success, or null if nothing was uploaded
 * (field empty - not an error), or false if the file was rejected.
 */
function save_upload($field, $subdir, $maxBytes = 4 * 1024 * 1024) {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // nothing chosen - that's fine, uploads are optional
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    if ($f['size'] > $maxBytes) {
        return false;
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        return false;
    }
    $dir = __DIR__ . "/uploads/$subdir";
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            $dir = sys_get_temp_dir() . "/uploads/$subdir";
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) {
        return false;
    }
    return $name;
}

// =====================================================
//  SHARED CSS (used by both layout styles below)
// =====================================================
function base_css() { ?>
:root {
    --ink: #0f3d2e;       --ink2: #15503c;
    --acc: #2f9e63;       --acc-dark: #1f7a4d;
    --bg: #f3f7f4;        --card: #ffffff;
    --ln: #e4ece6;        --text: #1c2a22;      --muted: #6b7a71;
    --warn: #e67e22;      --warn-bg: #fdf1e6;
    --bad: #d33f3f;       --bad-bg: #fbe9e9;
    --info: #2574a9;      --info-bg: #e8f2f9;
    --radius: 14px;
}
* { box-sizing: border-box }
body { margin: 0; font: 15px/1.55 "Segoe UI", system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text) }
h1, h2, h3 { color: var(--ink); margin: .1em 0 .5em; font-weight: 700 }
a { color: var(--acc-dark) }

.box {
    background: var(--card);
    border: 1px solid var(--ln);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 18px;
    overflow-x: auto;
    box-shadow: 0 1px 2px rgba(15, 61, 46, .04);
}

label { display: block; font-weight: 600; margin: 10px 0 4px; font-size: .88rem; color: #33463c }
input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #cfdcd3; border-radius: 10px; font: inherit; background: #fff }
input:focus, select:focus, textarea:focus { outline: 2px solid var(--acc); border-color: var(--acc) }

button, .btn {
    background: var(--ink2);
    color: #fff;
    border: 0;
    padding: 10px 18px;
    border-radius: 10px;
    cursor: pointer;
    font: inherit;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    transition: filter .15s;
}
button:hover, .btn:hover { filter: brightness(1.08) }
button.ok { background: var(--acc-dark) }
button.no { background: var(--bad) }
button.sm { padding: 5px 12px; font-size: .82rem; border-radius: 8px }

table { width: 100%; border-collapse: collapse }
th, td { padding: 10px 8px; border-bottom: 1px solid var(--ln); text-align: left; vertical-align: top; font-size: .92rem }
th { color: var(--muted); font-weight: 600; font-size: .8rem; text-transform: uppercase; letter-spacing: .03em }

.msg { background: #eaf7ef; border: 1px solid var(--acc); color: #174a30; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: .92rem }
.msg.bad { background: var(--bad-bg); border-color: var(--bad); color: #7a1f1f }

.tag { padding: 3px 11px; border-radius: 999px; background: #eef3ef; font-size: .78rem; font-weight: 600; color: #3c5248; text-transform: capitalize }
.tag.pending, .tag.open { background: var(--warn-bg); color: #9a5614 }
.tag.approved, .tag.paid, .tag.done, .tag.available { background: #e6f6eb; color: #1b7f4b }
.tag.active { background: #e6f6eb; color: #1b7f4b }
.tag.inactive { background: var(--bad-bg); color: #9a2323 }
.tag.confirmed { background: var(--info-bg); color: #1c5c84 }
.tag.rescheduled { background: #eee6fb; color: #5b36a8 }
.tag.completed { background: #e6f6eb; color: #1b7f4b }
.tag.sent { background: #e6f6eb; color: #1b7f4b }
.tag.not_configured, .tag.no_number { background: #eef1ef; color: #5a6b62 }
.tag.cancelled, .tag.failed { background: var(--bad-bg); color: #9a2323 }
.tag.rejected, .tag.occupied { background: var(--bad-bg); color: #9a2323 }
.tag.unpaid, .tag.payment_rejected { background: var(--bad-bg); color: #9a2323 }
.tag.payment_paid { background: #e6f6eb; color: #1b7f4b }
.tag.payment_sent { background: var(--info-bg); color: #1c5c84 }
.stars { color: #e6a817; letter-spacing: 1px; white-space: nowrap }
.scard { display: flex; gap: 14px; align-items: flex-start }
.scard .av { width: 46px; height: 46px; border-radius: 50%; background: #e3f6ea; color: var(--ink); font-weight: 800; display: flex; align-items: center; justify-content: center; flex: 0 0 46px }
.slotgrid { display: flex; flex-wrap: wrap; gap: 8px; margin: 8px 0 }
.slotgrid label { flex: 0 0 auto; min-width: 0; margin: 0; border: 1px solid var(--ln); border-radius: 10px; padding: 8px 14px; background: #fff; cursor: pointer; font-weight: 600 }
.slotgrid input { width: auto; margin-right: 6px }
.tag.in_progress, .tag.maintenance { background: var(--info-bg); color: #1c5c84 }

.row { display: flex; gap: 10px; flex-wrap: wrap; align-items: end }
.row > * { flex: 1; min-width: 140px }
form.i { display: inline }
:focus-visible { outline: 3px solid var(--acc) }

/* ---- stat cards (dashboard) ---- */
.statgrid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 20px }
.stat {
    background: var(--card);
    border: 1px solid var(--ln);
    border-radius: var(--radius);
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    box-shadow: 0 1px 2px rgba(15, 61, 46, .04);
}
.stat .top { display: flex; align-items: center; justify-content: space-between }
.stat .ic { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.05rem }
.stat .lbl { font-size: .74rem; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; font-weight: 700 }
.stat .val { font-size: 1.7rem; font-weight: 800; color: var(--ink) }
.stat .view { font-size: .78rem; color: var(--acc-dark); text-decoration: none; font-weight: 600 }

.ic.g { background: #e3f6ea; color: #1b7f4b }
.ic.o { background: #fdecd8; color: #e67e22 }
.ic.r { background: #fbe4e4; color: #d33f3f }
.ic.p { background: #eee6fb; color: #7b4fd3 }
.ic.t { background: #dff3ee; color: #128a6f }

/* ---- quick-action tiles ---- */
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-top: 4px }
.tile { background: linear-gradient(135deg, #eef9f1, #e4f3e9); border: 1px solid var(--ln); border-radius: var(--radius); padding: 18px; text-decoration: none; color: inherit; display: block }
.tile .ic { width: 38px; height: 38px; border-radius: 10px; background: #fff; display: flex; align-items: center; justify-content: center; margin-bottom: 10px; font-size: 1.1rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .06) }
.tile b { display: block; color: var(--ink); margin-bottom: 3px }
.tile span { font-size: .85rem; color: var(--muted) }

/* ---- room photo thumbnails + "View" gallery modal ---- */
.thumbs { display: flex; gap: 6px; flex-wrap: wrap }
.thumbs img { width: 52px; height: 52px; object-fit: cover; border-radius: 8px; border: 1px solid var(--ln) }
.viewbtn { background: #eef6f0; color: var(--acc-dark); border: 1px solid var(--ln); padding: 6px 14px; border-radius: 8px; cursor: pointer; font: inherit; font-weight: 600; font-size: .85rem }
.viewbtn:hover { background: #e3f1e7 }

.modal-bg { display: none; position: fixed; inset: 0; background: rgba(10, 30, 20, .7); z-index: 50; align-items: center; justify-content: center; padding: 20px }
.modal-bg.open { display: flex }
.modal-box { background: #fff; border-radius: var(--radius); max-width: 640px; width: 100%; max-height: 85vh; overflow: auto; padding: 20px; position: relative }
.modal-box .x { position: absolute; top: 12px; right: 14px; background: none; border: 0; font-size: 1.4rem; line-height: 1; cursor: pointer; color: var(--muted); padding: 4px }
.modal-box h3 { margin-top: 0 }
.modal-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px }
.modal-gallery img { width: 100%; height: 130px; object-fit: cover; border-radius: 10px; border: 1px solid var(--ln) }
.modal-empty { color: var(--muted); font-size: .9rem }

/* ---- profile info card ---- */
.infogrid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px }
.infogrid .f { display: flex; flex-direction: column; gap: 3px }
.infogrid .f label { margin: 0; font-size: .74rem; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; font-weight: 700 }
.infogrid .f span { font-size: .98rem; color: var(--text); font-weight: 600 }

/* ---- file input ---- */
input[type=file] { padding: 8px; background: #fafcfa; cursor: pointer }
<?php }

// =====================================================
//  LAYOUT 1: sidebar dashboard (logged-in admin/tenant pages)
// =====================================================
function dash_head($title, $active, $nav, $who, $role) { ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> | <?= e(BRAND) ?></title>
<style>
<?php base_css(); ?>

.app { display: flex; min-height: 100vh }

.sidebar {
    width: 230px;
    flex: 0 0 230px;
    background: linear-gradient(180deg, var(--ink) 0%, #0b2e22 100%);
    color: #eafff2;
    display: flex;
    flex-direction: column;
    position: sticky;
    top: 0;
    height: 100vh;
}
.sidebar .brand { display: flex; align-items: center; gap: 10px; padding: 20px 18px; border-bottom: 1px solid rgba(255, 255, 255, .08) }
.sidebar .brand .logo { width: 34px; height: 34px; border-radius: 9px; background: #fff; color: var(--ink); display: flex; align-items: center; justify-content: center; font-weight: 800 }
.sidebar .brand b { font-size: 1rem; line-height: 1.1 }
.sidebar .brand small { display: block; font-size: .68rem; color: #a9d9bf; font-weight: 400 }

.who { margin: 14px 14px; background: rgba(255, 255, 255, .07); border-radius: 12px; padding: 12px; display: flex; gap: 10px; align-items: center }
.who .av { width: 36px; height: 36px; border-radius: 50%; background: #eafff2; color: var(--ink); font-weight: 800; display: flex; align-items: center; justify-content: center; font-size: .8rem; flex: 0 0 36px }
.who b { display: block; font-size: .86rem }
.who small { color: #a9d9bf; font-size: .72rem }

.nav { flex: 1; padding: 8px 10px; display: flex; flex-direction: column; gap: 2px }
.nav a { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; color: #d9f2e3; text-decoration: none; font-size: .9rem; font-weight: 500 }
.nav a .em { width: 18px; text-align: center }
.nav a:hover { background: rgba(255, 255, 255, .08) }
.nav a.on { background: #eafff2; color: var(--ink); font-weight: 700 }

.sidebar .out { padding: 14px; border-top: 1px solid rgba(255, 255, 255, .08) }
.sidebar .out a { display: flex; align-items: center; gap: 10px; color: #ffd7d7; text-decoration: none; font-size: .88rem; font-weight: 600 }

main { flex: 1; min-width: 0 }
.topbar { background: #fff; border-bottom: 1px solid var(--ln); padding: 16px 28px; display: flex; align-items: center; justify-content: space-between }
.topbar h1 { margin: 0; font-size: 1.25rem }
.content { padding: 24px 28px; max-width: 1200px }

@media (max-width: 820px) {
    .sidebar { width: 72px; flex: 0 0 72px }
    .sidebar .brand b, .sidebar .brand small,
    .who b, .who small,
    .nav a span, .sidebar .out a span { display: none }
    .sidebar .brand, .who, .nav a, .sidebar .out a { justify-content: center }
    .who { padding: 8px }
}
</style>
</head>
<body>
<div class=app>
    <aside class=sidebar>
        <div class=brand>
            <div class=logo>G</div>
            <div><b><?= e(BRAND) ?></b><small>Management</small></div>
        </div>
        <div class=who>
            <div class=av><?= e(initials($who)) ?></div>
            <div><b><?= e($who) ?></b><small><?= e(ucfirst($role)) ?></small></div>
        </div>
        <nav class=nav>
        <?php foreach ($nav as $key => $it) {
            [$label, $icon, $url] = $it;
            $on = $key === $active ? ' on' : '';
            echo "<a class='$on' href='" . e($url) . "'><span class=em>$icon</span><span>" . e($label) . "</span></a>";
        } ?>
        </nav>
        <div class=out><a href="logout.php"><span class=em>&#8617;</span><span>Log out</span></a></div>
    </aside>
    <main>
        <div class=topbar><h1><?= e($title) ?></h1></div>
        <div class=content>
        <?php if ($m = flash()) echo "<div class='msg'>" . $m . "</div>"; }

function dash_foot() { ?>
        </div>
    </main>
</div>
</body>
</html>
<?php }

// =====================================================
//  LAYOUT 2: split-screen auth style (login page & public booking page)
// =====================================================
function auth_head($title, $tagline, $staff = false) { ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> | <?= e(BRAND) ?></title>
<style>
<?php base_css(); ?>

body { background: var(--ink) }
.split { min-height: 100vh; display: flex }

.pic {
    flex: 1 1 50%;
    position: relative;
    overflow: hidden;
    background:
        radial-gradient(circle at 20% 20%, #2f9e63 0%, transparent 45%),
        radial-gradient(circle at 80% 30%, #1f7a4d 0%, transparent 40%),
        radial-gradient(circle at 50% 85%, #0b2e22 0%, transparent 55%),
        linear-gradient(135deg, #123524, #0b2e22 60%);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    color: #fff;
    padding: 40px;
    text-align: center;
}
.pic .ring { width: 84px; height: 84px; border-radius: 22px; background: rgba(255, 255, 255, .12); display: flex; align-items: center; justify-content: center; font-size: 2rem; margin-bottom: 22px; border: 1px solid rgba(255, 255, 255, .25) }
.pic h2 { color: #fff; font-size: 1.9rem; margin: 0 0 6px }
.pic p { color: #cdeedd; margin: 0; font-size: .95rem }
.pic .deco { position: absolute; border-radius: 50%; background: rgba(255, 255, 255, .06) }

.formside { flex: 1 1 50%; background: var(--bg); display: flex; align-items: center; justify-content: center; padding: 40px 24px }
.formcard { width: 100%; max-width: 380px }
.formcard h1 { font-size: 1.7rem; margin-bottom: 2px }
.formcard .sub { color: var(--muted); margin-bottom: 22px; font-size: .92rem }

<?php if ($staff): ?>
.pic { background: radial-gradient(circle at 25% 20%, #3b5b8c 0%, transparent 45%), linear-gradient(135deg, #14213d, #0b1426 70%) }
.formcard h1 { color: #14213d }
<?php endif; ?>
@media (max-width: 760px) {
    .pic { display: none }
    .split { min-height: auto }
}
</style>
</head>
<body>
<div class=split>
    <div class=pic>
        <div class="deco" style="width:220px;height:220px;top:-60px;left:-60px"></div>
        <div class="deco" style="width:160px;height:160px;bottom:-40px;right:-30px"></div>
        <div class=ring><?= $staff ? '&#128274;' : '&#9986;' ?></div>
        <h2><?= e(BRAND) ?></h2>
        <p><?= e($tagline) ?></p>
    </div>
    <div class=formside>
        <div class=formcard>
        <?php if ($m = flash()) echo "<div class='msg'>" . $m . "</div>"; }

function auth_foot() { ?>
        </div>
    </div>
</div>
</body>
</html>
<?php }

require __DIR__ . '/lib.php';
