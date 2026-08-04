<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/student_promotion.php';

require_role('admin');
$active = 'students';
$title = 'Data Siswa dan Kartu';
$subtitle = 'Tambah, ubah, hapus, dan bulk upload data siswa';

$pdo = db();
$u = current_user();
$classFilter = $_GET['class'] ?? '7';
if (!in_array($classFilter, ['7', '8', '9', 'all'], true)) $classFilter = '7';
$search = trim($_GET['q'] ?? '');
$previewSessionKey = 'student_bulk_import_preview';
$previewFileNameKey = 'student_bulk_import_file_name';
$defaultAcademicYear = student_default_academic_year();
$supportsStatus = student_schema_has_column($pdo, 'students', 'status_siswa');

function students_redirect(string $classFilter, string $search = '', string $fragment = ''): void
{
  $params = ['class' => $classFilter];
  if ($search !== '') {
    $params['q'] = $search;
  }
  $url = base_url('/admin/students.php?' . http_build_query($params));
  if ($fragment !== '') {
    $url .= '#' . $fragment;
  }
  header('Location: ' . $url);
  exit;
}

if (isset($_GET['download']) && $_GET['download'] === 'template') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="template_kenaikan_kelas.csv"');
  $out = fopen('php://output', 'w');
  fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
  fputcsv($out, ['NIS', 'Nama Siswa', 'Kelas Baru', 'UID RFID', 'No HP Ortu', 'Status Siswa', 'Catatan'], ';');
  fputcsv($out, ['0134607541', 'Dania Hafisya Raudhah', '8', '6AFA5537', '081234567890', 'aktif', 'Naik kelas ke 8'], ';');
  fputcsv($out, ['0117179384', 'Salman Al-Farisi', '9', '3A9D5937', '081234567891', 'lulus', 'Siswa lulus tahun ajaran ini'], ';');
  fclose($out);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_preview') {
  unset($_SESSION[$previewSessionKey], $_SESSION[$previewFileNameKey]);
  students_redirect($classFilter, $search, 'bulk-import');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview_import') {
  $academicYear = trim((string)($_POST['academic_year'] ?? ''));
  if ($academicYear === '') {
    $academicYear = $defaultAcademicYear;
  }

  $file = $_FILES['import_file'] ?? null;
  if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $_SESSION['flash_error'] = 'File impor wajib dipilih.';
    students_redirect($classFilter, $search, 'bulk-import');
  }

  try {
    $rowsImport = student_parse_spreadsheet_rows((string)$file['tmp_name'], (string)$file['name']);
    if ($rowsImport === []) {
      throw new RuntimeException('File tidak berisi data siswa.');
    }
    $_SESSION[$previewSessionKey] = student_build_import_preview($pdo, $rowsImport, $academicYear);
    $_SESSION[$previewFileNameKey] = (string)$file['name'];
    $_SESSION['flash_success'] = 'Preview impor berhasil dibuat. Periksa hasilnya sebelum simpan.';
  } catch (Throwable $e) {
    $_SESSION['flash_error'] = $e->getMessage();
  }

  students_redirect($classFilter, $search, 'bulk-import');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'commit_import') {
  $preview = $_SESSION[$previewSessionKey] ?? null;
  $fileName = (string)($_SESSION[$previewFileNameKey] ?? 'bulk_import.xlsx');
  if (!is_array($preview) || empty($preview['rows'])) {
    $_SESSION['flash_error'] = 'Preview impor tidak ditemukan. Upload ulang file terlebih dahulu.';
    students_redirect($classFilter, $search, 'bulk-import');
  }

  try {
    $result = student_apply_import_preview($pdo, $preview, $u ?? [], $fileName);
    unset($_SESSION[$previewSessionKey], $_SESSION[$previewFileNameKey]);
    $_SESSION['flash_success'] = sprintf(
      'Bulk upload selesai. Diproses %d data, dilewati %d data, gagal %d data.',
      (int)$result['processed'],
      (int)$result['skipped'],
      (int)$result['failed']
    );
  } catch (Throwable $e) {
    $_SESSION['flash_error'] = 'Bulk upload gagal: ' . $e->getMessage();
  }

  students_redirect($classFilter, $search, 'bulk-import');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
  $nis = trim($_POST['nis'] ?? '');
  $nama = trim($_POST['nama_siswa'] ?? '');
  $class = $_POST['class_level'] ?? '7';
  $rfid = strtoupper(trim($_POST['rfid_uid'] ?? ''));

  if ($rfid !== '') {
    $cek = $pdo->prepare("SELECT id_siswa FROM students WHERE rfid_uid=? LIMIT 1");
    $cek->execute([$rfid]);
    if ($cek->fetch()) {
      $_SESSION['flash_error'] = "UID $rfid sudah dipakai siswa lain.";
      students_redirect($classFilter, $search);
    }
  }

  try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO students (nis, nama_siswa, class_level, rfid_uid) VALUES (?,?,?,?)");
    $stmt->execute([$nis, $nama, $class, $rfid ?: null]);
    $idSiswa = (int)$pdo->lastInsertId();

    if ($rfid !== '') {
      $cekCard = $pdo->prepare("SELECT id_kartu FROM rfid_cards WHERE rfid_uid=? LIMIT 1");
      $cekCard->execute([$rfid]);
      if ($cekCard->fetch()) {
        $pdo->prepare("UPDATE rfid_cards SET status_kartu='Aktif', assigned_student_id=? WHERE rfid_uid=?")
          ->execute([$idSiswa, $rfid]);
      } else {
        $pdo->prepare("INSERT INTO rfid_cards (rfid_uid, status_kartu, assigned_student_id) VALUES (?, 'Aktif', ?)")
          ->execute([$rfid, $idSiswa]);
      }
    }

    $pdo->commit();
    $_SESSION['flash_success'] = 'Data siswa berhasil ditambahkan.';
  } catch (Throwable $e) {
    $pdo->rollBack();
    $_SESSION['flash_error'] = 'Gagal simpan siswa. Coba lagi.';
  }

  $redirectClass = $classFilter !== 'all' ? $classFilter : '7';
  students_redirect($redirectClass, $search);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update-student') {
  $id = (int)($_POST['id_siswa'] ?? 0);
  $nis = trim($_POST['nis'] ?? '');
  $nama = trim($_POST['nama_siswa'] ?? '');
  $class = $_POST['class_level'] ?? '';
  $redirectClass = $_POST['redirect_class'] ?? $classFilter;
  if (!in_array($redirectClass, ['7', '8', '9', 'all'], true)) $redirectClass = '7';

  if ($id <= 0 || $nama === '' || $class === '' || $nis === '') {
    $_SESSION['flash_error'] = 'NIS, nama, dan kelas wajib diisi.';
  } else {
    $pdo->prepare("UPDATE students SET nis=?, nama_siswa=?, class_level=? WHERE id_siswa=?")
      ->execute([$nis, $nama, $class, $id]);
    $_SESSION['flash_success'] = 'Data siswa berhasil diperbarui.';
  }

  $redirectClass = $redirectClass !== 'all' ? $redirectClass : '7';
  students_redirect($redirectClass, $search);
}

