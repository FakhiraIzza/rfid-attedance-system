<?php

function student_schema_has_column(PDO $pdo, string $table, string $column): bool
{
  static $cache = [];
  $key = $table . '.' . $column;
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }

  $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
  $stmt->execute([$column]);
  $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
  return $cache[$key];
}

function student_schema_has_table(PDO $pdo, string $table): bool
{
  static $cache = [];
  if (array_key_exists($table, $cache)) {
    return $cache[$table];
  }

  $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
  $stmt->execute([$table]);
  $cache[$table] = (bool)$stmt->fetch(PDO::FETCH_NUM);
  return $cache[$table];
}

function student_promotion_allowed_classes(): array
{
  return ['7', '8', '9'];
}

function student_promotion_allowed_statuses(): array
{
  return ['aktif', 'lulus', 'pindah', 'nonaktif'];
}

function student_status_expr(PDO $pdo, string $studentAlias = 's'): string
{
  if (!student_schema_has_column($pdo, 'students', 'status_siswa')) {
    return "'aktif'";
  }
  return "COALESCE(NULLIF($studentAlias.status_siswa, ''), 'aktif')";
}

function student_active_condition(PDO $pdo, string $studentAlias = 's'): string
{
  if (!student_schema_has_column($pdo, 'students', 'status_siswa')) {
    return '1=1';
  }
  return student_status_expr($pdo, $studentAlias) . " = 'aktif'";
}

function student_attendance_class_expr(PDO $pdo, string $attendanceAlias = 'a', string $studentAlias = 's'): string
{
  if (!student_schema_has_column($pdo, 'attendance', 'class_level_snapshot')) {
    return "$studentAlias.class_level";
  }
  return "COALESCE(NULLIF($attendanceAlias.class_level_snapshot, ''), $studentAlias.class_level)";
}

function student_default_academic_year(?int $timestamp = null): string
{
  $timestamp ??= time();
  $year = (int)date('Y', $timestamp);
  $month = (int)date('n', $timestamp);
  $startYear = $month >= 7 ? $year : ($year - 1);
  return $startYear . '/' . ($startYear + 1);
}

function student_normalize_class_level(?string $value): ?string
{
  $value = strtoupper(trim((string)$value));
  if ($value === '') {
    return null;
  }

  $map = [
    'KELAS 7' => '7',
    'VII' => '7',
    '7' => '7',
    'KELAS 8' => '8',
    'VIII' => '8',
    '8' => '8',
    'KELAS 9' => '9',
    'IX' => '9',
    '9' => '9',
  ];

  return $map[$value] ?? null;
}

function student_normalize_status(?string $value): ?string
{
  $value = strtolower(trim((string)$value));
  if ($value === '') {
    return null;
  }

  $map = [
    'aktif' => 'aktif',
    'active' => 'aktif',
    'lulus' => 'lulus',
    'graduated' => 'lulus',
    'alumni' => 'lulus',
    'pindah' => 'pindah',
    'mutasi' => 'pindah',
    'nonaktif' => 'nonaktif',
    'keluar' => 'nonaktif',
    'dropout' => 'nonaktif',
  ];

  return $map[$value] ?? null;
}

function student_normalize_phone(?string $value): string
{
  return preg_replace('/\D+/', '', (string)$value);
}

function student_normalize_uid(?string $value): string
{
  return strtoupper(trim((string)$value));
}

function student_normalize_header(string $value): string
{
  $value = strtolower(trim($value));
  $value = preg_replace('/[^a-z0-9]+/', '_', $value);
  return trim((string)$value, '_');
}

function student_header_alias_map(): array
{
  return [
    'nis' => 'nis',
    'nomor_induk_siswa' => 'nis',
    'nama' => 'nama_siswa',
    'nama_siswa' => 'nama_siswa',
    'kelas' => 'class_level',
    'kelas_baru' => 'class_level',
    'class_level' => 'class_level',
    'uid' => 'rfid_uid',
    'uid_rfid' => 'rfid_uid',
    'rfid_uid' => 'rfid_uid',
    'no_hp' => 'no_hp',
    'nomor_hp' => 'no_hp',
    'no_hp_ortu' => 'no_hp',
    'nomor_hp_ortu' => 'no_hp',
    'no_hp_orangtua' => 'no_hp',
    'status' => 'status_siswa',
    'status_siswa' => 'status_siswa',
    'catatan' => 'catatan',
    'keterangan' => 'catatan',
  ];
}

