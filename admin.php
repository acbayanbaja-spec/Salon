<?php
require 'config.php';
need('admin');

$p = $_GET['p'] ?? 'dash';

// =====================================================
//  FORM HANDLING
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    chk();
    $a  = $_POST['a'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $dup = '&#9888; That name/username is already in use.';

    if (!appt_post($db, null)) {          // confirm / cancel / complete / reschedule (any appointment)

        // ---- staff ----
        if ($a === 'staff_add') {
            $n = trim($_POST['name'] ?? ''); $em = strtolower(trim($_POST['email'] ?? '')); $ph = trim($_POST['phone'] ?? ''); $pw = $_POST['pw'] ?? '';
            $x = $db->prepare("SELECT COUNT(*) FROM account WHERE username=?"); $x->execute([$em]);
            if ($n === '' || mb_strlen($n) > 100) flash('&#9888; Enter the staff name.');
            elseif (!filter_var($em, FILTER_VALIDATE_EMAIL) || strlen($em) > 50) flash('&#9888; Enter a valid email (it becomes the username).');
            elseif ($ph !== '' && !valid_phone($ph)) flash('&#9888; Invalid phone number. Use 09171234567.');
            elseif (strlen($pw) < 8) flash('&#9888; Password must be at least 8 characters.');
            elseif ($x->fetchColumn()) flash($dup);
            else {
                $db->beginTransaction();
                $db->prepare("INSERT INTO account(username,password,role) VALUES(?,?,'staff')")->execute([$em, password_hash($pw, PASSWORD_DEFAULT)]);
                $aid = db_last_id($db, 'account', 'account_id');
                $db->prepare("INSERT INTO staff(account_id,name,phone) VALUES(?,?,?)")->execute([$aid, $n, $ph ?: null]);
                $new = db_last_id($db, 'staff', 'staff_id');
                $db->commit();
                setup_defaults($db, (int)$new, null);
                flash('Staff added with all services and default hours (Mon-Sat, 9:00 AM - 5:00 PM). Adjust them below if needed.');
                header('Location: admin.php?p=staff&edit=' . $new); exit;
            }
        }
        if ($a === 'staff_edit') {
            $n = trim($_POST['name'] ?? ''); $ph = trim($_POST['phone'] ?? '');
            if ($n === '' || mb_strlen($n) > 100) flash('&#9888; Enter the staff name.');
            elseif ($ph !== '' && !valid_phone($ph)) flash('&#9888; Invalid phone number.');
            elseif (($_POST['pw'] ?? '') !== '' && strlen($_POST['pw']) < 8) flash('&#9888; New password must be at least 8 characters.');
            else {
                $db->prepare("UPDATE staff SET name=?,phone=? WHERE staff_id=?")->execute([$n, $ph ?: null, $id]);
                if (($_POST['pw'] ?? '') !== '') {
                    $db->prepare("UPDATE account SET password=? WHERE account_id=(SELECT account_id FROM staff WHERE staff_id=?)")->execute([password_hash($_POST['pw'], PASSWORD_DEFAULT), $id]);
                }
                flash('Staff updated.');
            }
        }
        if ($a === 'staff_svc') {
            $db->beginTransaction();
            $db->prepare("DELETE FROM staff_service WHERE staff_id=?")->execute([$id]);
            foreach ((array)($_POST['svc'] ?? []) as $sv) {
                $db->prepare("INSERT INTO staff_service(staff_id,service_id) SELECT ?,service_id FROM service WHERE service_id=?")->execute([$id, (int)$sv]);
            }
            $db->commit();
            flash('Assigned services saved.');
        }
        if ($a === 'staff_toggle') {
            $s = $db->prepare("SELECT * FROM staff WHERE staff_id=?"); $s->execute([$id]); $s = $s->fetch();
            if ($s) {
                if ($s['active']) {
                    $c = $db->prepare("SELECT COUNT(*) FROM appointment WHERE staff_id=? AND active_slot=1 AND appt_date>=?");
                    $c->execute([$id, date('Y-m-d')]);
                    if ($c->fetchColumn()) { flash('&#9888; This staff member still has upcoming appointments. Reschedule or cancel them first.'); header('Location: admin.php?p=staff'); exit; }
                }
                $db->prepare("UPDATE staff SET active=? WHERE staff_id=?")->execute([$s['active'] ? 0 : 1, $id]);
                flash($s['active'] ? 'Staff deactivated (history is kept).' : 'Staff re-activated.');
            }
        }
        if ($a === 'sched') {
            $err = sched_save($db, (int)($_POST['sid'] ?? 0));
            flash($err ? '&#9888; ' . e($err) : 'Schedule saved.');
            header('Location: admin.php?p=sched&sid=' . (int)($_POST['sid'] ?? 0)); exit;
        }

        if ($a === 'sms_save') {
            $snd = trim($_POST['sender'] ?? '');
            $prov = trim($_POST['provider'] ?? 'philsms');
            if (!in_array($prov, ['philsms', 'semaphore'], true)) $prov = 'philsms';
            if (!setting_ready($db)) flash('&#9888; Import database.sql or upgrade.sql first.');
            elseif (!preg_match('/^[A-Za-z0-9_-]{0,11}$/', $snd)) flash('&#9888; Sender ID: letters, numbers, and dashes only, up to 11 characters (or leave blank).');
            else {
                setting_set($db, 'sms_enabled', !empty($_POST['enabled']) ? '1' : '0');
                setting_set($db, 'sms_provider', $prov);
                if (trim($_POST['key'] ?? '') !== '') setting_set($db, 'sms_key', trim($_POST['key']));
                setting_set($db, 'sms_sender', $snd);
                setting_set($db, 'sms_url', $prov === 'philsms' ? 'https://dashboard.philsms.com/api/v3/sms/send' : 'https://api.semaphore.co/api/v4/messages');
                flash('SMS settings saved. Send a test message below to check them.');
            }
        }
        if ($a === 'sms_test') {
            $ph = trim($_POST['phone'] ?? '');
            if (!valid_phone($ph)) flash('&#9888; Enter a valid mobile number, e.g. 09171234567.');
            else {
                [$st, $d] = sms_send_detail($db, $ph, 'Test message from ' . BRAND . ': SMS notifications are working.');
                flash($st === 'sent' ? '&#10004; Test SMS sent to ' . e($ph) . '. Check the phone.' : '&#9888; Test failed: ' . e($d));
            }
        }
        if ($a === 'sms_retry') {
            $try = $db->query("SELECT n.notification_id,n.message,c.phone FROM notification n JOIN customer c ON c.customer_id=n.customer_id
                WHERE n.sms_status='failed' ORDER BY n.notification_id DESC LIMIT 20")->fetchAll();
            $ok = 0;
            foreach ($try as $t) {
                $st = sms_send_detail($db, $t['phone'], $t['message'])[0];
                if ($st === 'sent') $ok++;
                $db->prepare("UPDATE notification SET sms_status=? WHERE notification_id=?")->execute([$st, $t['notification_id']]);
            }
            flash('Retried ' . count($try) . ' failed SMS: ' . $ok . ' sent, ' . (count($try) - $ok) . ' still failing.');
        }

        if ($a === 'autosetup') {
            $n = setup_autofix($db);
            flash($n ? 'Setup fixed: services assigned and default hours (Mon-Sat 9-5) set where missing. Adjust under Staff if needed.' : 'Nothing needed fixing.');
        }

        // ---- services ----
        if ($a === 'svc_add' || $a === 'svc_edit') {
            $n = trim($_POST['name'] ?? ''); $pr = $_POST['price'] ?? ''; $du = $_POST['duration'] ?? '';
            if ($n === '' || mb_strlen($n) > 100) flash('&#9888; Enter the service name.');
            elseif (!is_numeric($pr) || (float)$pr <= 0 || (float)$pr > 100000) flash('&#9888; Enter a valid price greater than 0.');
            elseif (!ctype_digit((string)$du) || (int)$du < 15 || (int)$du > 480) flash('&#9888; Duration must be between 15 and 480 minutes.');
            else {
                try {
                    if ($a === 'svc_add') { $db->prepare("INSERT INTO service(name,price,duration_min) VALUES(?,?,?)")->execute([$n, (float)$pr, (int)$du]); setup_defaults($db, null, (int)db_last_id($db, 'service', 'service_id')); }
                    else $db->prepare("UPDATE service SET name=?,price=?,duration_min=? WHERE service_id=?")->execute([$n, (float)$pr, (int)$du, $id]);
                    flash($a === 'svc_add' ? 'Service added and assigned to all active staff (change this under Staff).' : 'Service updated.');
                } catch (PDOException $x) {
                    if ($x->getCode() === '23000') flash('&#9888; A service with that name already exists.'); else throw $x;
                }
            }
        }
        if ($a === 'svc_toggle') {
            $db->prepare("UPDATE service SET active=1-active WHERE service_id=?")->execute([$id]);
            flash('Service availability changed.');
        }

        // ---- customers ----
        if ($a === 'cust_edit') {
            $n = trim($_POST['name'] ?? ''); $ph = trim($_POST['phone'] ?? '');
            if ($n === '' || !valid_phone($ph)) flash('&#9888; Enter a name and a valid mobile number.');
            else { $db->prepare("UPDATE customer SET name=?,phone=? WHERE customer_id=?")->execute([$n, $ph, $id]); flash('Customer updated.'); }
            header('Location: admin.php?p=customer&id=' . $id); exit;
        }

        // ---- reminders ----
        if ($a === 'reminders') flash('Reminders sent: ' . send_due_reminders($db));

        // ---- admin's own password ----
        if ($a === 'pw') {
            $s = $db->prepare("SELECT password FROM account WHERE account_id=?");
            $s->execute([$_SESSION['u']['id']]);
            if (!password_verify($_POST['old'] ?? '', (string)$s->fetchColumn())) flash('&#9888; Your current password is incorrect.');
            elseif (strlen($_POST['pw'] ?? '') < 8) flash('&#9888; New password must be at least 8 characters.');
            else {
                $db->prepare("UPDATE account SET password=? WHERE account_id=?")->execute([password_hash($_POST['pw'], PASSWORD_DEFAULT), $_SESSION['u']['id']]);
                flash('Password updated.');
            }
        }
    }

    header('Location: admin.php?p=' . urlencode($p) . (isset($_GET['edit']) ? '&edit=' . (int)$_GET['edit'] : ''));
    exit;
}

$nav = [
    'dash'      => ['Dashboard', '&#128202;', 'admin.php'],
    'appts'     => ['Appointments', '&#128197;', 'admin.php?p=appts'],
    'staff'     => ['Staff', '&#128135;', 'admin.php?p=staff'],
    'services'  => ['Services', '&#9986;', 'admin.php?p=services'],
    'customers' => ['Customers', '&#128101;', 'admin.php?p=customers'],
    'reviews'   => ['Ratings', '&#11088;', 'admin.php?p=reviews'],
    'notif'     => ['Notifications', '&#128276;', 'admin.php?p=notif'],
    'sms'       => ['SMS', '&#128172;', 'admin.php?p=sms'],
    'settings'  => ['Password', '&#9881;', 'admin.php?p=settings'],
];
$sub = ['sched' => 'Staff schedule', 'customer' => 'Customer record'];
if (!isset($nav[$p]) && !isset($sub[$p])) $p = 'dash';
dash_head($nav[$p][0] ?? $sub[$p], $p === 'sched' ? 'staff' : ($p === 'customer' ? 'customers' : $p), $nav, 'Administrator', 'admin');
$today = date('Y-m-d');
$activeStaff = [];
foreach ($db->query("SELECT staff_id,name FROM staff WHERE active=1 ORDER BY name") as $r) $activeStaff[(int)$r['staff_id']] = $r['name'];

// =====================================================
//  PAGES
// =====================================================
if ($p === 'dash') {
    $c = $db->prepare("SELECT COUNT(CASE WHEN appt_date=? AND active_slot=1 THEN 1 END) today, COUNT(CASE WHEN status='pending' THEN 1 END) pend, COUNT(CASE WHEN status='completed' THEN 1 END) done FROM appointment");
    $c->execute([$today]);
    $c = $c->fetch();
    $cust = (int)$db->query("SELECT COUNT(*) FROM customer")->fetchColumn();
    $incSql = db_is_pg($db)
        ? "SELECT COALESCE(SUM(amount),0) FROM payment WHERE status='paid' AND TO_CHAR(paid_at,'YYYY-MM')=?"
        : "SELECT COALESCE(SUM(amount),0) FROM payment WHERE status='paid' AND DATE_FORMAT(paid_at,'%Y-%m')=?";
    $inc  = $db->prepare($incSql);
    $inc->execute([date('Y-m')]);
    $problems = setup_problems($db);
    if ($problems): ?>
    <div class="msg bad"><b>&#9888; Setup incomplete &mdash; customers may not be able to book yet:</b>
        <ul style="margin:6px 0 10px 18px"><?php foreach ($problems as $pr) echo '<li>' . e($pr) . '</li>'; ?></ul>
        <form method=post class=i><?= tok() ?><input type=hidden name=a value=autosetup><button>Fix automatically</button></form>
        <a href="admin.php?p=staff" style="margin-left:10px">Or review staff manually</a>
    </div>
    <?php endif;
    if (!sms_cfg($db)['on']): ?>
    <div class=msg style="background:#fff7e0;color:#7a5b00">&#128172; <b>SMS is not turned on</b> &mdash; customers only get in-app notifications. <a href="admin.php?p=sms">Set up SMS</a></div>
    <?php endif; ?>
    <div class=statgrid>
        <div class=stat><div class=top><span class=lbl>Today</span><span class="ic g">&#128197;</span></div><div class=val><?= (int)$c['today'] ?></div><a class=view href="admin.php?p=appts">View</a></div>
        <div class=stat><div class=top><span class=lbl>Pending</span><span class="ic o">&#9203;</span></div><div class=val><?= (int)$c['pend'] ?></div><a class=view href="admin.php?p=appts&status=pending">Review</a></div>
        <div class=stat><div class=top><span class=lbl>Customers</span><span class="ic p">&#128101;</span></div><div class=val><?= $cust ?></div><a class=view href="admin.php?p=customers">View</a></div>
        <div class=stat><div class=top><span class=lbl>Active staff</span><span class="ic t">&#128135;</span></div><div class=val><?= count($activeStaff) ?></div><a class=view href="admin.php?p=staff">Manage</a></div>
        <div class=stat><div class=top><span class=lbl>Paid this month</span><span class="ic g">&#8369;</span></div><div class=val style="font-size:1.3rem"><?= money($inc->fetchColumn()) ?></div></div>
    </div>
    <div class=box>
        <h3>Reminders</h3>
        <p style="color:var(--muted)">Sends a reminder for confirmed appointments starting within <?= REMINDER_HOURS ?> hours (once per appointment). SMS is <b><?= SMS_ENABLED ? 'enabled' : 'not configured' ?></b>; reminders are always shown in the customer's Notifications.</p>
        <form method=post><?= tok() ?><input type=hidden name=a value=reminders><button>Send due reminders now</button></form>
    </div>
    <div class=box><h3>Today's appointments</h3>
    <?php appt_table(appt_rows($db, "a.appt_date=? AND a.status<>'cancelled'", [$today]), 'admin.php?p=customer&id=', $activeStaff); ?></div>
<?php }

if ($p === 'appts') {
    $fs = $_GET['status'] ?? ''; $fd = $_GET['date'] ?? ''; $ft = (int)($_GET['staff'] ?? 0); $fp = $_GET['pay'] ?? '';
    $w = ['1=1']; $args = [];
    if (in_array($fs, ['pending', 'confirmed', 'rescheduled', 'completed', 'cancelled'], true)) { $w[] = 'a.status=?'; $args[] = $fs; }
    if (valid_date($fd)) { $w[] = 'a.appt_date=?'; $args[] = $fd; }
    if ($ft) { $w[] = 'a.staff_id=?'; $args[] = $ft; }
    if ($fp === 'unpaid') { $w[] = "(pm.status IS NULL OR pm.status<>'paid') AND a.status<>'cancelled'"; }
    elseif ($fp === 'paid') { $w[] = "pm.status='paid'"; }
    ?>
    <div class=box>
        <form method=get class=row><input type=hidden name=p value=appts>
            <div><label>Status</label><select name=status><option value="">All</option>
                <?php foreach (['pending', 'confirmed', 'rescheduled', 'completed', 'cancelled'] as $s0) echo "<option" . ($fs === $s0 ? ' selected' : '') . ">$s0</option>"; ?></select></div>
            <div><label>Date</label><input type=date name=date value="<?= e($fd) ?>"></div>
            <div><label>Staff</label><select name=staff><option value="0">All</option>
                <?php foreach ($activeStaff as $k => $n) echo "<option value=$k" . ($ft === $k ? ' selected' : '') . ">" . e($n) . "</option>"; ?></select></div>
            <div><label>Payment</label><select name=pay><option value="">All</option>
                <?php foreach (['unpaid', 'paid'] as $s0) echo "<option" . ($fp === $s0 ? ' selected' : '') . ">$s0</option>"; ?></select></div>
            <div><button>Filter</button></div>
        </form>
    </div>
    <div class=box><?php appt_table(appt_rows($db, implode(' AND ', $w), $args), 'admin.php?p=customer&id=', $activeStaff); ?></div>
<?php }

if ($p === 'staff') {
    $edit = (int)($_GET['edit'] ?? 0);
    $services = $db->query("SELECT * FROM service ORDER BY name")->fetchAll();
    if ($edit) {
        $s = $db->prepare("SELECT st.*,ac.username FROM staff st LEFT JOIN account ac ON ac.account_id=st.account_id WHERE st.staff_id=?");
        $s->execute([$edit]); $s = $s->fetch();
        $mine = $db->prepare("SELECT service_id FROM staff_service WHERE staff_id=?"); $mine->execute([$edit]);
        $mine = array_column($mine->fetchAll(), 'service_id');
    }
    if ($edit && $s) { ?>
        <div class=box><h3>Edit <?= e($s['name']) ?></h3>
            <form method=post><?= tok() ?><input type=hidden name=a value=staff_edit><input type=hidden name=id value=<?= $edit ?>>
                <div class=row>
                    <div><label>Name</label><input name=name required maxlength=100 value="<?= e($s['name']) ?>"></div>
                    <div><label>Phone</label><input name=phone value="<?= e($s['phone']) ?>" placeholder="09171234567"></div>
                    <div><label>Username</label><input value="<?= e($s['username']) ?>" disabled></div>
                    <div><label>Reset password (optional)</label><input type=password name=pw minlength=8></div>
                    <div><button>Save</button></div>
                </div></form></div>
        <div class=box><h3>Assigned services</h3>
            <form method=post><?= tok() ?><input type=hidden name=a value=staff_svc><input type=hidden name=id value=<?= $edit ?>>
                <?php foreach ($services as $sv) echo "<label style='font-weight:400'><input type=checkbox name='svc[]' value=" . (int)$sv['service_id'] . " style='width:auto' " . (in_array($sv['service_id'], $mine) ? 'checked' : '') . "> " . e($sv['name']) . ($sv['active'] ? '' : ' (inactive)') . "</label>"; ?>
                <p><button>Save services</button> <a class=btn href="admin.php?p=sched&sid=<?= $edit ?>">Edit schedule</a> <a class=btn href="admin.php?p=staff" style="background:#6b7a71">Back</a></p>
            </form></div>
        <div class=box><h3>Assigned appointments</h3><?php appt_table(appt_rows($db, 'a.staff_id=?', [$edit]), 'admin.php?p=customer&id=', $activeStaff); ?></div>
    <?php } else { ?>
        <div class=box><h3>Add staff</h3>
            <form method=post><?= tok() ?><input type=hidden name=a value=staff_add>
                <div class=row>
                    <div><label>Name</label><input name=name required maxlength=100></div>
                    <div><label>Email (username)</label><input type=email name=email required maxlength=50></div>
                    <div><label>Phone</label><input name=phone placeholder="09171234567"></div>
                    <div><label>Password (min 8)</label><input type=password name=pw required minlength=8></div>
                    <div><button class=ok>Add</button></div>
                </div></form></div>
        <div class=box><h3>All staff</h3>
        <?php $rows = $db->query("SELECT st.*,ac.username,(SELECT COUNT(*) FROM staff_service x WHERE x.staff_id=st.staff_id) nsvc FROM staff st LEFT JOIN account ac ON ac.account_id=st.account_id ORDER BY st.active DESC,st.name")->fetchAll();
        if (!$rows) echo "<p style='color:var(--muted)'>No staff yet.</p>"; else { ?>
            <table><tr><th>Name</th><th>Username</th><th>Phone</th><th>Services</th><th>Status</th><th></th></tr>
            <?php foreach ($rows as $r) { ?>
                <tr><td><b><?= e($r['name']) ?></b></td><td><?= e($r['username']) ?></td><td><?= e($r['phone']) ?></td><td><?= (int)$r['nsvc'] ?></td>
                <td><?= tag($r['active'] ? 'active' : 'inactive') ?></td>
                <td><a class="btn sm" href="admin.php?p=staff&edit=<?= (int)$r['staff_id'] ?>">Manage</a>
                    <a class="btn sm" href="admin.php?p=sched&sid=<?= (int)$r['staff_id'] ?>">Schedule</a>
                    <form method=post class=i><?= tok() ?><input type=hidden name=a value=staff_toggle><input type=hidden name=id value=<?= (int)$r['staff_id'] ?>>
                    <button class="sm <?= $r['active'] ? 'no' : 'ok' ?>"><?= $r['active'] ? 'Deactivate' : 'Activate' ?></button></form></td></tr>
            <?php } ?></table>
        <?php } ?></div>
    <?php }
}

if ($p === 'sched') {
    $sid = (int)($_GET['sid'] ?? 0);
    $s = $db->prepare("SELECT * FROM staff WHERE staff_id=?"); $s->execute([$sid]); $s = $s->fetch();
    if (!$s) echo "<div class=box>Staff not found.</div>";
    else { ?>
        <div class=box><h3><?= e($s['name']) ?> &mdash; weekly working hours</h3>
        <p style="color:var(--muted)">Customers can only book inside these hours. Unticked days are days off. Existing appointments are not moved automatically.</p>
        <?php sched_form($db, $sid); ?><p><a href="admin.php?p=staff&edit=<?= $sid ?>">&larr; Back to staff</a></p></div>
    <?php }
}

if ($p === 'services') {
    $edit = (int)($_GET['edit'] ?? 0);
    $es = null;
    if ($edit) { $es = $db->prepare("SELECT * FROM service WHERE service_id=?"); $es->execute([$edit]); $es = $es->fetch(); }
    ?>
    <div class=box><h3><?= $es ? 'Edit service' : 'Add service' ?></h3>
        <form method=post><?= tok() ?><input type=hidden name=a value=<?= $es ? 'svc_edit' : 'svc_add' ?>><input type=hidden name=id value=<?= $es ? (int)$es['service_id'] : 0 ?>>
            <div class=row>
                <div><label>Name</label><input name=name required maxlength=100 value="<?= e($es['name'] ?? '') ?>"></div>
                <div><label>Price (PHP)</label><input type=number name=price required min=1 step=0.01 value="<?= e($es['price'] ?? '') ?>"></div>
                <div><label>Duration (minutes)</label><input type=number name=duration required min=15 max=480 step=15 value="<?= e($es['duration_min'] ?? '') ?>"></div>
                <div><button class=ok><?= $es ? 'Save' : 'Add' ?></button> <?= $es ? '<a class=btn href="admin.php?p=services" style="background:#6b7a71">Cancel</a>' : '' ?></div>
            </div></form></div>
    <div class=box><h3>All services</h3>
    <?php $rows = $db->query("SELECT * FROM service ORDER BY active DESC,name")->fetchAll();
    if (!$rows) echo "<p style='color:var(--muted)'>No services yet. Add your first service above.</p>"; else { ?>
        <table><tr><th>Service</th><th>Price</th><th>Duration</th><th>Status</th><th></th></tr>
        <?php foreach ($rows as $r) { ?>
            <tr><td><b><?= e($r['name']) ?></b></td><td><?= money($r['price']) ?></td><td><?= (int)$r['duration_min'] ?> min</td>
            <td><?= tag($r['active'] ? 'active' : 'inactive') ?></td>
            <td><a class="btn sm" href="admin.php?p=services&edit=<?= (int)$r['service_id'] ?>">Edit</a>
                <form method=post class=i><?= tok() ?><input type=hidden name=a value=svc_toggle><input type=hidden name=id value=<?= (int)$r['service_id'] ?>>
                <button class="sm <?= $r['active'] ? 'no' : 'ok' ?>"><?= $r['active'] ? 'Deactivate' : 'Activate' ?></button></form></td></tr>
        <?php } ?></table>
    <?php } ?></div>
<?php }

if ($p === 'customers') {
    $rows = $db->query("SELECT c.*,(SELECT COUNT(*) FROM appointment a WHERE a.customer_id=c.customer_id) n FROM customer c ORDER BY c.name")->fetchAll(); ?>
    <div class=box><h3>All customers</h3>
    <?php if (!$rows) echo "<p style='color:var(--muted)'>No customers have registered yet.</p>"; else { ?>
        <table><tr><th>Name</th><th>Email</th><th>Phone</th><th>Appointments</th><th></th></tr>
        <?php foreach ($rows as $r) echo "<tr><td><b>" . e($r['name']) . "</b></td><td>" . e($r['email']) . "</td><td>" . e($r['phone']) . "</td><td>" . (int)$r['n'] . "</td><td><a class='btn sm' href='admin.php?p=customer&id=" . (int)$r['customer_id'] . "'>View</a></td></tr>"; ?>
        </table>
    <?php } ?></div>
<?php }

if ($p === 'customer') {
    $cid = (int)($_GET['id'] ?? 0);
    $c = $db->prepare("SELECT * FROM customer WHERE customer_id=?"); $c->execute([$cid]); $c = $c->fetch();
    if (!$c) echo "<div class=box>Customer not found.</div>";
    else { ?>
        <div class=box><h3><?= e($c['name']) ?></h3>
            <form method=post><?= tok() ?><input type=hidden name=a value=cust_edit><input type=hidden name=id value=<?= $cid ?>>
                <div class=row>
                    <div><label>Name</label><input name=name required maxlength=100 value="<?= e($c['name']) ?>"></div>
                    <div><label>Phone</label><input name=phone required value="<?= e($c['phone']) ?>"></div>
                    <div><label>Email</label><input value="<?= e($c['email']) ?>" disabled></div>
                    <div><button>Save</button></div>
                </div></form></div>
        <div class=box><h3>Appointment &amp; service history</h3>
            <?php appt_table(appt_rows($db, 'a.customer_id=?', [$cid]), 'admin.php?p=customer&id=', $activeStaff); ?></div>
    <?php }
}

if ($p === 'reviews') {
    $avg = $db->query("SELECT st.name, AVG(r.rating) a, COUNT(r.review_id) n FROM staff st LEFT JOIN review r ON r.staff_id=st.staff_id GROUP BY st.staff_id,st.name ORDER BY st.name")->fetchAll();
    $rows = $db->query("SELECT r.*,c.name cname,s.name sname,sv.name svname,a.appt_date FROM review r JOIN customer c ON c.customer_id=r.customer_id
        JOIN staff s ON s.staff_id=r.staff_id JOIN appointment a ON a.appointment_id=r.appointment_id JOIN service sv ON sv.service_id=a.service_id
        ORDER BY r.review_id DESC LIMIT 100")->fetchAll(); ?>
    <div class=box><h3>Average rating per staff</h3>
        <table><tr><th>Staff</th><th>Rating</th></tr>
        <?php foreach ($avg as $r) echo "<tr><td><b>" . e($r['name']) . "</b></td><td>" . rating_html($r['n'] ? ['avg' => $r['a'], 'n' => $r['n']] : null) . "</td></tr>"; ?>
        </table></div>
    <div class=box><h3>Latest customer ratings</h3>
    <?php if (!$rows) echo "<p style='color:var(--muted)'>No ratings yet.</p>"; else { ?>
        <table><tr><th>Date</th><th>Customer</th><th>Staff</th><th>Service</th><th>Rating</th><th>Comment</th></tr>
        <?php foreach ($rows as $r) echo "<tr><td>" . e(d_nice($r['appt_date'])) . "</td><td>" . e($r['cname']) . "</td><td>" . e($r['sname']) . "</td><td>" . e($r['svname']) . "</td><td><span class=stars>" . stars($r['rating']) . "</span></td><td>" . e($r['comment']) . "</td></tr>"; ?>
        </table>
    <?php } ?></div>
<?php }

if ($p === 'sms') {
    $cfg = sms_cfg($db);
    $cnt = $db->query("SELECT sms_status s, COUNT(*) n FROM notification GROUP BY sms_status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $lastErr = setting_get($db, 'sms_last_error', ''); $lastOk = setting_get($db, 'sms_last_ok', '');
    $masked = $cfg['has_key'] ? str_repeat('&bull;', 8) . e(substr($cfg['key'], -4)) : ''; ?>
    <?php if (!setting_ready($db)): ?><div class="msg bad">Import <b>upgrade.sql</b> in phpMyAdmin first (it adds the table that stores these settings).</div><?php endif; ?>
    <div class=box>
        <h3>SMS notifications <?= $cfg['on'] ? tag('sent') . ' ON' : tag('not_configured') . ' OFF' ?></h3>
        <p style="color:var(--muted)">Customers get an SMS (plus the in-app notification) when they book, when it is confirmed, rescheduled, cancelled or completed, when payment is received, and 24 hours before the appointment.
            Configured for <b>PhilSMS</b> (philsms.com) and <b>Semaphore</b> (semaphore.co). Enter your credentials below.</p>
        <form method=post><?= tok() ?><input type=hidden name=a value=sms_save>
            <label style="font-weight:400"><input type=checkbox name=enabled value=1 style="width:auto;margin-right:6px" <?= $cfg['enabled'] ? 'checked' : '' ?>> Turn on SMS notifications</label>
            <div class=row style="margin-top:10px">
                <div>
                    <label>Provider</label>
                    <select name=provider>
                        <option value="philsms" <?= ($cfg['provider'] ?? 'philsms') === 'philsms' ? 'selected' : '' ?>>PhilSMS (philsms.com)</option>
                        <option value="semaphore" <?= ($cfg['provider'] ?? '') === 'semaphore' ? 'selected' : '' ?>>Semaphore (semaphore.co)</option>
                    </select>
                </div>
                <div><label>API Key / Bearer Token <?= $masked ? "<small>(saved: $masked &mdash; leave blank to keep)</small>" : '' ?></label><input type=password name=key autocomplete=off placeholder="<?= $masked ? 'unchanged' : 'paste your API token / key' ?>"></div>
                <div><label>Sender ID <small>(from PhilSMS &gt; Sending &gt; Sender ID)</small></label><input name=sender maxlength=11 value="<?= e($cfg['sender']) ?>" placeholder="e.g. your approved SID"></div>
                <div><button>Save</button></div>
            </div>
        </form>
    </div>
    <div class=box>
        <h3>Send a test SMS</h3>
        <form method=post><?= tok() ?><input type=hidden name=a value=sms_test>
            <div class=row><div><label>Mobile number</label><input name=phone required placeholder="09171234567"></div><div><button>Send test</button></div></div>
        </form>
    </div>
    <div class=box>
        <h3>Status</h3>
        <p>Sent: <b><?= (int)($cnt['sent'] ?? 0) ?></b> &nbsp; Failed: <b><?= (int)($cnt['failed'] ?? 0) ?></b> &nbsp; Not sent (SMS was off / no number): <b><?= (int)($cnt['not_configured'] ?? 0) + (int)($cnt['no_number'] ?? 0) ?></b></p>
        <?php if ($lastOk) echo "<p>Last successful SMS: " . e($lastOk) . "</p>"; ?>
        <?php if ($lastErr) echo "<div class='msg bad'><b>Last error:</b> " . e($lastErr) . "</div>"; ?>
        <?php if (!empty($cnt['failed'])): ?>
            <form method=post class=i><?= tok() ?><input type=hidden name=a value=sms_retry><button>Retry failed SMS (latest 20)</button></form>
        <?php endif; ?>
        <p style="color:var(--muted);font-size:.85rem">Tip: the &quot;Notifications&quot; page shows the SMS result for every message.</p>
    </div>
<?php }

if ($p === 'notif') {
    $rows = $db->query("SELECT n.*,c.name FROM notification n JOIN customer c ON c.customer_id=n.customer_id ORDER BY n.notification_id DESC LIMIT 100")->fetchAll(); ?>
    <div class=box><h3>Notification log (latest 100)</h3>
    <?php if (!$rows) echo "<p style='color:var(--muted)'>Nothing sent yet.</p>"; else { ?>
        <table><tr><th>When</th><th>Customer</th><th>Type</th><th>Message</th><th>SMS</th></tr>
        <?php foreach ($rows as $r) echo "<tr><td>" . e(date('M j, g:i A', strtotime($r['created_at']))) . "</td><td>" . e($r['name']) . "</td><td>" . tag($r['type']) . "</td><td>" . e($r['message']) . "</td><td>" . tag($r['sms_status']) . "</td></tr>"; ?>
        </table>
    <?php } ?></div>
<?php }

if ($p === 'settings') { ?>
    <div class=box><h3>Change password</h3>
        <form method=post><?= tok() ?><input type=hidden name=a value=pw>
            <div class=row>
                <div><label>Current password</label><input type=password name=old required></div>
                <div><label>New password (min 8)</label><input type=password name=pw required minlength=8></div>
                <div><button>Update</button></div>
            </div>
        </form></div>
<?php }

dash_foot();