if (isset($_GET['delete'])) {
  $id = (int)$_GET['delete'];
  $st = $pdo->prepare("SELECT rfid_uid FROM students WHERE id_siswa=?");
  $st->execute([$id]);
  $row = $st->fetch();

  try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM students WHERE id_siswa=?")->execute([$id]);
    if ($row && !empty($row['rfid_uid'])) {
      $pdo->prepare("DELETE FROM rfid_cards WHERE rfid_uid=? OR assigned_student_id=?")
        ->execute([$row['rfid_uid'], $id]);
    }
    $pdo->commit();
    $_SESSION['flash_success'] = 'Data siswa berhasil dihapus.';
  } catch (Throwable $e) {
    $pdo->rollBack();
    $_SESSION['flash_error'] = 'Gagal menghapus siswa. Coba lagi.';
  }

  $redirectClass = $classFilter !== 'all' ? $classFilter : '7';
  students_redirect($redirectClass, $search);
}

$editStudent = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
  $fields = "id_siswa, nama_siswa, nis, class_level";
  if ($supportsStatus) {
    $fields .= ", status_siswa";
  }
  $stEdit = $pdo->prepare("SELECT $fields FROM students WHERE id_siswa=? LIMIT 1");
  $stEdit->execute([$editId]);
  $editStudent = $stEdit->fetch();
  if (!$editStudent) {
    $_SESSION['flash_error'] = 'Data siswa tidak ditemukan.';
    $redirectClass = $classFilter !== 'all' ? $classFilter : '7';
    students_redirect($redirectClass, $search);
  }
}

