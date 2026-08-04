<?php
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/stats.php';

require_role('orangtua','ortu');

$pdo = db();
$u = current_user();
$parentId = (int)($u['parent_id'] ?? 0);
if ($parentId <= 0) {
  echo json_encode(['success'=>false,'message'=>'Akun orang tua belum terhubung ke siswa']);
  exit;
}

$childId = (int)($_GET['kid'] ?? 0);
if ($childId > 0) {
  $cek = $pdo->prepare("SELECT id_siswa FROM students WHERE id_siswa=? AND id_orangtua=? LIMIT 1");
  $cek->execute([$childId, $parentId]);
  if (!$cek->fetch()) $childId = 0;
}
if ($childId <= 0) {
  $kidStmt = $pdo->prepare("SELECT id_siswa FROM students WHERE id_orangtua=? ORDER BY class_level, nama_siswa LIMIT 1");
  $kidStmt->execute([$parentId]);
  $childId = (int)($kidStmt->fetchColumn() ?? 0);
}

// range: week / month (default month)
$range = $_GET['range'] ?? 'month';
$range = in_array($range, ['week','month']) ? $range : 'month';

if ($range === 'week') {
  $start = date('Y-m-d', strtotime('monday this week'));
  $end   = date('Y-m-d', strtotime('sunday this week'));
} else {
  $start = date('Y-m-01');
  $end   = date('Y-m-t');
}

// Hitung status dari attendance (masuk + tidak masuk), tanpa pulang
$qStat = $pdo->prepare("
  SELECT status, COUNT(*) c
  FROM attendance
  WHERE id_siswa=? AND tanggal BETWEEN ? AND ?
    AND (
      type IN ('MASUK','TIDAK MASUK')
      OR LOWER(TRIM(status)) IN ('izin','ijin','sakit','alfa','alpha')
    )
  GROUP BY status
");
$qStat->execute([$childId, $start, $end]);

$counts = [
  'Hadir Tepat Waktu' => 0,
  'Hadir Terlambat' => 0,
  'Izin' => 0,
  'Sakit' => 0,
  'Alfa' => 0,
];
foreach ($qStat->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $st = normalize_status((string)$r['status']);
  if (isset($counts[$st])) $counts[$st] = (int)$r['c'];
}

$hadir = $counts['Hadir Tepat Waktu'] + $counts['Hadir Terlambat'];
$alfa = $counts['Alfa'];
$sakit = $counts['Sakit'];
$izin = $counts['Izin'];

// ambil nama siswa
$qS = $pdo->prepare("SELECT nama_siswa, class_level FROM students WHERE id_siswa=? LIMIT 1");
$qS->execute([$childId]);
$siswa = $qS->fetch(PDO::FETCH_ASSOC);

echo json_encode([
  'success'=>true,
  'range'=>$range,
  'start'=>$start,
  'end'=>$end,
  'nama'=>$siswa['nama_siswa'] ?? '',
  'kelas'=>$siswa['class_level'] ?? '',
  'hadir'=>$hadir,
  'alfa'=>$alfa,
  'sakit'=>$sakit,
  'izin'=>$izin
]);
