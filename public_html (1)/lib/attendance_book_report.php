<?php

require_once __DIR__ . '/simple_pdf.php';

function attendance_book_normalize_type_filter(string $typeFilter): string
{
    return in_array($typeFilter, ['semua', 'masuk', 'tidak_masuk'], true) ? $typeFilter : 'semua';
}

function attendance_book_type_label(string $typeFilter): string
{
    $typeFilter = attendance_book_normalize_type_filter($typeFilter);
    if ($typeFilter === 'masuk') {
        return 'Kehadiran saja';
    }
    if ($typeFilter === 'tidak_masuk') {
        return 'Tidak hadir saja';
    }
    return 'Kehadiran dan tidak hadir';
}

function attendance_book_status_code(?string $status): string
{
    $normalized = attendance_normalize_status((string)$status);
    $map = [
        'Hadir Tepat Waktu' => 'H',
        'Hadir Terlambat' => 'T',
        'Sakit' => 'S',
        'Izin' => 'I',
        'Alfa' => 'A',
    ];
    return $map[$normalized] ?? '';
}

function attendance_book_status_priority(?string $status, ?string $source, ?string $scanAt, ?int $idAbsensi = null): array
{
    $normalized = attendance_normalize_status((string)$status);
    $source = strtolower(trim((string)$source));
    $scanAt = trim((string)$scanAt);
    $timestamp = strtotime($scanAt);
    if ($timestamp === false) {
        $timestamp = 0;
    }

    $weight = 0;
    if (in_array($normalized, ['Sakit', 'Izin', 'Alfa'], true)) {
        $weight = 400;
    } elseif (in_array($normalized, ['Hadir Tepat Waktu', 'Hadir Terlambat'], true)) {
        $weight = 200;
    }

    if ($source === 'manual') {
        $weight += 50;
    } elseif ($source === 'auto') {
        $weight += 10;
    }

    return [$weight, $timestamp, (int)$idAbsensi];
}

