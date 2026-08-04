<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/attendance_helpers.php';
require_once __DIR__ . '/../lib/attendance_features.php';
require_once __DIR__ . '/../lib/stats.php';
require_once __DIR__ . '/../lib/student_promotion.php';

require_role('admin');
$active='attendance';
$title='Rekap Presensi';
$subtitle='Filter dan download data presensi berdasarkan kelas dan rentang tanggal';

$pdo=db();
$attendanceClassExpr = student_attendance_class_expr($pdo, 'a', 's');
$activeStudentWhere = student_active_condition($pdo, 's');

function wa_cfg(): array {
  $path = __DIR__ . '/../config/whatsapp.php';
  if (is_file($path)) {
    $cfg = require $path;
    return is_array($cfg) ? $cfg : [];
  }
  return [];
}

function wa_normalize_phone(string $phone): string {
  $digits = preg_replace('/\D+/', '', $phone);
  if ($digits === '') return '';
  if (str_starts_with($digits, '0')) {
    return '62' . substr($digits, 1);
  }
  return $digits;
}

function wa_status_label(string $type, string $status): string {
  if ($type === 'PULANG') return 'Pulang';
  if ($status === 'Hadir Tepat Waktu') return 'Masuk Tepat Waktu';
  if ($status === 'Hadir Terlambat') return 'Masuk Terlambat';
  return $status;
}

function wa_send_fonnte(string $target, string $message, array $cfg): array {
  if (!($cfg['enabled'] ?? false)) {
    return [false, 'disabled'];
  }
  $endpoint = trim((string)($cfg['endpoint'] ?? ''));
  $token = trim((string)($cfg['token'] ?? ''));
  if ($endpoint === '' || $token === '') {
    return [false, 'missing-config'];
  }
  if (!function_exists('curl_init')) {
    return [false, 'curl-missing'];
  }

  $payload = http_build_query([
    'target' => $target,
    'message' => $message,
  ]);

  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: ' . $token,
      'Content-Type: application/x-www-form-urlencoded',
    ],
  ]);
  $resp = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($err) {
    return [false, $err];
  }
  if ($code < 200 || $code >= 300) {
    return [false, 'http-' . $code . ':' . (string)$resp];
  }
  return [true, 'ok'];
}

