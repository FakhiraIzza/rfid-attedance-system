<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';

require_role('admin');
$active='settings';
$title='Pengaturan Jam Presensi';
$subtitle='Atur jam valid presensi (dipakai oleh API NodeMCU)';

$pdo=db();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  foreach (['jam_masuk_mulai','jam_masuk_tepat_akhir','jam_masuk_terlambat_akhir','jam_pulang_mulai','jam_pulang_akhir'] as $k) {
    $val = $_POST[$k] ?? '';
    $pdo->prepare("UPDATE settings SET value=? WHERE `key`=?")->execute([$val,$k]);
  }
  header('Location: ' . base_url('/admin/settings.php?ok=1'));
  exit;
}

$rows = $pdo->query("SELECT * FROM settings")->fetchAll();
$set = [];
foreach($rows as $r){ $set[$r['key']] = $r['value']; }

include __DIR__ . '/../partials/head.php';
?>
<div class="container-fluid">
  <div class="row">
    <?php include __DIR__ . '/../partials/sidebar.php'; ?>
    <div class="col-12 col-lg-9 col-xl-10 p-4">
      <?php include __DIR__ . '/../partials/topbar.php'; ?>

      <?php if(isset($_GET['ok'])): ?>
        <div class="alert alert-success">Pengaturan tersimpan.</div>
      <?php endif; ?>

      <div class="card shadow-sm">
        <div class="card-body">
          <form method="post" class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">Jam Masuk Mulai</label>
              <input type="time" step="1" name="jam_masuk_mulai" class="form-control" value="<?= htmlspecialchars($set['jam_masuk_mulai'] ?? '06:30:00') ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Jam Masuk Tepat Akhir</label>
              <input type="time" step="1" name="jam_masuk_tepat_akhir" class="form-control" value="<?= htmlspecialchars($set['jam_masuk_tepat_akhir'] ?? '07:15:00') ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Jam Masuk Terlambat Akhir</label>
              <input type="time" step="1" name="jam_masuk_terlambat_akhir" class="form-control" value="<?= htmlspecialchars($set['jam_masuk_terlambat_akhir'] ?? '08:00:00') ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">Jam Pulang Mulai</label>
              <input type="time" step="1" name="jam_pulang_mulai" class="form-control" value="<?= htmlspecialchars($set['jam_pulang_mulai'] ?? '13:00:00') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Jam Pulang Akhir</label>
              <input type="time" step="1" name="jam_pulang_akhir" class="form-control" value="<?= htmlspecialchars($set['jam_pulang_akhir'] ?? '15:00:00') ?>">
            </div>

            <div class="col-12">
              <button class="btn btn-brand text-white">Simpan Jam</button>
            </div>
          </form>

          <hr>
          <div class="small-muted">
            Jam ini dipakai oleh API presensi (dari Alat Presensi/NodeMCU). Jika anak scan di luar jam, data tidak disimpan ke tabel <code>attendance</code>.
          </div>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/foot.php'; ?>
