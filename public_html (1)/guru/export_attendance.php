<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/attendance_helpers.php';
require_once __DIR__ . '/../lib/attendance_book_report.php';
require_once __DIR__ . '/../lib/student_promotion.php';

require_role('guru');
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

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-t');
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
  attendance_book_csv_download($report, 'buku_absen_guru_kelas' . $classLevel);
}

$typeWhere = 'AND ' . attendance_filter_sql($typeFilter, 'a.type', 'a.status');

$whereStudent = '';
$params = [$classLevel, $from, $to];
if ($studentId > 0) {
  $whereStudent = "AND s.id_siswa = ?";
  $params[] = $studentId;
}

$stmt = $pdo->prepare("
  SELECT a.tanggal, a.scan_at, a.type, a.status, a.catatan, s.nis, s.nama_siswa,
         $attendanceClassExpr AS class_level
  FROM attendance a
  JOIN students s ON s.id_siswa=a.id_siswa
  WHERE $attendanceClassExpr = ?
    AND a.tanggal BETWEEN ? AND ?
    $whereStudent
    $typeWhere
  ORDER BY a.scan_at ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$fname = "rekap_presensi_guru_kelas{$classLevel}_"
  . ($studentId > 0 ? "siswa{$studentId}_" : "")
  . "{$from}_sd_{$to}.csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($out, ['Tanggal','Waktu Scan','Tipe','Status','Catatan','NIS','Nama','Kelas'], ';');
foreach ($rows as $r) {
  $type = attendance_display_type((string)($r['type'] ?? ''), (string)($r['status'] ?? ''));
  $status = attendance_display_status((string)($r['type'] ?? ''), (string)($r['status'] ?? ''));
  fputcsv($out, [$r['tanggal'],$r['scan_at'],$type,$status,$r['catatan'],$r['nis'],$r['nama_siswa'],$r['class_level']], ';');
}
fclose($out);
exit;