function logSync(PDO $pdo, string $deviceId, string $status, string $msg): void {
  if ($deviceId === '') $deviceId = '-';
  $log = $pdo->prepare("INSERT INTO sync_logs (device_id, waktu, status_sinkron, pesan_log)
                        VALUES (?, NOW(), ?, ?)");
  $log->execute([$deviceId, $status, $msg]);
}

// Manual add attendance
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='manual_add') {
  $idSiswa = (int)($_POST['id_siswa'] ?? 0);
  $date = trim($_POST['tanggal'] ?? '');
  $time = trim($_POST['waktu'] ?? '');
  $status = trim($_POST['status'] ?? '');
  $catatan = trim($_POST['catatan'] ?? '');
  $redirectTo = trim((string)($_POST['redirect_to'] ?? '/admin/attendance.php'));
  if (!preg_match('#^/admin/[a-z0-9_./-]+\.php$#i', $redirectTo)) {
    $redirectTo = '/admin/attendance.php';
  }
  $evidenceFile = $_FILES['evidence_photo'] ?? null;
  $_SESSION['attendance_manual_form_admin'] = [
    'id_siswa' => $idSiswa,
    'tanggal' => $date,
    'waktu' => preg_match('/^\d{2}:\d{2}/', $time, $matches) ? $matches[0] : $time,
    'status' => $status,
    'catatan' => $catatan,
  ];

  $validStatus = attendance_manual_allowed_statuses();

  if ($idSiswa <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($status, $validStatus, true)) {
    $_SESSION['flash_error'] = 'Data absensi manual tidak valid.';
  } else {
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
      $time = date('H:i:s');
    }
    if (strlen($time) === 5) $time .= ':00';
    $scanAt = $date . ' ' . $time;

    $type = attendance_storage_type_for_status($status);
    $currentUser = current_user();
    $existingAttendanceStmt = $pdo->prepare("SELECT id_absensi FROM attendance WHERE id_siswa=? AND tanggal=? AND type=? LIMIT 1");
    $existingAttendanceStmt->execute([$idSiswa, $date, $type]);
    $existingAttendanceId = (int)$existingAttendanceStmt->fetchColumn();
    $existingEvidence = $existingAttendanceId > 0 ? attendance_get_existing_evidence($pdo, $existingAttendanceId) : null;
    $hasNewEvidence = is_array($evidenceFile) && (($evidenceFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

    if (attendance_status_requires_evidence($status) && !$hasNewEvidence && !$existingEvidence) {
      $_SESSION['flash_error'] = 'Foto evidence wajib diunggah untuk absensi manual hadir tepat waktu atau hadir terlambat.';
      header('Location: ' . base_url($redirectTo));
      exit;
    }

    $studentClassStmt = $pdo->prepare("SELECT class_level FROM students WHERE id_siswa=? LIMIT 1");
    $studentClassStmt->execute([$idSiswa]);
    $studentClass = (string)($studentClassStmt->fetchColumn() ?: '');

    try {
      $pdo->beginTransaction();

      if (student_schema_has_column($pdo, 'attendance', 'class_level_snapshot')) {
        $stmt = $pdo->prepare("
          INSERT INTO attendance (id_siswa, scan_at, tanggal, type, status, class_level_snapshot, source, catatan)
          VALUES (?, ?, ?, ?, ?, ?, 'manual', ?)
          ON DUPLICATE KEY UPDATE scan_at=VALUES(scan_at), type=VALUES(type), status=VALUES(status), class_level_snapshot=VALUES(class_level_snapshot), source='manual', catatan=VALUES(catatan)
        ");
        $stmt->execute([$idSiswa, $scanAt, $date, $type, $status, $studentClass, $catatan !== '' ? $catatan : null]);
      } else {
        $stmt = $pdo->prepare("
          INSERT INTO attendance (id_siswa, scan_at, tanggal, type, status, source, catatan)
          VALUES (?, ?, ?, ?, ?, 'manual', ?)
          ON DUPLICATE KEY UPDATE scan_at=VALUES(scan_at), type=VALUES(type), status=VALUES(status), source='manual', catatan=VALUES(catatan)
        ");
        $stmt->execute([$idSiswa, $scanAt, $date, $type, $status, $catatan !== '' ? $catatan : null]);
      }

      $attendanceIdStmt = $pdo->prepare("SELECT id_absensi FROM attendance WHERE id_siswa=? AND tanggal=? AND type=? LIMIT 1");
      $attendanceIdStmt->execute([$idSiswa, $date, $type]);
      $attendanceId = (int)$attendanceIdStmt->fetchColumn();

      if ($hasNewEvidence) {
        $evidenceResult = attendance_upsert_evidence(
          $pdo,
          $attendanceId,
          $evidenceFile,
          $scanAt,
          (int)($currentUser['id_user'] ?? 0),
          'admin',
          $catatan
        );
        if (!($evidenceResult['ok'] ?? false)) {
          throw new RuntimeException((string)($evidenceResult['message'] ?? 'Evidence gagal disimpan.'));
        }
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $_SESSION['flash_error'] = $e->getMessage() !== '' ? $e->getMessage() : 'Absensi manual gagal disimpan.';
      header('Location: ' . base_url($redirectTo));
      exit;
    }

    if (in_array($status, ['Hadir Tepat Waktu', 'Hadir Terlambat'], true)) {
      $studentStmt = $pdo->prepare("
        SELECT s.nama_siswa, s.nis, s.class_level, p.no_hp
        FROM students s
        LEFT JOIN parents p ON p.id_orangtua = s.id_orangtua
        WHERE s.id_siswa=? LIMIT 1
      ");
      $studentStmt->execute([$idSiswa]);
      $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
      if ($student) {
        $waCfg = wa_cfg();
        $target = wa_normalize_phone((string)($student['no_hp'] ?? ''));
        if (!($waCfg['enabled'] ?? false)) {
          logSync($pdo, 'manual', 'Online', "WA skip manual disabled {$idSiswa}");
        } elseif ($target === '') {
          logSync($pdo, 'manual', 'Online', "WA skip manual no_hp {$idSiswa}");
        } else {
          $nama = (string)($student['nama_siswa'] ?? '');
          $nis = (string)($student['nis'] ?? '');
          $tanggalMsg = date('Y-m-d', strtotime($scanAt));
          $pukulMsg = date('H.i', strtotime($scanAt));
          $statusMsg = wa_status_label($type, $status);
          $message = attendance_build_parent_message($pdo, $nama, $nis, $tanggalMsg, $pukulMsg, $statusMsg, $catatan);

          [$waOk, $waInfo] = wa_send_fonnte($target, $message, $waCfg);
          if (!$waOk) {
            logSync($pdo, 'manual', 'Online', "WA gagal manual {$idSiswa} {$target} {$waInfo}");
          } else {
            logSync($pdo, 'manual', 'Tersinkron', "WA ok manual {$idSiswa} {$target}");
          }
        }
      }
    }

    $_SESSION['flash_success'] = 'Absensi manual tersimpan.';
  }

  header('Location: ' . base_url($redirectTo));
  exit;
}

// Update catatan
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='update_note') {
  $idAbsensi = (int)($_POST['id_absensi'] ?? 0);
  $idSiswa = (int)($_POST['id_siswa'] ?? 0);
  $tanggal = trim($_POST['tanggal'] ?? '');
  $type = trim($_POST['type'] ?? '');
  $scanAt = trim($_POST['scan_at'] ?? '');
  $catatan = trim($_POST['catatan'] ?? '');

  $hasScanAt = preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $scanAt);
  if ($idAbsensi > 0) {
    $pdo->prepare("UPDATE attendance SET catatan=? WHERE id_absensi=?")
        ->execute([$catatan !== '' ? $catatan : null, $idAbsensi]);
    $_SESSION['flash_success'] = 'Catatan diperbarui.';
  } elseif ($idSiswa > 0 && $hasScanAt) {
    $pdo->prepare("UPDATE attendance SET catatan=? WHERE id_siswa=? AND scan_at=?")
        ->execute([$catatan !== '' ? $catatan : null, $idSiswa, $scanAt]);
    $_SESSION['flash_success'] = 'Catatan diperbarui.';
  } elseif ($idSiswa > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) && in_array($type, ['MASUK','TIDAK MASUK'], true)) {
    $pdo->prepare("UPDATE attendance SET catatan=? WHERE id_siswa=? AND tanggal=? AND type=?")
        ->execute([$catatan !== '' ? $catatan : null, $idSiswa, $tanggal, $type]);
    $_SESSION['flash_success'] = 'Catatan diperbarui.';
  } else {
    $_SESSION['flash_error'] = 'Data catatan tidak valid.';
  }

  header('Location: ' . base_url('/admin/attendance.php'));
  exit;
}

// Update attendance status
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='update_attendance') {
  $idAbsensi = (int)($_POST['id_absensi'] ?? 0);
  $status = trim($_POST['status'] ?? '');
  $catatan = trim($_POST['catatan'] ?? '');

  $validStatus = [
    'Hadir Tepat Waktu',
    'Hadir Terlambat',
    'Alfa',
    'Izin',
    'Sakit',
  ];

  if ($idAbsensi > 0 && in_array($status, $validStatus, true)) {
    $type = attendance_storage_type_for_status($status);
    $stmt = $pdo->prepare("UPDATE attendance SET status=?, type=?, catatan=? WHERE id_absensi=?");
    $stmt->execute([$status, $type, $catatan !== '' ? $catatan : null, $idAbsensi]);
    $_SESSION['flash_success'] = 'Absensi diperbarui.';
  } else {
    $_SESSION['flash_error'] = 'Data absensi tidak valid.';
  }

  header('Location: ' . base_url('/admin/attendance.php'));
  exit;
}

// Delete attendance
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='delete_attendance') {
  $idAbsensi = (int)($_POST['id_absensi'] ?? 0);
  if ($idAbsensi > 0) {
    try {
      $pdo->beginTransaction();
      $existingEvidence = attendance_get_existing_evidence($pdo, $idAbsensi);
      if (attendance_evidence_supported($pdo)) {
        $pdo->prepare("DELETE FROM attendance_evidence WHERE attendance_id=?")->execute([$idAbsensi]);
      }
      $pdo->prepare("DELETE FROM attendance WHERE id_absensi=?")->execute([$idAbsensi]);
      $pdo->commit();

      if ($existingEvidence) {
        attendance_delete_evidence_file((string)($existingEvidence['file_path'] ?? ''));
      }
      $_SESSION['flash_success'] = 'Absensi dihapus.';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $_SESSION['flash_error'] = 'Absensi gagal dihapus.';
    }
  } else {
    $_SESSION['flash_error'] = 'Data hapus tidak valid.';
  }
  header('Location: ' . base_url('/admin/attendance.php'));
  exit;
}