function attendance_book_report(PDO $pdo, array $options): array
{
    $from = attendance_book_valid_date((string)($options['from'] ?? ''), date('Y-m-01'));
    $to = attendance_book_valid_date((string)($options['to'] ?? ''), date('Y-m-t'));
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $fixedClassLevel = trim((string)($options['fixed_class_level'] ?? ''));
    $classLevel = trim((string)($options['class_level'] ?? ''));
    if ($fixedClassLevel !== '') {
        $classLevel = $fixedClassLevel;
    }

    $studentId = (int)($options['student_id'] ?? 0);
    $typeFilter = attendance_book_normalize_type_filter((string)($options['type_filter'] ?? 'semua'));

    $activeStudentWhere = student_active_condition($pdo, 's');
    $attendanceClassExpr = student_attendance_class_expr($pdo, 'a', 's');

    $rosterWhere = [$activeStudentWhere];
    $rosterParams = [];
    if ($classLevel !== '') {
        $rosterWhere[] = 's.class_level = ?';
        $rosterParams[] = $classLevel;
    }
    if ($studentId > 0) {
        $rosterWhere[] = 's.id_siswa = ?';
        $rosterParams[] = $studentId;
    }

    $rosterSql = "
      SELECT s.id_siswa, s.nis, s.nama_siswa, s.class_level
      FROM students s
      WHERE " . implode(' AND ', $rosterWhere) . "
      ORDER BY s.class_level, s.nama_siswa
    ";
    $rosterStmt = $pdo->prepare($rosterSql);
    $rosterStmt->execute($rosterParams);
    $students = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);

    $studentsByClass = [];
    $studentClassById = [];
    $studentRosterSeen = [];
    foreach ($students as $student) {
        $studentClass = (string)($student['class_level'] ?? '');
        if ($studentClass === '') {
            continue;
        }
        $studentsByClass[$studentClass][] = $student;
        $studentClassById[(int)$student['id_siswa']] = $studentClass;
        $studentRosterSeen[$studentClass][(int)$student['id_siswa']] = true;
    }
    ksort($studentsByClass, SORT_NATURAL);

    $attendanceWhere = [];
    $attendanceParams = [$from, $to];
    if ($classLevel !== '') {
        $attendanceWhere[] = "$attendanceClassExpr = ?";
        $attendanceParams[] = $classLevel;
    }
    if ($studentId > 0) {
        $attendanceWhere[] = 's.id_siswa = ?';
        $attendanceParams[] = $studentId;
    }

    $attendanceSql = "
      SELECT a.id_absensi, a.id_siswa, a.tanggal, a.scan_at, a.type, a.status, a.source,
             s.nis, s.nama_siswa,
             $attendanceClassExpr AS class_level
      FROM attendance a
      JOIN students s ON s.id_siswa = a.id_siswa
      WHERE a.tanggal BETWEEN ? AND ?
        AND " . attendance_filter_sql($typeFilter, 'a.type', 'a.status') . "
        " . ($attendanceWhere ? 'AND ' . implode(' AND ', $attendanceWhere) : '') . "
      ORDER BY a.tanggal ASC, a.id_siswa ASC, a.scan_at ASC, a.id_absensi ASC
    ";
    $attendanceStmt = $pdo->prepare($attendanceSql);
    $attendanceStmt->execute($attendanceParams);
    $attendanceRows = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($attendanceRows as $row) {
        $rowStudentId = (int)($row['id_siswa'] ?? 0);
        if ($rowStudentId <= 0) {
            continue;
        }

        $rowClass = (string)($row['class_level'] ?? '');
        if ($rowClass === '') {
            $rowClass = $studentClassById[$rowStudentId] ?? '';
        }
        if ($rowClass === '') {
            continue;
        }

        if (!isset($studentRosterSeen[$rowClass][$rowStudentId])) {
            $studentsByClass[$rowClass][] = [
                'id_siswa' => $rowStudentId,
                'nis' => (string)($row['nis'] ?? ''),
                'nama_siswa' => (string)($row['nama_siswa'] ?? ''),
                'class_level' => $rowClass,
            ];
            $studentRosterSeen[$rowClass][$rowStudentId] = true;
        }
    }

    foreach ($studentsByClass as $studentClass => &$classStudents) {
        usort($classStudents, static function (array $a, array $b): int {
            return strcasecmp((string)($a['nama_siswa'] ?? ''), (string)($b['nama_siswa'] ?? ''));
        });
    }
    unset($classStudents);
    ksort($studentsByClass, SORT_NATURAL);

    $attendanceMap = [];
    $attendancePriorityMap = [];
    foreach ($attendanceRows as $row) {
        $rowStudentId = (int)($row['id_siswa'] ?? 0);
        if ($rowStudentId <= 0) {
            continue;
        }
        $rowClass = (string)($row['class_level'] ?? '');
        if ($rowClass === '') {
            $rowClass = $studentClassById[$rowStudentId] ?? '';
        }
        if ($rowClass === '') {
            continue;
        }

        $date = (string)($row['tanggal'] ?? '');
        $code = attendance_book_status_code((string)($row['status'] ?? ''));
        if ($date === '' || $code === '') {
            continue;
        }

        $priority = attendance_book_status_priority(
            (string)($row['status'] ?? ''),
            (string)($row['source'] ?? ''),
            (string)($row['scan_at'] ?? ''),
            (int)($row['id_absensi'] ?? 0)
        );

        $currentPriority = $attendancePriorityMap[$rowClass][$rowStudentId][$date] ?? null;
        if ($currentPriority === null || $priority > $currentPriority) {
            $attendancePriorityMap[$rowClass][$rowStudentId][$date] = $priority;
            $attendanceMap[$rowClass][$rowStudentId][$date] = $code;
        }
    }

    $months = attendance_book_months_in_range($from, $to);
    $sections = [];
    foreach ($studentsByClass as $studentClass => $classStudents) {
        foreach ($months as $monthInfo) {
            $rows = [];
            $number = 1;
            foreach ($classStudents as $student) {
                $statuses = [];
                for ($day = 1; $day <= 31; $day++) {
                    if ($day > $monthInfo['days_in_month']) {
                        $statuses[$day] = '';
                        continue;
                    }
                    $date = sprintf('%04d-%02d-%02d', $monthInfo['year'], $monthInfo['month'], $day);
                    if ($date < $monthInfo['active_from'] || $date > $monthInfo['active_to']) {
                        $statuses[$day] = '';
                        continue;
                    }
                    $statuses[$day] = $attendanceMap[$studentClass][(int)$student['id_siswa']][$date] ?? '';
                }

                $rows[] = [
                    'no' => $number++,
                    'student' => $student,
                    'statuses' => $statuses,
                ];
            }

            $sections[] = [
                'class_level' => $studentClass,
                'month' => $monthInfo,
                'rows' => $rows,
            ];
        }
    }

    $classLabel = $classLevel !== '' ? ('Kelas ' . $classLevel) : 'Semua Kelas';
    if ($studentId > 0 && $students) {
        $student = $students[0];
        $classLabel = 'Kelas ' . (string)($student['class_level'] ?? '-');
    }
    $studentLabel = 'Semua Siswa';
    if ($studentId > 0 && $students) {
        $student = $students[0];
        $studentLabel = trim(((string)($student['nis'] ?? '')) . ' | ' . ((string)($student['nama_siswa'] ?? '')));
    }

    return [
        'from' => $from,
        'to' => $to,
        'type_filter' => $typeFilter,
        'type_label' => attendance_book_type_label($typeFilter),
        'class_label' => $classLabel,
        'student_label' => $studentLabel,
        'printed_at_label' => date('d/m/Y H:i'),
        'sections' => $sections,
        'legend' => 'H=Hadir, T=Hadir Terlambat, S=Sakit, I=Izin, A=Alfa',
    ];
}

