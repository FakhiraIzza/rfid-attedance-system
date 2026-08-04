<?php
// ======================================================
// API ABSENSI untuk NodeMCU (ESP8266)
// Endpoint: POST /presensi_web/api/absensi.php
// Header  : X-Api-Key: <api_key>
// Body    : {"device_id":"...","uid":"A1B2C3D4","client_time":"2025-12-14 06:45:00"}
// ======================================================

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json; charset=utf-8');

// Matikan warning agar response tetap JSON bersih
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/attendance_features.php';
require_once __DIR__ . '/../lib/student_promotion.php';

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

function logWaTime(PDO $pdo, string $uid, float $elapsedMs): void {
  $stmt = $pdo->prepare("INSERT INTO wa_log (uid, wa_time_ms, created_at) VALUES (?, ?, NOW())");
  $stmt->execute([$uid, $elapsedMs]);
}

$API_KEY = getenv('RFID_API_KEY') ?: '';

if ($API_KEY === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'API key belum dikonfigurasi'
    ]);
    exit;
}

// ====== AUTH API KEY ======
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!hash_equals($API_KEY, $apiKey)) {
  http_response_code(401);
  echo json_encode(['success'=>false,'status'=>'Unauthorized','message'=>'Unauthorized']);
  exit;
}

// ====== BACA JSON ======
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$uid       = isset($data['uid']) ? strtoupper(trim($data['uid'])) : '';
$deviceId  = isset($data['device_id']) ? trim($data['device_id']) : '';
$clientTime= isset($data['client_time']) ? trim($data['client_time']) : '';

if ($uid === '') {
  http_response_code(400);
  echo json_encode(['success'=>false,'status'=>'Bad Request','message'=>'UID wajib diisi']);
  exit;
}

$pdo = db();

// ====== helper: settings ======
function getSetting(PDO $pdo, string $key, string $default): string {
  $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=? LIMIT 1");
  $s->execute([$key]);
  $r = $s->fetch(PDO::FETCH_ASSOC);
  return $r ? (string)$r['value'] : $default;
}

// ====== helper: log ======
function logSync(PDO $pdo, string $deviceId, string $status, string $msg): void {
  if ($deviceId === '') $deviceId = '-';
  $log = $pdo->prepare("INSERT INTO sync_logs (device_id, waktu, status_sinkron, pesan_log)
                        VALUES (?, NOW(), ?, ?)");
  $log->execute([$deviceId, $status, $msg]);
}

// ====== Tentukan waktu scan ======
$serverNow = date('Y-m-d H:i:s');
$scanAt = null;
if ($clientTime !== '' && preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $clientTime)) {
  $scanAt = $clientTime;
} else {
  $scanAt = $serverNow;
}

// Jika jam alat melenceng jauh atau beda tanggal, pakai waktu server.
$maxSkewSeconds = 600;
$scanTs = strtotime($scanAt);
$serverTs = strtotime($serverNow);
if ($scanTs === false || $serverTs === false) {
  $scanAt = $serverNow;
} else {
  $skewSeconds = abs($scanTs - $serverTs);
  $scanDate = date('Y-m-d', $scanTs);
  $serverDate = date('Y-m-d', $serverTs);
  if ($skewSeconds > $maxSkewSeconds || $scanDate !== $serverDate) {
    $scanAt = $serverNow;
  }
}

$time    = date('H:i:s', strtotime($scanAt));
$tanggal = date('Y-m-d', strtotime($scanAt));

// ====== 1) CEK KARTU di rfid_cards ======
$stmt = $pdo->prepare("SELECT id_kartu, rfid_uid, status_kartu, assigned_student_id
                       FROM rfid_cards WHERE rfid_uid=? LIMIT 1");
$stmt->execute([$uid]);
$card = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$card) {
  logSync($pdo, $deviceId, 'Gagal', "UID tidak terdaftar: {$uid}");
  http_response_code(200);
  echo json_encode([
    'success'=>false,
    'status'=>'Kartu belum terdaftar, hubungi admin',
    'message'=>'Kartu belum terdaftar'
  ]);
  exit;
}

