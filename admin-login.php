<?php
require 'config.php';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    chk();
    $err = try_login($db, ['admin', 'staff'], 'This is a customer account. Please use the customer login page.');
}

auth_head('Staff & Admin login', 'Staff & Admin Portal', true);
?>
<h1>Staff &amp; Admin</h1>
<p class=sub>Sign in to manage appointments, payments and schedules</p>

<?php if ($err) echo "<div class='msg bad'>" . e($err) . "</div>"; ?>

<form method=post>
    <?= tok() ?>
    <label>Username</label>
    <input name=username required autofocus>

    <label>Password</label>
    <input type=password name=password required>

    <p><button style="width:100%;background:#14213d">Sign In</button></p>
</form>

<p style="text-align:center;font-size:.85rem;margin-top:26px"><a href="login.php" style="color:var(--muted)">&larr; Customer login</a></p>

<?php auth_foot();