$class = $_GET['class_level'] ?? '';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
$today = date('Y-m-d');
$typeFilter = $_GET['type_filter'] ?? 'semua';
if ($typeFilter === 'both') $typeFilter = 'semua';
if (!in_array($typeFilter, ['semua','masuk','tidak_masuk','pulang'], true)) $typeFilter = 'semua';
$studentId = (int)($_GET['student_id'] ?? 0);

$params = [$from, $to];
$attendanceWhereClass = '';
$rosterWhereClass = '';
if (in_array($class, ['7','8','9'], true)) {
  $attendanceWhereClass = "AND $attendanceClassExpr = ?";
  $rosterWhereClass = "AND s.class_level = ?";
  $params[] = $class;
}

$todayDate = date('Y-m-d');
$nowTime = date('H:i:s');
$weekday = (int)date('N');
if ($nowTime >= '12:00:00' && $weekday <= 5) {
  $autoParams = [$todayDate, $todayDate, $todayDate];
  $autoWhereClass = '';
  if ($rosterWhereClass !== '') {
    $autoWhereClass = "AND s.class_level = ?";
    $autoParams[] = $class;
  }
  $autoStmt = $pdo->prepare("
    " . (
      student_schema_has_column($pdo, 'attendance', 'class_level_snapshot')
        ? "INSERT INTO attendance (id_siswa, scan_at, tanggal, type, status, class_level_snapshot, source, catatan)
           SELECT s.id_siswa, CONCAT(?, ' 12:00:00'), ?, 'MASUK', 'Alfa', s.class_level, 'auto', 'Auto alfa (lewat jam 12:00)'"
        : "INSERT INTO attendance (id_siswa, scan_at, tanggal, type, status, source, catatan)
           SELECT s.id_siswa, CONCAT(?, ' 12:00:00'), ?, 'MASUK', 'Alfa', 'auto', 'Auto alfa (lewat jam 12:00)'"
    ) . "
    FROM students s
    LEFT JOIN attendance a
      ON a.id_siswa = s.id_siswa AND a.tanggal = ?
    WHERE a.id_siswa IS NULL
      AND $activeStudentWhere
    $autoWhereClass
  ");
  $autoStmt->execute($autoParams);
}

$whereStudent = '';
if ($studentId > 0) {
  $whereStudent = "AND s.id_siswa = ?";
  $params[] = $studentId;
}
$typeWhere = 'AND ' . attendance_filter_sql($typeFilter, 'a.type', 'a.status');

