<?php
require 'config.php';
$err = '';
$v = ['name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    chk();
    $v['name']  = trim($_POST['name'] ?? '');
    $v['email'] = strtolower(trim($_POST['email'] ?? ''));
    $v['phone'] = trim($_POST['phone'] ?? '');
    $pw  = $_POST['pw'] ?? '';
    $pw2 = $_POST['pw2'] ?? '';

    $x = $db->prepare("SELECT (SELECT COUNT(*) FROM account WHERE username=?) + (SELECT COUNT(*) FROM customer WHERE email=?)");
    $x->execute([$v['email'], $v['email']]);

    if ($v['name'] === '' || mb_strlen($v['name']) > 100) {
        $err = 'Please enter your full name.';
    } elseif (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 50) {
        $err = 'Please enter a valid email address (max 50 characters).';
    } elseif (!valid_phone($v['phone'])) {
        $err = 'Enter a valid Philippine mobile number, e.g. 09171234567 or +639171234567.';
    } elseif (strlen($pw) < 8) {
        $err = 'Password must be at least 8 characters.';
    } elseif ($pw !== $pw2) {
        $err = 'The two passwords do not match.';
    } elseif ($x->fetchColumn()) {
        $err = 'An account with this email already exists. Please log in.';
    } else {
        $db->beginTransaction();
        $db->prepare("INSERT INTO account(username,password,role) VALUES(?,?,'customer')")
           ->execute([$v['email'], password_hash($pw, PASSWORD_DEFAULT)]);
        $aid = $db->lastInsertId();
        $db->prepare("INSERT INTO customer(account_id,name,email,phone) VALUES(?,?,?,?)")
           ->execute([$aid, $v['name'], $v['email'], $v['phone']]);
        $db->commit();

        session_regenerate_id(true);
        $_SESSION['u'] = ['id' => $aid, 'role' => 'customer', 'username' => $v['email']];
        flash('Welcome! Your account is ready. You can book your first appointment now.');
        header('Location: customer.php?p=book');
        exit;
    }
}

auth_head('Register', 'Smart Salon Appointment & Service Management');
?>
<h1>Create account</h1>
<p class=sub>Register once, then book anytime</p>
<?php if ($err) echo "<div class='msg bad'>" . e($err) . "</div>"; ?>
<form method=post>
    <?= tok() ?>
    <label>Full name</label>
    <input name=name required maxlength=100 value="<?= e($v['name']) ?>">
    <label>Email (this is your username)</label>
    <input type=email name=email required maxlength=50 value="<?= e($v['email']) ?>">
    <label>Mobile number (for SMS updates)</label>
    <input name=phone required placeholder="09171234567" pattern="(09\d{9}|\+639\d{9})" value="<?= e($v['phone']) ?>">
    <label>Password (min 8 characters)</label>
    <input type=password name=pw required minlength=8>
    <label>Confirm password</label>
    <input type=password name=pw2 required minlength=8>
    <p><button style="width:100%">Register</button></p>
</form>
<p style="text-align:center;color:var(--muted);font-size:.9rem">Already registered? <a href="login.php"><b>Log in</b></a></p>
<?php auth_foot();