function attendance_book_csv_download(array $report, string $filenamePrefix = 'rekap_buku_absen'): void
{
    $filename = attendance_book_filename($report, $filenamePrefix, 'csv');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $sections = $report['sections'] ?? [];
    if (!$sections) {
        fputcsv($out, ['Rekap buku absen tidak memiliki data siswa untuk filter yang dipilih.'], ';');
        fclose($out);
        exit;
    }

    foreach ($sections as $index => $section) {
        if ($index > 0) {
            fputcsv($out, [], ';');
            fputcsv($out, [], ';');
        }

        $month = $section['month'];
        fputcsv($out, ['BUKU ABSEN KEHADIRAN'], ';');
        fputcsv($out, ['Kelas', (string)($section['class_level'] ?? '-')], ';');
        fputcsv($out, ['Bulan', (string)($month['month_label'] ?? '-')], ';');
        fputcsv($out, ['Rentang Filter', attendance_book_display_date((string)($month['active_from'] ?? '')) . ' s/d ' . attendance_book_display_date((string)($month['active_to'] ?? ''))], ';');
        fputcsv($out, ['Jenis Rekap', (string)($report['type_label'] ?? '-') . ' (tanpa pulang)'], ';');
        fputcsv($out, ['Kode', (string)($report['legend'] ?? '')], ';');

        $headerDays = ['No', 'Nama'];
        $headerWeekdays = ['', ''];
        for ($day = 1; $day <= 31; $day++) {
            $headerDays[] = (string)$day;
            $headerWeekdays[] = $month['day_headers'][$day]['weekday'] ?? '';
        }
        fputcsv($out, $headerDays, ';');
        fputcsv($out, $headerWeekdays, ';');

        foreach (($section['rows'] ?? []) as $row) {
            $line = [
                (string)($row['no'] ?? ''),
                (string)($row['student']['nama_siswa'] ?? ''),
            ];
            for ($day = 1; $day <= 31; $day++) {
                $line[] = (string)($row['statuses'][$day] ?? '');
            }
            fputcsv($out, $line, ';');
        }
    }

    fclose($out);
    exit;
}

function attendance_book_pdf_download(array $report, string $filenamePrefix = 'rekap_buku_absen'): void
{
    $pdf = attendance_book_pdf_build($report);
    $filename = attendance_book_filename($report, $filenamePrefix, 'pdf');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $pdf;
    exit;
}

