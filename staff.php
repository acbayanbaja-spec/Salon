<?php
require 'config.php';
need('staff');

$q = $db->prepare("SELECT * FROM staff WHERE account_id=? AND active=1");
$q->execute([$_SESSION['u']['id']]);
$me = $q->fetch();
if (!$me) {            // deactivated or unlinked staff account
    $_SESSION = [];
    header('Location: admin-login.php');
    exit;
}
$sid = (int)$me['staff_id'];
$p = $_GET['p'] ?? 'dash';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    chk();
    $a = $_POST['a'] ?? '';

    if (!appt_post($db, $sid)) {            // confirm / cancel / complete / reschedule (own appointments only)
        if ($a === 'sched') {               // staff may edit only their own hours
            $err = sched_save($db, $sid);
            flash($err ? '&#9888; ' . e($err) : 'Your schedule was saved.');
        }
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
    header('Location: staff.php?p=' . urlencode($p) . (isset($_GET['cid']) ? '&cid=' . (int)$_GET['cid'] : ''));
    exit;
}

$nav = [
    'dash'     => ['Dashboard', '&#128202;', 'staff.php'],
    'appts'    => ['My Appointments', '&#128197;', 'staff.php?p=appts'],
    'schedule' => ['My Schedule', '&#128337;', 'staff.php?p=schedule'],
    'reviews'  => ['My Ratings', '&#11088;', 'staff.php?p=reviews'],
    'settings' => ['Password', '&#9881;', 'staff.php?p=settings'],
];
if (!isset($nav[$p]) && $p !== 'customer') $p = 'dash';
dash_head($p === 'customer' ? 'Customer record' : $nav[$p][0], $p, $nav, $me['name'], 'staff');
$today = date('Y-m-d');
$custUrl = 'staff.php?p=customer&cid=';

if ($p === 'dash') {
    $c = $db->prepare("SELECT SUM(appt_date=? AND active_slot=1) today, SUM(status='pending') pend, SUM(active_slot=1 AND appt_date>=?) up FROM appointment WHERE staff_id=?");
    $c->execute([$today, $today, $sid]);
    $c = $c->fetch(); ?>
    <div class=statgrid>
        <div class=stat><div class=top><span class=lbl>Today</span><span class="ic g">&#128197;</span></div><div class=val><?= (int)$c['today'] ?></div></div>
        <div class=stat><div class=top><span class=lbl>Awaiting confirmation</span><span class="ic o">&#9203;</span></div><div class=val><?= (int)$c['pend'] ?></div><a class=view href="staff.php?p=appts">Review</a></div>
        <div class=stat><div class=top><span class=lbl>Upcoming</span><span class="ic t">&#9986;</span></div><div class=val><?= (int)$c['up'] ?></div></div>
    </div>
    <div class=box><h3>Today's appointments</h3>
    <?php appt_table(appt_rows($db, "a.staff_id=? AND a.appt_date=? AND a.status<>'cancelled'", [$sid, $today]), $custUrl); ?></div>
<?php }

if ($p === 'appts') {
    $f = $_GET['status'] ?? '';
    $ok = in_array($f, ['pending', 'confirmed', 'rescheduled', 'completed', 'cancelled'], true);
    ?>
    <div class=box>
        <form method=get class=row><input type=hidden name=p value=appts>
            <div><label>Status</label><select name=status onchange="this.form.submit()"><option value="">All</option>
            <?php foreach (['pending', 'confirmed', 'rescheduled', 'completed', 'cancelled'] as $s0) echo "<option" . ($f === $s0 ? ' selected' : '') . ">$s0</option>"; ?>
            </select></div>
        </form>
    </div>
    <div class=box><?php appt_table($ok ? appt_rows($db, 'a.staff_id=? AND a.status=?', [$sid, $f]) : appt_rows($db, 'a.staff_id=?', [$sid]), $custUrl); ?></div>
<?php }

if ($p === 'customer') {
    $cid = (int)($_GET['cid'] ?? 0);
    // Staff may only open customers who have an appointment with them.
    $c = $db->prepare("SELECT c.* FROM customer c WHERE c.customer_id=? AND EXISTS (SELECT 1 FROM appointment a WHERE a.customer_id=c.customer_id AND a.staff_id=?)");
    $c->execute([$cid, $sid]);
    $c = $c->fetch();
    if (!$c) {
        echo "<div class=box>Customer not found.</div>";
    } else { ?>
        <div class=box><div class=infogrid>
            <div class=f><label>Name</label><span><?= e($c['name']) ?></span></div>
            <div class=f><label>Phone</label><span><?= e($c['phone']) ?></span></div>
            <div class=f><label>Email</label><span><?= e($c['email']) ?></span></div>
            <div class=f><label>Customer since</label><span><?= e(date('M j, Y', strtotime($c['created_at']))) ?></span></div>
        </div></div>
        <div class=box><h3>Appointment &amp; service history</h3><?php cust_history($db, $cid); ?></div>
    <?php }
}

if ($p === 'schedule') { ?>
    <div class=box><h3>My weekly working hours</h3>
    <p style="color:var(--muted)">Customers can only book you inside these hours. Unticked days are days off. Existing appointments are not moved automatically.</p>
    <?php sched_form($db, $sid); ?></div>
<?php }

if ($p === 'reviews') {
    $rt = staff_ratings($db)[$sid] ?? null;
    $rows = $db->prepare("SELECT r.*,c.name cname,sv.name svname,a.appt_date FROM review r JOIN customer c ON c.customer_id=r.customer_id
        JOIN appointment a ON a.appointment_id=r.appointment_id JOIN service sv ON sv.service_id=a.service_id WHERE r.staff_id=? ORDER BY r.review_id DESC LIMIT 100");
    $rows->execute([$sid]); $rows = $rows->fetchAll(); ?>
    <div class=box><h3>My rating: <?= rating_html($rt) ?></h3>
    <?php if (!$rows) echo "<p style='color:var(--muted)'>No ratings yet.</p>"; else { ?>
        <table><tr><th>Date</th><th>Customer</th><th>Service</th><th>Rating</th><th>Comment</th></tr>
        <?php foreach ($rows as $r) echo "<tr><td>" . e(d_nice($r['appt_date'])) . "</td><td>" . e($r['cname']) . "</td><td>" . e($r['svname']) . "</td><td><span class=stars>" . stars($r['rating']) . "</span></td><td>" . e($r['comment']) . "</td></tr>"; ?>
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
