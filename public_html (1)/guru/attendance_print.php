<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/attendance_helpers.php';
require_once __DIR__ . '/../lib/attendance_book_report.php';
require_once __DIR__ . '/../lib/attendance_pdf.php';
require_once __DIR__ . '/../lib/student_promotion.php';

require_role('guru');
$pdo = db();
$u = current_user();
$teacherId = $u['teacher_id'] ?? null;

$cacheTtlSeconds = 3;
$cacheHit = false;
$cacheFile = null;

$classLevel = '';
if ($teacherId) {
  $st = $pdo->prepare("SELECT class_level FROM teachers WHERE id_guru=?");
  $st->execute([$teacherId]);
  $classLevel = $st->fetchColumn() ?: '';
}
if ($classLevel === '') {
  http_response_code(400);
  echo "Kelas guru belum diset.";
  exit;
}

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-t');
$typeFilter = $_GET['type_filter'] ?? 'semua';
if ($typeFilter === 'both') $typeFilter = 'semua';
if (!in_array($typeFilter, ['semua','masuk','tidak_masuk','pulang'], true)) $typeFilter = 'semua';
$layout = $_GET['layout'] ?? 'detail';
if (!in_array($layout, ['detail', 'book'], true)) $layout = 'detail';
$studentId = (int)($_GET['student_id'] ?? 0);
$attendanceClassExpr = student_attendance_class_expr($pdo, 'a', 's');

if ($layout === 'book') {
  $report = attendance_book_report($pdo, [
    'fixed_class_level' => $classLevel,
    'from' => $from,
    'to' => $to,
    'type_filter' => $typeFilter,
    'student_id' => $studentId,
  ]);
  attendance_book_pdf_download($report, 'buku_absen_guru_kelas' . $classLevel);
}

$typeWhere = 'AND ' . attendance_filter_sql($typeFilter, 'a.type', 'a.status');

$cacheKey = hash('sha256', $classLevel . '|' . $from . '|' . $to . '|' . $typeFilter . '|' . $studentId);
$cacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'att_print_' . $cacheKey . '.cache';
if (is_file($cacheFile) && (time() - filemtime($cacheFile) <= $cacheTtlSeconds)) {
  $raw = @file_get_contents($cacheFile);
  $payload = $raw !== false ? @unserialize($raw) : null;
  if (is_array($payload) && isset($payload['rows'], $payload['printed_at'])) {
    $rows = $payload['rows'];
    $printedAt = $payload['printed_at'];
    $cacheHit = true;
  }
}
if (!$cacheHit) {
  $whereStudent = '';
  $params = [$classLevel, $from, $to];
  if ($studentId > 0) {
    $whereStudent = "AND s.id_siswa = ?";
    $params[] = $studentId;
  }
  $stmt = $pdo->prepare("
    SELECT a.tanggal, a.scan_at, a.type, a.status, a.catatan,
           s.nis, s.nama_siswa, $attendanceClassExpr AS class_level,
           " . attendance_bucket_case_sql('a.type', 'a.status') . " AS jenis_bucket
    FROM attendance a
    JOIN students s ON s.id_siswa=a.id_siswa
    WHERE $attendanceClassExpr = ?
      AND a.tanggal BETWEEN ? AND ?
      $whereStudent
      $typeWhere
    ORDER BY a.scan_at DESC
  ");
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $printedAt = date('Y-m-d H:i');
  $payload = serialize([
    'rows' => $rows,
    'printed_at' => $printedAt,
  ]);
  @file_put_contents($cacheFile, $payload, LOCK_EX);
}

$classLabel = 'Kelas ' . $classLevel;
$studentLabel = 'Semua Siswa';
if ($studentId > 0) {
  $studentStmt = $pdo->prepare("SELECT nis, nama_siswa FROM students WHERE class_level=? AND id_siswa=? LIMIT 1");
  $studentStmt->execute([$classLevel, $studentId]);
  $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
  if ($student) {
    $studentLabel = trim(($student['nis'] ?? '') . ' | ' . ($student['nama_siswa'] ?? ''));
  }
}
$yearLabel = date('Y');
$typeLabel = $typeFilter === 'masuk'
  ? 'Masuk saja'
  : ($typeFilter === 'tidak_masuk'
    ? 'Tidak masuk saja'
    : ($typeFilter === 'pulang' ? 'Pulang saja' : 'Semua'));
$printedAtLabel = date('d/m/Y H:i', strtotime($printedAt));
$periodLabel = date('d/m/Y', strtotime($from)) . ' s/d ' . date('d/m/Y', strtotime($to));

foreach ($rows as &$row) {
  $row['jenis_label'] = attendance_display_type((string)($row['type'] ?? ''), (string)($row['status'] ?? ''));
  $row['status_label'] = attendance_display_status((string)($row['type'] ?? ''), (string)($row['status'] ?? ''));
  $row['catatan'] = trim((string)($row['catatan'] ?? '')) !== '' ? trim((string)$row['catatan']) : '-';
}
unset($row);

attendance_pdf_download([
  'filename_prefix' => 'rekap_presensi_guru',
  'from' => $from,
  'to' => $to,
  'type_filter' => $typeFilter,
  'class_label' => $classLabel,
  'student_label' => $studentLabel,
  'type_label' => $typeLabel,
  'printed_at_label' => $printedAtLabel,
  'year_label' => $yearLabel,
  'period_label' => $periodLabel,
  'rows' => $rows,
]);