function student_excel_column_index(string $cellRef): int
{
  $letters = preg_replace('/[^A-Z]/', '', strtoupper($cellRef));
  $index = 0;
  $len = strlen($letters);
  for ($i = 0; $i < $len; $i++) {
    $index = ($index * 26) + (ord($letters[$i]) - 64);
  }
  return max($index - 1, 0);
}

function student_parse_xlsx_matrix(string $path): array
{
  if (!class_exists('ZipArchive')) {
    throw new RuntimeException('Ekstensi ZipArchive tidak tersedia. Gunakan file CSV.');
  }

  $zip = new ZipArchive();
  if ($zip->open($path) !== true) {
    throw new RuntimeException('File XLSX tidak dapat dibuka.');
  }

  $sharedStrings = [];
  $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
  if ($sharedXml !== false) {
    $sx = simplexml_load_string($sharedXml);
    if ($sx instanceof SimpleXMLElement) {
      $sx->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
      $items = $sx->xpath('//a:si');
      if (is_array($items)) {
        foreach ($items as $item) {
          $texts = $item->xpath('.//a:t');
          $parts = [];
          if (is_array($texts)) {
            foreach ($texts as $textNode) {
              $parts[] = (string)$textNode;
            }
          }
          $sharedStrings[] = implode('', $parts);
        }
      }
    }
  }

  $sheetPath = 'xl/worksheets/sheet1.xml';
  $workbookRels = $zip->getFromName('xl/_rels/workbook.xml.rels');
  $workbookXml = $zip->getFromName('xl/workbook.xml');
  if ($workbookXml !== false && $workbookRels !== false) {
    $wb = simplexml_load_string($workbookXml);
    $rels = simplexml_load_string($workbookRels);
    if ($wb instanceof SimpleXMLElement && $rels instanceof SimpleXMLElement) {
      $wb->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
      $rels->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
      $sheet = $wb->xpath('//a:sheets/a:sheet[1]');
      $relId = is_array($sheet) && isset($sheet[0]) ? (string)$sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id : '';
      if ($relId !== '') {
        $relNodes = $rels->xpath("//r:Relationship[@Id='$relId']");
        if (is_array($relNodes) && isset($relNodes[0])) {
          $target = (string)$relNodes[0]['Target'];
          if ($target !== '') {
            $target = ltrim(str_replace('\\', '/', $target), '/');
            $sheetPath = str_starts_with($target, 'xl/') ? $target : ('xl/' . $target);
          }
        }
      }
    }
  }

  $sheetXml = $zip->getFromName($sheetPath);
  $zip->close();

  if ($sheetXml === false) {
    throw new RuntimeException('Worksheet pertama pada file XLSX tidak ditemukan.');
  }

  $xml = simplexml_load_string($sheetXml);
  if (!($xml instanceof SimpleXMLElement)) {
    throw new RuntimeException('Worksheet XLSX tidak valid.');
  }
  $xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
  $rows = [];
  $rowNodes = $xml->xpath('//a:sheetData/a:row');
  if (!is_array($rowNodes)) {
    return [];
  }

  foreach ($rowNodes as $rowNode) {
    $row = [];
    $cells = $rowNode->xpath('./a:c');
    if (!is_array($cells)) {
      continue;
    }
    foreach ($cells as $cell) {
      $ref = (string)$cell['r'];
      $idx = student_excel_column_index($ref);
      $type = (string)$cell['t'];
      $value = '';

      if ($type === 'inlineStr') {
        $parts = $cell->xpath('./a:is/a:t');
        if (is_array($parts)) {
          $tmp = [];
          foreach ($parts as $part) {
            $tmp[] = (string)$part;
          }
          $value = implode('', $tmp);
        }
      } else {
        $v = $cell->xpath('./a:v');
        $raw = is_array($v) && isset($v[0]) ? (string)$v[0] : '';
        if ($type === 's') {
          $value = $sharedStrings[(int)$raw] ?? '';
        } else {
          $value = $raw;
        }
      }

      $row[$idx] = trim((string)$value);
    }
    if ($row !== []) {
      ksort($row);
      $rows[] = $row;
    }
  }

  return $rows;
}