function attendance_book_pdf_build(array $report): string
{
    $pdf = new SimplePdfDocument();
    $pageWidth = 297.0;
    $pageHeight = 210.0;
    $marginLeft = 7.0;
    $marginRight = 7.0;
    $pageBottom = 202.0;
    $tableTop = 57.0;
    $rowHeight = 5.6;
    $dayWidth = 6.0;
    $colNo = 7.0;
    $colName = 90.0;
    $headerFill = [239, 239, 239];
    $invalidFill = [228, 228, 228];

    $drawPageFrame = function (int $pageIndex, array $section, bool $continued) use ($pdf, $report, $pageWidth, $pageHeight, $marginLeft, $marginRight): float {
        $leftLogo = dirname(__DIR__) . '/assets/img/logoikc.png';
        $rightLogo = dirname(__DIR__) . '/assets/img/logo.png';

        if (is_file($leftLogo)) {
            $pdf->image($pageIndex, $leftLogo, $marginLeft, 8, 17, 12);
        }
        if (is_file($rightLogo)) {
            $pdf->image($pageIndex, $rightLogo, $pageWidth - $marginRight - 14, 8, 14, 14);
        }

        $pdf->cellText($pageIndex, 30, 8, $pageWidth - 60, 5, 'PEMERINTAH KOTA BUKITTINGGI', 10.5, 'Helvetica-Bold', 'C');
        $pdf->cellText($pageIndex, 30, 13, $pageWidth - 60, 5, 'DINAS PENDIDIKAN DAN KEBUDAYAAN', 9.2, 'Helvetica-Bold', 'C');
        $pdf->cellText($pageIndex, 30, 18, $pageWidth - 60, 5.5, 'SMP ISLAM AL AZHAR 39', 12, 'Helvetica-Bold', 'C');
        $pdf->line($pageIndex, $marginLeft, 25.5, $pageWidth - $marginRight, 25.5, 0.35);

        $title = 'BUKU ABSEN KEHADIRAN';
        if ($continued) {
            $title .= ' (LANJUTAN)';
        }
        $pdf->cellText($pageIndex, $marginLeft, 28.5, $pageWidth - $marginLeft - $marginRight, 6, $title, 11.5, 'Helvetica-Bold', 'C');

        $metaY = 35.0;
        $month = $section['month'] ?? [];
        $metaRows = [
            ['Kelas', 'Kelas ' . (string)($section['class_level'] ?? '-')],
            ['Bulan', (string)($month['month_label'] ?? '-')],
            ['Rentang Filter', attendance_book_display_date((string)($month['active_from'] ?? '')) . ' s/d ' . attendance_book_display_date((string)($month['active_to'] ?? ''))],
            ['Jenis Rekap', (string)($report['type_label'] ?? '-') . ' (tanpa pulang)'],
            ['Kode', (string)($report['legend'] ?? '')],
        ];
        foreach ($metaRows as $metaRow) {
            $pdf->cellText($pageIndex, $marginLeft, $metaY, 24, 4.5, $metaRow[0], 7.8, 'Helvetica-Bold', 'L');
            $pdf->cellText($pageIndex, $marginLeft + 24, $metaY, 3, 4.5, ':', 7.8, 'Helvetica', 'C');
            $pdf->cellText($pageIndex, $marginLeft + 28, $metaY, $pageWidth - $marginLeft - $marginRight - 28, 4.5, attendance_book_pdf_shorten((string)$metaRow[1], 110), 7.8, 'Helvetica', 'L');
            $metaY += 4.3;
        }

        $pdf->cellText($pageIndex, $pageWidth - $marginRight - 46, 48.2, 46, 4.2, 'Dicetak: ' . (string)($report['printed_at_label'] ?? '-'), 7.2, 'Helvetica', 'R', [90, 90, 90]);
        $pdf->line($pageIndex, $marginLeft, 53.5, $pageWidth - $marginRight, 53.5, 0.25);

        return 57.0;
    };

    $drawTableHeader = function (int $pageIndex, float $y, array $section) use ($pdf, $marginLeft, $colNo, $colName, $dayWidth, $headerFill, $invalidFill): float {
        $month = $section['month'] ?? [];
        $x = $marginLeft;
        $pdf->rect($pageIndex, $x, $y, $colNo, 8, 'FD', $headerFill, 0.2);
        $pdf->cellText($pageIndex, $x, $y + 1.4, $colNo, 5, 'No', 7.2, 'Helvetica-Bold', 'C');
        $x += $colNo;

        $pdf->rect($pageIndex, $x, $y, $colName, 8, 'FD', $headerFill, 0.2);
        $pdf->cellText($pageIndex, $x, $y + 1.4, $colName, 5, 'Nama Siswa', 7.2, 'Helvetica-Bold', 'C');
        $x += $colName;

        for ($day = 1; $day <= 31; $day++) {
            $dayHeader = $month['day_headers'][$day] ?? ['day' => '', 'weekday' => '', 'valid' => false];
            $fill = !($dayHeader['valid'] ?? false) ? $invalidFill : $headerFill;
            $pdf->rect($pageIndex, $x, $y, $dayWidth, 4, 'FD', $fill, 0.2);
            $pdf->rect($pageIndex, $x, $y + 4, $dayWidth, 4, 'FD', $fill, 0.2);
            $pdf->cellText($pageIndex, $x, $y + 0.4, $dayWidth, 3.3, (string)($dayHeader['day'] ?? ''), 6.3, 'Helvetica-Bold', 'C');
            $pdf->cellText($pageIndex, $x, $y + 4.1, $dayWidth, 3.1, (string)($dayHeader['weekday'] ?? ''), 5.6, 'Helvetica', 'C');
            $x += $dayWidth;
        }

        return $y + 8;
    };

    $sections = $report['sections'] ?? [];
    if (!$sections) {
        $page = $pdf->addPage($pageWidth, $pageHeight);
        $drawPageFrame($page, ['class_level' => '-', 'month' => ['month_label' => '-', 'active_from' => $report['from'] ?? '', 'active_to' => $report['to'] ?? '']], false);
        $pdf->rect($page, $marginLeft, $tableTop, $colNo + $colName + ($dayWidth * 31), 12, 'S', null, 0.2);
        $pdf->cellText($page, $marginLeft, $tableTop + 2, $colNo + $colName + ($dayWidth * 31), 7, 'Tidak ada data siswa untuk filter yang dipilih.', 9, 'Helvetica', 'C');
        return $pdf->output();
    }

    foreach ($sections as $section) {
        $rows = $section['rows'] ?? [];
        $page = $pdf->addPage($pageWidth, $pageHeight);
        $y = $drawPageFrame($page, $section, false);
        $y = $drawTableHeader($page, $y, $section);

        if (!$rows) {
            $pdf->rect($page, $marginLeft, $y, $colNo + $colName + ($dayWidth * 31), 10, 'S', null, 0.2);
            $pdf->cellText($page, $marginLeft, $y + 1.2, $colNo + $colName + ($dayWidth * 31), 6.5, 'Tidak ada data siswa.', 8.4, 'Helvetica', 'C');
            continue;
        }

        foreach ($rows as $row) {
            if (($y + $rowHeight) > $pageBottom) {
                $page = $pdf->addPage($pageWidth, $pageHeight);
                $y = $drawPageFrame($page, $section, true);
                $y = $drawTableHeader($page, $y, $section);
            }

            $x = $marginLeft;
            $pdf->rect($page, $x, $y, $colNo, $rowHeight, 'S', null, 0.2);
            $pdf->cellText($page, $x, $y + 0.2, $colNo, $rowHeight - 0.4, (string)($row['no'] ?? ''), 6.4, 'Helvetica', 'C');
            $x += $colNo;

            $studentName = attendance_book_pdf_shorten((string)($row['student']['nama_siswa'] ?? ''), 34);
            $pdf->rect($page, $x, $y, $colName, $rowHeight, 'S', null, 0.2);
            $pdf->cellText($page, $x + 0.4, $y + 0.2, $colName - 0.8, $rowHeight - 0.4, $studentName, 6.4, 'Helvetica', 'L');
            $x += $colName;

            for ($day = 1; $day <= 31; $day++) {
                $dayHeader = $section['month']['day_headers'][$day] ?? ['valid' => false];
                $fill = !($dayHeader['valid'] ?? false) ? $invalidFill : null;
                $pdf->rect($page, $x, $y, $dayWidth, $rowHeight, $fill === null ? 'S' : 'FD', $fill, 0.2);
                $pdf->cellText($page, $x, $y + 0.2, $dayWidth, $rowHeight - 0.4, (string)($row['statuses'][$day] ?? ''), 6.3, 'Helvetica-Bold', 'C');
                $x += $dayWidth;
            }

            $y += $rowHeight;
        }
    }

    return $pdf->output();
}

