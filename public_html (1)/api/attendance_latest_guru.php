<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/attendance_features.php';

require_role('guru');

$pdo = db();
$u = current_user();
$kelas = $u['class_level'] ?? '';
$teacherId = $u['teacher_id'] ?? null;

if ($kelas === '' && $teacherId) {
  $st = $pdo->prepare("SELECT class_level FROM teachers WHERE id_guru=?");
  $st->execute([$teacherId]);
  $kelas = $st->fetchColumn() ?: '';
}

if ($kelas === '') {
  echo json_encode(['success'=>false,'message'=>'Kelas guru belum diset']);
  exit;
}

$limit = (int)($_GET['limit'] ?? 20);
if ($limit < 1 || $limit > 100) $limit = 20;

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-t');

$todayOnly = ($_GET['today'] ?? '') === '1';
if ($todayOnly) {
  $today = date('Y-m-d');
  $from = $today;
  $to = $today;
}

$typesParam = $_GET['types'] ?? '';
if ($typesParam === 'masuk_pulang') {
  $types = ['MASUK', 'PULANG'];
} elseif ($typesParam === 'all') {
  $types = ['MASUK', 'TIDAK MASUK', 'PULANG'];
} else {
  $types = ['MASUK', 'TIDAK MASUK'];
}
$typeKeys = [];
foreach ($types as $idx => $t) {
  $typeKeys[] = ':type' . $idx;
}
$typePlaceholders = implode(',', $typeKeys);

$sql = "SELECT a.id_absensi, a.tanggal, a.scan_at, a.type, a.status, a.catatan,
               s.nama_siswa, s.class_level
        FROM attendance a
        JOIN students s ON s.id_siswa = a.id_siswa
        WHERE s.class_level = :kelas
          AND a.tanggal BETWEEN :from AND :to
          AND (
            a.type IN ($typePlaceholders)
            OR LOWER(TRIM(a.status)) IN ('izin','ijin','sakit','alfa','alpha')
          )
        ORDER BY a.scan_at DESC
        LIMIT :lim";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':kelas', $kelas);
$stmt->bindValue(':from', $from);
$stmt->bindValue(':to', $to);
foreach ($types as $idx => $t) {
  $stmt->bindValue(':type' . $idx, $t);
}
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();

$rows = attendance_attach_evidence_to_rows($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC));

echo json_encode([
  'success' => true,
  'rows' => $rows,
  'kelas' => $kelas,
  'from' => $from,
  'to' => $to,
  'server_time' => date('Y-m-d H:i:s')
]);
