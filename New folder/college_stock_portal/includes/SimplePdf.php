<?php
final class SimplePdf
{
    private array $pages = [];
    private int $current = -1;
    private float $pageWidth;
    private float $pageHeight;
    private float $margin = 28;
    private float $y;
    // Track if a logo is registered
    private ?string $logoPath = null;

    public function __construct(string $orientation = 'L')
    {
        if ($orientation === 'P') {
            $this->pageWidth = 595;
            $this->pageHeight = 842;
        } else {
            $this->pageWidth = 842;
            $this->pageHeight = 595;
        }
        $this->addPage();
    }

    public function addPage(): void
    {
        $this->pages[] = [];
        $this->current = count($this->pages) - 1;
        $this->y = $this->pageHeight - $this->margin;
    }

    /**
     * Set the path to the logo and place it on the current page header.
     */
    public function logo(string $path, float $width = 40, float $height = 40): void
    {
        $this->logoPath = $path;
        
        $x = $this->margin;
        $y = $this->pageHeight - 47;
        $this->raw(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Logo Do Q', $width, $height, $x, $y));
    }

    public function title(string $text): void
    {
        $appName = defined('APP_NAME') ? APP_NAME : 'Stock Management System';
        $this->raw(sprintf('0.96 0.96 0.96 rg %.2F %.2F %.2F %.2F re f 0 0 0 RG 0 0 0 rg', 0, $this->pageHeight - 54, $this->pageWidth, 54));
        $textXOffset = $this->logoPath ? ($this->margin + 50) : $this->margin;
        $this->text($textXOffset, $this->pageHeight - 20, $appName, 10, true);
        $this->text($textXOffset, $this->pageHeight - 42, $text, 16, true);
        $this->y = $this->pageHeight - 72;
    }

    public function subtitle(string $text): void
    {
        $this->text($this->margin, $this->y, $text, 9);
        $this->y -= 16;
    }

    public function categoryHeading(string $text): void
    {
        $this->text($this->margin, $this->y, $text, 9);
        $this->y -= 8;   // smaller gap for category headings
    }

    public function line(string $text = ''): void
    {
        if ($this->y < $this->margin + 30) {
            $this->addPage();
        }
        $this->text($this->margin, $this->y, $text, 9);
        $this->y -= 14;
    }

    public function paragraph(string $text, float $size = 9, float $leading = 13): void
    {
        foreach ($this->wrap($text, $this->pageWidth - ($this->margin * 2), $size) as $line) {
            $this->line($line);
        }
        $this->y -= 4;
    }

    public function keyValues(array $rows, int $columns = 2): void
    {
        $colWidth = ($this->pageWidth - ($this->margin * 2)) / $columns;
        $i = 0;
        foreach ($rows as $label => $value) {
            $x = $this->margin + (($i % $columns) * $colWidth);
            if ($i > 0 && $i % $columns === 0) {
                $this->y -= 16;
            }
            $this->text($x, $this->y, $label . ': ' . $value, 9);
            $i++;
        }
        $this->y -= 22;
    }

    public function table(array $headers, array $rows, array $widths, string $emptyMessage = 'No records found for this period.'): void
    {
        $rowHeight = 22;
        $this->drawHeader($headers, $widths, $rowHeight);
        if (!$rows) {
            $this->drawRow([$emptyMessage], [array_sum($widths)], $rowHeight, false);
            return;
        }
        foreach ($rows as $row) {
            if ($this->y - $rowHeight < $this->margin + 40) {
                $this->addPage();
                $this->drawHeader($headers, $widths, $rowHeight);
            }
            $this->drawRow($row, $widths, $rowHeight, false);
        }
    }

    public function wrappedTable(array $headers, array $rows, array $widths, string $emptyMessage = 'No records found.'): void
    {
        $this->drawWrappedRow($headers, $widths, true);
        if (!$rows) {
            $this->drawWrappedRow([$emptyMessage], [array_sum($widths)], false);
            return;
        }
        foreach ($rows as $row) {
            $height = $this->wrappedRowHeight($row, $widths);
            if ($this->y - $height < $this->margin + 55) {
                $this->addPage();
                $this->drawWrappedRow($headers, $widths, true);
            }
            $this->drawWrappedRow($row, $widths, false);
        }
    }

    public function signatures(array $labels): void
    {
        if ($this->y < $this->margin + 70) {
            $this->addPage();
        }
        $this->y -= 22;
        $width = ($this->pageWidth - ($this->margin * 2)) / count($labels);
        foreach (array_values($labels) as $i => $label) {
            $x = $this->margin + ($i * $width);
            $this->drawLine($x, $this->y, $x + $width - 28, $this->y);
            $this->text($x, $this->y - 14, $label, 9);
        }
    }

    private function drawHeader(array $headers, array $widths, float $rowHeight): void
    {
        $this->drawRow($headers, $widths, $rowHeight, true);
    }

    private function drawRow(array $values, array $widths, float $rowHeight, bool $header): void
    {
        $x = $this->margin;
        foreach ($widths as $i => $width) {
            if ($header) {
                $this->raw(sprintf('0.90 0.90 0.90 rg %.2F %.2F %.2F %.2F re f 0 0 0 RG 0 0 0 rg', $x, $this->y - $rowHeight, $width, $rowHeight));
            } elseif ($i === 0) {
                $this->raw('1 1 1 rg 0 0 0 rg');
            }
            $this->rect($x, $this->y - $rowHeight, $width, $rowHeight);
            $text = (string)($values[$i] ?? '');
            $this->text($x + 4, $this->y - 14, $this->fit($text, $width), $header ? 8.2 : 7.8, $header);
            $x += $width;
        }
        $this->y -= $rowHeight;
    }

    private function fit(string $text, float $width): string
    {
        $limit = max(8, (int)floor($width / 4.4));
        $clean = preg_replace('/\s+/', ' ', trim($text));
        return strlen($clean) > $limit ? substr($clean, 0, $limit - 3) . '...' : $clean;
    }

    private function wrappedRowHeight(array $values, array $widths): float
    {
        $maxLines = 1;
        foreach ($widths as $i => $width) {
            $maxLines = max($maxLines, count($this->wrap((string)($values[$i] ?? ''), $width - 8, 7.5)));
        }
        return max(22, 10 + ($maxLines * 10));
    }

    private function drawWrappedRow(array $values, array $widths, bool $header): void
    {
        $height = $header ? 24 : $this->wrappedRowHeight($values, $widths);
        $x = $this->margin;
        foreach ($widths as $i => $width) {
            if ($header) {
                $this->raw(sprintf('0.90 0.90 0.90 rg %.2F %.2F %.2F %.2F re f 0 0 0 RG 0 0 0 rg', $x, $this->y - $height, $width, $height));
            }
            $this->rect($x, $this->y - $height, $width, $height);
            $lines = $header ? [$this->fit((string)($values[$i] ?? ''), $width)] : $this->wrap((string)($values[$i] ?? ''), $width - 8, 7.5);
            $lineY = $this->y - 14;
            foreach ($lines as $line) {
                $this->text($x + 4, $lineY, $line, $header ? 8 : 7.5, $header);
                $lineY -= 10;
            }
            $x += $width;
        }
        $this->y -= $height;
    }

    private function wrap(string $text, float $width, float $size): array
    {
        $limit = max(8, (int)floor($width / ($size * 0.48)));
        $text = preg_replace('/\s+/', ' ', trim($text));
        if ($text === '') {
            return [''];
        }
        $words = explode(' ', $text);
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = trim($line . ' ' . $word);
            if (strlen($candidate) > $limit && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines;
    }

    private function text(float $x, float $y, string $text, float $size = 9, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $this->raw(sprintf('0 0 0 rg BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET', $font, $size, $x, $y, $safe));
    }

    private function rect(float $x, float $y, float $w, float $h): void
    {
        $this->raw(sprintf('0 0 0 RG 0.35 w %.2F %.2F %.2F %.2F re S', $x, $y, $w, $h));
    }

    private function drawLine(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->raw(sprintf('0 0 0 RG 0.5 w %.2F %.2F m %.2F %.2F l S', $x1, $y1, $x2, $y2));
    }

    private function raw(string $command): void
    {
        $this->pages[$this->current][] = $command;
    }

    public function output(string $filename): never
    {
        $objects = [];
        $pageObjectNumbers = [];
        $objects[] = 'CATALOG';
        $objects[] = 'PAGES';
        $fontRegularObject = 3;
        $fontBoldObject = 4;
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        $imageObjectNo = 0;
        if ($this->logoPath && file_exists($this->logoPath) && function_exists('imagecreatefrompng')) {
            $img = @imagecreatefrompng($this->logoPath);
            if ($img) {
                $w = imagesx($img);
                $h = imagesy($img);
                $stream = '';
                for ($y = 0; $y < $h; $y++) {
                    for ($x = 0; $x < $w; $x++) {
                        $rgb = imagecolorat($img, $x, $y);
                        $r = ($rgb >> 16) & 0xFF;
                        $g = ($rgb >> 8) & 0xFF;
                        $b = $rgb & 0xFF;
                        $stream .= chr($r) . chr($g) . chr($b);
                    }
                }
                imagedestroy($img);
                $imageObjectNo = count($objects) + 1;
                $objects[] = "<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB /BitsPerComponent 8 /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream";
            }
        }

        foreach ($this->pages as $pageCommands) {
            $content = implode("\n", $pageCommands);
            $contentObjectNo = count($objects) + 2;
            $pageObjectNumbers[] = count($objects) + 1;
            $xobjectResource = $imageObjectNo > 0 ? sprintf('/XObject << /Logo %d 0 R >>', $imageObjectNo) : '';
            $objects[] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0F %.0F] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> %s >> /Contents %d 0 R >>',
                $this->pageWidth,
                $this->pageHeight,
                $fontRegularObject,
                $fontBoldObject,
                $xobjectResource,
                $contentObjectNo
            );
            $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream";
        }

        $objects[0] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($n) => $n . ' 0 R', $pageObjectNumbers)) . '] /Count ' . count($pageObjectNumbers) . ' >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n$obj\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $pdf;
        exit;
    }
}