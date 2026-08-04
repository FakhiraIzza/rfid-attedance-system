<?php
session_start();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/auth.php';

$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'Email tidak valid.';
  } else {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id_user, email FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
      $token = bin2hex(random_bytes(16));
      $hash = hash('sha256', $token);
      $expires = date('Y-m-d H:i:s', time() + 3600);

      $upd = $pdo->prepare("UPDATE users SET reset_token_hash=?, reset_token_expires=? WHERE id_user=?");
      $upd->execute([$hash, $expires, (int)$user['id_user']]);

      $link = base_url('/reset_password.php') . '?token=' . urlencode($token) . '&email=' . urlencode($email);
      $html = "<p>Anda meminta reset password.</p>"
            . "<p>Klik link berikut untuk membuat password baru:</p>"
            . "<p><a href=\"{$link}\">Reset Password</a></p>"
            . "<p>Link berlaku 1 jam.</p>";

      smtp_send($email, 'Reset Password Presensi RFID', $html);
    }

    $ok = 'Jika email terdaftar, tautan reset telah dikirim.';
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
            <div class="fw-semibold">Lupa Password</div>
            <div class="text-muted">Kirim tautan reset ke email.</div>
          </div>

          <?php if ($err): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
          <?php endif; ?>
          <?php if ($ok): ?>
            <div class="alert alert-success"><?= htmlspecialchars($ok) ?></div>
          <?php endif; ?>

          <form method="post" class="vstack gap-3">
            <div>
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" placeholder="email terdaftar" required>
            </div>
            <button class="btn btn-brand text-white w-100">Kirim Tautan Reset</button>
          </form>

          <div class="mt-3">
            <a class="small" href="<?= base_url('/login.php') ?>">Kembali ke Login</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/foot.php'; ?>