function student_detect_csv_delimiter(string $line): string
{
  $candidates = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
  arsort($candidates);
  $delimiter = array_key_first($candidates);
  return is_string($delimiter) ? $delimiter : ';';
}

function student_parse_csv_matrix(string $path): array
{
  $handle = fopen($path, 'rb');
  if ($handle === false) {
    throw new RuntimeException('File CSV tidak dapat dibuka.');
  }

  $firstLine = fgets($handle);
  if ($firstLine === false) {
    fclose($handle);
    return [];
  }
  $delimiter = student_detect_csv_delimiter($firstLine);
  rewind($handle);

  $rows = [];
  while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
    if ($data === [null]) {
      continue;
    }
    $clean = [];
    foreach ($data as $index => $value) {
      if ($index === 0) {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', (string)$value);
      }
      $clean[$index] = trim((string)$value);
    }
    $rows[] = $clean;
  }
  fclose($handle);
  return $rows;
}

function student_parse_spreadsheet_rows(string $path, string $originalName): array
{
  $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
  if ($ext === 'xlsx') {
    $matrix = student_parse_xlsx_matrix($path);
  } elseif ($ext === 'csv') {
    $matrix = student_parse_csv_matrix($path);
  } else {
    throw new RuntimeException('Format file harus .xlsx atau .csv.');
  }

  $headerMap = student_header_alias_map();
  $headerIndex = null;
  $rows = [];

  foreach ($matrix as $matrixRow) {
    $values = array_values($matrixRow);
    if ($headerIndex === null) {
      $candidate = [];
      foreach ($values as $idx => $value) {
        $normalized = student_normalize_header((string)$value);
        if (isset($headerMap[$normalized])) {
          $candidate[$idx] = $headerMap[$normalized];
        }
      }
      if (!in_array('nis', $candidate, true)) {
        continue;
      }
      $headerIndex = $candidate;
      continue;
    }

    $row = [
      'nis' => '',
      'nama_siswa' => '',
      'class_level' => '',
      'rfid_uid' => '',
      'no_hp' => '',
      'status_siswa' => '',
      'catatan' => '',
    ];
    foreach ($headerIndex as $idx => $field) {
      $row[$field] = trim((string)($values[$idx] ?? ''));
    }
    if (implode('', $row) === '') {
      continue;
    }
    $rows[] = $row;
  }

  if ($headerIndex === null) {
    throw new RuntimeException('Header file tidak valid. Kolom NIS wajib ada.');
  }

  return $rows;
}

