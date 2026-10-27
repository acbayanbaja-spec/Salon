<?php
require 'config.php';
need('customer');

$q = $db->prepare("SELECT * FROM customer WHERE account_id=?");
$q->execute([$_SESSION['u']['id']]);
$me = $q->fetch();

$p = $_GET['p'] ?? 'dash';

// =====================================================
//  FORM HANDLING
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me) {
    chk();
    $a = $_POST['a'] ?? '';
    $back = 'customer.php?p=' . urlencode($p);

    if ($a === 'book') {
        $svcId = (int)($_POST['service_id'] ?? 0);
        $sid   = (int)($_POST['staff_id'] ?? 0);      // 0 = any available staff
        $date  = $_POST['date'] ?? '';
        $time  = $_POST['time'] ?? '';
        if (!empty($_POST['slot']) && strpos($_POST['slot'], '|') !== false) {   // "staffId|HH:MM" picked from a staff's free times
            [$sid, $time] = explode('|', $_POST['slot'], 2);
            $sid = (int)$sid;
        }
        $back  = 'customer.php?p=book&service=' . $svcId . '&staff=' . $sid . '&date=' . urlencode($date);

        $s = $db->prepare("SELECT * FROM service WHERE service_id=? AND active=1");
        $s->execute([$svcId]);
        $svc = $s->fetch();
        $err = '';

        if (!$svc) {
            $err = 'Please choose a service.';
        } else {
            if ($sid === 0 && valid_date($date) && valid_time($time)) {
                $sid = pick_staff($db, $svc, $date, $time);
                if (!$sid) $err = 'No staff member is free at that time. Please choose another time.';
            }
            if (!$err) {
                [$ok, $res] = appt_save($db, [
                    'customer_id' => $me['customer_id'], 'staff_id' => $sid, 'service_id' => $svcId,
                    'date' => $date, 'time' => $time, 'notes' => mb_substr(trim($_POST['notes'] ?? ''), 0, 255),
                ]);
                if ($ok) {
                    notify_appt($db, $res, 'booked');
                    flash('&#10004; Your appointment was booked! Status: <b>pending</b> until the salon confirms it.');
                    $back = 'customer.php?p=appts';
                } else {
                    $err = $res;
                }
            }
        }
        if ($err) flash('&#9888; ' . e($err));
    }

    if ($a === 'cancel') {
        $s = $db->prepare("SELECT * FROM appointment WHERE appointment_id=? AND customer_id=?");
        $s->execute([(int)($_POST['id'] ?? 0), $me['customer_id']]);
        $r = $s->fetch();
        if (!$r) {
            flash('&#9888; Appointment not found.');
        } elseif (!in_array($r['status'], ACTIVE_STATUSES, true)) {
            flash('&#9888; This appointment can no longer be cancelled.');
        } elseif (strtotime($r['appt_date'] . ' ' . $r['start_time']) <= time()) {
            flash('&#9888; Past appointments cannot be cancelled.');
        } else {
            [$ok, $m] = appt_set_status($db, (int)$r['appointment_id'], 'cancelled');
            flash($ok ? 'Your appointment was cancelled.' : '&#9888; ' . e($m));
        }
    }

    if ($a === 'rate') {
        [$ok, $m] = review_save($db, $me['customer_id'], (int)($_POST['id'] ?? 0), (int)($_POST['rating'] ?? 0), mb_substr(trim($_POST['comment'] ?? ''), 0, 255));
        flash($ok ? e($m) : '&#9888; ' . e($m));
        $back = 'customer.php?p=history';
    }

    if ($a === 'profile') {
        $n = trim($_POST['name'] ?? '');
        $ph = trim($_POST['phone'] ?? '');
        if ($n === '' || mb_strlen($n) > 100) {
            flash('&#9888; Please enter your name.');
        } elseif (!valid_phone($ph)) {
            flash('&#9888; Enter a valid Philippine mobile number, e.g. 09171234567.');
        } else {
            $db->prepare("UPDATE customer SET name=?,phone=? WHERE customer_id=?")->execute([$n, $ph, $me['customer_id']]);
            flash('Profile updated.');
        }
    }

    if ($a === 'pw') {
        $s = $db->prepare("SELECT password FROM account WHERE account_id=?");
        $s->execute([$_SESSION['u']['id']]);
        if (!password_verify($_POST['old'] ?? '', (string)$s->fetchColumn())) {
            flash('&#9888; Your current password is incorrect.');
        } elseif (strlen($_POST['pw'] ?? '') < 8) {
            flash('&#9888; New password must be at least 8 characters.');
        } else {
            $db->prepare("UPDATE account SET password=? WHERE account_id=?")->execute([password_hash($_POST['pw'], PASSWORD_DEFAULT), $_SESSION['u']['id']]);
            flash('Password updated.');
        }
    }

    header('Location: ' . $back);
    exit;
}

