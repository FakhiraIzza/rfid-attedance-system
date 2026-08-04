<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/attendance_features.php';

require_login(); // atau require_role('admin') kalau khusus admin

$pdo = db();
$limit = (int)($_GET['limit'] ?? 20);
if ($limit < 1 || $limit > 100) $limit = 20;

$class = $_GET['class_level'] ?? '';
if (!in_array($class, ['7','8','9'], true)) $class = '';

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-t');

$sql = "SELECT a.id_absensi, a.id_siswa, a.tanggal, a.scan_at, a.type, a.status, a.catatan,
               s.nama_siswa, s.class_level
        FROM attendance a
        JOIN students s ON s.id_siswa = a.id_siswa
        WHERE a.tanggal BETWEEN :from AND :to
          AND (
            a.type IN ('MASUK','TIDAK MASUK','PULANG')
            OR LOWER(TRIM(a.status)) IN ('izin','ijin','sakit','alfa','alpha')
          )
        " . ($class !== '' ? "AND s.class_level = :kelas" : "") . "
        ORDER BY a.scan_at DESC
        LIMIT :lim";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':from', $from);
$stmt->bindValue(':to', $to);
if ($class !== '') $stmt->bindValue(':kelas', $class);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();

$rows = attendance_attach_evidence_to_rows($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC));

echo json_encode([
  'success' => true,
  'rows' => $rows,
  'server_time' => date('Y-m-d H:i:s')
]);
