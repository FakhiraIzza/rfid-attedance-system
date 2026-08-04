<?php
session_start();
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';

$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nis = trim($_POST['nis'] ?? '');
  $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
  $email = trim($_POST['email'] ?? '');
  $pass1 = $_POST['password'] ?? '';
  $pass2 = $_POST['password_confirm'] ?? '';

  if ($nis === '' || $phone === '' || $email === '' || $pass1 === '') {
    $err = 'Semua field wajib diisi.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'Email tidak valid.';
  } elseif ($pass1 !== $pass2) {
    $err = 'Konfirmasi password tidak sama.';
  } elseif (strlen($pass1) < 6) {
    $err = 'Password minimal 6 karakter.';
  } else {
    $pdo = db();
    $st = $pdo->prepare("
      SELECT s.id_siswa, s.nama_siswa, s.class_level, p.id_orangtua, p.no_hp
      FROM students s
      JOIN parents p ON p.id_orangtua = s.id_orangtua
      WHERE s.nis = ?
    ");
    $st->execute([$nis]);
    $rows = $st->fetchAll();

    $parent = null;
    foreach ($rows as $r) {
      $dbPhone = preg_replace('/\D+/', '', (string)($r['no_hp'] ?? ''));
      if ($dbPhone !== '' && $dbPhone === $phone) {
        $parent = $r;
        break;
      }
    }

    if (!$parent) {
      $err = 'NIS atau nomor HP tidak cocok.';
    } else {
      $checkEmail = $pdo->prepare("SELECT id_user, parent_id FROM users WHERE email = ? LIMIT 1");
      $checkEmail->execute([$email]);
      $existing = $checkEmail->fetch(PDO::FETCH_ASSOC);
      if ($existing && (int)$existing['parent_id'] !== (int)$parent['id_orangtua']) {
        $err = 'Email sudah digunakan akun lain.';
      } else {
        $hash = password_hash($pass1, PASSWORD_BCRYPT);
        $display = 'Orang Tua Ananda ' . $parent['nama_siswa'];

        $checkUser = $pdo->prepare("SELECT id_user FROM users WHERE role='orangtua' AND parent_id=? LIMIT 1");
        $checkUser->execute([(int)$parent['id_orangtua']]);
        $user = $checkUser->fetch(PDO::FETCH_ASSOC);

        if ($user) {
          $upd = $pdo->prepare("UPDATE users SET username=?, email=?, password_hash=?, nama_lengkap=? WHERE id_user=?");
          $upd->execute([$email, $email, $hash, $display, (int)$user['id_user']]);
        } else {
          $ins = $pdo->prepare("
            INSERT INTO users (username, email, password_hash, role, nama_lengkap, parent_id)
            VALUES (?, ?, ?, 'orangtua', ?, ?)
          ");
          $ins->execute([$email, $email, $hash, $display, (int)$parent['id_orangtua']]);
        }

        $ok = 'Akun berhasil diperbarui. Silakan login dengan email dan password baru.';
      }
    }
  }
}

$body_class = 'login-body';
include __DIR__ . '/partials/head.php';
?>
<div class="login-hero">
  <div class="login-overlay"></div>
  <div class="container login-content py-4 py-lg-5">
    <div class="row justify-content-center">
      <div class="col-12 col-lg-6">
        <div class="login-panel">
          <div class="mb-3">
            <div class="fw-semibold">Ubah Password Orang Tua</div>
            <div class="text-muted">Ganti login ke email & password baru.</div>
          </div>

          <?php if ($err): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
          <?php endif; ?>
          <?php if ($ok): ?>
            <div class="alert alert-success"><?= htmlspecialchars($ok) ?></div>
          <?php endif; ?>

          <form method="post" class="vstack gap-3">
            <div>
              <label class="form-label">NIS</label>
              <input name="nis" class="form-control" placeholder="contoh: 20231234" required>
            </div>
            <div>
              <label class="form-label">No HP Orang Tua</label>
              <input name="phone" class="form-control" placeholder="contoh: 08123456789" required>
            </div>
            <div>
              <label class="form-label">Email (username baru)</label>
              <input type="email" name="email" class="form-control" placeholder="contoh: orangtua@gmail.com" required>
            </div>
            <div>
              <label class="form-label">Password Baru</label>
              <div class="input-group">
                <input type="password" name="password" class="form-control" required>
                <button class="btn btn-outline-secondary" type="button" data-toggle="password">Lihat</button>
              </div>
            </div>
            <div>
              <label class="form-label">Konfirmasi Password</label>
              <div class="input-group">
                <input type="password" name="password_confirm" class="form-control" required>
                <button class="btn btn-outline-secondary" type="button" data-toggle="password">Lihat</button>
              </div>
            </div>
            <button class="btn btn-brand text-white w-100">Simpan</button>
          </form>

          <div class="mt-3">
            <a class="small" href="<?= base_url('/login.php') ?>">Kembali ke Login</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
  const toggleButtons = document.querySelectorAll('[data-toggle="password"]');
  toggleButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const input = btn.parentElement?.querySelector('input');
      if (!input) return;
      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      btn.textContent = isHidden ? 'Sembunyi' : 'Lihat';
    });
  });
</script>
<?php include __DIR__ . '/partials/foot.php'; ?>