$nav = [
    'dash'    => ['Dashboard', '&#128202;', 'customer.php'],
    'book'    => ['Book Appointment', '&#9986;', 'customer.php?p=book'],
    'appts'   => ['My Appointments', '&#128197;', 'customer.php?p=appts'],
    'staff'   => ['Our Staff', '&#128135;', 'customer.php?p=staff'],
    'history' => ['History', '&#128339;', 'customer.php?p=history'],
    'notif'   => ['Notifications', '&#128276;', 'customer.php?p=notif'],
    'profile' => ['My Profile', '&#9881;', 'customer.php?p=profile'],
];
if (!isset($nav[$p])) $p = 'dash';
dash_head($nav[$p][0], $p, $nav, $me['name'] ?? $_SESSION['u']['username'], 'customer');

if (!$me) {
    echo "<div class=box>Your account isn't linked to a customer record yet. Please contact the salon.</div>";
    dash_foot();
    exit;
}
$cid = (int)$me['customer_id'];
$act = "'" . implode("','", ACTIVE_STATUSES) . "'";
$today = date('Y-m-d');

/** Customer's own appointments: cancel + pay buttons ($mode 'current'), or rating ($mode 'history'). */
function my_appts(array $rows, $mode) {
    if (!$rows) { echo "<p style='color:var(--muted)'>Nothing here yet.</p>"; return; }
    echo "<table><tr><th>Date &amp; time</th><th>Service</th><th>Staff</th><th>Price</th><th>Status</th><th>Payment</th><th></th></tr>";
    foreach ($rows as $r) {
        $ps = $r['pstatus'] ?? null;
        echo "<tr><td>" . e(d_nice($r['appt_date'])) . "<br><small>" . t12($r['start_time']) . " - " . t12($r['end_time']) . "</small></td>"
            . "<td>" . e($r['svname']) . "</td><td>" . e($r['sname']) . "</td><td>" . money($r['price']) . "</td><td>" . tag($r['status']) . "</td>"
            . "<td>" . ($r['status'] === 'cancelled' ? '&mdash;' : ($ps ? tag('paid') : tag('unpaid') . "<br><small>Pay at the salon</small>")) . "</td><td>";
        if ($mode === 'current' && in_array($r['status'], ACTIVE_STATUSES, true) && strtotime($r['appt_date'] . ' ' . $r['start_time']) > time()) {
            echo "<form method=post class=i onsubmit=\"return confirm('Cancel this appointment?')\">" . tok()
                . "<input type=hidden name=a value=cancel><input type=hidden name=id value=" . (int)$r['appointment_id'] . "><button class='sm no'>Cancel</button></form> ";
        }
        if ($mode === 'history' && $r['status'] === 'completed') {
            if ($r['rating']) {
                echo "<div><span class=stars>" . stars($r['rating']) . "</span>" . ($r['rcomment'] ? "<br><small>" . e($r['rcomment']) . "</small>" : '') . "</div>";
            } else {
                echo "<form method=post>" . tok() . "<input type=hidden name=a value=rate><input type=hidden name=id value=" . (int)$r['appointment_id'] . ">"
                    . "<select name=rating required style='width:auto'><option value=''>Rate...</option>"
                    . "<option value=5>&#9733;&#9733;&#9733;&#9733;&#9733; Excellent</option><option value=4>&#9733;&#9733;&#9733;&#9733; Good</option><option value=3>&#9733;&#9733;&#9733; Okay</option>"
                    . "<option value=2>&#9733;&#9733; Poor</option><option value=1>&#9733; Very poor</option></select> "
                    . "<input name=comment maxlength=255 placeholder='Comment (optional)' style='margin:4px 0'> <button class='sm'>Submit</button></form>";
            }
        }
        echo "</td></tr>";
    }
    echo "</table>";
}