$missingMasukParams = [$today];
if ($rosterWhereClass !== '') $missingMasukParams[] = $class;
$missingMasukStmt = $pdo->prepare("
  SELECT s.id_siswa, s.nama_siswa, s.class_level
  FROM students s
  LEFT JOIN attendance a
    ON a.id_siswa = s.id_siswa AND a.tanggal = ?
  WHERE a.id_siswa IS NULL
    AND $activeStudentWhere
  $rosterWhereClass
  ORDER BY s.class_level, s.nama_siswa
");
$missingMasukStmt->execute($missingMasukParams);
$missingMasuk = $missingMasukStmt->fetchAll();

$missingPulangParams = [$today, $today];
if ($rosterWhereClass !== '') $missingPulangParams[] = $class;
$missingPulangStmt = $pdo->prepare("
  SELECT s.id_siswa, s.nama_siswa, s.class_level
  FROM students s
  JOIN attendance am
    ON am.id_siswa = s.id_siswa AND am.tanggal = ? AND " . attendance_filter_sql('masuk', 'am.type', 'am.status') . "
  LEFT JOIN attendance ap
    ON ap.id_siswa = s.id_siswa AND ap.tanggal = ? AND " . attendance_filter_sql('pulang', 'ap.type', 'ap.status') . "
  WHERE ap.id_siswa IS NULL
    AND $activeStudentWhere
  $rosterWhereClass
  ORDER BY s.class_level, s.nama_siswa
");
$missingPulangStmt->execute($missingPulangParams);
$missingPulang = $missingPulangStmt->fetchAll();

$stmt = $pdo->prepare("
  SELECT a.id_absensi, a.id_siswa, a.tanggal, a.scan_at, a.type, a.status, a.catatan, s.nama_siswa,
         $attendanceClassExpr AS class_level
  FROM attendance a
  JOIN students s ON s.id_siswa=a.id_siswa
  WHERE a.tanggal BETWEEN ? AND ?
    $attendanceWhereClass
    $whereStudent
    $typeWhere
  ORDER BY a.scan_at DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$rows = attendance_attach_evidence_to_rows($pdo, $rows);

$studentParams = [];
$studentWhere = '';
if (in_array($class, ['7','8','9'], true)) {
  $studentWhere = "WHERE class_level = ? AND $activeStudentWhere";
  $studentParams[] = $class;
} else {
  $studentWhere = "WHERE $activeStudentWhere";
}
$studentWhere = str_replace('class_level', 's.class_level', $studentWhere);
$studentStmt = $pdo->prepare("SELECT s.id_siswa, s.nis, s.nama_siswa, s.class_level FROM students s $studentWhere ORDER BY s.class_level, s.nama_siswa");
$studentStmt->execute($studentParams);
$students = $studentStmt->fetchAll();

$manualForm = $_SESSION['attendance_manual_form_admin'] ?? [];
$manualStudentId = (int)($manualForm['id_siswa'] ?? 0);
$manualDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($manualForm['tanggal'] ?? ''))
  ? (string)$manualForm['tanggal']
  : date('Y-m-d');
$manualTime = preg_match('/^\d{2}:\d{2}$/', (string)($manualForm['waktu'] ?? ''))
  ? (string)$manualForm['waktu']
  : date('H:i');
$manualStatus = in_array((string)($manualForm['status'] ?? ''), attendance_manual_allowed_statuses(), true)
  ? (string)$manualForm['status']
  : 'Hadir Tepat Waktu';
$manualCatatan = (string)($manualForm['catatan'] ?? '');
$manualStudentLabel = '';
if ($manualStudentId > 0) {
  foreach ($students as $s) {
    if ((int)$s['id_siswa'] === $manualStudentId) {
      $manualStudentLabel = $s['class_level']." | ".$s['nis']." | ".$s['nama_siswa'];
      break;
    }
  }
}

$studentLabel = '';
if ($studentId > 0) {
  foreach ($students as $s) {
    if ((int)$s['id_siswa'] === $studentId) {
      $studentLabel = $s['class_level']." | ".$s['nis']." | ".$s['nama_siswa'];
      break;
    }
  }
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
            <a class="btn btn-sm <?= $class==='7'?'btn-brand text-white':'btn-outline-primary' ?>" href="<?= base_url('/admin/attendance.php?class_level=7&from=' . urlencode($from) . '&to=' . urlencode($to)) ?>">Kelas 7</a>
            <a class="btn btn-sm <?= $class==='8'?'btn-brand text-white':'btn-outline-primary' ?>" href="<?= base_url('/admin/attendance.php?class_level=8&from=' . urlencode($from) . '&to=' . urlencode($to)) ?>">Kelas 8</a>
            <a class="btn btn-sm <?= $class==='9'?'btn-brand text-white':'btn-outline-primary' ?>" href="<?= base_url('/admin/attendance.php?class_level=9&from=' . urlencode($from) . '&to=' . urlencode($to)) ?>">Kelas 9</a>
            <a class="btn btn-sm <?= $class===''?'btn-brand text-white':'btn-outline-primary' ?>" href="<?= base_url('/admin/attendance.php?class_level=&from=' . urlencode($from) . '&to=' . urlencode($to)) ?>">Semua</a>
          </div>
          <form class="row g-2 align-items-end" method="get">
            <div class="col-12 col-md-2">
              <label class="form-label">Kelas</label>
              <select name="class_level" class="form-select">
                <option value="" <?= $class===''?'selected':'' ?>>(Semua)</option>
                <option value="7" <?= $class==='7'?'selected':'' ?>>7</option>
                <option value="8" <?= $class==='8'?'selected':'' ?>>8</option>
                <option value="9" <?= $class==='9'?'selected':'' ?>>9</option>
              </select>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Siswa</label>
              <input id="studentSearchFilterAdmin" list="studentListFilterAdmin" class="form-control" placeholder="Ketik nama/NIS/kelas..." autocomplete="off" value="<?= htmlspecialchars($studentLabel) ?>">
              <datalist id="studentListFilterAdmin">
                <?php foreach($students as $s): ?>
                  <?php $label = $s['class_level']." | ".$s['nis']." | ".$s['nama_siswa']; ?>
                  <option value="<?= htmlspecialchars($label) ?>" data-id="<?= (int)$s['id_siswa'] ?>"></option>
                <?php endforeach; ?>
              </datalist>
              <input type="hidden" name="student_id" id="studentIdFilterAdmin" value="<?= $studentId > 0 ? (int)$studentId : '' ?>">
            </div>
            <div class="col-12 col-md-2">
              <label class="form-label">Jenis</label>
              <select name="type_filter" class="form-select">
                <option value="semua" <?= $typeFilter==='semua'?'selected':'' ?>>Semua</option>
                <option value="masuk" <?= $typeFilter==='masuk'?'selected':'' ?>>Masuk saja</option>
                <option value="tidak_masuk" <?= $typeFilter==='tidak_masuk'?'selected':'' ?>>Tidak masuk saja</option>
                <option value="pulang" <?= $typeFilter==='pulang'?'selected':'' ?>>Pulang saja</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Dari</label>
              <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Sampai</label>
              <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="form-control">
            </div>
            <div class="col-12 col-md-2">
              <button class="btn btn-brand text-white w-100">Filter</button>
            </div>
            <div class="col-12 col-md-2">
              <a class="btn btn-outline-primary w-100" href="<?= base_url('/admin/export_attendance.php?class_level=' . urlencode($class) . '&from=' . urlencode($from) . '&to=' . urlencode($to) . '&type_filter=' . urlencode($typeFilter) . '&student_id=' . urlencode((string)$studentId) . '&layout=detail') ?>">CSV Detail</a>
            </div>
            <div class="col-12 col-md-2">
              <a class="btn btn-outline-secondary w-100" href="<?= base_url('/admin/attendance_print.php?class_level=' . urlencode($class) . '&from=' . urlencode($from) . '&to=' . urlencode($to) . '&type_filter=' . urlencode($typeFilter) . '&student_id=' . urlencode((string)$studentId) . '&layout=detail') ?>">PDF Detail</a>
            </div>
            <div class="col-12 col-md-2">
              <a class="btn btn-outline-success w-100" href="<?= base_url('/admin/export_attendance.php?class_level=' . urlencode($class) . '&from=' . urlencode($from) . '&to=' . urlencode($to) . '&type_filter=' . urlencode($typeFilter) . '&student_id=' . urlencode((string)$studentId) . '&layout=book') ?>">CSV Buku Absen</a>
            </div>
            <div class="col-12 col-md-2">
              <a class="btn btn-outline-dark w-100" href="<?= base_url('/admin/attendance_print.php?class_level=' . urlencode($class) . '&from=' . urlencode($from) . '&to=' . urlencode($to) . '&type_filter=' . urlencode($typeFilter) . '&student_id=' . urlencode((string)$studentId) . '&layout=book') ?>">PDF Buku Absen</a>
            </div>
          </form>
          <div class="small-muted mt-2">Format buku absen hanya memuat kehadiran dan tidak hadir (`H/T/S/I/A`) tanpa data pulang, dengan kolom tanggal `1-31` dan baris nama siswa.</div>
        </div>
      </div>

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
            <div class="fw-semibold">Tambah Absensi Manual</div>
            <a class="btn btn-sm btn-outline-primary" href="<?= base_url('/admin/message_templates.php') ?>">Kelola Template Pesan</a>
          </div>
          <form method="post" class="row g-2 align-items-end" enctype="multipart/form-data">
            <input type="hidden" name="action" value="manual_add">
            <input type="hidden" name="redirect_to" value="/admin/attendance.php">
            <div class="col-12 col-md-4">
              <label class="form-label">Siswa</label>
              <input id="studentSearchAdmin" list="studentListAdmin" class="form-control" placeholder="Ketik nama/NIS/kelas..." autocomplete="off" value="<?= htmlspecialchars($manualStudentLabel) ?>" required>
              <datalist id="studentListAdmin">
                <?php foreach($students as $s): ?>
                  <?php $label = $s['class_level']." | ".$s['nis']." | ".$s['nama_siswa']; ?>
                  <option value="<?= htmlspecialchars($label) ?>" data-id="<?= (int)$s['id_siswa'] ?>"></option>
                <?php endforeach; ?>
              </datalist>
              <input type="hidden" name="id_siswa" id="studentIdAdmin" value="<?= $manualStudentId > 0 ? (int)$manualStudentId : '' ?>">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Tanggal</label>
              <input type="date" name="tanggal" class="form-control" value="<?= htmlspecialchars($manualDate) ?>" required>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Waktu</label>
              <input type="time" name="waktu" class="form-control" value="<?= htmlspecialchars($manualTime) ?>">
            </div>
            <div class="col-12 col-md-2">
              <label class="form-label">Status</label>
              <select name="status" class="form-select" required>
                <?php foreach (attendance_manual_allowed_statuses() as $opt): ?>
                  <option value="<?= htmlspecialchars($opt) ?>" <?= $manualStatus === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-2">
              <label class="form-label">Catatan</label>
              <input name="catatan" class="form-control" placeholder="contoh: kartu tertinggal" value="<?= htmlspecialchars($manualCatatan) ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Foto Evidence</label>
              <input type="file" name="evidence_photo" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/*">
              <div class="small-muted mt-1">Wajib untuk status hadir tepat waktu atau hadir terlambat manual.</div>
            </div>
            <div class="col-12 col-md-2 d-grid">
              <button class="btn btn-brand text-white">Tambah</button>
            </div>
          </form>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <div class="fw-semibold mb-1">Belum Absen Masuk Hari Ini (<?= count($missingMasuk) ?>)</div>
              <div class="small-muted mb-3"><?= htmlspecialchars($today) ?></div>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>Nama</th><th>Kelas</th></tr></thead>
                  <tbody>
                    <?php foreach ($missingMasuk as $r): ?>
                      <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($r['nama_siswa']) ?></td>
                        <td><?= htmlspecialchars($r['class_level']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (!$missingMasuk): ?>
                      <tr><td colspan="2" class="small-muted">Semua siswa sudah absen masuk.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <div class="col-12 col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <div class="fw-semibold mb-1">Belum Absen Pulang Hari Ini (<?= count($missingPulang) ?>)</div>
              <div class="small-muted mb-3"><?= htmlspecialchars($today) ?></div>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>Nama</th><th>Kelas</th></tr></thead>
                  <tbody>
                    <?php foreach ($missingPulang as $r): ?>
                      <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($r['nama_siswa']) ?></td>
                        <td><?= htmlspecialchars($r['class_level']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (!$missingPulang): ?>
                      <tr><td colspan="2" class="small-muted">Semua siswa sudah absen pulang.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-2">
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <label for="attendancePerPage" class="form-label mb-0">Tampilkan</label>
              <select id="attendancePerPage" class="form-select form-select-sm" style="width:auto">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="all">Semua</option>
              </select>
              <span class="small-muted" id="attendanceCount"></span>
            </div>
            <div class="d-flex gap-2 align-items-center">
              <input id="attendanceSearch" class="form-control form-control-sm" placeholder="Cari nama siswa...">
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>Tanggal</th><th>Waktu</th><th>Nama</th><th>Kelas</th><th>Jenis</th><th>Status</th><th>Catatan</th><th>Evidence</th><th>Aksi</th></tr></thead>
              <tbody id="attendanceBody">
                <?php foreach($rows as $r): ?>
                  <?php
                    $jenis = attendance_display_type((string)($r['type'] ?? ''), (string)($r['status'] ?? ''));
                    $statusLabel = attendance_display_status((string)($r['type'] ?? ''), (string)($r['status'] ?? ''));
                    $searchText = strtolower(trim(($r['nama_siswa'] ?? '') . ' ' . ($r['class_level'] ?? '') . ' ' . $statusLabel . ' ' . ($r['tanggal'] ?? '') . ' ' . $jenis));
                  ?>
                  <tr class="attendance-row" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">
                    <td class="fw-semibold"><?= htmlspecialchars($r['tanggal']) ?></td>
                    <td><?= htmlspecialchars($r['scan_at']) ?></td>
                    <td><?= htmlspecialchars($r['nama_siswa']) ?></td>
                    <td><?= htmlspecialchars($r['class_level']) ?></td>
                    <td><?= htmlspecialchars($jenis) ?></td>
                    <td><span class="badge <?= htmlspecialchars(status_badge_class((string)($r['status'] ?? ''), (string)($r['type'] ?? ''))) ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                    <td><?= htmlspecialchars($r['catatan'] ?? '-') ?></td>
                    <td>
                      <?php if (!empty($r['evidence_url'])): ?>
                        <a href="<?= htmlspecialchars($r['evidence_url']) ?>" target="_blank" rel="noopener noreferrer" class="d-inline-flex align-items-center gap-2 text-decoration-none">
                          <img src="<?= htmlspecialchars($r['evidence_url']) ?>" alt="Evidence" style="width:52px;height:52px;object-fit:cover;border-radius:10px;border:1px solid #dbe2ea;">
                          <span class="small">Lihat</span>
                        </a>
                      <?php else: ?>
                        <span class="small-muted">Belum ada</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if(($r['type'] ?? '') !== 'PULANG'): ?>
                        <button type="button"
                          class="btn btn-sm btn-outline-primary me-1"
                          data-action="edit-attendance"
                          data-id-absensi="<?= (int)$r['id_absensi'] ?>"
                          data-status="<?= htmlspecialchars($r['status']) ?>"
                          data-catatan="<?= htmlspecialchars($r['catatan'] ?? '', ENT_QUOTES) ?>">
                          Edit Status
                        </button>
                      <?php endif; ?>
                      <button type="button"
                        class="btn btn-sm btn-outline-secondary"
                        data-action="edit-note"
                        data-id-absensi="<?= (int)$r['id_absensi'] ?>"
                        data-id-siswa="<?= (int)$r['id_siswa'] ?>"
                        data-tanggal="<?= htmlspecialchars($r['tanggal']) ?>"
                        data-scan-at="<?= htmlspecialchars($r['scan_at']) ?>"
                        data-type="<?= htmlspecialchars($r['type']) ?>"
                        data-catatan="<?= htmlspecialchars($r['catatan'] ?? '', ENT_QUOTES) ?>">
                        Edit Catatan
                      </button>
                      <button type="button"
                        class="btn btn-sm btn-outline-danger ms-1"
                        data-action="delete-attendance"
                        data-id-absensi="<?= (int)$r['id_absensi'] ?>">
                        Hapus
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if(!$rows): ?>
                  <tr id="attendanceEmpty"><td colspan="9" class="small-muted">Tidak ada data pada filter ini.</td></tr>
                <?php else: ?>
                  <tr id="attendanceEmpty" style="display:none"><td colspan="9" class="small-muted">Tidak ada data pada filter ini.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <nav class="d-flex justify-content-end mt-2">
            <ul class="pagination pagination-sm mb-0" id="attendancePagination"></ul>
          </nav>
        </div>
      </div>

    </div>
  </div>
</div>
<style>
  .modal-backdrop-custom {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1050;
  }
  .modal-custom {
    background: #fff;
    border-radius: 12px;
    max-width: 480px;
    width: calc(100% - 32px);
    padding: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
  }
  .modal-custom h5 { margin: 0 0 8px; }
  .modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }
</style>
<script>
const statusOptions = [
  "Hadir Tepat Waktu",
  "Hadir Terlambat",
  "Alfa",
  "Izin",
  "Sakit",
];
const attendanceRows = Array.from(document.querySelectorAll("#attendanceBody .attendance-row"));
const attendanceEmpty = document.getElementById("attendanceEmpty");
const attendancePerPage = document.getElementById("attendancePerPage");
const attendanceSearch = document.getElementById("attendanceSearch");
const attendancePagination = document.getElementById("attendancePagination");
const attendanceCount = document.getElementById("attendanceCount");
let attendancePage = 1;
const studentSearchAdmin = document.getElementById("studentSearchAdmin");
const studentIdAdmin = document.getElementById("studentIdAdmin");
const studentListAdmin = document.getElementById("studentListAdmin");
const studentSearchFilterAdmin = document.getElementById("studentSearchFilterAdmin");
const studentIdFilterAdmin = document.getElementById("studentIdFilterAdmin");
const studentListFilterAdmin = document.getElementById("studentListFilterAdmin");

function resolveStudentId(inputEl, listEl, hiddenEl) {
  if (!inputEl || !listEl || !hiddenEl) return;
  const value = inputEl.value.trim();
  let foundId = "";
  let startsWithId = "";
  let startsWithCount = 0;
  Array.from(listEl.options).forEach((opt) => {
    const label = String(opt.value || "");
    const id = opt.getAttribute("data-id") || "";
    if (label === value) {
      foundId = id;
    }
    if (label.toLowerCase().startsWith(value.toLowerCase())) {
      startsWithId = id;
      startsWithCount += 1;
    }
  });
  if (foundId) {
    hiddenEl.value = foundId;
  } else if (value && startsWithCount === 1) {
    hiddenEl.value = startsWithId;
    const match = Array.from(listEl.options).find((opt) => String(opt.value || "").toLowerCase().startsWith(value.toLowerCase()));
    if (match) inputEl.value = match.value;
  } else {
    hiddenEl.value = "";
  }
}

studentSearchAdmin?.addEventListener("input", () => {
  resolveStudentId(studentSearchAdmin, studentListAdmin, studentIdAdmin);
});
studentSearchAdmin?.addEventListener("blur", () => {
  resolveStudentId(studentSearchAdmin, studentListAdmin, studentIdAdmin);
});
studentSearchFilterAdmin?.addEventListener("input", () => {
  resolveStudentId(studentSearchFilterAdmin, studentListFilterAdmin, studentIdFilterAdmin);
});
studentSearchFilterAdmin?.addEventListener("blur", () => {
  resolveStudentId(studentSearchFilterAdmin, studentListFilterAdmin, studentIdFilterAdmin);
});

function getFilteredAttendanceRows(){
  const q = String(attendanceSearch?.value || "").trim().toLowerCase();
  if (!q) return attendanceRows;
  return attendanceRows.filter((row) => (row.getAttribute("data-search") || "").includes(q));
}

function renderAttendancePagination(totalPages){
  if (!attendancePagination) return;
  attendancePagination.innerHTML = "";
  if (totalPages <= 1) return;

  const maxButtons = 7;
  let start = Math.max(1, attendancePage - 2);
  let end = Math.min(totalPages, attendancePage + 2);

  if (end - start + 1 < maxButtons) {
    if (start === 1) {
      end = Math.min(totalPages, start + maxButtons - 1);
    } else if (end === totalPages) {
      start = Math.max(1, end - maxButtons + 1);
    }
  }

  const addPage = (page, label, isActive, isDisabled) => {
    const li = document.createElement("li");
    li.className = `page-item${isActive ? " active" : ""}${isDisabled ? " disabled" : ""}`;
    if (isDisabled) {
      li.innerHTML = `<span class="page-link">${label}</span>`;
      attendancePagination.appendChild(li);
      return;
    }
    li.innerHTML = `<button class="page-link" type="button" data-page="${page}">${label}</button>`;
    attendancePagination.appendChild(li);
  };

  if (start > 1) {
    addPage(1, "1", false, false);
    if (start > 2) addPage(0, "...", false, true);
  }
  for (let p = start; p <= end; p++) {
    addPage(p, String(p), p === attendancePage, false);
  }
  if (end < totalPages) {
    if (end < totalPages - 1) addPage(0, "...", false, true);
    addPage(totalPages, String(totalPages), false, false);
  }
}

function renderAttendanceTable(){
  const filtered = getFilteredAttendanceRows();
  const perPageValue = attendancePerPage?.value || "10";
  const pageSize = perPageValue === "all" ? filtered.length || 1 : parseInt(perPageValue, 10);
  const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));

  if (attendancePage > totalPages) attendancePage = totalPages;
  const start = (attendancePage - 1) * pageSize;
  const end = start + pageSize;

  attendanceRows.forEach((row) => { row.style.display = "none"; });
  filtered.slice(start, end).forEach((row) => { row.style.display = ""; });

  if (attendanceEmpty) {
    attendanceEmpty.style.display = filtered.length ? "none" : "";
  }
  if (attendanceCount) {
    const shown = filtered.length ? Math.min(pageSize, filtered.length - start) : 0;
    attendanceCount.textContent = `Menampilkan ${shown} dari ${filtered.length} data`;
  }
  renderAttendancePagination(totalPages);
}

attendanceSearch?.addEventListener("input", () => {
  attendancePage = 1;
  renderAttendanceTable();
});

attendancePerPage?.addEventListener("change", () => {
  attendancePage = 1;
  renderAttendanceTable();
});

attendancePagination?.addEventListener("click", (e) => {
  const btn = e.target.closest("[data-page]");
  if (!btn) return;
  const page = parseInt(btn.getAttribute("data-page"), 10);
  if (!page || Number.isNaN(page)) return;
  attendancePage = page;
  renderAttendanceTable();
});

renderAttendanceTable();

const serverDate = "<?= $today ?>";
function getLocalDate(){
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}
setInterval(() => {
  const now = getLocalDate();
  if (now && now !== serverDate) {
    location.reload();
  }
}, 60000);


function submitAction(formId, payload){
  const form = document.getElementById(formId);
  if (!form) return;
  form.querySelector('input[name="id_absensi"]').value = payload.id_absensi || "";
  form.querySelector('input[name="id_siswa"]').value = payload.id_siswa;
  form.querySelector('input[name="tanggal"]').value = payload.tanggal;
  form.querySelector('input[name="type"]').value = payload.type;
  form.querySelector('input[name="scan_at"]').value = payload.scan_at || "";
  if (payload.catatan !== undefined) {
    form.querySelector('input[name="catatan"]').value = payload.catatan;
  }
  form.submit();
}

function submitUpdate(payload){
  const form = document.getElementById("formUpdateAttendance");
  if (!form) return;
  form.querySelector('input[name="id_absensi"]').value = payload.id_absensi || "";
  form.querySelector('input[name="status"]').value = payload.status || "";
  form.querySelector('input[name="catatan"]').value = payload.catatan || "";
  form.submit();
}

function submitDelete(payload){
  const form = document.getElementById("formDeleteAttendance");
  if (!form) return;
  form.querySelector('input[name="id_absensi"]').value = payload.id_absensi || "";
  form.submit();
}

document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-action]');
  if (!btn) return;

  const payload = {
    id_absensi: btn.getAttribute('data-id-absensi') || '',
    id_siswa: btn.getAttribute('data-id-siswa') || '',
    tanggal: btn.getAttribute('data-tanggal') || '',
    type: btn.getAttribute('data-type') || '',
    scan_at: btn.getAttribute('data-scan-at') || '',
    catatan: btn.getAttribute('data-catatan') || '',
  };

  if (btn.getAttribute('data-action') === 'edit-note') {
    const note = prompt('Edit catatan:', payload.catatan || '');
    if (note === null) return;
    payload.catatan = note.trim();
    submitAction('formUpdateNote', payload);
  } else if (btn.getAttribute('data-action') === 'edit-attendance') {
    openEditModal({
      id_absensi: btn.getAttribute('data-id-absensi') || '',
      status: btn.getAttribute('data-status') || '',
      catatan: btn.getAttribute('data-catatan') || '',
    });
  } else if (btn.getAttribute('data-action') === 'delete-attendance') {
    if (!confirm('Hapus presensi ini?')) return;
    submitDelete(payload);
  }
});
</script>

<div id="editAttendanceModal" class="modal-backdrop-custom" role="dialog" aria-modal="true">
  <div class="modal-custom">
    <h5>Edit Status Presensi</h5>
    <div class="mb-2">
      <label class="form-label">Status</label>
      <select id="editAttendanceStatus" class="form-select">
        <?php foreach (['Hadir Tepat Waktu','Hadir Terlambat','Alfa','Izin','Sakit'] as $opt): ?>
          <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="mb-2">
      <label class="form-label">Catatan (opsional)</label>
      <input id="editAttendanceCatatan" class="form-control" placeholder="Catatan...">
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-outline-secondary" id="editAttendanceCancel">Batal</button>
      <button type="button" class="btn btn-brand text-white" id="editAttendanceSave">Simpan</button>
    </div>
  </div>
</div>

<script>
const editModal = document.getElementById("editAttendanceModal");
const editStatus = document.getElementById("editAttendanceStatus");
const editCatatan = document.getElementById("editAttendanceCatatan");
const editCancel = document.getElementById("editAttendanceCancel");
const editSave = document.getElementById("editAttendanceSave");
let editCurrentId = "";

function openEditModal(data){
  editCurrentId = data.id_absensi || "";
  if (editStatus) editStatus.value = data.status || "Hadir Tepat Waktu";
  if (editCatatan) editCatatan.value = data.catatan || "";
  if (editModal) editModal.style.display = "flex";
}

function closeEditModal(){
  if (editModal) editModal.style.display = "none";
}

editCancel?.addEventListener("click", closeEditModal);
editModal?.addEventListener("click", (e) => {
  if (e.target === editModal) closeEditModal();
});
editSave?.addEventListener("click", () => {
  const status = editStatus?.value || "";
  if (!statusOptions.includes(status)) {
    alert("Status tidak valid.");
    return;
  }
  submitUpdate({
    id_absensi: editCurrentId,
    status: status,
    catatan: (editCatatan?.value || "").trim(),
  });
});
</script>

<form id="formUpdateNote" method="post" style="display:none">
  <input type="hidden" name="action" value="update_note">
  <input type="hidden" name="id_absensi" value="">
  <input type="hidden" name="id_siswa" value="">
  <input type="hidden" name="tanggal" value="">
  <input type="hidden" name="type" value="">
  <input type="hidden" name="scan_at" value="">
  <input type="hidden" name="catatan" value="">
</form>
<form id="formUpdateAttendance" method="post" style="display:none">
  <input type="hidden" name="action" value="update_attendance">
  <input type="hidden" name="id_absensi" value="">
  <input type="hidden" name="status" value="">
  <input type="hidden" name="catatan" value="">
</form>
<form id="formDeleteAttendance" method="post" style="display:none">
  <input type="hidden" name="action" value="delete_attendance">
  <input type="hidden" name="id_absensi" value="">
</form>
<?php include __DIR__ . '/../partials/foot.php'; ?>
