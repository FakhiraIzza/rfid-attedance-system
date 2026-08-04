<?php

final class SimplePdfDocument
{
    private array $pages = [];
    private array $images = [];
    private int $imageCounter = 0;

    public function addPage(float $widthMm = 210.0, float $heightMm = 297.0): int
    {
        $this->pages[] = [
            'width_mm' => $widthMm,
            'height_mm' => $heightMm,
            'content' => '',
        ];

        return array_key_last($this->pages);
    }

    public function line(int $page, float $x1, float $y1, float $x2, float $y2, float $lineWidth = 0.2): void
    {
        $pageHeight = $this->pages[$page]['height_mm'];
        $this->pages[$page]['content'] .= sprintf(
            "%.3F w %.3F %.3F m %.3F %.3F l S\n",
            $this->mm($lineWidth),
            $this->mm($x1),
            $this->mmFromTop($pageHeight, $y1),
            $this->mm($x2),
            $this->mmFromTop($pageHeight, $y2)
        );
    }

    public function rect(
        int $page,
        float $x,
        float $y,
        float $width,
        float $height,
        string $style = 'S',
        ?array $fillRgb = null,
        float $lineWidth = 0.2
    ): void {
        $pageHeight = $this->pages[$page]['height_mm'];
        $op = $style === 'F' ? 'f' : ($style === 'FD' || $style === 'DF' ? 'B' : 'S');
        $stream = sprintf("%.3F w\n", $this->mm($lineWidth));

        if ($fillRgb !== null) {
            $stream .= sprintf(
                "%.3F %.3F %.3F rg\n",
                $this->colorValue($fillRgb[0] ?? 255),
                $this->colorValue($fillRgb[1] ?? 255),
                $this->colorValue($fillRgb[2] ?? 255)
            );
        }

        $stream .= sprintf(
            "%.3F %.3F %.3F %.3F re %s\n",
            $this->mm($x),
            $this->mmFromTop($pageHeight, $y),
            $this->mm($width),
            -$this->mm($height),
            $op
        );

        $this->pages[$page]['content'] .= $stream;
    }

    public function text(
        int $page,
        float $x,
        float $baselineY,
        string $text,
        float $fontSize = 10.0,
        string $font = 'Helvetica',
        ?array $rgb = null
    ): void {
        $encoded = $this->encodeText($text);
        $fontResource = $this->fontResource($font);
        $pageHeight = $this->pages[$page]['height_mm'];
        $rgb = $rgb ?? [0, 0, 0];
        $stream = "BT\n";
        $stream .= sprintf(
            "%.3F %.3F %.3F rg\n",
            $this->colorValue($rgb[0] ?? 0),
            $this->colorValue($rgb[1] ?? 0),
            $this->colorValue($rgb[2] ?? 0)
        );

        $stream .= sprintf(
            "/%s %.3F Tf\n1 0 0 1 %.3F %.3F Tm\n(%s) Tj\nET\n",
            $fontResource,
            $fontSize,
            $this->mm($x),
            $this->mmFromTop($pageHeight, $baselineY),
            $encoded
        );

        $this->pages[$page]['content'] .= $stream;
    }

    public function cellText(
        int $page,
        float $x,
        float $y,
        float $width,
        float $height,
        string $text,
        float $fontSize = 10.0,
        string $font = 'Helvetica',
        string $align = 'L',
        ?array $rgb = null
    ): void {
        $text = trim($text);
        $textWidthMm = $this->textWidthMm($text, $fontSize);
        $textX = $x + 1.2;

        if ($align === 'C') {
            $textX = $x + max(1.0, ($width - $textWidthMm) / 2);
        } elseif ($align === 'R') {
            $textX = $x + max(1.0, $width - $textWidthMm - 1.2);
        }

        $baselineY = $y + ($height * 0.68);
        $this->text($page, $textX, $baselineY, $text, $fontSize, $font, $rgb);
    }

    public function image(int $page, string $path, float $x, float $y, float $width, float $height): void
    {
        $image = $this->registerImage($path);
        $pageHeight = $this->pages[$page]['height_mm'];
        $this->pages[$page]['content'] .= sprintf(
            "q %.3F 0 0 %.3F %.3F %.3F cm /%s Do Q\n",
            $this->mm($width),
            $this->mm($height),
            $this->mm($x),
            $this->mmFromTop($pageHeight, $y + $height),
            $image['name']
        );
    }

