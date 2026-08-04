<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/attendance_features.php';
require_once __DIR__ . '/../lib/stats.php';

require_role('admin');

$pdo = db();
$today = date('Y-m-d');

$latestStmt = $pdo->prepare("
  SELECT a.scan_at, a.status, a.type, s.nama_siswa, s.class_level
  FROM attendance a
  JOIN students s ON s.id_siswa = a.id_siswa
  WHERE a.tanggal = ?
    AND (
      a.type IN ('MASUK','PULANG','TIDAK MASUK')
      OR LOWER(TRIM(a.status)) IN ('izin','ijin','sakit','alfa','alpha')
    )
  ORDER BY a.scan_at DESC
  LIMIT 1
");
$latestStmt->execute([$today]);
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
  SELECT status, COUNT(*) AS cnt
  FROM attendance
  WHERE tanggal = ?
    AND (
      type IN ('MASUK','TIDAK MASUK')
      OR LOWER(TRIM(status)) IN ('izin','ijin','sakit','alfa','alpha')
    )
  GROUP BY status
");
$stmt->execute([$today]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
  $status = normalize_status((string)$row['status']);
  if (isset($counts[$status])) {
    $counts[$status] = (int)$row['cnt'];
  }
}

$hadir = $counts['Hadir Tepat Waktu'] + $counts['Hadir Terlambat'];
$counts['Hadir'] = $hadir;

$totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$presentStmt = $pdo->prepare("SELECT COUNT(DISTINCT id_siswa) FROM attendance WHERE tanggal = ?");
$presentStmt->execute([$today]);
$presentStudents = (int)$presentStmt->fetchColumn();
$missingStudents = max($totalStudents - $presentStudents, 0);

echo json_encode([
  'success' => true,
  'server_time' => date('Y-m-d H:i:s'),
  'latest' => $latest,
  'counts' => $counts,
  'total_students' => $totalStudents,
  'present_students' => $presentStudents,
  'missing_students' => $missingStudents,
]);
