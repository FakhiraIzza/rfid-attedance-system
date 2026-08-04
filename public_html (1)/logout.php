<?php
session_start();
require_once __DIR__ . '/lib/auth.php';
logout();
header('Location: ' . base_url('/login.php'));
exit;
