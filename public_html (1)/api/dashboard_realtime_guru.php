<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/attendance_features.php';
require_once __DIR__ . '/../lib/stats.php';

require_role('guru');

$pdo = db();
$u = current_user();
$teacherId = $u['teacher_id'] ?? null;
$classLevel = $u['class_level'] ?? '';

if (!$classLevel && $teacherId) {
  $st = $pdo->prepare("SELECT class_level FROM teachers WHERE id_guru=?");
  $st->execute([$teacherId]);
  $classLevel = $st->fetchColumn() ?: '';
}

if ($classLevel === '') {
  echo json_encode(['success'=>false,'message'=>'Kelas guru belum diset']);
  exit;
}

$today = date('Y-m-d');

$latestStmt = $pdo->prepare("
  SELECT a.scan_at, a.status, a.type, s.nama_siswa, s.class_level
  FROM attendance a
  JOIN students s ON s.id_siswa = a.id_siswa
  WHERE s.class_level = ?
  ORDER BY a.scan_at DESC
  LIMIT 1
");
$latestStmt->execute([$classLevel]);
$latest = $latestStmt->fetch(PDO::FETCH_ASSOC) ?: null;
if ($latest) {
  $latestRows = attendance_attach_evidence_to_rows($pdo, [$latest]);
  $latest = $latestRows[0] ?? $latest;
}

$counts = [
  'Hadir Tepat Waktu' => 0,
  'Hadir Terlambat' => 0,
  'Izin' => 0,
  'Sakit' => 0,
  'Alfa' => 0,
];
$stmt = $pdo->prepare("
  SELECT a.status, COUNT(*) AS cnt
  FROM attendance a
  JOIN students s ON s.id_siswa = a.id_siswa
  WHERE s.class_level = ?
    AND a.tanggal = ?
    AND (
      a.type IN ('MASUK','TIDAK MASUK')
      OR LOWER(TRIM(a.status)) IN ('izin','ijin','sakit','alfa','alpha')
    )
  GROUP BY a.status
");
$stmt->execute([$classLevel, $today]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $status = normalize_status((string)$row['status']);
  if (isset($counts[$status])) {
    $counts[$status] = (int)$row['cnt'];
  }
}

$totalStudentsStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_level = ?");
$totalStudentsStmt->execute([$classLevel]);
$totalStudents = (int)$totalStudentsStmt->fetchColumn();

$presentStmt = $pdo->prepare("
  SELECT COUNT(DISTINCT a.id_siswa)
  FROM attendance a
  JOIN students s ON s.id_siswa = a.id_siswa
  WHERE s.class_level = ?
    AND a.tanggal = ?
");
$presentStmt->execute([$classLevel, $today]);
$presentStudents = (int)$presentStmt->fetchColumn();

$missingStudents = max($totalStudents - $presentStudents, 0);

echo json_encode([
  'success' => true,
  'server_time' => date('Y-m-d H:i:s'),
  'kelas' => $classLevel,
  'latest' => $latest,
  'counts' => $counts,
  'total_students' => $totalStudents,
  'present_students' => $presentStudents,
  'missing_students' => $missingStudents,
]);