// =====================================================
//  PAGES
// =====================================================
if ($p === 'dash') {
    $c = $db->prepare("SELECT SUM(active_slot=1 AND appt_date>=?) up, SUM(status='completed') done, COUNT(*) total FROM appointment WHERE customer_id=?");
    $c->execute([$today, $cid]);
    $c = $c->fetch();
    $next = appt_rows($db, "a.customer_id=? AND a.active_slot=1 AND TIMESTAMP(a.appt_date,a.start_time)>?", [$cid, date('Y-m-d H:i:s')], 50);
    $next = $next ? end($next) : null;   // rows are newest-first, so the last one is the soonest
    ?>
    <div class=statgrid>
        <div class=stat><div class=top><span class=lbl>Upcoming</span><span class="ic g">&#128197;</span></div><div class=val><?= (int)$c['up'] ?></div><a class=view href="customer.php?p=appts">View</a></div>
        <div class=stat><div class=top><span class=lbl>Completed visits</span><span class="ic t">&#10004;</span></div><div class=val><?= (int)$c['done'] ?></div><a class=view href="customer.php?p=history">History</a></div>
        <div class=stat><div class=top><span class=lbl>All bookings</span><span class="ic p">&#9986;</span></div><div class=val><?= (int)$c['total'] ?></div></div>
    </div>
    <div class=box>
        <h3>Next appointment</h3>
        <?php if ($next): ?>
            <p><b><?= e($next['svname']) ?></b> with <?= e($next['sname']) ?><br>
            <?= e(d_nice($next['appt_date'])) ?> at <?= t12($next['start_time']) ?> &nbsp; <?= tag($next['status']) ?></p>
        <?php else: ?>
            <p style="color:var(--muted)">You have no upcoming appointments.</p>
        <?php endif; ?>
        <a class=btn href="customer.php?p=book">Book an appointment</a>
    </div>
    <?php
    $ln = $db->prepare("SELECT * FROM notification WHERE customer_id=? ORDER BY notification_id DESC LIMIT 3");
    $ln->execute([$cid]);
    $ln = $ln->fetchAll();
    if ($ln) {
        echo "<div class=box><h3>Latest notifications</h3><table>";
        foreach ($ln as $n) echo "<tr><td style='white-space:nowrap'>" . e(date('M j, g:i A', strtotime($n['created_at']))) . "</td><td>" . tag($n['type']) . "</td><td>" . e($n['message']) . "</td></tr>";
        echo "</table><a href='customer.php?p=notif'>See all</a></div>";
    }
}

