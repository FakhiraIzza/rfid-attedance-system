<?php
require_once __DIR__ . '/../lib/auth.php';

require_role('admin');

header('Location: ' . base_url('/admin/students.php?class=all#bulk-import'));
exit;
