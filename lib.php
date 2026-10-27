<?php
// =====================================================
//  SALON LOGIC shared by customer.php, staff.php, admin.php, reminders.php
// =====================================================
const ACTIVE_STATUSES = ['pending', 'confirmed', 'rescheduled'];   // statuses that hold a time slot
const TRANSITIONS = [
    'pending'     => ['confirmed', 'cancelled'],
    'confirmed'   => ['completed', 'cancelled'],
    'rescheduled' => ['confirmed', 'completed', 'cancelled'],
];
const SLOT_STEP = 30;        // minutes between bookable start times
const MAX_DAYS_AHEAD = 90;
const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

// ---------- validation / formatting ----------
function valid_phone($p) { return (bool)preg_match('/^(09\d{9}|\+639\d{9}|639\d{9})$/', (string)$p); }
function valid_date($d) { $x = DateTime::createFromFormat('Y-m-d', (string)$d); return $x && $x->format('Y-m-d') === $d; }
function valid_time($t) { $x = DateTime::createFromFormat('H:i', (string)$t); return $x && $x->format('H:i') === $t; }
function to_min($t) { $p = explode(':', (string)$t); return (int)$p[0] * 60 + (int)($p[1] ?? 0); }
function from_min($n) { return sprintf('%02d:%02d:00', intdiv($n, 60), $n % 60); }
function t12($t) { return date('g:i A', strtotime('1970-01-01 ' . $t)); }
function d_nice($d) { return date('D, M j, Y', strtotime($d)); }
function tag($s) { return "<span class='tag " . e($s) . "'>" . e(str_replace('_', ' ', $s)) . "</span>"; }

