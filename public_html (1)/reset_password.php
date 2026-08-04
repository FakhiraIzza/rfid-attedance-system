<?php
session_start();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

$err = '';
$ok = '';

$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$email = trim($_GET['email'] ?? ($_POST['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pass1 = $_POST['password'] ?? '';
  $pass2 = $_POST['password_confirm'] ?? '';
  if ($pass1 === '' || $pass2 === '') {
    $err = 'Password wajib diisi.';
  } elseif ($pass1 !== $pass2) {
    $err = 'Konfirmasi password tidak sama.';
  } elseif (strlen($pass1) < 6) {
    $err = 'Password minimal 6 karakter.';
  } else {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id_user, reset_token_hash, reset_token_expires FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
      $err = 'Token tidak valid.';
    } else {
      $hash = hash('sha256', $token);
      $expires = $user['reset_token_expires'] ?? '';
      if ($hash !== ($user['reset_token_hash'] ?? '') || $expires === '' || $expires < date('Y-m-d H:i:s')) {
        $err = 'Token tidak valid atau sudah kadaluarsa.';
      } else {
        $pwd = password_hash($pass1, PASSWORD_BCRYPT);
        $upd = $pdo->prepare("UPDATE users SET password_hash=?, reset_token_hash=NULL, reset_token_expires=NULL WHERE id_user=?");
        $upd->execute([$pwd, (int)$user['id_user']]);
        $ok = 'Password berhasil diubah. Silakan login.';
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
      <div class="col-12 col-lg-5">
        <div class="login-panel">
          <div class="mb-3">
            <div class="fw-semibold">Reset Password</div>
            <div class="text-muted">Buat password baru.</div>
          </div>

          <?php if ($err): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
          <?php endif; ?>
          <?php if ($ok): ?>
            <div class="alert alert-success"><?= htmlspecialchars($ok) ?></div>
          <?php endif; ?>

          <form method="post" class="vstack gap-3">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
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
