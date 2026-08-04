<?php
session_start();
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';

require_login();
$u = current_user();

if ($u['role']==='admin') {
  header('Location: ' . base_url('/admin/dashboard.php'));
  exit;
}
if ($u['role']==='guru') {
  header('Location: ' . base_url('/guru/dashboard.php'));
  exit;
}
header('Location: ' . base_url('/ortu/dashboard.php'));
exit;
