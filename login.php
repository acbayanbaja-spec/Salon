<?php
require 'config.php';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    chk();
    $err = try_login($db, ['customer'], 'This is a staff/admin account. Please use the Staff & Admin login.');
}

auth_head('Customer login', 'Smart Salon Appointment & Service Management');
?>
<h1>Welcome Back!</h1>
<p class=sub>Customer login</p>

<?php if ($err) echo "<div class='msg bad'>" . e($err) . "</div>"; ?>

<form method=post>
    <?= tok() ?>
    <label>Email (your username)</label>
    <input name=username required autofocus>

    <label>Password</label>
    <input type=password name=password required>

    <p><button style="width:100%">Sign In</button></p>
</form>

<p style="text-align:center;color:var(--muted);font-size:.9rem">
    New customer? <a href="register.php"><b>Create an account</b></a>
</p>
<p style="text-align:center;font-size:.82rem;margin-top:26px"><a href="admin-login.php" style="color:var(--muted)">Staff or admin? Log in here</a></p>

<?php auth_foot();
