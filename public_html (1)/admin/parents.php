<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';

require_role('admin');
$active='parents';
$title='Kontak Orang Tua';
$subtitle='Hubungkan nomor HP orang tua ke siswa';

$pdo=db();

$class = $_GET['class'] ?? '7';
if (!in_array($class, ['7','8','9'], true)) $class = '7';
$search = trim($_GET['q'] ?? '');

$students = $pdo->query("SELECT id_siswa, nama_siswa, class_level FROM students ORDER BY class_level, nama_siswa");
$allStudents = $students->fetchAll();
$class = (string)$class;
$classMap = ['7' => 'vii', '8' => 'viii', '9' => 'ix'];
$classRoman = $classMap[$class] ?? '';
$studentRows = array_values(array_filter($allStudents, function ($s) use ($class, $classRoman) {
  $val = strtolower(trim((string)($s['class_level'] ?? '')));
  $norm = preg_replace('/[^a-z0-9]/', '', $val);
  if ($norm === '') return false;
  if ($norm === $class || str_starts_with($norm, $class)) return true;
  if ($classRoman && ($norm === $classRoman || str_starts_with($norm, $classRoman))) return true;
  if ($norm === 'kelas' . $class || str_starts_with($norm, 'kelas' . $class)) return true;
  if ($classRoman && ($norm === 'kelas' . $classRoman || str_starts_with($norm, 'kelas' . $classRoman))) return true;
  return false;
}));

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='update-phone') {
  $idSiswa = (int)($_POST['id_siswa'] ?? 0);
  $class = $_POST['class_level'] ?? $class;
  if (!in_array($class, ['7','8','9'], true)) $class = '7';
  $hpRaw = trim($_POST['no_hp'] ?? '');
  $hp = preg_replace('/\D+/', '', $hpRaw);

  if ($idSiswa <= 0 || $hp === '') {
    $_SESSION['flash_error'] = 'Nomor HP tidak boleh kosong.';
    header('Location: ' . base_url('/admin/parents.php?class=' . urlencode($class)));
    exit;
  }

  $st = $pdo->prepare("
    SELECT p.id_orangtua
    FROM students s
    JOIN parents p ON p.id_orangtua = s.id_orangtua
    WHERE s.id_siswa = ?
    LIMIT 1
  ");
  $st->execute([$idSiswa]);
  $row = $st->fetch();
  if (!$row) {
    $_SESSION['flash_error'] = 'Data nomor orang tua tidak ditemukan.';
    header('Location: ' . base_url('/admin/parents.php?class=' . urlencode($class)));
    exit;
  }

  $pdo->prepare("UPDATE parents SET no_hp=? WHERE id_orangtua=?")
      ->execute([$hp, (int)$row['id_orangtua']]);

  $_SESSION['flash_success'] = 'Nomor HP berhasil diperbarui.';
  header('Location: ' . base_url('/admin/parents.php?class=' . urlencode($class)));
  exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $class = $_POST['class_level'] ?? '7';
  if (!in_array($class, ['7','8','9'], true)) $class = '7';
  $nama = trim($_POST['nama_siswa'] ?? '');
  $idSiswa = (int)($_POST['id_siswa'] ?? 0);
  $hpRaw = trim($_POST['no_hp'] ?? '');
  $hp = preg_replace('/\D+/', '', $hpRaw);

  if (($nama === '' && $idSiswa <= 0) || $hp === '') {
    $_SESSION['flash_error'] = 'Pilih nama siswa atau ketik nama siswa, dan isi nomor HP.';
    header('Location: ' . base_url('/admin/parents.php?class=' . urlencode($class)));
    exit;
  }

  if ($idSiswa > 0) {
    $st = $pdo->prepare("SELECT id_siswa, nama_siswa, class_level FROM students WHERE id_siswa=? LIMIT 1");
    $st->execute([$idSiswa]);
    $kids = $st->fetchAll();
  } else {
    $namaLike = '%' . $nama . '%';
    $st = $pdo->prepare("SELECT id_siswa, nama_siswa, class_level FROM students WHERE class_level=? AND nama_siswa LIKE ? ORDER BY nama_siswa");
    $st->execute([$class, $namaLike]);
    $kids = $st->fetchAll();
  }

  if (!$kids) {
    $_SESSION['flash_error'] = 'Nama siswa tidak ditemukan pada kelas tersebut.';
    header('Location: ' . base_url('/admin/parents.php?class=' . urlencode($class)));
    exit;
  }
  if (count($kids) > 1) {
    $_SESSION['flash_error'] = 'Nama siswa tidak unik. Mohon tulis nama lengkap.';
    header('Location: ' . base_url('/admin/parents.php?class=' . urlencode($class)));
    exit;
  }

  $kid = $kids[0];
  $class = $kid['class_level'] ?? $class;
  $parentId = null;

  $st = $pdo->prepare("SELECT id_orangtua FROM parents WHERE no_hp = ? LIMIT 1");
  $st->execute([$hp]);
  $row = $st->fetch();
  $namaOrtu = 'Orang Tua ' . $kid['nama_siswa'];
  if ($row) {
    $parentId = (int)$row['id_orangtua'];
    $pdo->prepare("UPDATE parents SET nama_orangtua=? WHERE id_orangtua=?")
        ->execute([$namaOrtu, $parentId]);
  } else {
    $pdo->prepare("INSERT INTO parents (nama_orangtua,no_hp,email,alamat) VALUES (?,?,?,?)")
        ->execute([$namaOrtu, $hp, '', '']);
    $parentId = (int)$pdo->lastInsertId();
  }

  $pdo->prepare("UPDATE students SET id_orangtua=? WHERE id_siswa=?")
      ->execute([$parentId, (int)$kid['id_siswa']]);

  $_SESSION['flash_success'] = 'Nomor HP berhasil dihubungkan ke siswa.';
  header('Location: ' . base_url('/admin/parents.php?class=' . urlencode($class)));
  exit;
}

if (isset($_GET['unlink'])) {
  $idSiswa = (int)$_GET['unlink'];
  $st = $pdo->prepare("SELECT id_orangtua FROM students WHERE id_siswa=? LIMIT 1");
  $st->execute([$idSiswa]);
  $row = $st->fetch();
  $parentId = $row ? (int)$row['id_orangtua'] : 0;

  $pdo->prepare("UPDATE students SET id_orangtua=NULL WHERE id_siswa=?")->execute([$idSiswa]);

  if ($parentId > 0) {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE id_orangtua=?");
    $cnt->execute([$parentId]);
    if ((int)$cnt->fetchColumn() === 0) {
      $pdo->prepare("DELETE FROM parents WHERE id_orangtua=?")->execute([$parentId]);
    }
  }

  header('Location: ' . base_url('/admin/parents.php?class=' . urlencode($class)));
  exit;
}

// Edit data
$editRow = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
  $stEdit = $pdo->prepare("
    SELECT s.id_siswa, s.nama_siswa, s.class_level, p.id_orangtua, p.no_hp
    FROM students s
    JOIN parents p ON p.id_orangtua = s.id_orangtua
    WHERE s.id_siswa = ?
    LIMIT 1
  ");
  $stEdit->execute([$editId]);
  $editRow = $stEdit->fetch();
  if (!$editRow) {
    $_SESSION['flash_error'] = 'Data nomor orang tua tidak ditemukan.';
    header('Location: ' . base_url('/admin/parents.php?class=' . urlencode($class)));
    exit;
  }
}

$params = [$class];
$whereSearch = '';
if ($search !== '') {
  $whereSearch = " AND (s.nama_siswa LIKE ? OR p.no_hp LIKE ?)";
  $like = '%' . $search . '%';
  $params[] = $like;
  $params[] = $like;
}
$rowsStmt = $pdo->prepare("
  SELECT s.id_siswa, s.nis, s.nama_siswa, s.class_level, p.id_orangtua, p.no_hp
  FROM students s
  JOIN parents p ON p.id_orangtua = s.id_orangtua
  WHERE s.class_level = ?
  $whereSearch
  ORDER BY s.class_level, s.nama_siswa
");
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === '1') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="kontak_orangtua.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Kelas','NIS','Nama Siswa','No HP'], ';');
  foreach ($rows as $r) {
    fputcsv($out, [$r['class_level'],$r['nis'],$r['nama_siswa'],$r['no_hp']], ';');
  }
  fclose($out);
  exit;
}

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
          <div class="d-flex flex-wrap gap-2 mb-3">
            <a class="btn btn-sm <?= $class==='7'?'btn-brand text-white':'btn-outline-primary' ?>" href="<?= base_url('/admin/parents.php?class=7') ?>">Kelas 7</a>
            <a class="btn btn-sm <?= $class==='8'?'btn-brand text-white':'btn-outline-primary' ?>" href="<?= base_url('/admin/parents.php?class=8') ?>">Kelas 8</a>
            <a class="btn btn-sm <?= $class==='9'?'btn-brand text-white':'btn-outline-primary' ?>" href="<?= base_url('/admin/parents.php?class=9') ?>">Kelas 9</a>
          </div>
          <div class="fw-semibold mb-2">Tambah Nomor Orang Tua</div>
          <form method="post" class="row g-2">
            <input type="hidden" name="action" value="create">
            <div class="col-12 col-md-2">
              <label class="form-label">Kelas</label>
              <select name="class_level" class="form-select" onchange="window.location='<?= base_url('/admin/parents.php?class=') ?>' + encodeURIComponent(this.value)">
                <option value="7" <?= $class==='7'?'selected':'' ?>>7</option>
                <option value="8" <?= $class==='8'?'selected':'' ?>>8</option>
                <option value="9" <?= $class==='9'?'selected':'' ?>>9</option>
              </select>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Nama Siswa (Dropdown)</label>
              <select name="id_siswa" class="form-select">
                <option value="">Pilih dari data siswa</option>
                <?php foreach($studentRows as $s): ?>
                  <option value="<?= (int)$s['id_siswa'] ?>"><?= htmlspecialchars($s['nama_siswa']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Nama Siswa (Ketik)</label>
              <input name="nama_siswa" class="form-control" placeholder="ketik nama lengkap jika tidak ada di dropdown">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">No HP Orang Tua</label>
              <input name="no_hp" class="form-control" placeholder="contoh: 08123456789" required>
            </div>
            <div class="col-12">
              <button class="btn btn-brand text-white">Simpan</button>
            </div>
          </form>
          <div class="small-muted mt-2">Login orang tua menggunakan Nama Anak + No HP.</div>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
            <div class="fw-semibold">Daftar Nomor Orang Tua</div>
            <form method="get" class="d-flex gap-2 align-items-center">
              <input type="hidden" name="class" value="<?= htmlspecialchars($class) ?>">
              <input name="q" class="form-control form-control-sm" placeholder="Cari nama atau no HP" value="<?= htmlspecialchars($search) ?>">
              <button class="btn btn-sm btn-outline-primary">Cari</button>
              <a class="btn btn-sm btn-outline-success" href="<?= base_url('/admin/parents.php?class=' . urlencode($class) . '&q=' . urlencode($search) . '&export=1') ?>">Download Excel</a>
            </form>
          </div>
          <?php if($editRow): ?>
            <div class="border rounded p-3 mb-3">
              <div class="fw-semibold mb-1">Edit Nomor Orang Tua</div>
              <div class="small-muted mb-2">
                <?= htmlspecialchars("Kelas ".$editRow['class_level']." | ".$editRow['nama_siswa']) ?>
              </div>
              <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="update-phone">
                <input type="hidden" name="id_siswa" value="<?= (int)$editRow['id_siswa'] ?>">
                <input type="hidden" name="class_level" value="<?= htmlspecialchars($class) ?>">
                <div class="col-12 col-md-4">
                  <label class="form-label">No HP Orang Tua</label>
                  <input name="no_hp" class="form-control" value="<?= htmlspecialchars($editRow['no_hp'] ?? '') ?>" required>
                </div>
                <div class="col-6 col-md-2 d-grid">
                  <button class="btn btn-brand text-white">Simpan</button>
                </div>
                <div class="col-6 col-md-2 d-grid">
                  <a class="btn btn-outline-secondary" href="<?= base_url('/admin/parents.php?class=' . urlencode($class)) ?>">Batal</a>
                </div>
              </form>
            </div>
          <?php endif; ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>No</th><th>Kelas</th><th>NIS</th><th>Nama Siswa</th><th>No HP</th><th>Aksi</th></tr></thead>
              <tbody>
                <?php $no = 1; foreach($rows as $r): ?>
                  <tr>
                    <td><?= $no++ ?></td>
                    <td><?= htmlspecialchars($r['class_level']) ?></td>
                    <td><?= htmlspecialchars($r['nis'] ?? '-') ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($r['nama_siswa']) ?></td>
                    <td><?= htmlspecialchars($r['no_hp'] ?? '-') ?></td>
                    <td>
                      <div class="d-flex flex-wrap gap-1">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('/admin/parents.php?class=' . urlencode($class) . '&edit='.(int)$r['id_siswa']) ?>">Edit</a>
                        <a class="btn btn-sm btn-outline-danger" href="<?= base_url('/admin/parents.php?class=' . urlencode($class) . '&unlink='.(int)$r['id_siswa']) ?>" onclick="return confirm('Hapus hubungan nomor ini?')">Hapus</a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if(!$rows): ?>
                  <tr><td colspan="6" class="small-muted">Belum ada data.</td></tr>
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
