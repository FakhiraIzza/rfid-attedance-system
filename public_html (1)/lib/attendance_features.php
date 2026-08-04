<?php

require_once __DIR__ . '/attendance_helpers.php';
require_once __DIR__ . '/student_promotion.php';

function attendance_manual_allowed_statuses(): array
{
  return [
    'Hadir Tepat Waktu',
    'Hadir Terlambat',
    'Alfa',
    'Izin',
    'Sakit',
  ];
}

function attendance_status_requires_evidence(string $status): bool
{
  $status = attendance_normalize_status($status);
  return in_array($status, ['Hadir Tepat Waktu', 'Hadir Terlambat'], true);
}

function attendance_message_placeholders(): array
{
  return [
    '{nama}' => 'Nama siswa',
    '{nis}' => 'NIS siswa',
    '{tanggal}' => 'Tanggal presensi',
    '{pukul}' => 'Jam presensi',
    '{status}' => 'Status presensi',
    '{catatan}' => 'Catatan tambahan',
  ];
}

function attendance_default_message_templates(): array
{
  return [
    [
      'id_template' => null,
      'title' => 'Template Default 1',
      'message_body' => "Bismillahirrahmanirrahim\nAssalamualaikum Ayah/Bunda.\nInfo presensi ananda:\n*{nama}*\nNIS: {nis}\nTanggal: {tanggal}\nPukul: {pukul}\nStatus: {status}",
      'is_active' => 1,
      'is_default' => 1,
    ],
    [
      'id_template' => null,
      'title' => 'Template Default 2',
      'message_body' => "Assalamualaikum Ayah/Bunda.\nInformasi presensi ananda:\n*{nama}*\nNIS: {nis}\nTanggal: {tanggal}\nPukul: {pukul}\nStatus: {status}",
      'is_active' => 1,
      'is_default' => 0,
    ],
    [
      'id_template' => null,
      'title' => 'Template Default 3',
      'message_body' => "Bismillahirrahmanirrahim\nAssalamualaikum Ayah/Bunda.\nPresensi ananda tercatat:\n*{nama}*\nNIS: {nis}\nTanggal: {tanggal}\nPukul: {pukul}\nStatus: {status}",
      'is_active' => 1,
      'is_default' => 0,
    ],
  ];
}

function attendance_templates_supported(PDO $pdo): bool
{
  return student_schema_has_table($pdo, 'message_templates');
}

function attendance_fetch_message_templates(PDO $pdo, bool $activeOnly = true): array
{
  if (!attendance_templates_supported($pdo)) {
    return attendance_default_message_templates();
  }

  $sql = "SELECT id_template, title, message_body, is_active, is_default, created_at, updated_at
          FROM message_templates";
  if ($activeOnly) {
    $sql .= " WHERE is_active = 1";
  }
  $sql .= " ORDER BY is_default DESC, id_template ASC";

  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) {
    return attendance_default_message_templates();
  }

  return $rows;
}

function attendance_next_template_index(PDO $pdo, int $templatesCount): int
{
  if ($templatesCount <= 0) {
    return 0;
  }

  $key = 'wa_message_counter';
  try {
    $upd = $pdo->prepare("UPDATE settings SET value = CAST(value AS UNSIGNED) + 1 WHERE `key` = ?");
    $upd->execute([$key]);

    if ($upd->rowCount() === 0) {
      $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?)")->execute([$key, '1']);
      $counter = 1;
    } else {
      $row = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
      $row->execute([$key]);
      $data = $row->fetch(PDO::FETCH_ASSOC);
      $counter = (int)($data['value'] ?? 1);
    }
  } catch (Throwable $e) {
    return random_int(0, $templatesCount - 1);
  }

  $batch = intdiv(max($counter, 1) - 1, 10);
  return $batch % $templatesCount;
}

function attendance_pick_message_template(PDO $pdo): array
{
  $templates = attendance_fetch_message_templates($pdo, true);
  $index = attendance_next_template_index($pdo, count($templates));
  return $templates[$index] ?? $templates[0] ?? attendance_default_message_templates()[0];
}