function student_build_import_preview(PDO $pdo, array $rows, string $academicYear): array
{
  $prepared = [];
  $summary = ['total' => count($rows), 'ready' => 0, 'error' => 0, 'noop' => 0];
  $statusExpr = student_status_expr($pdo, 's');
  $supportsStatus = student_schema_has_column($pdo, 'students', 'status_siswa');

  $nisCounts = [];
  $uidTargets = [];
  foreach ($rows as $row) {
    $nis = trim((string)($row['nis'] ?? ''));
    if ($nis !== '') {
      $nisCounts[$nis] = ($nisCounts[$nis] ?? 0) + 1;
    }
    $uid = student_normalize_uid($row['rfid_uid'] ?? '');
    if ($uid !== '') {
      $uidTargets[$uid] = true;
    }
  }

  $studentsByNis = [];
  if ($nisCounts !== []) {
    $placeholders = implode(',', array_fill(0, count($nisCounts), '?'));
    $stmt = $pdo->prepare("
      SELECT s.id_siswa, s.nis, s.nama_siswa, s.class_level, s.rfid_uid, s.id_orangtua, p.no_hp,
             $statusExpr AS status_siswa
      FROM students s
      LEFT JOIN parents p ON p.id_orangtua = s.id_orangtua
      WHERE s.nis IN ($placeholders)
    ");
    $stmt->execute(array_keys($nisCounts));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
      $studentsByNis[$student['nis']][] = $student;
    }
  }

  $uidOwners = [];
  if ($uidTargets !== []) {
    $placeholders = implode(',', array_fill(0, count($uidTargets), '?'));
    $stmtUid = $pdo->prepare("SELECT id_siswa, rfid_uid FROM students WHERE rfid_uid IN ($placeholders)");
    $stmtUid->execute(array_keys($uidTargets));
    foreach ($stmtUid->fetchAll(PDO::FETCH_ASSOC) as $owner) {
      $uidOwners[$owner['rfid_uid']] = (int)$owner['id_siswa'];
    }
  }

  foreach ($rows as $index => $row) {
    $rowNumber = $index + 2;
    $nis = trim((string)($row['nis'] ?? ''));
    $messages = [];
    $status = 'ready';
    $actionParts = [];

    if ($nis === '') {
      $prepared[] = [
        'row_number' => $rowNumber,
        'nis' => '',
        'status' => 'error',
        'messages' => ['NIS wajib diisi.'],
      ];
      $summary['error']++;
      continue;
    }

    if (($nisCounts[$nis] ?? 0) > 1) {
      $messages[] = 'NIS duplikat di file impor.';
      $status = 'error';
    }

    $dbMatches = $studentsByNis[$nis] ?? [];
    if ($dbMatches === []) {
      $messages[] = 'NIS tidak ditemukan di database.';
      $status = 'error';
    }
    if (count($dbMatches) > 1) {
      $messages[] = 'NIS ganda di database. Rapikan data siswa dulu.';
      $status = 'error';
    }

    $student = $dbMatches[0] ?? null;
    $currentClass = $student['class_level'] ?? '';
    $currentStatus = $student['status_siswa'] ?? 'aktif';
    $currentUid = $student['rfid_uid'] ?? '';
    $currentPhone = $student['no_hp'] ?? '';

    $targetClassInput = student_normalize_class_level($row['class_level'] ?? '');
    $targetStatusInput = student_normalize_status($row['status_siswa'] ?? '');
    $targetStatus = $targetStatusInput ?? $currentStatus;
    $targetClass = $targetClassInput ?? $currentClass;
    $targetUid = student_normalize_uid($row['rfid_uid'] ?? '');
    if ($targetUid === '') {
      $targetUid = $currentUid;
    }
    $targetPhone = student_normalize_phone($row['no_hp'] ?? '');
    $targetName = trim((string)($row['nama_siswa'] ?? ''));
    if ($targetName === '') {
      $targetName = (string)($student['nama_siswa'] ?? '');
    }

    if ($targetStatus === 'aktif' && $targetClass === '') {
      $messages[] = 'Kelas baru wajib diisi untuk siswa aktif.';
      $status = 'error';
    }
    if (!$supportsStatus && ($row['status_siswa'] ?? '') !== '') {
      $messages[] = 'Kolom status_siswa di database belum ada. Jalankan migrasi dulu.';
      $status = 'error';
    }
    if (($row['status_siswa'] ?? '') !== '' && $targetStatusInput === null) {
      $messages[] = 'Status siswa tidak dikenali.';
      $status = 'error';
    }
    if ($targetStatusInput !== null && !in_array($targetStatusInput, student_promotion_allowed_statuses(), true)) {
      $messages[] = 'Status siswa tidak valid.';
      $status = 'error';
    }
    if (($row['class_level'] ?? '') !== '' && $targetClassInput === null) {
      $messages[] = 'Kelas baru hanya boleh 7, 8, atau 9.';
      $status = 'error';
    }
    if ($student && $targetUid !== '' && isset($uidOwners[$targetUid]) && $uidOwners[$targetUid] !== (int)$student['id_siswa']) {
      $messages[] = 'UID RFID sudah dipakai siswa lain.';
      $status = 'error';
    }

    if ($student) {
      if ($targetName !== (string)$student['nama_siswa']) {
        $actionParts[] = 'nama';
      }
      if ($targetClass !== $currentClass) {
        $actionParts[] = 'kelas ' . $currentClass . ' -> ' . $targetClass;
      }
      if ($targetStatus !== $currentStatus) {
        $actionParts[] = 'status ' . $currentStatus . ' -> ' . $targetStatus;
      }
      if ($targetUid !== (string)$currentUid) {
        $actionParts[] = 'UID RFID';
      }
      if ($targetPhone !== '' && $targetPhone !== student_normalize_phone($currentPhone)) {
        $actionParts[] = 'no HP ortu';
      }
    }

    if ($status !== 'error' && $actionParts === []) {
      $status = 'noop';
      $messages[] = 'Tidak ada perubahan data.';
    }

    $prepared[] = [
      'row_number' => $rowNumber,
      'student_id' => isset($student['id_siswa']) ? (int)$student['id_siswa'] : 0,
      'nis' => $nis,
      'student_name' => $student['nama_siswa'] ?? '',
      'current_class' => $currentClass,
      'target_class' => $targetClass,
      'current_status' => $currentStatus,
      'target_status' => $targetStatus,
      'current_uid' => $currentUid,
      'target_uid' => $targetUid,
      'current_phone' => $currentPhone,
      'target_phone' => $targetPhone,
      'target_name' => $targetName,
      'catatan' => trim((string)($row['catatan'] ?? '')),
      'status' => $status,
      'action_summary' => $actionParts !== [] ? implode(', ', $actionParts) : '-',
      'messages' => $messages,
      'academic_year' => $academicYear,
    ];

    $summary[$status]++;
  }

  return [
    'academic_year' => $academicYear,
    'rows' => $prepared,
    'summary' => $summary,
    'generated_at' => date('c'),
  ];
}

