<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';

require_role('guru');
$active='parents';
$title='Data Anak & Kontak Ortu';
$subtitle='Perbaiki NIS, nama siswa, dan nomor HP orang tua sesuai kelas yang diampu';

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

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='update-student') {
  $idSiswa = (int)($_POST['id_siswa'] ?? 0);
  $nis = trim($_POST['nis'] ?? '');
  $nama = trim($_POST['nama_siswa'] ?? '');
  $hpRaw = trim($_POST['no_hp'] ?? '');
  $hp = preg_replace('/\D+/', '', $hpRaw);

  if ($idSiswa <= 0 || $nis === '' || $nama === '') {
    $_SESSION['flash_error'] = 'NIS dan nama siswa wajib diisi.';
    header('Location: ' . base_url('/guru/parents.php'));
    exit;
  }

  $st = $pdo->prepare("SELECT id_orangtua FROM students WHERE id_siswa=? AND class_level=? LIMIT 1");
  $st->execute([$idSiswa, $classLevel]);
  $row = $st->fetch();
  if (!$row) {
    $_SESSION['flash_error'] = 'Siswa tidak ditemukan di kelas yang diampu.';
    header('Location: ' . base_url('/guru/parents.php'));
    exit;
  }

  $pdo->prepare("UPDATE students SET nis=?, nama_siswa=? WHERE id_siswa=?")
      ->execute([$nis, $nama, $idSiswa]);

  if ($hp !== '') {
    $parentId = (int)($row['id_orangtua'] ?? 0);
    $namaOrtu = 'Orang Tua ' . $nama;
    if ($parentId > 0) {
      $pdo->prepare("UPDATE parents SET no_hp=?, nama_orangtua=? WHERE id_orangtua=?")
          ->execute([$hp, $namaOrtu, $parentId]);
    } else {
      $cek = $pdo->prepare("SELECT id_orangtua FROM parents WHERE no_hp=? LIMIT 1");
      $cek->execute([$hp]);
      $pRow = $cek->fetch();
      if ($pRow) {
        $parentId = (int)$pRow['id_orangtua'];
        $pdo->prepare("UPDATE parents SET nama_orangtua=? WHERE id_orangtua=?")
            ->execute([$namaOrtu, $parentId]);
      } else {
        $pdo->prepare("INSERT INTO parents (nama_orangtua,no_hp,email,alamat) VALUES (?,?,?,?)")
            ->execute([$namaOrtu, $hp, '', '']);
        $parentId = (int)$pdo->lastInsertId();
      }

      $pdo->prepare("UPDATE students SET id_orangtua=? WHERE id_siswa=?")
          ->execute([$parentId, $idSiswa]);
    }
  }

  $_SESSION['flash_success'] = 'Data siswa dan kontak orang tua diperbarui.';
  header('Location: ' . base_url('/guru/parents.php'));
  exit;
}

$editRow = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
  $stEdit = $pdo->prepare("
    SELECT s.id_siswa, s.nis, s.nama_siswa, s.class_level, s.id_orangtua, p.no_hp
    FROM students s
    LEFT JOIN parents p ON p.id_orangtua = s.id_orangtua
    WHERE s.id_siswa = ? AND s.class_level = ?
    LIMIT 1
  ");
  $stEdit->execute([$editId, $classLevel]);
  $editRow = $stEdit->fetch();
  if (!$editRow) {
    $_SESSION['flash_error'] = 'Data siswa tidak ditemukan.';
    header('Location: ' . base_url('/guru/parents.php'));
    exit;
  }
}

$rowsStmt = $pdo->prepare("
  SELECT s.id_siswa, s.nis, s.nama_siswa, s.class_level, s.id_orangtua, p.no_hp
  FROM students s
  LEFT JOIN parents p ON p.id_orangtua = s.id_orangtua
  WHERE s.class_level = ?
  ORDER BY s.nama_siswa
");
$rowsStmt->execute([$classLevel]);
$rows = $rowsStmt->fetchAll();

include __DIR__ . '/../partials/head.php';
?>
<div class="container-fluid">
  <div class="row">
    <?php include __DIR__ . '/../partials/sidebar.php'; ?>
    <div class="col-12 col-lg-9 col-xl-10 p-4">
      <?php include __DIR__ . '/../partials/topbar.php'; ?>

      <?php if(!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
        <?php unset($_SESSION['flash_error']); ?>
      <?php endif; ?>
      <?php if(!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
        <?php unset($_SESSION['flash_success']); ?>
      <?php endif; ?>

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="small-muted">Kelas yang diampu: <b><?= htmlspecialchars($classLevel) ?></b></div>
          <div class="small-muted mt-1">Perubahan di sini otomatis mempengaruhi dashboard admin dan akun orang tua.</div>
        </div>
      </div>

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="fw-semibold mb-2">Edit Data Siswa & Nomor Orang Tua</div>
          <?php if($editRow): ?>
            <form method="post" class="row g-2 align-items-end">
              <input type="hidden" name="action" value="update-student">
              <input type="hidden" name="id_siswa" value="<?= (int)$editRow['id_siswa'] ?>">
              <div class="col-12 col-md-2">
                <label class="form-label">NIS</label>
                <input name="nis" class="form-control" value="<?= htmlspecialchars($editRow['nis'] ?? '') ?>" required>
              </div>
              <div class="col-12 col-md-4">
                <label class="form-label">Nama Siswa</label>
                <input name="nama_siswa" class="form-control" value="<?= htmlspecialchars($editRow['nama_siswa'] ?? '') ?>" required>
              </div>
              <div class="col-12 col-md-3">
                <label class="form-label">No HP Orang Tua</label>
                <input name="no_hp" class="form-control" value="<?= htmlspecialchars($editRow['no_hp'] ?? '') ?>" placeholder="contoh: 08123456789">
              </div>
              <div class="col-6 col-md-2 d-grid">
                <button class="btn btn-brand text-white">Simpan</button>
              </div>
              <div class="col-6 col-md-1 d-grid">
                <a class="btn btn-outline-secondary" href="<?= base_url('/guru/parents.php') ?>">Batal</a>
              </div>
            </form>
          <?php else: ?>
            <div class="small-muted">Klik tombol <b>Edit</b> pada tabel di bawah.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-body">
          <div class="fw-semibold mb-2">Daftar Siswa Kelas <?= htmlspecialchars($classLevel) ?></div>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>No</th><th>NIS</th><th>Nama Siswa</th><th>No HP Orang Tua</th><th>Aksi</th></tr></thead>
              <tbody>
                <?php $no = 1; foreach($rows as $r): ?>
                  <tr>
                    <td><?= $no++ ?></td>
                    <td><?= htmlspecialchars($r['nis'] ?? '-') ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($r['nama_siswa']) ?></td>
                    <td><?= htmlspecialchars($r['no_hp'] ?? '-') ?></td>
                    <td>
                      <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('/guru/parents.php?edit='.(int)$r['id_siswa']) ?>">Edit</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if(!$rows): ?>
                  <tr><td colspan="5" class="small-muted">Belum ada data siswa di kelas ini.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/foot.php'; ?>