if (($card['status_kartu'] ?? '') !== 'Aktif') {
  logSync($pdo, $deviceId, 'Gagal', "Kartu tidak aktif: {$uid}");
  http_response_code(200);
  echo json_encode([
    'success'=>false,
    'status'=>'Kartu tidak aktif, hubungi admin',
    'message'=>'Kartu tidak aktif'
  ]);
  exit;
}

$studentId = (int)($card['assigned_student_id'] ?? 0);
if ($studentId <= 0) {
  logSync($pdo, $deviceId, 'Gagal', "Kartu belum di-assign ke siswa: {$uid}");
  http_response_code(200);
  echo json_encode([
    'success'=>false,
    'status'=>'Kartu belum di-assign ke siswa, hubungi admin',
    'message'=>'Kartu belum di-assign'
  ]);
  exit;
}

// ====== 2) AMBIL DATA SISWA ======
$stmt = $pdo->prepare("
  SELECT s.id_siswa, s.nama_siswa, s.class_level, s.nis, p.no_hp,
         " . student_status_expr($pdo, 's') . " AS status_siswa
  FROM students s
  LEFT JOIN parents p ON p.id_orangtua = s.id_orangtua
  WHERE s.id_siswa=? LIMIT 1
");
$stmt->execute([$studentId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
  logSync($pdo, $deviceId, 'Gagal', "Data siswa tidak ditemukan untuk UID {$uid} (id_siswa={$studentId})");
  http_response_code(200);
  echo json_encode([
    'success'=>false,
    'status'=>'Data siswa tidak ditemukan, hubungi admin',
    'message'=>'Data siswa tidak ditemukan'
  ]);
  exit;
}

if (($student['status_siswa'] ?? 'aktif') !== 'aktif') {
  logSync($pdo, $deviceId, 'Gagal', "Siswa tidak aktif untuk UID {$uid} (id_siswa={$studentId})");
  http_response_code(200);
  echo json_encode([
    'success'=>false,
    'status'=>'Siswa tidak aktif, hubungi admin',
    'message'=>'Siswa tidak aktif'
  ]);
  exit;
}

// ====== 3) RULE JAM ABSENSI (dari settings) ======
$masukMulai      = getSetting($pdo,'jam_masuk_mulai','06:30:00');
$tepatAkhir      = getSetting($pdo,'jam_masuk_tepat_akhir','07:15:00');
$terlambatAkhir  = getSetting($pdo,'jam_masuk_terlambat_akhir','08:00:00');
$pulangMulai     = getSetting($pdo,'jam_pulang_mulai','13:00:00');
$pulangAkhir     = getSetting($pdo,'jam_pulang_akhir','15:00:00');

// tentukan type + status
$type = null;
$status = null;

if ($time >= $masukMulai && $time <= $tepatAkhir) {
  $type = 'MASUK';
  $status = 'Hadir Tepat Waktu';
} elseif ($time > $tepatAkhir && $time <= $terlambatAkhir) {
  $type = 'MASUK';
  $status = 'Hadir Terlambat';
} elseif ($time > $terlambatAkhir && $time < $pulangMulai) {
  $type = 'MASUK';
  $status = 'Hadir Terlambat';
} elseif ($time >= $pulangMulai && $time <= $pulangAkhir) {
  $type = 'PULANG';
  $status = 'Pulang';
} else {
  // Di luar jam => tidak simpan
  logSync($pdo, $deviceId, 'Online', "Di luar jam absensi: {$uid} @ {$scanAt}");
  http_response_code(200);
  echo json_encode([
    'success'=>false,
    'nama'=>$student['nama_siswa'],
    'kelas'=>$student['class_level'],
    'status'=>'Belum waktu absensi',
    'waktu'=>$scanAt,
    'message'=>'Di luar jam absensi'
  ]);
  exit;
}

// ====== 4) CEK DUPLIKAT (hindari tap ganda) ======
$alreadyStatus = ($type === 'MASUK') ? 'Sudah absen masuk' : 'Sudah absen pulang';
$chk = $pdo->prepare("SELECT 1 FROM attendance WHERE id_siswa=? AND tanggal=? AND type=? LIMIT 1");
$chk->execute([(int)$student['id_siswa'], $tanggal, $type]);
if ($chk->fetch(PDO::FETCH_ASSOC)) {
  logSync($pdo, $deviceId, 'Online', "Duplikat presensi {$uid} {$tanggal} {$type}");
  http_response_code(200);
  echo json_encode([
    'success'=>false,
    'nama'=>$student['nama_siswa'],
    'kelas'=>$student['class_level'],
    'status'=>$alreadyStatus,
    'waktu'=>$scanAt,
    'type'=>$type
  ]);
  exit;
}

// ====== 5) INSERT ATTENDANCE ======
// Pastikan tabel attendance punya UNIQUE untuk (id_siswa, tanggal, type) agar tidak dobel.
// Jika belum, buat: UNIQUE KEY uniq_att (id_siswa, tanggal, type)
try {
  if (student_schema_has_column($pdo, 'attendance', 'class_level_snapshot')) {
    $ins = $pdo->prepare("INSERT INTO attendance (id_siswa, scan_at, tanggal, type, status, class_level_snapshot, source)
                          VALUES (?, ?, ?, ?, ?, ?, 'device')");
    $ins->execute([(int)$student['id_siswa'], $scanAt, $tanggal, $type, $status, (string)$student['class_level']]);
  } else {
    $ins = $pdo->prepare("INSERT INTO attendance (id_siswa, scan_at, tanggal, type, status, source)
                          VALUES (?, ?, ?, ?, ?, 'device')");
    $ins->execute([(int)$student['id_siswa'], $scanAt, $tanggal, $type, $status]);
  }

  logSync($pdo, $deviceId, 'Tersinkron', "OK {$uid} {$type} {$status} @ {$scanAt}");
} catch (Throwable $e) {
  // kemungkinan duplikat
  logSync($pdo, $deviceId, 'Online', "Duplikat presensi {$uid} {$tanggal} {$type}");
  http_response_code(200);
  echo json_encode([
    'success'=>false,
    'nama'=>$student['nama_siswa'],
    'kelas'=>$student['class_level'],
    'status'=>$alreadyStatus,
    'waktu'=>$scanAt,
    'type'=>$type
  ]);
  exit;
}

// ====== 6) NOTIF WA ORANG TUA ======
$waCfg = wa_cfg();
$target = wa_normalize_phone((string)($student['no_hp'] ?? ''));
if ($target !== '' && ($waCfg['enabled'] ?? false)) {
  $nama = (string)($student['nama_siswa'] ?? '');
  $nis = (string)($student['nis'] ?? '');
  $tanggalMsg = date('Y-m-d', strtotime($scanAt));
  $pukulMsg = date('H.i', strtotime($scanAt));
  $statusMsg = wa_status_label($type, $status);
  $message = attendance_build_parent_message($pdo, $nama, $nis, $tanggalMsg, $pukulMsg, $statusMsg, '');

  $waStart = microtime(true);
  [$waOk, $waInfo] = wa_send_fonnte($target, $message, $waCfg);
  $waEnd = microtime(true);
  $waTimeMs = ($waEnd - $waStart) * 1000;
  logWaTime($pdo, $uid, $waTimeMs);
  if (!$waOk) {
    logSync($pdo, $deviceId, 'Online', "WA gagal {$uid} {$target} {$waInfo}");
  }
}

// ====== 6) RESPONSE ======
http_response_code(200);
echo json_encode([
  'success'=>true,
  'nama'=>$student['nama_siswa'],
  'kelas'=>$student['class_level'],
  'status'=>$status,
  'waktu'=>$scanAt,
  'type'=>$type
]);
exit;