$params = [];
$whereClass = '';
if (in_array($classFilter, ['7', '8', '9'], true)) {
  $whereClass = "WHERE s.class_level = ?";
  $params[] = $classFilter;
}
$whereSearch = '';
if ($search !== '') {
  $whereSearch = $whereClass ? " AND (s.nama_siswa LIKE ? OR s.nis LIKE ?)" : "WHERE (s.nama_siswa LIKE ? OR s.nis LIKE ?)";
  $like = '%' . $search . '%';
  $params[] = $like;
  $params[] = $like;
}

$selectFields = "s.id_siswa, s.nis, s.nama_siswa, s.class_level, s.rfid_uid";
if ($supportsStatus) {
  $selectFields .= ", COALESCE(NULLIF(s.status_siswa, ''), 'aktif') AS status_siswa";
}

$stmt = $pdo->prepare("
  SELECT $selectFields
  FROM students s
  $whereClass
  $whereSearch
  ORDER BY s.class_level, s.nama_siswa
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === '1') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="data_siswa.csv"');
  $out = fopen('php://output', 'w');
  fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
  $header = ['NIS', 'Nama', 'Kelas', 'UID RFID'];
  if ($supportsStatus) {
    $header[] = 'Status';
  }
  fputcsv($out, $header, ';');
  foreach ($rows as $r) {
    $line = [$r['nis'], $r['nama_siswa'], $r['class_level'], $r['rfid_uid'] ?? ''];
    if ($supportsStatus) {
      $line[] = $r['status_siswa'] ?? 'aktif';
    }
    fputcsv($out, $line, ';');
  }
  fclose($out);
  exit;
}

$preview = $_SESSION[$previewSessionKey] ?? null;
$previewFileName = (string)($_SESSION[$previewFileNameKey] ?? '');
$schemaWarnings = [];
if (!$supportsStatus) {
  $schemaWarnings[] = 'Kolom students.status_siswa belum ada.';
}
if (!student_schema_has_column($pdo, 'attendance', 'class_level_snapshot')) {
  $schemaWarnings[] = 'Kolom attendance.class_level_snapshot belum ada.';
}
if (!student_schema_has_table($pdo, 'student_class_history')) {
  $schemaWarnings[] = 'Tabel student_class_history belum ada.';
}
if (!student_schema_has_table($pdo, 'student_bulk_import_logs')) {
  $schemaWarnings[] = 'Tabel student_bulk_import_logs belum ada.';
}