function student_sync_parent_phone(PDO $pdo, int $studentId, string $studentName, ?int $parentId, string $phone): void
{
  if ($phone === '') {
    return;
  }

  if (($parentId ?? 0) > 0) {
    $pdo->prepare("UPDATE parents SET no_hp=? WHERE id_orangtua=?")
      ->execute([$phone, $parentId]);
    return;
  }

  $pdo->prepare("INSERT INTO parents (nama_orangtua, no_hp) VALUES (?, ?)")
    ->execute(['Orang Tua ' . $studentName, $phone]);
  $newParentId = (int)$pdo->lastInsertId();
  $pdo->prepare("UPDATE students SET id_orangtua=? WHERE id_siswa=?")
    ->execute([$newParentId, $studentId]);
}

function student_sync_rfid_card(PDO $pdo, int $studentId, string $oldUid, string $newUid): void
{
  if ($oldUid === $newUid) {
    return;
  }

  if ($oldUid !== '') {
    $pdo->prepare("DELETE FROM rfid_cards WHERE rfid_uid=? OR assigned_student_id=?")
      ->execute([$oldUid, $studentId]);
  }

  if ($newUid === '') {
    return;
  }

  $check = $pdo->prepare("SELECT id_kartu FROM rfid_cards WHERE rfid_uid=? LIMIT 1");
  $check->execute([$newUid]);
  if ($check->fetch(PDO::FETCH_ASSOC)) {
    $pdo->prepare("UPDATE rfid_cards SET status_kartu='Aktif', assigned_student_id=? WHERE rfid_uid=?")
      ->execute([$studentId, $newUid]);
  } else {
    $pdo->prepare("INSERT INTO rfid_cards (rfid_uid, status_kartu, assigned_student_id) VALUES (?, 'Aktif', ?)")
      ->execute([$newUid, $studentId]);
  }
}

function student_insert_class_history(
  PDO $pdo,
  int $studentId,
  string $academicYear,
  string $oldClass,
  string $newClass,
  string $oldStatus,
  string $newStatus,
  string $notes,
  int $userId,
  string $actorName
): void {
  if (!student_schema_has_table($pdo, 'student_class_history')) {
    return;
  }

  $changeType = 'bulk_import';
  if ($oldStatus !== $newStatus && $newStatus !== 'aktif') {
    $changeType = 'status_update';
  } elseif ($oldClass !== $newClass) {
    $changeType = 'promotion';
  }

  $pdo->prepare("
    INSERT INTO student_class_history (
      id_siswa, academic_year, class_from, class_to, status_from, status_to,
      change_type, notes, changed_by_user_id, changed_by_name, changed_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
  ")->execute([
    $studentId,
    $academicYear,
    $oldClass !== '' ? $oldClass : null,
    $newClass !== '' ? $newClass : null,
    $oldStatus,
    $newStatus,
    $changeType,
    $notes !== '' ? $notes : null,
    $userId > 0 ? $userId : null,
    $actorName !== '' ? $actorName : null,
  ]);
}

function student_insert_import_log(PDO $pdo, array $payload): void
{
  if (!student_schema_has_table($pdo, 'student_bulk_import_logs')) {
    return;
  }

  $pdo->prepare("
    INSERT INTO student_bulk_import_logs (
      file_name, academic_year, total_rows, processed_rows, skipped_rows, failed_rows,
      imported_by_user_id, imported_by_name, import_summary, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
  ")->execute([
    $payload['file_name'] ?? '',
    $payload['academic_year'] ?? '',
    (int)($payload['total_rows'] ?? 0),
    (int)($payload['processed_rows'] ?? 0),
    (int)($payload['skipped_rows'] ?? 0),
    (int)($payload['failed_rows'] ?? 0),
    !empty($payload['imported_by_user_id']) ? (int)$payload['imported_by_user_id'] : null,
    $payload['imported_by_name'] ?? null,
    json_encode($payload['summary'] ?? [], JSON_UNESCAPED_UNICODE),
  ]);
}

