<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';

require_role('admin');

$pdo = db();
$message = '';
$affected = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $stmt = $pdo->prepare("
    UPDATE attendance
    SET type = 'MASUK'
    WHERE LOWER(TRIM(status)) IN ('izin','ijin','sakit','alfa','alpha')
  ");
  $stmt->execute();
  $affected = $stmt->rowCount();
  $message = "Selesai. Baris diperbarui: {$affected}.";
}
?>
<!doctype html>
<html lang="id">
  <head>
    <meta charset="utf-8">
    <title>Perbaiki Jenis Presensi</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
      body{font-family:Arial, sans-serif; padding:24px; max-width:640px; margin:0 auto;}
      .box{border:1px solid #ddd; border-radius:10px; padding:16px;}
      .btn{background:#0b5ed7; color:#fff; border:0; padding:10px 16px; border-radius:8px; cursor:pointer;}
      .muted{color:#666; font-size:14px;}
      .ok{background:#e7f5e7; border:1px solid #b9e2b9; padding:10px; border-radius:8px; margin-top:12px;}
    </style>
  </head>
  <body>
    <h2>Perbaiki Jenis Presensi</h2>
    <div class="box">
      <p>Tool ini akan mengubah <b>type</b> menjadi <b>MASUK</b> untuk status: Izin, Ijin, Sakit, Alfa/Alpha, agar tetap valid dengan struktur tabel saat ini.</p>
      <form method="post">
        <button class="btn" type="submit">Jalankan Perbaikan</button>
      </form>
      <?php if ($message): ?>
        <div class="ok"><?= htmlspecialchars($message) ?></div>
      <?php else: ?>
        <p class="muted">Klik sekali saja. Setelah sukses, hapus file ini.</p>
      <?php endif; ?>
    </div>
  </body>
</html>
