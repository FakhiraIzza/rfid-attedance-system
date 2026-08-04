<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';

require_role('admin');
$active='cards';
$title='Manajemen Kartu RFID';
$subtitle='Penggantian kartu hilang (ganti UID per siswa)';

$pdo=db();

function redirect_back() {
  header('Location: ' . base_url('/admin/cards.php'));
  exit;
}

// ====== Ganti UID siswa (kartu hilang/diganti) ======
if (isset($_POST['action']) && $_POST['action']==='replace_uid') {
  $idSiswa = (int)($_POST['id_siswa'] ?? 0);
  $newUid = strtoupper(trim($_POST['new_uid'] ?? ''));

  if ($idSiswa <= 0 || $newUid === '') {
    $_SESSION['flash_error'] = "Pilih siswa dan isi UID baru.";
    redirect_back();
  }

  $cekDipakai = $pdo->prepare("SELECT id_siswa,nama_siswa FROM students WHERE rfid_uid=? AND id_siswa<>?");
  $cekDipakai->execute([$newUid,$idSiswa]);
  $used = $cekDipakai->fetch();
  if ($used) {
    $_SESSION['flash_error'] = "UID $newUid sudah dipakai oleh ".$used['nama_siswa'];
    redirect_back();
  }

  $st = $pdo->prepare("SELECT rfid_uid FROM students WHERE id_siswa=?");
  $st->execute([$idSiswa]);
  $row = $st->fetch();
  $oldUid = $row['rfid_uid'] ?? '';

  try {
    $pdo->beginTransaction();

    if ($oldUid !== '' && $oldUid !== $newUid) {
      $pdo->prepare("DELETE FROM rfid_cards WHERE rfid_uid=? OR assigned_student_id=?")
          ->execute([$oldUid, $idSiswa]);
    }

    $pdo->prepare("UPDATE students SET rfid_uid=? WHERE id_siswa=?")->execute([$newUid, $idSiswa]);

    $cekCard = $pdo->prepare("SELECT id_kartu FROM rfid_cards WHERE rfid_uid=? LIMIT 1");
    $cekCard->execute([$newUid]);
    if ($cekCard->fetch()) {
      $pdo->prepare("UPDATE rfid_cards SET status_kartu='Aktif', assigned_student_id=? WHERE rfid_uid=?")
          ->execute([$idSiswa, $newUid]);
    } else {
      $pdo->prepare("INSERT INTO rfid_cards (rfid_uid,status_kartu,assigned_student_id) VALUES (?, 'Aktif', ?)")
          ->execute([$newUid, $idSiswa]);
    }

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    $_SESSION['flash_error'] = "Gagal ganti UID. Coba lagi.";
  }

  redirect_back();
}