if ($p === 'book') {
    $services = $db->query("SELECT * FROM service WHERE active=1 ORDER BY name")->fetchAll();
    $svcId = (int)($_GET['service'] ?? 0);
    $sid   = (int)($_GET['staff'] ?? 0);
    $date  = $_GET['date'] ?? '';
    $svc = null;
    foreach ($services as $s0) if ((int)$s0['service_id'] === $svcId) $svc = $s0;

    if ($svc) {
        $st = $db->prepare("SELECT s.* FROM staff s JOIN staff_service ss ON ss.staff_id=s.staff_id WHERE s.active=1 AND ss.service_id=? ORDER BY s.name");
        $st->execute([$svcId]);
    } else {
        $st = $db->query("SELECT * FROM staff WHERE active=1 ORDER BY name");
    }
    $staffList = $st->fetchAll();
    if ($sid && !in_array($sid, array_map('intval', array_column($staffList, 'staff_id')), true)) $sid = 0;
    $ratings = staff_ratings($db);
    ?>
    <?php if (!$services): ?>
        <div class=box>No services are available for booking yet. Please check back soon.</div>
    <?php else: ?>
    <div class=box>
        <h3>1. Choose service, staff and date</h3>
        <form method=get>
            <input type=hidden name=p value=book>
            <div class=row>
                <div><label>Service</label>
                    <select name=service required onchange="this.form.submit()">
                        <option value="">-- select --</option>
                        <?php foreach ($services as $s0): ?>
                            <option value="<?= (int)$s0['service_id'] ?>" <?= $s0['service_id'] == $svcId ? 'selected' : '' ?>>
                                <?= e($s0['name']) ?> - <?= money($s0['price']) ?> (<?= (int)$s0['duration_min'] ?> min)</option>
                        <?php endforeach; ?>
                    </select></div>
                <div><label>Staff</label>
                    <select name=staff>
                        <option value="0">Show all staff</option>
                        <?php foreach ($staffList as $s1): ?>
                            <option value="<?= (int)$s1['staff_id'] ?>" <?= $s1['staff_id'] == $sid ? 'selected' : '' ?>><?= e($s1['name']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div><label>Date</label>
                    <input type=date name=date required min="<?= $today ?>" max="<?= date('Y-m-d', strtotime('+' . MAX_DAYS_AHEAD . ' days')) ?>" value="<?= e($date) ?>"></div>
                <div><button>Check availability</button></div>
            </div>
        </form>
    </div>
    <?php
        if ($svc && $date !== '') {
            echo "<div class=box><h3>2. Pick your stylist and time &mdash; " . e($svc['name']) . ", " . (valid_date($date) ? e(d_nice($date)) : '') . "</h3>";
            if (!valid_date($date) || $date < $today) {
                echo "<div class='msg bad'>Please choose today or a later date.</div>";
            } elseif ($date > date('Y-m-d', strtotime('+' . MAX_DAYS_AHEAD . ' days'))) {
                echo "<div class='msg bad'>Appointments can only be booked up to " . MAX_DAYS_AHEAD . " days ahead.</div>";
            } elseif (!$staffList) {
                echo "<div class='msg bad'>Sorry, no staff are set up for this service yet. Please contact the salon.</div>";
            } else {
                $any = false; $cards = '';
                foreach ($staffList as $s1) {
                    if ($sid && (int)$s1['staff_id'] !== $sid) continue;
                    $ssid = (int)$s1['staff_id'];
                    $free = free_slots($db, $ssid, $svc, $date);
                    $any = $any || $free;
                    $cards .= "<div class=box style='margin-bottom:12px'><div class=scard><div class=av>" . e(initials($s1['name'])) . "</div><div style='flex:1'>"
                        . "<b>" . e($s1['name']) . "</b> &nbsp; " . rating_html($ratings[$ssid] ?? null) . "<br>";
                    if ($free) {
                        $cards .= "<span class='tag available'>Available &middot; " . count($free) . " open time" . (count($free) > 1 ? 's' : '') . "</span><div class=slotgrid>";
                        foreach ($free as $t) {
                            $cards .= "<label><input type=radio name=slot value='" . $ssid . "|" . e($t) . "' required>" . t12($t) . "</label>";
                        }
                        $cards .= "</div>";
                    } else {
                        $cards .= "<span class='tag occupied'>" . (staff_works($db, $ssid, $date) ? 'Fully booked on this date' : 'Day off') . "</span>";
                    }
                    $cards .= "</div></div></div>";
                }
                if (!$any) echo "<div class='msg bad'>No free times on this date. Try another date" . ($sid ? ' or another staff member' : '') . ".</div>";
                echo "<form method=post>" . tok() . "<input type=hidden name=a value=book><input type=hidden name=service_id value=" . $svcId . "><input type=hidden name=date value='" . e($date) . "'>"
                    . $cards;
                if ($any) echo "<p style='color:var(--muted);margin:6px 0'>&#128181; Payment is in <b>cash at the salon</b>.</p><label>Notes for the salon (optional)</label><textarea name=notes maxlength=255 rows=2></textarea><p><button class=ok>Book appointment</button></p>";
                echo "</form>";
            }
            echo "</div>";
        }
    endif;
}

if ($p === 'staff') {
    $ratings = staff_ratings($db);
    $list = $db->query("SELECT * FROM staff WHERE active=1 ORDER BY name")->fetchAll();
    echo "<p style='color:var(--muted)'>See who is working, what they offer, and when they are free. Tap <b>Book</b> to reserve with your preferred stylist.</p>";
    if (!$list) echo "<div class=box>No staff have been added yet.</div>";
    foreach ($list as $s1) {
        $ssid = (int)$s1['staff_id'];
        $sv = $db->prepare("SELECT sv.* FROM service sv JOIN staff_service ss ON ss.service_id=sv.service_id WHERE ss.staff_id=? AND sv.active=1 ORDER BY sv.duration_min,sv.name");
        $sv->execute([$ssid]); $sv = $sv->fetchAll();
        $sc = $db->prepare("SELECT * FROM staff_schedule WHERE staff_id=? ORDER BY FIELD(weekday,1,2,3,4,5,6,0)");
        $sc->execute([$ssid]);
        $byHours = [];
        foreach ($sc->fetchAll() as $r) $byHours[t12($r['start_time']) . ' - ' . t12($r['end_time'])][] = substr(DAYS[(int)$r['weekday']], 0, 3);
        $sched = [];
        foreach ($byHours as $hrs => $days) $sched[] = implode(', ', $days) . ': ' . $hrs;
        if ($sv) {
            $open = count(free_slots($db, $ssid, $sv[0], $today));
            $next = $open ? null : staff_next_open($db, $ssid, $sv[0], 14);
            $avail = $open ? "<span class='tag available'>Available today &middot; $open open time" . ($open > 1 ? 's' : '') . "</span>"
                : ($next ? "<span class='tag pending'>Next available: " . e(d_nice($next)) . "</span>" : "<span class='tag occupied'>No openings in the next 2 weeks</span>");
        } else {
            $avail = "<span class='tag inactive'>No services assigned yet</span>";
        }
        echo "<div class=box><div class=scard><div class=av>" . e(initials($s1['name'])) . "</div><div style='flex:1'>"
            . "<h3 style='margin:0'>" . e($s1['name']) . "</h3>" . rating_html($ratings[$ssid] ?? null) . "<p style='margin:8px 0'>$avail</p>"
            . "<p style='margin:4px 0'><b>Services:</b> " . ($sv ? e(implode(', ', array_column($sv, 'name'))) : '&mdash;') . "</p>"
            . "<p style='margin:4px 0'><b>Working hours:</b> " . ($sched ? e(implode(' | ', $sched)) : 'Not set yet') . "</p>"
            . ($sv ? "<p><a class=btn href='customer.php?p=book&staff=$ssid'>Book with " . e($s1['name']) . "</a></p>" : '')
            . "</div></div></div>";
    }
}

if ($p === 'appts') {
    echo "<div class=box><h3>Current appointments</h3>";
    my_appts(appt_rows($db, "a.customer_id=? AND a.status IN ($act)", [$cid]), 'current');
    echo "</div>";
}

if ($p === 'history') {
    echo "<div class=box><h3>Appointment &amp; service history</h3>";
    my_appts(appt_rows($db, "a.customer_id=? AND a.status NOT IN ($act)", [$cid]), 'history');
    echo "</div>";
}

if ($p === 'notif') {
    $n = $db->prepare("SELECT * FROM notification WHERE customer_id=? ORDER BY notification_id DESC LIMIT 100");
    $n->execute([$cid]);
    echo "<div class=box><h3>Notifications</h3>";
    $rows = $n->fetchAll();
    if (!$rows) echo "<p style='color:var(--muted)'>No notifications yet.</p>";
    else {
        echo "<table><tr><th>When</th><th>Type</th><th>Message</th></tr>";
        foreach ($rows as $r) echo "<tr><td>" . e(date('M j, g:i A', strtotime($r['created_at']))) . "</td><td>" . tag($r['type']) . "</td><td>" . e($r['message']) . "</td></tr>";
        echo "</table>";
    }
    echo "</div>";
}

if ($p === 'profile') { ?>
    <div class=box>
        <h3>My information</h3>
        <form method=post><?= tok() ?><input type=hidden name=a value=profile>
            <div class=infogrid>
                <div class=f><label>Email / username</label><span><?= e($me['email']) ?></span></div>
                <div class=f><label>Member since</label><span><?= e(date('M j, Y', strtotime($me['created_at']))) ?></span></div>
            </div>
            <div class=row>
                <div><label>Full name</label><input name=name required maxlength=100 value="<?= e($me['name']) ?>"></div>
                <div><label>Mobile number</label><input name=phone required pattern="(09\d{9}|\+639\d{9})" value="<?= e($me['phone']) ?>"></div>
            </div>
            <p><button>Save</button></p>
        </form>
    </div>
    <div class=box>
        <h3>Change password</h3>
        <form method=post><?= tok() ?><input type=hidden name=a value=pw>
            <div class=row>
                <div><label>Current password</label><input type=password name=old required></div>
                <div><label>New password (min 8)</label><input type=password name=pw required minlength=8></div>
                <div><button>Update</button></div>
            </div>
        </form>
    </div>
<?php }

dash_foot();
