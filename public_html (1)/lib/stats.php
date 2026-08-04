<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/attendance_helpers.php';

function normalize_status(string $status): string {
  return attendance_normalize_status($status);
}

function range_dates(string $range): array {
  // range: 'week', 'month', or 'all'
  $today = new DateTime('today');
  if ($range === 'week') {
    // last 7 days including today
    $start = (clone $today)->modify('-6 days');
    $end = (clone $today);
  } elseif ($range === 'all') {
    // full history (safe lower bound)
    $start = new DateTime('2000-01-01');
    $end = (clone $today);
  } else {
    // current month
    $start = new DateTime(date('Y-m-01'));
    $end = (new DateTime(date('Y-m-t')));
  }
  return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function counts_for_student(int $idSiswa, string $startDate, string $endDate): array {
  $pdo = db();
  $stmt = $pdo->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM attendance
    WHERE id_siswa = ?
      AND tanggal BETWEEN ? AND ?
      AND " . attendance_filter_sql('semua', 'type', 'status') . "
    GROUP BY status
  ");
  $stmt->execute([$idSiswa, $startDate, $endDate]);
  $rows = $stmt->fetchAll();

  $out = [
    'Hadir Tepat Waktu' => 0,
    'Hadir Terlambat' => 0,
    'Izin' => 0,
    'Sakit' => 0,
    'Alfa' => 0,
  ];
  foreach ($rows as $r) {
    $s = normalize_status((string)$r['status']);
    if (isset($out[$s])) $out[$s] = (int)$r['cnt'];
  }
  $out['Hadir'] = $out['Hadir Tepat Waktu'] + $out['Hadir Terlambat'];
  return $out;
}

function counts_overall(?string $classLevel, string $startDate, string $endDate): array {
  $pdo = db();
  $params = [$startDate, $endDate];
  $whereClass = "";
  if ($classLevel) {
    $whereClass = "AND s.class_level = ?";
    $params[] = $classLevel;
  }
  $stmt = $pdo->prepare("
    SELECT a.status, COUNT(*) AS cnt
    FROM attendance a
    JOIN students s ON s.id_siswa = a.id_siswa
    WHERE a.tanggal BETWEEN ? AND ?
      AND " . attendance_filter_sql('semua', 'a.type', 'a.status') . "
      $whereClass
    GROUP BY a.status
  ");
  $stmt->execute($params);
  $rows = $stmt->fetchAll();

  $out = [
    'Hadir Tepat Waktu' => 0,
    'Hadir Terlambat' => 0,
    'Izin' => 0,
    'Sakit' => 0,
    'Alfa' => 0,
  ];
  foreach ($rows as $r) {
    $s = normalize_status((string)$r['status']);
    if (isset($out[$s])) $out[$s] = (int)$r['cnt'];
  }
  $out['Hadir'] = $out['Hadir Tepat Waktu'] + $out['Hadir Terlambat'];
  return $out;
}

function status_badge_class(string $status, string $type = ''): string {
  $type = strtoupper(trim($type));
  if ($type === 'PULANG') return 'status-badge status-pulang';

  $normalized = normalize_status($status);
  if (stripos($normalized, 'Tepat') !== false) return 'status-badge status-ontime';
  if (stripos($normalized, 'Terlambat') !== false) return 'status-badge status-late';
  if (stripos($normalized, 'Izin') !== false) return 'status-badge status-permit';
  if (stripos($normalized, 'Sakit') !== false) return 'status-badge status-sick';
  if (stripos($normalized, 'Alfa') !== false) return 'status-badge status-absent';
  return 'status-badge status-default';
}
