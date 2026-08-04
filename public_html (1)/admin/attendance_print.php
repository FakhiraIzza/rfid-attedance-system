<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/attendance_helpers.php';
require_once __DIR__ . '/../lib/attendance_book_report.php';
require_once __DIR__ . '/../lib/attendance_pdf.php';
require_once __DIR__ . '/../lib/student_promotion.php';

require_role('admin');
$pdo = db();

$class = $_GET['class_level'] ?? '';
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
    'class_level' => $class,
    'from' => $from,
    'to' => $to,
    'type_filter' => $typeFilter,
    'student_id' => $studentId,
  ]);
  attendance_book_pdf_download($report, 'buku_absen_admin');
}

$params = [$from, $to];
$whereClass = '';
if (in_array($class, ['7','8','9'], true)) {
  $whereClass = "AND $attendanceClassExpr = ?";
  $params[] = $class;
}

$whereStudent = '';
$studentLabel = '';
if ($studentId > 0) {
  $whereStudent = "AND s.id_siswa = ?";
  $params[] = $studentId;
  $studentStmt = $pdo->prepare("SELECT nis, nama_siswa, class_level FROM students WHERE id_siswa=? LIMIT 1");
  $studentStmt->execute([$studentId]);
  $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
  if ($student) {
    $studentLabel = trim(($student['class_level'] ?? '') . ' | ' . ($student['nis'] ?? '') . ' | ' . ($student['nama_siswa'] ?? ''));
  }
}

$typeWhere = 'AND ' . attendance_filter_sql($typeFilter, 'a.type', 'a.status');

$stmt = $pdo->prepare("
  SELECT a.tanggal, a.scan_at, a.type, a.status, a.catatan,
         s.nis, s.nama_siswa, $attendanceClassExpr AS class_level,
         " . attendance_bucket_case_sql('a.type', 'a.status') . " AS jenis_bucket
  FROM attendance a
  JOIN students s ON s.id_siswa=a.id_siswa
  WHERE a.tanggal BETWEEN ? AND ?
    $whereClass
    $whereStudent
    $typeWhere
  ORDER BY a.scan_at DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$classLabel = $class !== '' ? ('Kelas ' . $class) : 'Semua Kelas';
$studentLabel = $studentLabel !== '' ? $studentLabel : 'Semua Siswa';
$printedAt = date('Y-m-d H:i');
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
  'filename_prefix' => 'rekap_presensi_admin',
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
