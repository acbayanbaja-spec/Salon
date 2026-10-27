<?php
require 'config.php';

$services = $db->query("SELECT * FROM service WHERE active=1 ORDER BY name")->fetchAll();
$staff    = $db->query("SELECT name FROM staff WHERE active=1 ORDER BY name")->fetchAll();
$role     = $_SESSION['u']['role'] ?? '';
$panel    = ['admin' => 'admin.php', 'staff' => 'staff.php', 'customer' => 'customer.php'][$role] ?? '';

auth_head('Welcome', 'Smart Salon Appointment & Service Management');
?>
<h1>Book your appointment</h1>
<p class=sub>Choose a service, pick your stylist, and reserve a time online.</p>

<p>
<?php if ($panel): ?>
    <a class=btn href="<?= e($panel) ?>">Go to my dashboard</a>
<?php else: ?>
    <a class=btn href="register.php">Create an account &amp; book</a>
    <a class=btn style="background:#fff;color:var(--ink);border:1px solid var(--ln)" href="login.php">Log in</a>
<?php endif; ?>
</p>

<h3>Services &amp; prices</h3>
<?php if (!$services): ?>
    <div class=msg>No services have been added yet. Please check back soon.</div>
<?php else: ?>
    <table>
    <?php foreach ($services as $s): ?>
        <tr><td><b><?= e($s['name']) ?></b><br><small><?= (int)$s['duration_min'] ?> min</small></td><td style="text-align:right"><?= money($s['price']) ?></td></tr>
    <?php endforeach; ?>
    </table>
<?php endif; ?>

<p style="font-size:.82rem;margin-top:24px"><a href="admin-login.php" style="color:var(--muted)">Staff &amp; admin login</a></p>

<?php if ($staff): ?>
    <h3 style="margin-top:20px">Our team</h3>
    <p><?= e(implode(', ', array_column($staff, 'name'))) ?></p>
<?php endif; ?>
<?php auth_foot();