// ====== Sinkron kartu dari data siswa ======
if (isset($_POST['action']) && $_POST['action']==='sync_cards') {
  try {
    $pdo->beginTransaction();
    $insertStmt = $pdo->prepare("
      INSERT INTO rfid_cards (rfid_uid, status_kartu, assigned_student_id)
      SELECT s.rfid_uid, 'Aktif', s.id_siswa
      FROM students s
      LEFT JOIN rfid_cards c ON c.rfid_uid = s.rfid_uid
      WHERE s.rfid_uid IS NOT NULL
        AND s.rfid_uid <> ''
        AND c.id_kartu IS NULL
    ");
    $insertStmt->execute();
    $updateStmt = $pdo->prepare("
      UPDATE rfid_cards c
      JOIN students s ON s.rfid_uid = c.rfid_uid
      SET c.assigned_student_id = s.id_siswa, c.status_kartu = 'Aktif'
      WHERE c.assigned_student_id IS NULL
    ");
    $updateStmt->execute();
    $pdo->commit();
    $added = $insertStmt->rowCount();
    $updated = $updateStmt->rowCount();
    $_SESSION['flash_success'] = "Sinkron selesai. Ditambahkan $added kartu, diperbarui $updated kartu.";
  } catch (Throwable $e) {
    $pdo->rollBack();
    $_SESSION['flash_error'] = "Gagal sinkron kartu. Coba lagi.";
  }

  redirect_back();
}

// ====== Hapus UID siswa (kartu hilang) ======
if (isset($_POST['action']) && $_POST['action']==='remove_uid') {
  $idSiswa = (int)($_POST['id_siswa'] ?? 0);
  if ($idSiswa <= 0) {
    $_SESSION['flash_error'] = "Pilih siswa.";
    redirect_back();
  }

  $st = $pdo->prepare("SELECT rfid_uid FROM students WHERE id_siswa=?");
  $st->execute([$idSiswa]);
  $row = $st->fetch();
  $oldUid = $row['rfid_uid'] ?? '';

  try {
    $pdo->beginTransaction();
    if ($oldUid !== '') {
      $pdo->prepare("DELETE FROM rfid_cards WHERE rfid_uid=? OR assigned_student_id=?")
          ->execute([$oldUid, $idSiswa]);
    }
    $pdo->prepare("UPDATE students SET rfid_uid=NULL WHERE id_siswa=?")->execute([$idSiswa]);
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    $_SESSION['flash_error'] = "Gagal menghapus UID. Coba lagi.";
  }

  redirect_back();
}

// ====== Data untuk tampilan ======
$search = trim($_GET['q'] ?? '');
$params = [];
$whereSearch = '';
if ($search !== '') {
  $whereSearch = "WHERE (s.nama_siswa LIKE ? OR s.nis LIKE ? OR c.rfid_uid LIKE ? OR s.class_level LIKE ?)";
  $like = '%' . $search . '%';
  $params = [$like, $like, $like, $like];
}

$stmtRows = $pdo->prepare("
  SELECT c.*,
         s.id_siswa, s.nama_siswa, s.nis, s.class_level
  FROM rfid_cards c
  LEFT JOIN students s
    ON s.id_siswa = c.assigned_student_id
    OR (c.assigned_student_id IS NULL AND s.rfid_uid = c.rfid_uid)
  $whereSearch
  ORDER BY (s.class_level IS NULL) ASC, s.class_level ASC, s.nama_siswa ASC, c.tanggal_daftar DESC
");
$stmtRows->execute($params);
$rows = $stmtRows->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === '1') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="kartu_rfid.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['UID','Status','Terdaftar','NIS','Nama','Kelas'], ';');
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['rfid_uid'],
      $r['status_kartu'],
      $r['tanggal_daftar'],
      $r['nis'] ?? '',
      $r['nama_siswa'] ?? '',
      $r['class_level'] ?? ''
    ], ';');
  }
  fclose($out);
  exit;
}