// ---------- availability ----------
/** Bookable start times ('HH:MM') for one staff member / service / date. */
function free_slots(PDO $db, $sid, array $svc, $date, $exclude = null) {
    if (!valid_date($date)) return [];
    $q = $db->prepare("SELECT start_time,end_time FROM staff_schedule WHERE staff_id=? AND weekday=?");
    $q->execute([$sid, (int)date('w', strtotime($date))]);
    $sch = $q->fetch();
    if (!$sch) return [];
    $q = $db->prepare("SELECT start_time,end_time FROM appointment WHERE staff_id=? AND appt_date=? AND active_slot=1 AND appointment_id<>?");
    $q->execute([$sid, $date, (int)$exclude]);
    $busy = $q->fetchAll();
    $dur = (int)$svc['duration_min'];
    $out = [];
    for ($m = to_min($sch['start_time']); $m + $dur <= to_min($sch['end_time']); $m += SLOT_STEP) {
        if (strtotime($date . ' ' . from_min($m)) <= time()) continue;
        $ok = true;
        foreach ($busy as $b) {
            if (to_min($b['start_time']) < $m + $dur && to_min($b['end_time']) > $m) { $ok = false; break; }
        }
        if ($ok) $out[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
    return $out;
}

/** First active staff member who offers the service and is free at that exact time. */
function pick_staff(PDO $db, array $svc, $date, $time) {
    $q = $db->prepare("SELECT s.staff_id FROM staff s JOIN staff_service ss ON ss.staff_id=s.staff_id WHERE s.active=1 AND ss.service_id=? ORDER BY s.name");
    $q->execute([$svc['service_id']]);
    foreach ($q->fetchAll() as $r) {
        if (in_array($time, free_slots($db, $r['staff_id'], $svc, $date), true)) return (int)$r['staff_id'];
    }
    return 0;
}

// ---------- create / reschedule (the double-booking guard) ----------
/**
 * Create a new appointment ($id = null) or move an existing one ($id given).
 * $a = customer_id, staff_id, service_id, date (Y-m-d), time (H:i), notes
 * Returns [true, appointment_id] or [false, 'error message'].
 * Every rule is checked here on the server; the browser checks are only a convenience.
 */
function appt_save(PDO $db, array $a, $id = null) {
    $date = $a['date'] ?? '';
    $time = $a['time'] ?? '';
    $busyMsg = 'Sorry, that staff member is already booked during that time. Please choose another time or staff member.';

    if (!valid_date($date)) return [false, 'Please choose a valid date.'];
    if (!valid_time($time)) return [false, 'Please choose a valid time.'];
    if (strtotime("$date $time") <= time()) return [false, 'That date and time is already in the past.'];
    if ($date > date('Y-m-d', strtotime('+' . MAX_DAYS_AHEAD . ' days'))) return [false, 'Appointments can only be booked up to ' . MAX_DAYS_AHEAD . ' days ahead.'];

    $q = $db->prepare("SELECT * FROM service WHERE service_id=? AND active=1");
    $q->execute([(int)($a['service_id'] ?? 0)]);
    $svc = $q->fetch();
    if (!$svc) return [false, 'That service is not available.'];

    $sid = (int)($a['staff_id'] ?? 0);
    $q = $db->prepare("SELECT 1 FROM staff s JOIN staff_service ss ON ss.staff_id=s.staff_id WHERE s.staff_id=? AND s.active=1 AND ss.service_id=?");
    $q->execute([$sid, $svc['service_id']]);
    if (!$q->fetchColumn()) return [false, 'That staff member is not available for this service.'];

    $q = $db->prepare("SELECT start_time,end_time FROM staff_schedule WHERE staff_id=? AND weekday=?");
    $q->execute([$sid, (int)date('w', strtotime($date))]);
    $sch = $q->fetch();
    if (!$sch) return [false, 'That staff member does not work on ' . DAYS[(int)date('w', strtotime($date))] . 's.'];

    $start = to_min($time);
    $end = $start + (int)$svc['duration_min'];
    if ($start < to_min($sch['start_time']) || $end > to_min($sch['end_time'])) {
        return [false, 'That time is outside the staff member\'s working hours (' . t12($sch['start_time']) . ' - ' . t12($sch['end_time']) . ').'];
    }
    $startT = from_min($start);
    $endT = from_min($end);

    $db->beginTransaction();
    try {
        // Lock this staff member's row so two simultaneous bookings are checked one at a time.
        $db->prepare("SELECT staff_id FROM staff WHERE staff_id=? FOR UPDATE")->execute([$sid]);

        $c = $db->prepare("SELECT COUNT(*) FROM appointment WHERE staff_id=? AND appt_date=? AND active_slot=1 AND start_time<? AND end_time>? AND appointment_id<>?");
        $c->execute([$sid, $date, $endT, $startT, (int)$id]);
        if ($c->fetchColumn()) { $db->rollBack(); return [false, $busyMsg]; }

        $c = $db->prepare("SELECT COUNT(*) FROM appointment WHERE customer_id=? AND appt_date=? AND active_slot=1 AND start_time<? AND end_time>? AND appointment_id<>?");
        $c->execute([(int)$a['customer_id'], $date, $endT, $startT, (int)$id]);
        if ($c->fetchColumn()) { $db->rollBack(); return [false, 'The customer already has another appointment that overlaps this time.']; }

        if ($id) {
            $db->prepare("UPDATE appointment SET staff_id=?,appt_date=?,start_time=?,end_time=?,status='rescheduled',active_slot=1,reminder_sent=0 WHERE appointment_id=?")
               ->execute([$sid, $date, $startT, $endT, $id]);
        } else {
            $db->prepare("INSERT INTO appointment(customer_id,staff_id,service_id,appt_date,start_time,end_time,status,notes,active_slot) VALUES(?,?,?,?,?,?,'pending',?,1)")
               ->execute([(int)$a['customer_id'], $sid, $svc['service_id'], $date, $startT, $endT, $a['notes'] ?? null]);
            $id = (int)db_last_id($db, 'appointment', 'appointment_id');
        }
        $db->commit();
        return [true, (int)$id];
    } catch (PDOException $ex) {
        if ($db->inTransaction()) $db->rollBack();
        if ($ex->getCode() === '23000') return [false, $busyMsg];   // unique key no_double_booking
        throw $ex;
    }
}

/** Change status (confirm / cancel / complete) following the allowed transitions. */
function appt_set_status(PDO $db, $id, $to) {
    $q = $db->prepare("SELECT * FROM appointment WHERE appointment_id=?");
    $q->execute([$id]);
    $r = $q->fetch();
    if (!$r) return [false, 'Appointment not found.'];
    if (!in_array($to, TRANSITIONS[$r['status']] ?? [], true)) return [false, "A {$r['status']} appointment cannot be changed to $to."];
    if ($to === 'completed' && strtotime($r['appt_date'] . ' ' . $r['start_time']) > time()) {
        return [false, 'An appointment can only be marked completed once its start time has passed.'];
    }
    $db->prepare("UPDATE appointment SET status=?,active_slot=? WHERE appointment_id=?")
       ->execute([$to, in_array($to, ACTIVE_STATUSES, true) ? 1 : null, $id]);
    if (in_array($to, ['confirmed', 'cancelled', 'completed'], true)) notify_appt($db, $id, $to);
    return [true, "Appointment marked $to."];
}

// ---------- notifications ----------
function setting_get(PDO $db, $k, $default = '') {
    try {
        $q = $db->prepare("SELECT svalue FROM setting WHERE skey=?");
        $q->execute([$k]);
        $v = $q->fetchColumn();
        return $v === false ? $default : $v;
    } catch (PDOException $e) { return $default; }   // table missing: import upgrade.sql
}
function setting_set(PDO $db, $k, $v) {
    try {
        if (db_is_pg($db)) {
            $db->prepare("INSERT INTO setting(skey,svalue) VALUES(?,?) ON CONFLICT (skey) DO UPDATE SET svalue=EXCLUDED.svalue")->execute([$k, $v]);
        } else {
            $db->prepare("INSERT INTO setting(skey,svalue) VALUES(?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)")->execute([$k, $v]);
        }
        return true;
    } catch (PDOException $e) { return false; }
}
function setting_ready(PDO $db) {
    try { $db->query("SELECT 1 FROM setting LIMIT 1"); return true; } catch (PDOException $e) { return false; }
}

/** SMS settings: values saved on the admin SMS page win over the defaults in config.php. */
function sms_cfg(PDO $db) {
    $provider = (string)setting_get($db, 'sms_provider', defined('SMS_PROVIDER') ? SMS_PROVIDER : 'philsms');
    $defaultKey = defined('SMS_API_KEY') ? SMS_API_KEY : '';
    $key = trim((string)setting_get($db, 'sms_key', $defaultKey));
    $defaultEnabled = defined('SMS_ENABLED') ? SMS_ENABLED : false;
    $enabled = setting_get($db, 'sms_enabled', $defaultEnabled ? '1' : '0') === '1';
    $demo = setting_get($db, 'sms_demo', '0') === '1';
    $has = $key !== '' && $key !== 'your-semaphore-api-key' && $key !== 'your-philsms-api-token';
    $defaultSender = defined('SMS_SENDER') ? SMS_SENDER : ($provider === 'philsms' ? 'GLOWSALON' : '');
    $defaultUrl = $provider === 'philsms' ? 'https://dashboard.philsms.com/api/v3/sms/send' : 'https://api.semaphore.co/api/v4/messages';
    $rawUrl = (string)setting_get($db, 'sms_url', (defined('SMS_URL') && SMS_URL !== '' ? SMS_URL : $defaultUrl));
    if (strpos($rawUrl, 'app.philsms.com') !== false) {
        $rawUrl = str_replace('app.philsms.com', 'dashboard.philsms.com', $rawUrl);
    }
    return [
        'provider' => $provider,
        'enabled'  => $enabled,
        'demo'     => $demo,
        'has_key'  => $has,
        'on'       => $enabled && ($has || $demo),
        'key'      => $key,
        'sender'   => trim((string)setting_get($db, 'sms_sender', $defaultSender)),
        'url'      => $rawUrl,
    ];
}

/** Returns [status, detail]. status: sent | failed | not_configured | no_number */
function sms_send_detail(PDO $db, $phone, $msg) {
    $cfg = sms_cfg($db);
    if (!$cfg['on']) return ['not_configured', $cfg['enabled'] ? 'SMS is turned on but there is no API key yet.' : 'SMS is turned off (admin > SMS).'];
    $raw = preg_replace('/\D/', '', (string)$phone);
    if ($raw === '') return ['no_number', 'The customer has no mobile number.'];

    // Academic Demo / Presentation Mode (instant simulated delivery while PhilSMS Sender ID is Pending approval)
    if (!empty($cfg['demo'])) {
        $sid = $cfg['sender'] !== '' ? $cfg['sender'] : 'GLOWSALON';
        setting_set($db, 'sms_last_ok', date('M j, g:i A') . ' (Demo Mode)');
        return ['sent', 'Demo SMS sent to ' . $phone . ' via ' . strtoupper($cfg['provider']) . ' [Sender ID: ' . $sid . ']'];
    }

    $provider = $cfg['provider'] ?? 'philsms';

    if ($provider === 'philsms') {
        // PhilSMS expects 639XXXXXXXXX
        $num = $raw;
        if (strpos($num, '09') === 0) {
            $num = '63' . substr($num, 1);
        } elseif (strpos($num, '9') === 0 && strlen($num) === 10) {
            $num = '63' . $num;
        }
        if (!preg_match('/^639\d{9}$/', $num)) {
            return ['failed', 'Invalid mobile number for PhilSMS (expected 09XXXXXXXXX or 639XXXXXXXXX): ' . $phone];
        }

        $sender = $cfg['sender'] !== '' ? $cfg['sender'] : 'PhilSMS';
        $payload = json_encode([
            'recipient' => $num,
            'sender_id' => $sender,
            'type'      => 'plain',
            'message'   => $msg
        ]);

        $headers = [
            'Authorization: Bearer ' . $cfg['key'],
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $res = false;
        $err = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($cfg['url']);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $res = curl_exec($ch);
            if ($res === false) $err = curl_error($ch);
            curl_close($ch);
        } else {
            $headerStr = implode("\r\n", $headers) . "\r\n";
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'timeout'       => 12,
                'ignore_errors' => true,
                'header'        => $headerStr,
                'content'       => $payload
            ]]);
            $res = @file_get_contents($cfg['url'], false, $ctx);
            if ($res === false) $err = 'Could not reach PhilSMS server (check your internet connection).';
        }

        $j = $res !== false ? json_decode($res, true) : null;
        if (is_array($j) && (($j['status'] ?? '') === 'success' || isset($j['data']['id']))) {
            setting_set($db, 'sms_last_ok', date('M j, g:i A'));
            return ['sent', 'Sent to ' . $num];
        }

        $detail = '';
        if (is_array($j) && !empty($j['message'])) {
            $detail = 'PhilSMS: ' . $j['message'];
        } elseif ($res === false) {
            $detail = $err ?: 'No response from PhilSMS server.';
        } else {
            $detail = 'PhilSMS replied: ' . mb_substr(trim((string)$res), 0, 200);
        }
        setting_set($db, 'sms_last_error', date('M j, g:i A') . ' - ' . $detail);
        return ['failed', $detail];
    } else {
        // Semaphore expects 09XXXXXXXXX
        $num = $raw;
        if (strpos($num, '63') === 0) $num = '0' . substr($num, 2);
        if (!preg_match('/^09\d{9}$/', $num)) return ['failed', 'Invalid mobile number: ' . $phone];

        $f = ['apikey' => $cfg['key'], 'number' => $num, 'message' => $msg];
        if ($cfg['sender'] !== '') $f['sendername'] = $cfg['sender'];
        $body = http_build_query($f);
        $res = false; $err = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($cfg['url']);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6]);
            $res = curl_exec($ch);
            if ($res === false) $err = curl_error($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10, 'ignore_errors' => true,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $body]]);
            $res = @file_get_contents($cfg['url'], false, $ctx);
            if ($res === false) $err = 'Could not reach the SMS server (check your internet connection).';
        }
        $j = $res !== false ? json_decode($res, true) : null;
        if (is_array($j) && isset($j[0]['message_id'])) {
            setting_set($db, 'sms_last_ok', date('M j, g:i A'));
            return ['sent', 'Sent to ' . $num];
        }
        $detail = $res === false ? ($err ?: 'No response from the SMS server.') : 'The SMS provider replied: ' . mb_substr(trim((string)$res), 0, 200);
        setting_set($db, 'sms_last_error', date('M j, g:i A') . ' - ' . $detail);
        return ['failed', $detail];
    }
}