function attendance_book_filename(array $report, string $prefix, string $extension): string
{
    $from = preg_replace('/[^0-9-]/', '', (string)($report['from'] ?? date('Y-m-d'))) ?: date('Y-m-d');
    $to = preg_replace('/[^0-9-]/', '', (string)($report['to'] ?? date('Y-m-d'))) ?: date('Y-m-d');
    return $prefix . '_' . $from . '_sd_' . $to . '.' . $extension;
}

function attendance_book_valid_date(string $date, string $fallback): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : $fallback;
}

function attendance_book_months_in_range(string $from, string $to): array
{
    $start = new DateTimeImmutable(substr($from, 0, 7) . '-01');
    $end = new DateTimeImmutable(substr($to, 0, 7) . '-01');
    $months = [];
    $current = $start;

    while ($current <= $end) {
        $year = (int)$current->format('Y');
        $month = (int)$current->format('n');
        $daysInMonth = (int)$current->format('t');
        $monthStart = $current->format('Y-m-01');
        $monthEnd = $current->format('Y-m-t');
        $activeFrom = max($from, $monthStart);
        $activeTo = min($to, $monthEnd);

        $dayHeaders = [];
        for ($day = 1; $day <= 31; $day++) {
            if ($day <= $daysInMonth) {
                $dayHeaders[$day] = [
                    'day' => (string)$day,
                    'weekday' => attendance_book_weekday_short($year, $month, $day),
                    'valid' => true,
                ];
            } else {
                $dayHeaders[$day] = [
                    'day' => '',
                    'weekday' => '',
                    'valid' => false,
                ];
            }
        }

        $months[] = [
            'year' => $year,
            'month' => $month,
            'month_label' => attendance_book_month_label($year, $month),
            'days_in_month' => $daysInMonth,
            'active_from' => $activeFrom,
            'active_to' => $activeTo,
            'day_headers' => $dayHeaders,
        ];

        $current = $current->modify('+1 month');
    }

    return $months;
}