function student_apply_import_preview(PDO $pdo, array $preview, array $actor, string $fileName): array
{
  $processed = 0;
  $skipped = 0;
  $failed = 0;
  $details = [];
  $supportsStatus = student_schema_has_column($pdo, 'students', 'status_siswa');

  $pdo->beginTransaction();
  try {
    foreach (($preview['rows'] ?? []) as $row) {
      if (($row['status'] ?? '') === 'noop') {
        $skipped++;
        continue;
      }
      if (($row['status'] ?? '') !== 'ready') {
        $failed++;
        continue;
      }

      $studentId = (int)($row['student_id'] ?? 0);
      $stmt = $pdo->prepare("
        SELECT id_siswa, nama_siswa, class_level, rfid_uid, id_orangtua
        " . ($supportsStatus ? ", COALESCE(NULLIF(status_siswa, ''), 'aktif') AS status_siswa" : ", 'aktif' AS status_siswa") . "
        FROM students
        WHERE id_siswa=?
        LIMIT 1
      ");
      $stmt->execute([$studentId]);
      $current = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$current) {
        $failed++;
        continue;
      }

      $newName = trim((string)($row['target_name'] ?? $current['nama_siswa']));
      $newClass = trim((string)($row['target_class'] ?? $current['class_level']));
      $newStatus = trim((string)($row['target_status'] ?? ($current['status_siswa'] ?? 'aktif')));
      $newUid = student_normalize_uid($row['target_uid'] ?? $current['rfid_uid'] ?? '');
      $newPhone = student_normalize_phone($row['target_phone'] ?? '');

      if ($supportsStatus) {
        $pdo->prepare("UPDATE students SET nama_siswa=?, class_level=?, status_siswa=?, rfid_uid=? WHERE id_siswa=?")
          ->execute([$newName, $newClass, $newStatus, $newUid !== '' ? $newUid : null, $studentId]);
      } else {
        $pdo->prepare("UPDATE students SET nama_siswa=?, class_level=?, rfid_uid=? WHERE id_siswa=?")
          ->execute([$newName, $newClass, $newUid !== '' ? $newUid : null, $studentId]);
      }

      student_sync_rfid_card($pdo, $studentId, (string)($current['rfid_uid'] ?? ''), $newUid);
      student_sync_parent_phone($pdo, $studentId, $newName, isset($current['id_orangtua']) ? (int)$current['id_orangtua'] : null, $newPhone);

      if (
        (string)$current['class_level'] !== $newClass ||
        (string)($current['status_siswa'] ?? 'aktif') !== $newStatus
      ) {
        student_insert_class_history(
          $pdo,
          $studentId,
          (string)($preview['academic_year'] ?? ''),
          (string)$current['class_level'],
          $newClass,
          (string)($current['status_siswa'] ?? 'aktif'),
          $newStatus,
          (string)($row['catatan'] ?? ''),
          (int)($actor['id_user'] ?? 0),
          (string)($actor['nama_lengkap'] ?? '')
        );
      }

      $processed++;
      $details[] = [
        'nis' => $row['nis'] ?? '',
        'nama' => $newName,
        'kelas' => $newClass,
        'status' => $newStatus,
      ];
    }

    student_insert_import_log($pdo, [
      'file_name' => $fileName,
      'academic_year' => $preview['academic_year'] ?? '',
      'total_rows' => $preview['summary']['total'] ?? 0,
      'processed_rows' => $processed,
      'skipped_rows' => $skipped,
      'failed_rows' => $failed,
      'imported_by_user_id' => $actor['id_user'] ?? null,
      'imported_by_name' => $actor['nama_lengkap'] ?? null,
      'summary' => [
        'processed' => $processed,
        'skipped' => $skipped,
        'failed' => $failed,
        'details' => $details,
      ],
    ]);

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  return [
    'processed' => $processed,
    'skipped' => $skipped,
    'failed' => $failed,
  ];
}