function attendance_render_message_template(string $body, array $context): string
{
  $normalizedBody = str_replace(["\r\n", "\r"], "\n", trim($body));
  $message = strtr($normalizedBody, [
    '{nama}' => (string)($context['nama'] ?? '-'),
    '{nis}' => (string)($context['nis'] ?? '-'),
    '{tanggal}' => (string)($context['tanggal'] ?? '-'),
    '{pukul}' => (string)($context['pukul'] ?? '-'),
    '{status}' => (string)($context['status'] ?? '-'),
    '{catatan}' => (string)($context['catatan'] ?? ''),
  ]);

  if ((string)($context['catatan'] ?? '') !== '' && !str_contains($normalizedBody, '{catatan}')) {
    $message .= "\nKeterangan: " . (string)$context['catatan'];
  }

  return trim($message);
}

function attendance_build_parent_message(
  PDO $pdo,
  string $nama,
  string $nis,
  string $tanggal,
  string $pukul,
  string $status,
  string $catatan
): string {
  $template = attendance_pick_message_template($pdo);
  $body = (string)($template['message_body'] ?? '');
  if ($body === '') {
    $body = attendance_default_message_templates()[0]['message_body'];
  }

  return attendance_render_message_template($body, [
    'nama' => $nama,
    'nis' => $nis !== '' ? $nis : '-',
    'tanggal' => $tanggal,
    'pukul' => $pukul,
    'status' => $status,
    'catatan' => $catatan,
  ]);
}

function attendance_evidence_supported(PDO $pdo): bool
{
  return student_schema_has_table($pdo, 'attendance_evidence');
}

function attendance_resolve_evidence_url(?string $path): ?string
{
  $path = trim((string)$path);
  if ($path === '') {
    return null;
  }

  if (function_exists('base_url')) {
    return base_url($path);
  }

  return $path;
}

function attendance_get_existing_evidence(PDO $pdo, int $attendanceId): ?array
{
  if ($attendanceId <= 0 || !attendance_evidence_supported($pdo)) {
    return null;
  }

  $stmt = $pdo->prepare("
    SELECT id_evidence, attendance_id, file_path, original_filename, mime_type, file_size,
           captured_at, evidence_note, uploaded_by_user_id, uploader_role, created_at, updated_at
    FROM attendance_evidence
    WHERE attendance_id = ?
    LIMIT 1
  ");
  $stmt->execute([$attendanceId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    return null;
  }
  $row['evidence_url'] = attendance_resolve_evidence_url((string)($row['file_path'] ?? ''));
  return $row;
}

function attendance_validate_evidence_upload(array $file): array
{
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return ['ok' => false, 'message' => 'Foto evidence belum dipilih.'];
  }

  if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    return ['ok' => false, 'message' => 'Upload foto evidence gagal.'];
  }

  $size = (int)($file['size'] ?? 0);
  if ($size <= 0) {
    return ['ok' => false, 'message' => 'File evidence tidak valid.'];
  }

  if ($size > 4 * 1024 * 1024) {
    return ['ok' => false, 'message' => 'Ukuran foto evidence maksimal 4 MB.'];
  }

  $originalName = (string)($file['name'] ?? 'evidence');
  $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
  $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
  if (!in_array($ext, $allowedExt, true)) {
    return ['ok' => false, 'message' => 'Format foto evidence harus JPG, PNG, atau WEBP.'];
  }

  $mime = '';
  if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
      $mime = (string)finfo_file($finfo, (string)($file['tmp_name'] ?? ''));
      finfo_close($finfo);
    }
  }

  $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
  if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
    return ['ok' => false, 'message' => 'Tipe file evidence tidak didukung.'];
  }

  return [
    'ok' => true,
    'ext' => $ext,
    'mime' => $mime !== '' ? $mime : null,
    'size' => $size,
    'original_name' => $originalName,
  ];
}

function attendance_store_evidence_upload(array $file): array
{
  $validation = attendance_validate_evidence_upload($file);
  if (!($validation['ok'] ?? false)) {
    return $validation;
  }

  $relativeDir = '/assets/uploads/attendance_evidence/' . date('Y/m');
  $absoluteDir = dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
  if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
    return ['ok' => false, 'message' => 'Folder evidence tidak dapat dibuat.'];
  }

  $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $validation['ext'];
  $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $filename;
  $relativePath = $relativeDir . '/' . $filename;

  if (!move_uploaded_file((string)$file['tmp_name'], $absolutePath)) {
    return ['ok' => false, 'message' => 'File evidence gagal disimpan.'];
  }

  return [
    'ok' => true,
    'file_path' => $relativePath,
    'original_filename' => $validation['original_name'],
    'mime_type' => $validation['mime'],
    'file_size' => $validation['size'],
  ];
}