function attendance_book_month_label(int $year, int $month): string
{
    $months = [
        1 => 'JANUARI',
        2 => 'FEBRUARI',
        3 => 'MARET',
        4 => 'APRIL',
        5 => 'MEI',
        6 => 'JUNI',
        7 => 'JULI',
        8 => 'AGUSTUS',
        9 => 'SEPTEMBER',
        10 => 'OKTOBER',
        11 => 'NOVEMBER',
        12 => 'DESEMBER',
    ];
    return ($months[$month] ?? '-') . ' ' . $year;
}

function attendance_book_weekday_short(int $year, int $month, int $day): string
{
    $weekdayMap = [
        1 => 'Sen',
        2 => 'Sel',
        3 => 'Rab',
        4 => 'Kam',
        5 => 'Jum',
        6 => 'Sab',
        7 => 'Min',
    ];
    $weekday = (int)(new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->format('N');
    return $weekdayMap[$weekday] ?? '';
}

function attendance_book_display_date(string $date): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }
    return date('d/m/Y', $timestamp);
}

function attendance_book_pdf_shorten(string $text, int $maxLength): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($text === '') {
        return '-';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, max(1, $maxLength - 1))) . '.';
    }
    if (strlen($text) <= $maxLength) {
        return $text;
    }
    return rtrim(substr($text, 0, max(1, $maxLength - 1))) . '.';
}
