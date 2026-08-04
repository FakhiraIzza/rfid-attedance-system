<?php
require_once __DIR__ . '/db.php';

function app_cfg(): array {
  static $cfg = null;
  if ($cfg) return $cfg;
  $cfg = require __DIR__ . '/../config/app.php';
  date_default_timezone_set($cfg['timezone'] ?? 'Asia/Jakarta');
  return $cfg;
}

function base_url(string $path=''): string {
  $cfg = app_cfg();
  $base = rtrim($cfg['base_path'] ?? '', '/');
  return $base . $path;
}

function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

function require_login(): void {
  if (!current_user()) {
    header('Location: ' . base_url('/login.php'));
    exit;
  }
}

function require_role(string ...$roles): void {
  require_login();
  $u = current_user();
  if (!$u || !in_array($u['role'], $roles, true)) {
    http_response_code(403);
    echo "403 Forbidden";
    exit;
  }
}

function login(string $username, string $password): bool {
  $pdo = db();
  $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
  $stmt->execute([$username, $username]);
  $u = $stmt->fetch();

  if ($u) {
    // password_hash dari SQL sudah pakai bcrypt
    if (!password_verify($password, $u['password_hash'])) return false;

    $_SESSION['user'] = [
      'id_user' => (int)$u['id_user'],
      'username' => $u['username'],
      'role' => $u['role'],
      'nama_lengkap' => $u['nama_lengkap'] ?? $u['username'],
      'parent_id' => $u['parent_id'],
      'teacher_id' => $u['teacher_id'],
    ];
    return true;
  }

  // Login orang tua: gunakan NIS + No HP
  $nis = trim($username);
  $phone = preg_replace('/\D+/', '', (string)$password);
  if ($nis === '' || $phone === '') return false;

  $st = $pdo->prepare("
    SELECT s.id_siswa, s.nama_siswa, s.class_level, p.id_orangtua, p.no_hp
    FROM students s
    JOIN parents p ON p.id_orangtua = s.id_orangtua
    WHERE s.nis = ?
  ");
  $st->execute([$nis]);
  $rows = $st->fetchAll();
  foreach ($rows as $r) {
    $dbPhone = preg_replace('/\D+/', '', (string)($r['no_hp'] ?? ''));
    if ($dbPhone !== '' && $dbPhone === $phone) {
      $_SESSION['user'] = [
        'id_user' => 0,
        'username' => $nis,
        'role' => 'orangtua',
        'nama_lengkap' => 'Orang Tua Ananda ' . $r['nama_siswa'],
        'parent_id' => (int)$r['id_orangtua'],
        'teacher_id' => null,
      ];
      return true;
    }
  }

  return false;
}

function logout(): void {
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000,
      $params["path"], $params["domain"],
      $params["secure"], $params["httponly"]
    );
  }
  session_destroy();
}
