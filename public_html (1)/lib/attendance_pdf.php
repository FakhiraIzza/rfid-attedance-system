<?php

require_once __DIR__ . '/simple_pdf.php';

function attendance_pdf_download(array $report): void
{
    $pdf = attendance_pdf_build($report);
    $filename = attendance_pdf_filename($report);

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

function attendance_pdf_build(array $report): string
{
    $pdf = new SimplePdfDocument();
    $page = $pdf->addPage();

    $pageWidth = 210.0;
    $pageHeight = 297.0;
    $marginLeft = 15.0;
    $marginRight = 15.0;
    $pageBottom = 282.0;

    $columns = [
        ['label' => 'No', 'width' => 8, 'align' => 'C'],
        ['label' => 'Tanggal', 'width' => 18, 'align' => 'C'],
        ['label' => 'Waktu', 'width' => 15, 'align' => 'C'],
        ['label' => 'NIS', 'width' => 18, 'align' => 'C'],
        ['label' => 'Nama', 'width' => 45, 'align' => 'L'],
        ['label' => 'Kelas', 'width' => 12, 'align' => 'C'],
        ['label' => 'Jenis', 'width' => 16, 'align' => 'C'],
        ['label' => 'Status', 'width' => 24, 'align' => 'L'],
        ['label' => 'Catatan', 'width' => 24, 'align' => 'L'],
    ];

    $drawHeader = function (int $pageIndex) use ($pdf, $report, $pageWidth, $marginLeft, $marginRight): float {
        $schoolLine1 = 'YAYASAN INSAN KARIMA CENDEKIA';
        $schoolLine2 = 'MENAUNGI';
        $schoolLine3 = 'SMP ISLAM AL AZHAR 39';
        $schoolLine4 = 'Jl. Mr. Asaat No.70, Kec. Mandiangin Koto Selayan, Campago Guguak Bulek';
        $schoolLine5 = 'Bukittinggi - Sumatera Barat, Telp 0811 6680 039, kode pos 26123';

        $leftLogo = dirname(__DIR__) . '/assets/img/logoikc.png';
        $rightLogo = dirname(__DIR__) . '/assets/img/logo.png';

        if (is_file($leftLogo)) {
            $pdf->image($pageIndex, $leftLogo, $marginLeft + 2, 14, 28, 19.7);
        }
        if (is_file($rightLogo)) {
            $pdf->image($pageIndex, $rightLogo, $pageWidth - $marginRight - 24, 12.5, 22, 22);
        }

        $centerLeft = 48.0;
        $centerWidth = 114.0;
        $pdf->cellText($pageIndex, $centerLeft, 15, $centerWidth, 6, $schoolLine1, 14, 'Helvetica-Bold', 'C');
        $pdf->cellText($pageIndex, $centerLeft, 21, $centerWidth, 5, $schoolLine2, 11, 'Helvetica-Bold', 'C');
        $pdf->cellText($pageIndex, $centerLeft, 26, $centerWidth, 7, $schoolLine3, 16, 'Helvetica-Bold', 'C');
        $pdf->cellText($pageIndex, $centerLeft, 33, $centerWidth, 4.5, $schoolLine4, 8.3, 'Helvetica', 'C', [70, 70, 70]);
        $pdf->cellText($pageIndex, $centerLeft, 37.5, $centerWidth, 4.5, $schoolLine5, 8.3, 'Helvetica', 'C', [70, 70, 70]);

        $pdf->line($pageIndex, $marginLeft, 43, $pageWidth - $marginRight, 43, 0.6);
        $pdf->line($pageIndex, $marginLeft, 44.6, $pageWidth - $marginRight, 44.6, 0.2);

        $pdf->cellText($pageIndex, $marginLeft, 48, $pageWidth - $marginLeft - $marginRight, 7, 'REKAP ABSENSI MURID', 13, 'Helvetica-Bold', 'C');
        $pdf->cellText($pageIndex, $marginLeft, 54, $pageWidth - $marginLeft - $marginRight, 6, 'SMP ISLAM AL AZHAR BUKITTINGGI 39', 11, 'Helvetica-Bold', 'C');

        $metaY = 64.0;
        $metaRows = [
            ['Kelas', $report['class_label'] ?? '-'],
            ['Siswa', $report['student_label'] ?? '-'],
            ['Jenis', $report['type_label'] ?? '-'],
            ['Tanggal Penarikan Data', $report['printed_at_label'] ?? '-'],
            ['Tahun', $report['year_label'] ?? '-'],
            ['Periode', $report['period_label'] ?? '-'],
        ];

        foreach ($metaRows as $row) {
            $pdf->cellText($pageIndex, $marginLeft, $metaY, 38, 5, $row[0], 9.2, 'Helvetica-Bold', 'L');
            $pdf->cellText($pageIndex, $marginLeft + 38, $metaY, 4, 5, ':', 9.2, 'Helvetica', 'C');
            $pdf->cellText($pageIndex, $marginLeft + 43, $metaY, 120, 5, attendance_pdf_shorten($row[1], 70), 9.2, 'Helvetica', 'L');
            $metaY += 5.2;
        }

        return $metaY + 3;
    };

    $drawTableHeader = function (int $pageIndex, float $y) use ($pdf, $columns, $marginLeft): float {
        $x = $marginLeft;
        foreach ($columns as $column) {
            $pdf->rect($pageIndex, $x, $y, $column['width'], 8, 'FD', [240, 240, 240], 0.2);
            $pdf->cellText($pageIndex, $x, $y + 0.4, $column['width'], 7, $column['label'], 8.2, 'Helvetica-Bold', 'C');
            $x += $column['width'];
        }
        return $y + 8;
    };

    $y = $drawHeader($page);
    $y = $drawTableHeader($page, $y);

    $rows = $report['rows'] ?? [];
    $rowHeight = 7.2;

    if (!$rows) {
        $totalWidth = 0;
        foreach ($columns as $column) {
            $totalWidth += $column['width'];
        }
        $pdf->rect($page, $marginLeft, $y, $totalWidth, 9, 'S', null, 0.2);
        $pdf->cellText($page, $marginLeft, $y + 0.5, $totalWidth, 8, 'Tidak ada data untuk filter yang dipilih.', 9.2, 'Helvetica', 'C');
    } else {
        $number = 1;
        foreach ($rows as $row) {
            if (($y + $rowHeight) > $pageBottom) {
                $page = $pdf->addPage();
                $y = $drawHeader($page);
                $y = $drawTableHeader($page, $y);
            }

            $values = [
                attendance_pdf_shorten((string)$number, 3),
                attendance_pdf_format_date($row['tanggal'] ?? ''),
                attendance_pdf_format_time($row['scan_at'] ?? ''),
                attendance_pdf_shorten((string)($row['nis'] ?? '-'), 14),
                attendance_pdf_shorten((string)($row['nama_siswa'] ?? '-'), 27),
                attendance_pdf_shorten((string)($row['class_level'] ?? '-'), 4),
                attendance_pdf_shorten((string)($row['jenis_label'] ?? '-'), 11),
                attendance_pdf_shorten((string)($row['status_label'] ?? '-'), 18),
                attendance_pdf_shorten((string)($row['catatan'] ?? '-'), 18),
            ];

            $x = $marginLeft;
            foreach ($columns as $index => $column) {
                $pdf->rect($page, $x, $y, $column['width'], $rowHeight, 'S', null, 0.2);
                $pdf->cellText($page, $x, $y + 0.1, $column['width'], $rowHeight - 0.3, $values[$index], 7.6, 'Helvetica', $column['align']);
                $x += $column['width'];
            }

            $y += $rowHeight;
            $number++;
        }
    }

    return $pdf->output();
}

function attendance_pdf_filename(array $report): string
{
    $prefix = $report['filename_prefix'] ?? 'rekap_presensi';
    $type = $report['type_filter'] ?? 'both';
    $from = preg_replace('/[^0-9-]/', '', (string)($report['from'] ?? date('Y-m-d'))) ?: date('Y-m-d');
    $to = preg_replace('/[^0-9-]/', '', (string)($report['to'] ?? date('Y-m-d'))) ?: date('Y-m-d');
    return $prefix . '_' . $type . '_' . $from . '_sd_' . $to . '.pdf';
}

function attendance_pdf_shorten(string $text, int $maxLength): string
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

function attendance_pdf_format_date(string $value): string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('d/m/Y', $timestamp);
        }
    }
    return attendance_pdf_shorten($value, 10);
}

function attendance_pdf_format_time(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '-';
    }
    if (preg_match('/\b(\d{2}:\d{2})(:\d{2})?\b/', $value, $matches)) {
        return $matches[1];
    }
    return attendance_pdf_shorten($value, 8);
}