    public function output(): string
    {
        $objects = [];
        $nextId = 1;

        $fontMap = [
            'F1' => $nextId++,
            'F2' => $nextId++,
        ];

        $objects[$fontMap['F1']] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objects[$fontMap['F2']] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

        foreach ($this->images as $key => $image) {
            $this->images[$key]['object_id'] = $nextId;
            $stream = $image['data'];
            $dict = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent %d /Filter /%s /Length %d >>\nstream\n",
                $image['width'],
                $image['height'],
                $image['color_space'],
                $image['bits_per_component'],
                $image['filter'],
                strlen($stream)
            );
            $objects[$nextId++] = $dict . $stream . "\nendstream";
        }

        $pageEntries = [];
        foreach ($this->pages as $index => $page) {
            $contentId = $nextId++;
            $pageId = $nextId++;

            $content = $page['content'];
            $objects[$contentId] = sprintf(
                "<< /Length %d >>\nstream\n%sendstream",
                strlen($content),
                $content
            );

            $fontResources = sprintf(
                "/Font << /F1 %d 0 R /F2 %d 0 R >>",
                $fontMap['F1'],
                $fontMap['F2']
            );

            $xObjectResources = '';
            if ($this->images) {
                $parts = [];
                foreach ($this->images as $image) {
                    $parts[] = sprintf('/%s %d 0 R', $image['name'], $image['object_id']);
                }
                $xObjectResources = ' /XObject << ' . implode(' ', $parts) . ' >>';
            }

            $objects[$pageId] = sprintf(
                "<< /Type /Page /Parent __PAGES__ /MediaBox [0 0 %.3F %.3F] /Resources << %s%s >> /Contents %d 0 R >>",
                $this->mm($page['width_mm']),
                $this->mm($page['height_mm']),
                $fontResources,
                $xObjectResources,
                $contentId
            );

            $pageEntries[$index] = [
                'page_id' => $pageId,
                'width_mm' => $page['width_mm'],
                'height_mm' => $page['height_mm'],
            ];
        }

        $pagesId = $nextId++;
        $catalogId = $nextId++;

        $kids = [];
        foreach ($pageEntries as $entry) {
            $kids[] = $entry['page_id'] . ' 0 R';
        }
        $objects[$pagesId] = sprintf(
            "<< /Type /Pages /Kids [%s] /Count %d >>",
            implode(' ', $kids),
            count($pageEntries)
        );

        foreach ($pageEntries as $entry) {
            $objects[$entry['page_id']] = str_replace('__PAGES__', $pagesId . ' 0 R', $objects[$entry['page_id']]);
        }

