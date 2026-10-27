<?php require 'config.php';$r=$_SESSION['u']['role']??'customer';$_SESSION=[];session_destroy();header('Location: '.($r==='customer'?'login.php':'admin-login.php'));