function attendance_delete_evidence_file(?string $path): void
{
  $path = trim((string)$path);
  if ($path === '') {
    return;
  }

  $absolutePath = dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $path);
  if (is_file($absolutePath)) {
    @unlink($absolutePath);
  }
}

function attendance_upsert_evidence(
  PDO $pdo,
  int $attendanceId,
  array $file,
  ?string $capturedAt,
  ?int $uploadedByUserId,
  string $uploaderRole,
  ?string $evidenceNote = null
): array {
  if (!attendance_evidence_supported($pdo)) {
    return ['ok' => false, 'message' => 'Tabel attendance_evidence belum tersedia.'];
  }

  if ($attendanceId <= 0) {
    return ['ok' => false, 'message' => 'Data absensi untuk evidence tidak ditemukan.'];
  }

  $stored = attendance_store_evidence_upload($file);
  if (!($stored['ok'] ?? false)) {
    return $stored;
  }

  $existing = attendance_get_existing_evidence($pdo, $attendanceId);
  $stmt = $pdo->prepare("
    INSERT INTO attendance_evidence (
      attendance_id, file_path, original_filename, mime_type, file_size,
      captured_at, evidence_note, uploaded_by_user_id, uploader_role, created_at, updated_at
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
      file_path = VALUES(file_path),
      original_filename = VALUES(original_filename),
      mime_type = VALUES(mime_type),
      file_size = VALUES(file_size),
      captured_at = VALUES(captured_at),
      evidence_note = VALUES(evidence_note),
      uploaded_by_user_id = VALUES(uploaded_by_user_id),
      uploader_role = VALUES(uploader_role),
      updated_at = NOW()
  ");
  $stmt->execute([
    $attendanceId,
    $stored['file_path'],
    $stored['original_filename'],
    $stored['mime_type'],
    $stored['file_size'],
    $capturedAt !== '' ? $capturedAt : null,
    $evidenceNote !== '' ? $evidenceNote : null,
    $uploadedByUserId ?: null,
    $uploaderRole,
  ]);

  if ($existing && (string)($existing['file_path'] ?? '') !== (string)$stored['file_path']) {
    attendance_delete_evidence_file((string)$existing['file_path']);
  }

  return [
    'ok' => true,
    'file_path' => $stored['file_path'],
    'evidence_url' => attendance_resolve_evidence_url((string)$stored['file_path']),
  ];
}

function attendance_attach_evidence_to_rows(PDO $pdo, array $rows, string $attendanceKey = 'id_absensi'): array
{
  if (!$rows || !attendance_evidence_supported($pdo)) {
    return $rows;
  }

  $attendanceIds = [];
  foreach ($rows as $row) {
    $id = (int)($row[$attendanceKey] ?? 0);
    if ($id > 0) {
      $attendanceIds[$id] = $id;
    }
  }
  if (!$attendanceIds) {
    return $rows;
  }

  $placeholders = implode(',', array_fill(0, count($attendanceIds), '?'));
  $stmt = $pdo->prepare("
    SELECT attendance_id, file_path, original_filename, captured_at, evidence_note
    FROM attendance_evidence
    WHERE attendance_id IN ($placeholders)
  ");
  $stmt->execute(array_values($attendanceIds));

  $evidenceMap = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $evidence) {
    $attendanceId = (int)($evidence['attendance_id'] ?? 0);
    if ($attendanceId <= 0) {
      continue;
    }
    $evidence['evidence_url'] = attendance_resolve_evidence_url((string)($evidence['file_path'] ?? ''));
    $evidenceMap[$attendanceId] = $evidence;
  }

  foreach ($rows as &$row) {
    $attendanceId = (int)($row[$attendanceKey] ?? 0);
    $evidence = $attendanceId > 0 ? ($evidenceMap[$attendanceId] ?? null) : null;
    $row['evidence_path'] = $evidence['file_path'] ?? null;
    $row['evidence_url'] = $evidence['evidence_url'] ?? null;
    $row['evidence_original_filename'] = $evidence['original_filename'] ?? null;
    $row['evidence_captured_at'] = $evidence['captured_at'] ?? null;
    $row['evidence_note'] = $evidence['evidence_note'] ?? null;
  }
  unset($row);

  return $rows;
}