        $objects[$catalogId] = sprintf("<< /Type /Catalog /Pages %d 0 R >>", $pagesId);

        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }

        $pdf .= "trailer\n";
        $pdf .= sprintf("<< /Size %d /Root %d 0 R >>\n", count($objects) + 1, $catalogId);
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    public function textWidthMm(string $text, float $fontSize): float
    {
        $encoded = $this->decodeForWidth($text);
        return strlen($encoded) * ($fontSize * 0.175);
    }

    private function fontResource(string $font): string
    {
        return strtolower($font) === 'helvetica-bold' ? 'F2' : 'F1';
    }

    private function registerImage(string $path): array
    {
        $key = realpath($path) ?: $path;
        if (isset($this->images[$key])) {
            return $this->images[$key];
        }

        $info = @getimagesize($path);
        if (!$info || empty($info['mime'])) {
            throw new RuntimeException('Gambar tidak valid: ' . $path);
        }

        if ($info['mime'] === 'image/jpeg') {
            $data = @file_get_contents($path);
            if ($data === false) {
                throw new RuntimeException('Gagal membaca gambar JPEG: ' . $path);
            }

            $image = [
                'name' => 'Im' . (++$this->imageCounter),
                'width' => (int)$info[0],
                'height' => (int)$info[1],
                'color_space' => 'DeviceRGB',
                'bits_per_component' => 8,
                'filter' => 'DCTDecode',
                'data' => $data,
            ];
        } elseif ($info['mime'] === 'image/png') {
            $image = $this->parsePng($path);
            $image['name'] = 'Im' . (++$this->imageCounter);
        } else {
            throw new RuntimeException('Format gambar tidak didukung: ' . $path);
        }

        $this->images[$key] = $image;
        return $image;
    }

    private function parsePng(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('PNG tidak valid: ' . $path);
        }

        $offset = 8;
        $width = 0;
        $height = 0;
        $bitDepth = 8;
        $colorType = 2;
        $interlace = 0;
        $idat = '';

        while ($offset < strlen($raw)) {
            $length = unpack('N', substr($raw, $offset, 4))[1];
            $offset += 4;
            $type = substr($raw, $offset, 4);
            $offset += 4;
            $chunk = substr($raw, $offset, $length);
            $offset += $length + 4;

            if ($type === 'IHDR') {
                $ihdr = unpack('Nwidth/Nheight/Cbit/Ccolor/Ccompression/Cfilter/Cinterlace', $chunk);
                $width = (int)$ihdr['width'];
                $height = (int)$ihdr['height'];
                $bitDepth = (int)$ihdr['bit'];
                $colorType = (int)$ihdr['color'];
                $interlace = (int)$ihdr['interlace'];
            } elseif ($type === 'IDAT') {
                $idat .= $chunk;
            } elseif ($type === 'IEND') {
                break;
            }
        }

        if ($width <= 0 || $height <= 0 || $bitDepth !== 8 || !in_array($colorType, [2, 6], true) || $interlace !== 0) {
            throw new RuntimeException('PNG tidak didukung untuk PDF: ' . $path);
        }

        $decoded = zlib_decode($idat);
        if ($decoded === false) {
            throw new RuntimeException('PNG gagal didekompresi: ' . $path);
        }

        $bytesPerPixel = $colorType === 6 ? 4 : 3;
        $stride = $width * $bytesPerPixel;
        $pos = 0;
        $prevRow = str_repeat("\0", $stride);
        $rgb = '';

        for ($row = 0; $row < $height; $row++) {
            $filter = ord($decoded[$pos]);
            $pos++;
            $scanline = substr($decoded, $pos, $stride);
            $pos += $stride;
            $recon = $this->unfilterPngRow($scanline, $prevRow, $bytesPerPixel, $filter);
            $prevRow = $recon;

            if ($colorType === 6) {
                $line = '';
                for ($i = 0; $i < $stride; $i += 4) {
                    $r = ord($recon[$i]);
                    $g = ord($recon[$i + 1]);
                    $b = ord($recon[$i + 2]);
                    $a = ord($recon[$i + 3]);

                    $r = (int)round(($r * $a + 255 * (255 - $a)) / 255);
                    $g = (int)round(($g * $a + 255 * (255 - $a)) / 255);
                    $b = (int)round(($b * $a + 255 * (255 - $a)) / 255);

                    $line .= chr($r) . chr($g) . chr($b);
                }
                $rgb .= $line;
            } else {
                $rgb .= $recon;
            }
        }

        $compressed = gzcompress($rgb, 9);
        if ($compressed === false) {
            throw new RuntimeException('PNG gagal dikompresi ulang: ' . $path);
        }

        return [
            'width' => $width,
            'height' => $height,
            'color_space' => 'DeviceRGB',
            'bits_per_component' => 8,
            'filter' => 'FlateDecode',
            'data' => $compressed,
        ];
    }

    private function unfilterPngRow(string $row, string $prevRow, int $bytesPerPixel, int $filter): string
    {
        $length = strlen($row);
        $result = array_fill(0, $length, 0);

        for ($i = 0; $i < $length; $i++) {
            $raw = ord($row[$i]);
            $left = $i >= $bytesPerPixel ? $result[$i - $bytesPerPixel] : 0;
            $up = ord($prevRow[$i]);
            $upLeft = $i >= $bytesPerPixel ? ord($prevRow[$i - $bytesPerPixel]) : 0;

            if ($filter === 1) {
                $value = ($raw + $left) & 255;
            } elseif ($filter === 2) {
                $value = ($raw + $up) & 255;
            } elseif ($filter === 3) {
                $value = ($raw + intdiv($left + $up, 2)) & 255;
            } elseif ($filter === 4) {
                $value = ($raw + $this->paethPredictor($left, $up, $upLeft)) & 255;
            } else {
                $value = $raw;
            }

            $result[$i] = $value;
        }

        return implode('', array_map('chr', $result));
    }

    private function paethPredictor(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        if ($pb <= $pc) {
            return $b;
        }
        return $c;
    }

    private function encodeText(string $text): string
    {
        $converted = $this->decodeForWidth($text);
        $converted = str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $converted);
        return str_replace("\n", ' ', $converted);
    }

    private function decodeForWidth(string $text): string
    {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
        }
        return $converted;
    }

    private function mm(float $value): float
    {
        return $value * 72 / 25.4;
    }

    private function mmFromTop(float $pageHeightMm, float $valueFromTopMm): float
    {
        return $this->mm($pageHeightMm - $valueFromTopMm);
    }

    private function colorValue(int $value): float
    {
        return max(0, min(255, $value)) / 255;
    }
}