$students = $pdo->query("SELECT id_siswa, nis, nama_siswa, class_level, rfid_uid FROM students ORDER BY class_level, nama_siswa")->fetchAll();

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

      <div class="row g-3">
        <div class="col-12 col-xl-6">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <div class="fw-semibold mb-2">Ganti UID Siswa (kartu baru)</div>
              <form method="post" class="row g-2">
                <input type="hidden" name="action" value="replace_uid">

                <div class="col-12 col-md-6">
                  <label class="form-label">Pilih Siswa</label>
                  <select name="id_siswa" class="form-select" required>
                    <option value="">-- Pilih siswa --</option>
                    <?php foreach($students as $s): ?>
                      <option value="<?= (int)$s['id_siswa'] ?>">
                        <?= htmlspecialchars($s['class_level']." | ".$s['nis']." | ".$s['nama_siswa']) ?>
                        <?= $s['rfid_uid'] ? " (UID: ".$s['rfid_uid'].")" : "" ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-12 col-md-4">
                  <label class="form-label">UID Baru</label>
                  <input name="new_uid" class="form-control" placeholder="contoh: 1D82B6C" required>
                </div>

                <div class="col-12 col-md-2 d-grid">
                  <button class="btn btn-brand text-white" style="margin-top:32px">Ganti</button>
                </div>
              </form>
              <div class="small-muted mt-2">
                UID lama akan dihapus dari inventori, lalu UID baru langsung aktif.
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-xl-6">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <div class="fw-semibold mb-2">Hapus UID Siswa (kartu hilang)</div>
              <form method="post" class="row g-2">
                <input type="hidden" name="action" value="remove_uid">

                <div class="col-12 col-md-8">
                  <label class="form-label">Pilih Siswa</label>
                  <select name="id_siswa" class="form-select" required>
                    <option value="">-- Pilih siswa --</option>
                    <?php foreach($students as $s): ?>
                      <option value="<?= (int)$s['id_siswa'] ?>">
                        <?= htmlspecialchars($s['class_level']." | ".$s['nis']." | ".$s['nama_siswa']) ?>
                        <?= $s['rfid_uid'] ? " (UID: ".$s['rfid_uid'].")" : "" ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-12 col-md-4 d-grid">
                  <button class="btn btn-outline-danger" style="margin-top:32px">Hapus UID</button>
                </div>
              </form>
              <div class="small-muted mt-2">
                Menghapus UID dari siswa dan dari inventori kartu.
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- daftar kartu -->
      <div class="card shadow-sm mt-3">
        <div class="card-body">
          <div class="d-flex flex-wrap align-items-center justify-content-between mb-2 gap-2">
            <div class="fw-semibold">Daftar Kartu</div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <form method="get" class="d-flex gap-2 align-items-center">
                <input name="q" class="form-control form-control-sm" placeholder="Cari nama, NIS, UID" value="<?= htmlspecialchars($search) ?>">
                <button class="btn btn-sm btn-outline-primary">Cari</button>
                <a class="btn btn-sm btn-outline-success" href="<?= base_url('/admin/cards.php?q=' . urlencode($search) . '&export=1') ?>">Download Excel</a>
              </form>
              <form method="post" class="m-0">
                <input type="hidden" name="action" value="sync_cards">
                <button class="btn btn-sm btn-outline-primary" onclick="return confirm('Sinkron kartu dari data siswa?')">Sinkron Kartu</button>
              </form>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>No</th>
                  <th>UID</th>
                  <th>Status</th>
                  <th>Terdaftar</th>
                  <th>Kelas</th>
                  <th>Dipakai Oleh</th>
                  
                </tr>
              </thead>
              <tbody>
              <?php $no = 1; $currentClass = null; foreach($rows as $r): ?>
                <?php
                  $rowClass = $r['class_level'] ?? '';
                  $groupClass = $rowClass !== '' ? $rowClass : 'Tidak Terhubung';
                  if ($groupClass !== $currentClass):
                    $currentClass = $groupClass;
                ?>
                  <tr class="table-light">
                    <td colspan="6" class="fw-semibold">
                      <?= $currentClass === 'Tidak Terhubung' ? 'Tidak Terhubung' : 'Kelas ' . htmlspecialchars($currentClass) ?>
                    </td>
                  </tr>
                <?php endif; ?>
                <tr>
                  <td><?= $no++ ?></td>
                  <td><code><?= htmlspecialchars($r['rfid_uid']) ?></code></td>
                  <td><?= htmlspecialchars($r['status_kartu']) ?></td>
                  <td><?= htmlspecialchars($r['tanggal_daftar']) ?></td>
                  <td><?= htmlspecialchars($r['class_level'] ?? '-') ?></td>
                  <td>
                    <?php if($r['id_siswa']): ?>
                      <div class="fw-semibold"><?= htmlspecialchars($r['nama_siswa']) ?></div>
                      <div class="small-muted"><?= htmlspecialchars("NIS ".$r['nis']) ?></div>
                    <?php else: ?>
                      <span class="small-muted">Tidak terhubung</span>
                    <?php endif; ?>
                  </td>
                  
                </tr>
              <?php endforeach; ?>

              <?php if(!$rows): ?>
                <tr><td colspan="5" class="small-muted">Belum ada data kartu.</td></tr>
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
