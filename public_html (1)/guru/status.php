<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/attendance_helpers.php';

require_role('guru');
$active='status';
$title='Input Izin / Sakit / Alfa';
$subtitle='Set status presensi manual (misal siswa izin/sakit/alfa)';

$pdo=db();
$u=current_user();
$teacherId = $u['teacher_id'] ?? null;

$classLevel = '7';
if ($teacherId) {
  $st=$pdo->prepare("SELECT class_level FROM teachers WHERE id_guru=?");
  $st->execute([$teacherId]);
  $t=$st->fetch();
  $classLevel = $t['class_level'] ?? '7';
}

$date = $_GET['date'] ?? date('Y-m-d');

$students = $pdo->prepare("SELECT id_siswa, nama_siswa FROM students WHERE class_level=? ORDER BY nama_siswa");
$students->execute([$classLevel]);
$kids = $students->fetchAll();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $id = (int)($_POST['id_siswa'] ?? 0);
  $status = $_POST['status'] ?? 'Izin';
  $ket = trim($_POST['keterangan'] ?? '');
  $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
  $scanAt = $tanggal . ' 07:00:00'; // jam default untuk manual

  // Simpan ke tipe non-pulang yang valid; kategori tidak masuk ditentukan dari status.
  $pdo->prepare("
    INSERT INTO attendance (id_siswa, scan_at, tanggal, type, status, catatan, source)
    VALUES (?,?,?,?,?,?, 'manual')
    ON DUPLICATE KEY UPDATE status=VALUES(status), catatan=VALUES(catatan), source='manual', scan_at=VALUES(scan_at), type=VALUES(type)
  ")->execute([$id, $scanAt, $tanggal, attendance_storage_type_for_status($status), $status, $ket ?: null]);

  header('Location: ' . base_url('/guru/status.php?date=' . urlencode($tanggal) . '&ok=1'));
  exit;
}

include __DIR__ . '/../partials/head.php';
?>
<div class="container-fluid">
  <div class="row">
    <?php include __DIR__ . '/../partials/sidebar.php'; ?>
    <div class="col-12 col-lg-9 col-xl-10 p-4">
      <?php include __DIR__ . '/../partials/topbar.php'; ?>

      <?php if(isset($_GET['ok'])): ?>
        <div class="alert alert-success">Status tersimpan.</div>
      <?php endif; ?>

      <div class="card shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div class="small-muted">Kelas: <b><?= htmlspecialchars($classLevel) ?></b></div>
          <form method="get" class="d-flex gap-2 align-items-center">
            <div class="small-muted">Tanggal:</div>
            <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($date) ?>" onchange="this.form.submit()">
          </form>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-body">
          <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="tanggal" value="<?= htmlspecialchars($date) ?>">
            <div class="col-12 col-md-5">
              <label class="form-label">Siswa</label>
              <select name="id_siswa" class="form-select" required>
                <?php foreach($kids as $k): ?>
                  <option value="<?= (int)$k['id_siswa'] ?>"><?= htmlspecialchars($k['nama_siswa']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="Izin">Izin</option>
                <option value="Sakit">Sakit</option>
                <option value="Alfa">Alfa</option>
              </select>
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">Keterangan (opsional)</label>
              <input name="keterangan" class="form-control" placeholder="misal: surat dokter">
            </div>
            <div class="col-12 col-md-1 d-grid">
              <button class="btn btn-brand text-white">Save</button>
            </div>
          </form>

          <hr>
          <div class="small-muted">
            Catatan: status manual disimpan ke tabel <code>attendance</code> dengan <code>source=manual</code>.
          </div>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/foot.php'; ?>
