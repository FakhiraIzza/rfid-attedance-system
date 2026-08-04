<?php

function attendance_normalize_status(string $status): string
{
  $status = preg_replace('/\s+/', ' ', trim($status));
  $key = strtolower($status);
  if ($key === '') return '';

  $map = [
    'hadir tepat waktu' => 'Hadir Tepat Waktu',
    'hadir terlambat' => 'Hadir Terlambat',
    'izin' => 'Izin',
    'ijin' => 'Izin',
    'sakit' => 'Sakit',
    'alfa' => 'Alfa',
    'alpha' => 'Alfa',
    'pulang' => 'Pulang',
  ];

  return $map[$key] ?? $status;
}

function attendance_storage_type_for_status(string $status): string
{
  $normalized = attendance_normalize_status($status);
  return $normalized === 'Pulang' ? 'PULANG' : 'MASUK';
}

function attendance_effective_bucket(?string $type, ?string $status): string
{
  $type = strtoupper(trim((string)$type));
  $status = strtolower(trim((string)$status));

  if ($type === 'PULANG' || $status === 'pulang') {
    return 'pulang';
  }
  if (in_array($status, ['izin', 'ijin', 'sakit', 'alfa', 'alpha'], true)) {
    return 'tidak_masuk';
  }
  if (in_array($status, ['hadir tepat waktu', 'hadir terlambat'], true)) {
    return 'masuk';
  }
  if ($type === 'TIDAK MASUK') {
    return 'tidak_masuk';
  }
  if ($type === 'MASUK') {
    return 'masuk';
  }

  return '';
}

function attendance_display_type(?string $type, ?string $status): string
{
  $bucket = attendance_effective_bucket($type, $status);
  if ($bucket === 'pulang') return 'PULANG';
  if ($bucket === 'tidak_masuk') return 'TIDAK MASUK';
  if ($bucket === 'masuk') return 'MASUK';
  return '-';
}

function attendance_display_status(?string $type, ?string $status): string
{
  $bucket = attendance_effective_bucket($type, $status);
  if ($bucket === 'pulang') {
    return 'Pulang';
  }

  $normalized = attendance_normalize_status((string)$status);
  return $normalized !== '' ? $normalized : '-';
}

function attendance_bucket_case_sql(string $typeColumn = 'a.type', string $statusColumn = 'a.status'): string
{
  return "(
    CASE
      WHEN UPPER(TRIM($typeColumn)) = 'PULANG' OR LOWER(TRIM($statusColumn)) = 'pulang' THEN 'pulang'
      WHEN LOWER(TRIM($statusColumn)) IN ('izin','ijin','sakit','alfa','alpha') THEN 'tidak_masuk'
      WHEN LOWER(TRIM($statusColumn)) IN ('hadir tepat waktu','hadir terlambat') THEN 'masuk'
      WHEN UPPER(TRIM($typeColumn)) = 'TIDAK MASUK' THEN 'tidak_masuk'
      WHEN UPPER(TRIM($typeColumn)) = 'MASUK' THEN 'masuk'
      ELSE ''
    END
  )";
}

function attendance_filter_sql(string $filter, string $typeColumn = 'a.type', string $statusColumn = 'a.status'): string
{
  $filter = in_array($filter, ['semua', 'masuk', 'tidak_masuk', 'pulang'], true) ? $filter : 'semua';
  $bucketCase = attendance_bucket_case_sql($typeColumn, $statusColumn);

  if ($filter === 'semua') {
    return $bucketCase . " IN ('masuk','tidak_masuk')";
  }

  return $bucketCase . " = '" . $filter . "'";
}
