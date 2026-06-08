<?php
require_once __DIR__ . '/includes/auth.php';
log_activity('Logged out');
$_SESSION = [];
session_destroy();
redirect('/login.php');