$logs = [];
if (student_schema_has_table($pdo, 'student_bulk_import_logs')) {
  $logs = $pdo->query("
    SELECT file_name, academic_year, total_rows, processed_rows, skipped_rows, failed_rows, imported_by_name, created_at
    FROM student_bulk_import_logs
    ORDER BY created_at DESC
    LIMIT 10
  ")->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../partials/head.php';
?>
<div class="container-fluid">
  <div class="row">
    <?php include __DIR__ . '/../partials/sidebar.php'; ?>
    <div class="col-12 col-lg-9 col-xl-10 p-4">
      <?php include __DIR__ . '/../partials/topbar.php'; ?>

      <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
        <?php unset($_SESSION['flash_error']); ?>
      <?php endif; ?>
      <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
        <?php unset($_SESSION['flash_success']); ?>
      <?php endif; ?>

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2 mb-3">
            <a class="btn btn-sm <?= $classFilter === '7' ? 'btn-brand text-white' : 'btn-outline-primary' ?>" href="<?= base_url('/admin/students.php?class=7') ?>">Kelas 7</a>
            <a class="btn btn-sm <?= $classFilter === '8' ? 'btn-brand text-white' : 'btn-outline-primary' ?>" href="<?= base_url('/admin/students.php?class=8') ?>">Kelas 8</a>
            <a class="btn btn-sm <?= $classFilter === '9' ? 'btn-brand text-white' : 'btn-outline-primary' ?>" href="<?= base_url('/admin/students.php?class=9') ?>">Kelas 9</a>
            <a class="btn btn-sm <?= $classFilter === 'all' ? 'btn-brand text-white' : 'btn-outline-primary' ?>" href="<?= base_url('/admin/students.php?class=all') ?>">Semua</a>
          </div>
          <div class="fw-semibold mb-2">Tambah Siswa</div>
          <form method="post" class="row g-2">
            <input type="hidden" name="action" value="create">
            <div class="col-12 col-md-2">
              <label class="form-label">NIS</label>
              <input name="nis" class="form-control" required>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Nama</label>
              <input name="nama_siswa" class="form-control" required>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Kelas</label>
              <select name="class_level" class="form-select">
                <option value="7">7</option>
                <option value="8">8</option>
                <option value="9">9</option>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">UID RFID</label>
              <input name="rfid_uid" class="form-control" placeholder="contoh: A1B2C3D4">
            </div>
            <div class="col-12">
              <button class="btn btn-brand text-white">Simpan</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card shadow-sm mb-3" id="bulk-import">
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
            <div>
              <div class="fw-semibold">Bulk Upload Kenaikan Kelas</div>
              <div class="small-muted">Perbarui kelas siswa secara massal melalui file Excel</div>
            </div>
            <a class="btn btn-outline-success" href="<?= base_url('/admin/students.php?class=' . urlencode($classFilter) . '&q=' . urlencode($search) . '&download=template') ?>">Download Template Excel</a>
          </div>

          <?php if ($schemaWarnings): ?>
            <div class="alert alert-warning">
              Jalankan migrasi database lebih dulu: <code>database/20260716_student_promotion_and_bulk_import.sql</code>.
              <?= htmlspecialchars(implode(' ', $schemaWarnings)) ?>
            </div>
          <?php endif; ?>

          <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="preview_import">
            <div class="col-12 col-md-3">
              <label class="form-label">Tahun Ajaran</label>
              <input name="academic_year" class="form-control" value="<?= htmlspecialchars((string)($preview['academic_year'] ?? $defaultAcademicYear)) ?>" placeholder="2026/2027">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">File Excel / CSV</label>
              <input type="file" name="import_file" class="form-control" accept=".xlsx,.csv" required>
            </div>
            <div class="col-12 col-md-3 d-grid">
              <button class="btn btn-brand text-white">Preview Impor</button>
            </div>
          </form>

          <div class="small-muted mt-3">
            Kolom yang dikenali: <code>NIS</code>, <code>Nama Siswa</code>, <code>Kelas Baru</code>, <code>UID RFID</code>, <code>No HP Ortu</code>, <code>Status Siswa</code>, <code>Catatan</code>.
          </div>
        </div>
      </div>

      <?php if (is_array($preview) && !empty($preview['rows'])): ?>
        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
              <div>
                <div class="fw-semibold">Preview Hasil Bulk Upload</div>
                <div class="small-muted">File: <b><?= htmlspecialchars($previewFileName) ?></b> | Tahun ajaran: <b><?= htmlspecialchars((string)$preview['academic_year']) ?></b></div>
              </div>
              <div class="d-flex gap-2">
                <form method="post">
                  <input type="hidden" name="action" value="clear_preview">
                  <button class="btn btn-outline-secondary">Hapus Preview</button>
                </form>
                <form method="post">
                  <input type="hidden" name="action" value="commit_import">
                  <button class="btn btn-brand text-white" <?= (($preview['summary']['ready'] ?? 0) < 1) ? 'disabled' : '' ?>>Simpan Perubahan</button>
                </form>
              </div>
            </div>

            <div class="row g-2 mb-3">
              <div class="col-6 col-md-3"><div class="border rounded p-2">Total Baris: <b><?= (int)($preview['summary']['total'] ?? 0) ?></b></div></div>
              <div class="col-6 col-md-3"><div class="border rounded p-2">Siap Diproses: <b><?= (int)($preview['summary']['ready'] ?? 0) ?></b></div></div>
              <div class="col-6 col-md-3"><div class="border rounded p-2">Tidak Berubah: <b><?= (int)($preview['summary']['noop'] ?? 0) ?></b></div></div>
              <div class="col-6 col-md-3"><div class="border rounded p-2">Error: <b><?= (int)($preview['summary']['error'] ?? 0) ?></b></div></div>
            </div>

            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>Baris</th>
                    <th>NIS</th>
                    <th>Nama</th>
                    <th>Kelas</th>
                    <th>Status</th>
                    <th>Aksi</th>
                    <th>Validasi</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($preview['rows'] as $row): ?>
                    <?php $badge = ($row['status'] ?? '') === 'ready' ? 'bg-success' : (($row['status'] ?? '') === 'noop' ? 'bg-secondary' : 'bg-danger'); ?>
                    <tr>
                      <td><?= (int)($row['row_number'] ?? 0) ?></td>
                      <td><?= htmlspecialchars((string)($row['nis'] ?? '')) ?></td>
                      <td><?= htmlspecialchars((string)($row['target_name'] ?? $row['student_name'] ?? '-')) ?></td>
                      <td><?= htmlspecialchars((string)($row['current_class'] ?? '-')) ?> -> <?= htmlspecialchars((string)($row['target_class'] ?? '-')) ?></td>
                      <td><?= htmlspecialchars((string)($row['current_status'] ?? 'aktif')) ?> -> <?= htmlspecialchars((string)($row['target_status'] ?? 'aktif')) ?></td>
                      <td><?= htmlspecialchars((string)($row['action_summary'] ?? '-')) ?></td>
                      <td>
                        <span class="badge <?= $badge ?>"><?= htmlspecialchars((string)($row['status'] ?? 'error')) ?></span>
                        <?php if (!empty($row['messages'])): ?>
                          <div class="small-muted mt-1"><?= htmlspecialchars(implode(' | ', (array)$row['messages'])) ?></div>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
            <div class="fw-semibold">Daftar Siswa</div>
            <form class="d-flex gap-2 align-items-center" method="get">
              <input type="hidden" name="class" value="<?= htmlspecialchars($classFilter) ?>">
              <input name="q" class="form-control form-control-sm" placeholder="Cari nama atau NIS" value="<?= htmlspecialchars($search) ?>">
              <button class="btn btn-sm btn-outline-primary">Cari</button>
              <a class="btn btn-sm btn-outline-success" href="<?= base_url('/admin/students.php?class=' . urlencode($classFilter) . '&q=' . urlencode($search) . '&export=1') ?>">Download Excel</a>
            </form>
          </div>

          <?php if ($editStudent): ?>
            <div class="border rounded p-3 mb-3">
              <div class="fw-semibold mb-1">Edit Data Siswa</div>
              <div class="small-muted mb-2"><?= htmlspecialchars("NIS " . $editStudent['nis'] . " | Kelas " . $editStudent['class_level']) ?></div>
              <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="update-student">
                <input type="hidden" name="id_siswa" value="<?= (int)$editStudent['id_siswa'] ?>">
                <input type="hidden" name="redirect_class" value="<?= htmlspecialchars($classFilter) ?>">
                <div class="col-12 col-md-3">
                  <label class="form-label">NIS</label>
                  <input name="nis" class="form-control" value="<?= htmlspecialchars($editStudent['nis']) ?>" required>
                </div>
                <div class="col-12 col-md-5">
                  <label class="form-label">Nama Siswa</label>
                  <input name="nama_siswa" class="form-control" value="<?= htmlspecialchars($editStudent['nama_siswa']) ?>" required>
                </div>
                <div class="col-6 col-md-2">
                  <label class="form-label">Kelas</label>
                  <select name="class_level" class="form-select">
                    <option value="7" <?= $editStudent['class_level'] === '7' ? 'selected' : '' ?>>7</option>
                    <option value="8" <?= $editStudent['class_level'] === '8' ? 'selected' : '' ?>>8</option>
                    <option value="9" <?= $editStudent['class_level'] === '9' ? 'selected' : '' ?>>9</option>
                  </select>
                </div>
                <div class="col-6 col-md-2 d-grid">
                  <button class="btn btn-brand text-white">Simpan</button>
                </div>
                <div class="col-6 col-md-2 d-grid">
                  <a class="btn btn-outline-secondary" href="<?= base_url('/admin/students.php?class=' . urlencode($classFilter)) ?>">Batal</a>
                </div>
              </form>
            </div>
          <?php endif; ?>

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>No</th>
                  <th>NIS</th>
                  <th>Nama</th>
                  <th>Kelas</th>
                  <th>UID</th>
                  <?php if ($supportsStatus): ?><th>Status</th><?php endif; ?>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php $no = 1; foreach ($rows as $r): ?>
                  <tr>
                    <td><?= $no++ ?></td>
                    <td><?= htmlspecialchars($r['nis']) ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($r['nama_siswa']) ?></td>
                    <td><?= htmlspecialchars($r['class_level']) ?></td>
                    <td><code><?= htmlspecialchars($r['rfid_uid'] ?? '-') ?></code></td>
                    <?php if ($supportsStatus): ?><td><?= htmlspecialchars($r['status_siswa'] ?? 'aktif') ?></td><?php endif; ?>
                    <td>
                      <div class="d-flex flex-wrap gap-1">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('/admin/students.php?class=' . urlencode($classFilter) . '&q=' . urlencode($search) . '&edit=' . (int)$r['id_siswa']) ?>">Edit Data</a>
                        <a class="btn btn-sm btn-outline-danger" href="<?= base_url('/admin/students.php?class=' . urlencode($classFilter) . '&q=' . urlencode($search) . '&delete=' . (int)$r['id_siswa']) ?>" onclick="return confirm('Hapus siswa ini?')">Hapus</a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                  <tr><td colspan="<?= $supportsStatus ? '7' : '6' ?>" class="small-muted">Belum ada data.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <?php if ($logs): ?>
        <div class="card shadow-sm">
          <div class="card-body">
            <div class="fw-semibold mb-2">Riwayat Bulk Upload Terakhir</div>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>Waktu</th>
                    <th>File</th>
                    <th>Tahun Ajaran</th>
                    <th>Diproses</th>
                    <th>Dilewati</th>
                    <th>Gagal</th>
                    <th>Oleh</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($logs as $log): ?>
                    <tr>
                      <td><?= htmlspecialchars((string)$log['created_at']) ?></td>
                      <td><?= htmlspecialchars((string)$log['file_name']) ?></td>
                      <td><?= htmlspecialchars((string)$log['academic_year']) ?></td>
                      <td><?= (int)$log['processed_rows'] ?>/<?= (int)$log['total_rows'] ?></td>
                      <td><?= (int)$log['skipped_rows'] ?></td>
                      <td><?= (int)$log['failed_rows'] ?></td>
                      <td><?= htmlspecialchars((string)($log['imported_by_name'] ?? '-')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/foot.php'; ?>