function sms_send($phone, $msg) {
    global $db;
    return sms_send_detail($db, $phone, $msg)[0];
}

/** types: booked | confirmed | rescheduled | cancelled | reminder */
function notify_appt(PDO $db, $id, $type) {
    $q = $db->prepare("SELECT a.*,c.name cname,c.email,c.phone,s.name sname,sv.name svname FROM appointment a
        JOIN customer c ON c.customer_id=a.customer_id JOIN staff s ON s.staff_id=a.staff_id
        JOIN service sv ON sv.service_id=a.service_id WHERE a.appointment_id=?");
    $q->execute([$id]);
    $r = $q->fetch();
    if (!$r) return;
    $what = "{$r['svname']} with {$r['sname']} on " . d_nice($r['appt_date']) . ' at ' . t12($r['start_time']);
    $text = [
        'booked'      => "Hi {$r['cname']}, we received your booking: $what. Status: pending confirmation.",
        'confirmed'   => "Hi {$r['cname']}, your appointment is CONFIRMED: $what.",
        'rescheduled' => "Hi {$r['cname']}, your appointment was RESCHEDULED to: $what.",
        'cancelled'   => "Hi {$r['cname']}, your appointment was CANCELLED: $what.",
        'reminder'    => "Reminder: Hi {$r['cname']}, you have an appointment: $what.",
        'completed'   => "Hi {$r['cname']}, thank you for visiting! Your appointment is COMPLETED: $what. Please rate your visit under My Appointments > History.",
    ][$type] ?? null;
    if ($text === null) return;
    $text .= ' - ' . BRAND;
    $sms = sms_send($r['phone'], $text);
    send_mail($r['email'], BRAND . ': appointment ' . $type, $text);   // always logged to emails.log
    $db->prepare("INSERT INTO notification(customer_id,appointment_id,type,message,sms_status) VALUES(?,?,?,?,?)")
       ->execute([$r['customer_id'], $id, $type, $text, $sms]);
}

/** Send reminders for confirmed/rescheduled appointments starting within REMINDER_HOURS. Returns how many. */
function send_due_reminders(PDO $db) {
    $ts = db_ts_expr($db, 'appt_date', 'start_time');
    $q = $db->prepare("SELECT appointment_id FROM appointment WHERE status IN ('confirmed','rescheduled') AND reminder_sent=0
        AND {$ts} > ? AND {$ts} <= ?");
    $q->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+' . REMINDER_HOURS . ' hours'))]);
    $n = 0;
    foreach ($q->fetchAll() as $r) {
        notify_appt($db, $r['appointment_id'], 'reminder');
        $db->prepare("UPDATE appointment SET reminder_sent=1 WHERE appointment_id=?")->execute([$r['appointment_id']]);
        $n++;
    }
    return $n;
}

// ---------- shared staff/admin appointment handling ----------
/**
 * Handles confirm / cancel / complete / reschedule POSTs.
 * $onlyStaff = the logged-in staff member's id (they may only touch their own appointments), or null for admin.
 * Returns true if the POST was one of these actions (flash message already set).
 */
function appt_post(PDO $db, $onlyStaff) {
    $a = $_POST['a'] ?? '';
    if (!in_array($a, ['confirmed', 'cancelled', 'completed', 'reschedule', 'pay_paid'], true)) return false;
    $q = $db->prepare("SELECT * FROM appointment WHERE appointment_id=?");
    $q->execute([(int)($_POST['id'] ?? 0)]);
    $r = $q->fetch();
    if (!$r || ($onlyStaff !== null && (int)$r['staff_id'] !== (int)$onlyStaff)) { flash('&#9888; Appointment not found.'); return true; }

    if ($a === 'pay_paid') {
        [$ok, $m] = pay_set($db, (int)$r['appointment_id']);
        flash(($ok ? '' : '&#9888; ') . e($m));
        return true;
    }

    if ($a === 'reschedule') {
        if (!in_array($r['status'], ACTIVE_STATUSES, true)) { flash('&#9888; Only pending, confirmed or rescheduled appointments can be rescheduled.'); return true; }
        $sid = $onlyStaff !== null ? (int)$onlyStaff : (int)($_POST['staff_id'] ?? $r['staff_id']);
        [$ok, $res] = appt_save($db, ['customer_id' => $r['customer_id'], 'staff_id' => $sid, 'service_id' => $r['service_id'],
            'date' => $_POST['date'] ?? '', 'time' => $_POST['time'] ?? ''], (int)$r['appointment_id']);
        if ($ok) { notify_appt($db, $res, 'rescheduled'); flash('Appointment rescheduled. The customer was notified.'); }
        else flash('&#9888; ' . e($res));
        return true;
    }
    [$ok, $m] = appt_set_status($db, (int)$r['appointment_id'], $a);
    flash(($ok ? '' : '&#9888; ') . e($m));
    return true;
}

/** Appointments joined with customer/staff/service. $where uses a./c./s./sv. aliases. */
function appt_rows(PDO $db, $where = '1=1', array $args = [], $limit = 200) {
    $q = $db->prepare("SELECT a.*,c.name cname,c.phone cphone,c.email cemail,s.name sname,sv.name svname,sv.price,sv.duration_min,
        CASE WHEN pm.status='paid' THEN 'paid' ELSE NULL END AS pstatus,pm.method pmethod,rv.rating,rv.comment rcomment
        FROM appointment a JOIN customer c ON c.customer_id=a.customer_id JOIN staff s ON s.staff_id=a.staff_id
        JOIN service sv ON sv.service_id=a.service_id
        LEFT JOIN payment pm ON pm.appointment_id=a.appointment_id LEFT JOIN review rv ON rv.appointment_id=a.appointment_id
        WHERE $where ORDER BY a.appt_date DESC,a.start_time DESC LIMIT " . (int)$limit);
    $q->execute($args);
    return $q->fetchAll();
}

/** Table with status badges and action buttons. $custUrl e.g. 'admin.php?p=customer&id='. $staffOpts: [id=>name] for admin reschedule. */
function appt_table(array $rows, $custUrl, $staffOpts = null) {
    if (!$rows) { echo "<p style='color:var(--muted)'>No appointments found.</p>"; return; }
    $today = date('Y-m-d');
    echo "<table><tr><th>Customer</th><th>Service</th><th>Staff</th><th>Date &amp; time</th><th>Status</th><th>Payment</th><th>Actions</th></tr>";
    foreach ($rows as $r) {
        $id = (int)$r['appointment_id'];
        $ps = $r['pstatus'] ?? null;
        $btn = function ($act, $label, $cls = '') use ($id) {
            return "<form method=post class=i>" . tok() . "<input type=hidden name=id value=$id><input type=hidden name=a value=$act><button class='sm $cls'>$label</button></form> ";
        };
        echo "<tr><td><a href='" . e($custUrl . $r['customer_id']) . "'><b>" . e($r['cname']) . "</b></a><br><small>" . e($r['cphone']) . "</small></td>";
        echo "<td>" . e($r['svname']) . "<br><small>" . money($r['price']) . " &middot; " . (int)$r['duration_min'] . " min</small>"
            . ($r['notes'] ? "<br><small>Note: " . e($r['notes']) . "</small>" : '') . "</td>";
        echo "<td>" . e($r['sname']) . "</td>";
        echo "<td>" . e(d_nice($r['appt_date'])) . "<br><small>" . t12($r['start_time']) . " - " . t12($r['end_time']) . "</small></td>";
        echo "<td>" . tag($r['status']) . "</td>";
        echo "<td>" . tag($ps ?: 'unpaid');
        if ($ps) echo "<br><small>" . e(PAY_METHODS[$r['pmethod']] ?? $r['pmethod']) . "</small>";
        echo "</td><td>";
        if ($r['status'] === 'pending') echo $btn('confirmed', 'Confirm', 'ok');
        if (in_array($r['status'], ['confirmed', 'rescheduled'], true)) {
            if ($r['status'] === 'rescheduled') echo $btn('confirmed', 'Confirm', 'ok');
            echo $btn('completed', 'Complete', 'ok');
        }
        if ($r['status'] !== 'cancelled' && $ps !== 'paid') echo $btn('pay_paid', 'Mark paid (cash)', 'ok');
        if (in_array($r['status'], ACTIVE_STATUSES, true)) {
            echo $btn('cancelled', 'Cancel', 'no');
            echo "<details><summary style='cursor:pointer;font-size:.82rem'>Reschedule</summary><form method=post>" . tok()
                . "<input type=hidden name=id value=$id><input type=hidden name=a value=reschedule>";
            if ($staffOpts) {
                echo "<select name=staff_id>";
                foreach ($staffOpts as $sid => $sn) echo "<option value=$sid" . ($sid == $r['staff_id'] ? ' selected' : '') . ">" . e($sn) . "</option>";
                echo "</select>";
            }
            echo "<input type=date name=date min='$today' required><input type=time name=time step=1800 required><button class='sm'>Move</button></form></details>";
        }
        echo "</td></tr>";
    }
    echo "</table>";
}

/** Read-only history of one customer's appointments (service history). */
function cust_history(PDO $db, $cid) {
    $rows = appt_rows($db, 'a.customer_id=?', [$cid]);
    if (!$rows) { echo "<p style='color:var(--muted)'>No appointments yet.</p>"; return; }
    echo "<table><tr><th>Date</th><th>Service</th><th>Staff</th><th>Price</th><th>Status</th></tr>";
    foreach ($rows as $r) {
        echo "<tr><td>" . e(d_nice($r['appt_date'])) . "<br><small>" . t12($r['start_time']) . "</small></td><td>" . e($r['svname']) . "</td><td>" . e($r['sname'])
            . "</td><td>" . money($r['price']) . "</td><td>" . tag($r['status']) . "</td></tr>";
    }
    echo "</table>";
}

// ---------- weekly schedule editor (used by admin and by staff for their own hours) ----------
function sched_save(PDO $db, $sid) {
    $db->beginTransaction();
    $db->prepare("DELETE FROM staff_schedule WHERE staff_id=?")->execute([$sid]);
    for ($w = 0; $w < 7; $w++) {
        if (empty($_POST['on'][$w])) continue;
        $s = $_POST['start'][$w] ?? ''; $en = $_POST['end'][$w] ?? '';
        if (!valid_time($s) || !valid_time($en) || to_min($en) <= to_min($s)) {
            $db->rollBack();
            return DAYS[$w] . ': enter a valid start and end time (end must be after start).';
        }
        $db->prepare("INSERT INTO staff_schedule(staff_id,weekday,start_time,end_time) VALUES(?,?,?,?)")->execute([$sid, $w, "$s:00", "$en:00"]);
    }
    $db->commit();
    return '';
}

function sched_form(PDO $db, $sid) {
    $q = $db->prepare("SELECT * FROM staff_schedule WHERE staff_id=?");
    $q->execute([$sid]);
    $cur = [];
    foreach ($q->fetchAll() as $r) $cur[(int)$r['weekday']] = $r;
    echo "<form method=post>" . tok() . "<input type=hidden name=a value=sched><input type=hidden name=sid value=" . (int)$sid . ">";
    echo "<table><tr><th>Day</th><th>Working?</th><th>From</th><th>To</th></tr>";
    foreach ([1, 2, 3, 4, 5, 6, 0] as $w) {
        $r = $cur[$w] ?? null;
        echo "<tr><td>" . DAYS[$w] . "</td><td><input type=checkbox name='on[$w]' value=1 style='width:auto'" . ($r ? ' checked' : '') . "></td>"
            . "<td><input type=time name='start[$w]' step=1800 value='" . ($r ? substr($r['start_time'], 0, 5) : '09:00') . "'></td>"
            . "<td><input type=time name='end[$w]' step=1800 value='" . ($r ? substr($r['end_time'], 0, 5) : '18:00') . "'></td></tr>";
    }
    echo "</table><p><button class=ok>Save schedule</button></p></form>";
}


// =====================================================
//  STAFF AVAILABILITY + RATINGS (customer can see who is free and how well they are rated)
// =====================================================
function stars($n) { $n = max(0, min(5, (int)round($n))); return str_repeat('★', $n) . str_repeat('☆', 5 - $n); }

/** [staff_id => ['avg' => 4.5, 'n' => 3]] */
function staff_ratings(PDO $db) {
    $o = [];
    foreach ($db->query("SELECT staff_id, AVG(rating) a, COUNT(*) n FROM review GROUP BY staff_id") as $r) {
        $o[(int)$r['staff_id']] = ['avg' => (float)$r['a'], 'n' => (int)$r['n']];
    }
    return $o;
}

function rating_html($rt) {
    if (!$rt) return "<small style='color:var(--muted)'>No ratings yet</small>";
    return "<span class=stars>" . stars($rt['avg']) . "</span> " . number_format($rt['avg'], 1) . " <small style='color:var(--muted)'>(" . (int)$rt['n'] . ")</small>";
}

function staff_works(PDO $db, $sid, $date) {
    $q = $db->prepare("SELECT 1 FROM staff_schedule WHERE staff_id=? AND weekday=?");
    $q->execute([$sid, (int)date('w', strtotime($date))]);
    return (bool)$q->fetchColumn();
}

/** First date within $days on which this staff member has a free slot for the service, or null. */
function staff_next_open(PDO $db, $sid, array $svc, $days = 14) {
    for ($i = 0; $i <= $days; $i++) {
        $d = date('Y-m-d', strtotime("+$i days"));
        if (free_slots($db, $sid, $svc, $d)) return $d;
    }
    return null;
}

/** Customer rates a completed appointment (once). Returns [ok, message]. */
function review_save(PDO $db, $cid, $aid, $rating, $comment) {
    if ($rating < 1 || $rating > 5) return [false, 'Please choose 1 to 5 stars.'];
    $q = $db->prepare("SELECT * FROM appointment WHERE appointment_id=? AND customer_id=?");
    $q->execute([$aid, $cid]);
    $a = $q->fetch();
    if (!$a) return [false, 'Appointment not found.'];
    if ($a['status'] !== 'completed') return [false, 'You can rate an appointment once it is completed.'];
    try {
        $db->prepare("INSERT INTO review(appointment_id,customer_id,staff_id,rating,comment) VALUES(?,?,?,?,?)")
           ->execute([$aid, $cid, $a['staff_id'], $rating, $comment !== '' ? $comment : null]);
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') return [false, 'You already rated this appointment.'];
        throw $ex;
    }
    return [true, 'Thank you for your feedback!'];
}

// =====================================================
//  PAYMENTS - cash only. The salon marks an appointment "paid" when the customer pays at the salon.
// =====================================================
// Only cash is used now. The other names are just labels for old, already-paid records from the removed online feature.
const PAY_METHODS = ['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya', 'bank' => 'Bank transfer', 'other' => 'Other'];

/** Admin/staff marks an appointment as paid (cash received). Returns [ok, message]. */
function pay_set(PDO $db, $aid) {
    $q = $db->prepare("SELECT a.status astatus, sv.price FROM appointment a JOIN service sv ON sv.service_id=a.service_id WHERE a.appointment_id=?");
    $q->execute([$aid]);
    $a = $q->fetch();
    if (!$a) return [false, 'Appointment not found.'];
    if ($a['astatus'] === 'cancelled') return [false, 'A cancelled appointment cannot be marked paid.'];
    $q = $db->prepare("SELECT * FROM payment WHERE appointment_id=?");
    $q->execute([$aid]);
    $p = $q->fetch();
    if ($p && $p['status'] === 'paid') return [false, 'This appointment is already marked paid.'];
    $now = date('Y-m-d H:i:s');
    try {
        if ($p) $db->prepare("UPDATE payment SET status='paid',method='cash',amount=?,reference_no=NULL,proof=NULL,paid_at=? WHERE payment_id=?")->execute([$a['price'], $now, $p['payment_id']]);
        else    $db->prepare("INSERT INTO payment(appointment_id,method,amount,status,paid_at) VALUES(?,'cash',?,'paid',?)")->execute([$aid, $a['price'], $now]);
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') return [false, 'This appointment is already marked paid.'];   // double click
        throw $ex;
    }
    notify_payment($db, $aid);
    return [true, 'Marked as paid (cash). The customer was notified.'];
}

/** Tell the customer their payment was received (in-app + SMS/email as configured). */
function notify_payment(PDO $db, $aid) {
    $q = $db->prepare("SELECT a.*,c.name cname,c.email,c.phone,s.name sname,sv.name svname,pm.amount FROM appointment a
        JOIN customer c ON c.customer_id=a.customer_id JOIN staff s ON s.staff_id=a.staff_id JOIN service sv ON sv.service_id=a.service_id
        JOIN payment pm ON pm.appointment_id=a.appointment_id WHERE a.appointment_id=?");
    $q->execute([$aid]);
    $r = $q->fetch();
    if (!$r) return;
    $what = "{$r['svname']} with {$r['sname']} on " . d_nice($r['appt_date']) . ' at ' . t12($r['start_time']);
    $text = "Hi {$r['cname']}, we received your payment of " . money($r['amount']) . " (cash) for $what. Thank you! - " . BRAND;
    $sms = sms_send($r['phone'], $text);
    send_mail($r['email'], BRAND . ': payment received', $text);
    $db->prepare("INSERT INTO notification(customer_id,appointment_id,type,message,sms_status) VALUES(?,?,?,?,?)")
       ->execute([$r['customer_id'], $aid, 'payment_paid', $text, $sms]);
}

// =====================================================
//  LOGIN (customers and staff/admin use different forms)
// =====================================================
/**
 * Check the posted login against the allowed roles. On success it starts the session and redirects (never returns).
 * On failure it returns the error message to show.
 */
function try_login(PDO $db, array $roles, $wrongPortalMsg) {
    $s = $db->prepare("SELECT * FROM account WHERE username=?");
    $s->execute([strtolower(trim($_POST['username'] ?? ''))]);
    $u = $s->fetch();
    if (!$u || !password_verify($_POST['password'] ?? '', $u['password'])) return 'Incorrect username or password.';
    if (!in_array($u['role'], $roles, true)) return $wrongPortalMsg;
    if ($u['role'] === 'staff') {
        $a = $db->prepare("SELECT active FROM staff WHERE account_id=?");
        $a->execute([$u['account_id']]);
        if (!(int)$a->fetchColumn()) return 'This staff account has been deactivated. Please contact the admin.';
    }
    session_regenerate_id(true);
    $_SESSION['u'] = ['id' => $u['account_id'], 'role' => $u['role'], 'username' => $u['username']];
    header('Location: ' . (['admin' => 'admin.php', 'staff' => 'staff.php'][$u['role']] ?? 'customer.php'));
    exit;
}


// =====================================================
//  SETUP HELPERS - customers only see staff that (1) offer the service and (2) have working hours
// =====================================================
/** Give a new service to every active staff member, and/or a new staff member every active service + default hours (Mon-Sat 9-5). */
function setup_defaults(PDO $db, $staffId = null, $serviceId = null) {
    $isPg = db_is_pg($db);
    if ($serviceId) {
        $sql = $isPg
            ? "INSERT INTO staff_service(staff_id,service_id) SELECT staff_id,? FROM staff WHERE active=1 ON CONFLICT (staff_id,service_id) DO NOTHING"
            : "INSERT IGNORE INTO staff_service(staff_id,service_id) SELECT staff_id,? FROM staff WHERE active=1";
        $db->prepare($sql)->execute([$serviceId]);
    }
    if ($staffId) {
        $sql = $isPg
            ? "INSERT INTO staff_service(staff_id,service_id) SELECT ?,service_id FROM service WHERE active=1 ON CONFLICT (staff_id,service_id) DO NOTHING"
            : "INSERT IGNORE INTO staff_service(staff_id,service_id) SELECT ?,service_id FROM service WHERE active=1";
        $db->prepare($sql)->execute([$staffId]);
        set_default_hours($db, $staffId);
    }
}

function set_default_hours(PDO $db, $staffId) {
    $has = $db->prepare("SELECT COUNT(*) FROM staff_schedule WHERE staff_id=?");
    $has->execute([$staffId]);
    if ($has->fetchColumn()) return;
    $ins = $db->prepare("INSERT INTO staff_schedule(staff_id,weekday,start_time,end_time) VALUES(?,?,'09:00:00','17:00:00')");
    foreach ([1, 2, 3, 4, 5, 6] as $w) $ins->execute([$staffId, $w]);
}

/** Things that stop customers from booking. Returns a list of plain-text problems (empty = all good). */
function setup_problems(PDO $db) {
    $p = [];
    if (!(int)$db->query("SELECT COUNT(*) FROM service WHERE active=1")->fetchColumn()) $p[] = 'There is no active service yet.';
    if (!(int)$db->query("SELECT COUNT(*) FROM staff WHERE active=1")->fetchColumn()) $p[] = 'There is no active staff member yet.';
    foreach ($db->query("SELECT sv.name FROM service sv WHERE sv.active=1 AND NOT EXISTS
        (SELECT 1 FROM staff_service ss JOIN staff s ON s.staff_id=ss.staff_id WHERE ss.service_id=sv.service_id AND s.active=1)") as $r)
        $p[] = 'Service "' . $r['name'] . '" is not assigned to any staff, so customers cannot book it.';
    foreach ($db->query("SELECT s.name FROM staff s WHERE s.active=1 AND NOT EXISTS (SELECT 1 FROM staff_service ss WHERE ss.staff_id=s.staff_id)") as $r)
        $p[] = 'Staff "' . $r['name'] . '" has no services assigned.';
    foreach ($db->query("SELECT s.name FROM staff s WHERE s.active=1 AND NOT EXISTS (SELECT 1 FROM staff_schedule sc WHERE sc.staff_id=s.staff_id)") as $r)
        $p[] = 'Staff "' . $r['name'] . '" has no working days/hours set, so shows as "Day off".';
    return $p;
}

/** One-click repair for existing data. Returns how many things were fixed. */
function setup_autofix(PDO $db) {
    $n = 0;
    $isPg = db_is_pg($db);
    $insStaffSql = $isPg
        ? "INSERT INTO staff_service(staff_id,service_id) SELECT ?,service_id FROM service WHERE active=1 ON CONFLICT (staff_id,service_id) DO NOTHING"
        : "INSERT IGNORE INTO staff_service(staff_id,service_id) SELECT ?,service_id FROM service WHERE active=1";
    $insSvcSql = $isPg
        ? "INSERT INTO staff_service(staff_id,service_id) SELECT staff_id,? FROM staff WHERE active=1 ON CONFLICT (staff_id,service_id) DO NOTHING"
        : "INSERT IGNORE INTO staff_service(staff_id,service_id) SELECT staff_id,? FROM staff WHERE active=1";

    foreach ($db->query("SELECT staff_id FROM staff WHERE active=1")->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $c = $db->prepare("SELECT COUNT(*) FROM staff_service WHERE staff_id=?"); $c->execute([$sid]);
        if (!$c->fetchColumn()) { $db->prepare($insStaffSql)->execute([$sid]); $n++; }
        $c = $db->prepare("SELECT COUNT(*) FROM staff_schedule WHERE staff_id=?"); $c->execute([$sid]);
        if (!$c->fetchColumn()) { set_default_hours($db, $sid); $n++; }
    }
    foreach ($db->query("SELECT sv.service_id FROM service sv WHERE sv.active=1 AND NOT EXISTS
        (SELECT 1 FROM staff_service ss JOIN staff s ON s.staff_id=ss.staff_id WHERE ss.service_id=sv.service_id AND s.active=1)")->fetchAll(PDO::FETCH_COLUMN) as $svc) {
        $db->prepare($insSvcSql)->execute([$svc]); $n++;
    }
    return $n;
}
