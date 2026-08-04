<?php
session_start();
require_once __DIR__ . '/lib/auth.php';

if (!current_user()) {
  header('Location: ' . base_url('/login.php'));
  exit;
}
header('Location: ' . base_url('/dashboard.php'));
exit;
