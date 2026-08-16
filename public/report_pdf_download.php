<?php
/**
 * Generic report PDF download endpoint.
 *
 * Accepts a JSON payload from the browser with report headings and table rows,
 * then returns a simple valid PDF as an attachment. Kept dependency-free so it
 * works in the local XAMPP install without Composer packages.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../backend/lib.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    json_response(['ok' => false, 'error' => 'Invalid report data'], 400);
}

function report_pdf_text($value): string {
    $text = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strtr($text, [
        "\xC2\xA0" => ' ',
        "\xE2\x82\xB1" => 'PHP ',
        "\xE2\x80\x93" => '-',
        "\xE2\x80\x94" => '-',
        "\xE2\x80\xA2" => '-',
        "\xE2\x80\x99" => "'",
        "\xE2\x80\x98" => "'",
        "\xE2\x80\x9C" => '"',
        "\xE2\x80\x9D" => '"',
        "\xE2\x9C\x93" => 'OK',
        "\xE2\x9C\x94" => 'OK',
        "\xE2\x9C\x95" => 'X',
        "\xC3\x97" => 'x',
    ]);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }
    $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\x80-\xFF]/', '', $text) ?? $text;
    return trim($text);
}

function report_pdf_filename(string $filename): string {
    $filename = report_pdf_text($filename);
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'report';
    $filename = trim($filename, '._-');
    if ($filename === '') {
        $filename = 'report';
    }
    if (!preg_match('/\.pdf$/i', $filename)) {
        $filename .= '.pdf';
    }
    return $filename;
}

class SimpleReportPdf {
    private float $width = 842.0;
    private float $height = 595.0;
    private float $margin = 28.0;
    private float $y;
    private array $pages = [];

    public function __construct() {
        $this->addPage();
    }

    public function render(string $title, array $metaLines, array $sections): void {
        $this->title($title !== '' ? $title : 'Report');
        // Render station address + date range meta lines centered under title
        foreach ($metaLines as $line) {
            $line = report_pdf_text($line);
            if ($line !== '') {
                $this->lineText($line, 8.5, 'F1', [0.15, 0.15, 0.15], true);
            }
        }
        $this->y -= 10;

        if (empty($sections)) {
            $this->lineText('No report data found.', 10, 'F1');
            return;
        }

        foreach ($sections as $section) {
            $sectionTitle = report_pdf_text($section['title'] ?? '');
            $headers = array_values(array_map('report_pdf_text', $section['headers'] ?? []));
            $rows = $section['rows'] ?? [];
            $cleanRows = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cleanRows[] = array_values(array_map('report_pdf_text', $row));
            }
            $this->table($sectionTitle, $headers, $cleanRows);
            $this->y -= 10;
        }

        // SIGNATURE BLOCK (Role-specific)
        if ($this->y < 80.0) {
            $this->addPage();
        }

        $u = current_user();
        $user_role = function_exists('role_key') ? role_key($u['role'] ?? '') : strtolower((string)($u['role'] ?? ''));

        if (in_array($user_role, ['admin', 'manager'], true)) {
            // ── ADMIN & MANAGER REPORT SIGNATURE BLOCK (Prepared By, Verified By, Approved By) ──
            $this->y -= 15;
            $sigWidth = 170.0;
            $sig1X = $this->margin;
            $sig2X = ($this->width - $sigWidth) / 2.0;
            $sig3X = $this->width - $this->margin - $sigWidth;

            $user_name = strtoupper(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?: strtoupper($u['username'] ?? ($user_role === 'admin' ? 'SYSTEM ADMIN' : 'STATION MANAGER'));

            $startY = $this->y;

            // Labels
            $this->append($this->pdfText($sig1X, $startY, 'Prepared By:', 9, 'F2', [0.0, 0.18, 0.42]));
            $this->append($this->pdfText($sig2X, $startY, 'Verified By:', 9, 'F2', [0.0, 0.18, 0.42]));
            $this->append($this->pdfText($sig3X, $startY, 'Approved By:', 9, 'F2', [0.0, 0.18, 0.42]));

            $lineY = $startY - 26;
            // Lines
            $this->line($sig1X, $lineY, $sig1X + $sigWidth, $lineY, [0.0, 0.18, 0.42], 0.8);
            $this->line($sig2X, $lineY, $sig2X + $sigWidth, $lineY, [0.0, 0.18, 0.42], 0.8);
            $this->line($sig3X, $lineY, $sig3X + $sigWidth, $lineY, [0.0, 0.18, 0.42], 0.8);

            $nameY = $lineY - 12;
            // Name 1 (User Name)
            $x1 = $sig1X + max(0, ($sigWidth - strlen($user_name) * 9.5 * 0.48) / 2);
            $this->append($this->pdfText($x1, $nameY, $user_name, 9.5, 'F2', [0.1, 0.16, 0.23]));

            // Name 2: Shift Supervisor
            $txt2 = 'SHIFT SUPERVISOR';
            $x2 = $sig2X + max(0, ($sigWidth - strlen($txt2) * 9.5 * 0.48) / 2);
            $this->append($this->pdfText($x2, $nameY, $txt2, 9.5, 'F2', [0.1, 0.16, 0.23]));

            // Name 3: Station Manager
            $txt3 = 'STATION MANAGER';
            $x3 = $sig3X + max(0, ($sigWidth - strlen($txt3) * 9.5 * 0.48) / 2);
            $this->append($this->pdfText($x3, $nameY, $txt3, 9.5, 'F2', [0.1, 0.16, 0.23]));

            $subY = $nameY - 11;
            $subTxt = 'Signature over Printed Name';
            $sx1 = $sig1X + max(0, ($sigWidth - strlen($subTxt) * 8 * 0.48) / 2);
            $sx2 = $sig2X + max(0, ($sigWidth - strlen($subTxt) * 8 * 0.48) / 2);
            $sx3 = $sig3X + max(0, ($sigWidth - strlen($subTxt) * 8 * 0.48) / 2);
            $this->append($this->pdfText($sx1, $subY, $subTxt, 8, 'F1', [0.4, 0.45, 0.55]));
            $this->append($this->pdfText($sx2, $subY, $subTxt, 8, 'F1', [0.4, 0.45, 0.55]));
            $this->append($this->pdfText($sx3, $subY, $subTxt, 8, 'F1', [0.4, 0.45, 0.55]));
        } elseif ($user_role === 'staff') {
            // ── STAFF REPORT SIGNATURE BLOCK (PREPARED BY:) ──
            $this->y -= 20;
            $sigWidth = 180;
            $sigX = $this->width - $this->margin - $sigWidth;
            $staff_name = strtoupper(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?: strtoupper($u['username'] ?? 'STAFF');

            // Label: PREPARED BY:
            $label = 'PREPARED BY:';
            $labelSize = 9;
            $labelX = $sigX + ($sigWidth - strlen($label) * $labelSize * 0.48) / 2;
            $this->append($this->pdfText($labelX, $this->y, $label, $labelSize, 'F2', [0.2, 0.2, 0.2]));
            $this->y -= 22;

            // Horizontal signature line
            $this->line($sigX, $this->y, $sigX + $sigWidth, $this->y, [0, 0, 0], 0.8);
            $this->y -= 13; // drop below line

            // Name centered below line
            $nameSize = 10;
            $nameX = $sigX + ($sigWidth - strlen($staff_name) * $nameSize * 0.48) / 2;
            $this->append($this->pdfText($nameX, $this->y, $staff_name, $nameSize, 'F2', [0, 0, 0]));
            $this->y -= 13;

            // Role label: Staff
            $roleLabel = 'Staff';
            $roleLabelSize = 8.5;
            $roleLabelX = $sigX + ($sigWidth - strlen($roleLabel) * $roleLabelSize * 0.48) / 2;
            $this->append($this->pdfText($roleLabelX, $this->y, $roleLabel, $roleLabelSize, 'F1', [0.35, 0.35, 0.35]));
        } else {
            // ── SUPERADMIN / DEVELOPER SIGNATURE BLOCK (right-aligned) ──
            $this->y -= 20;
            $sigWidth = 180;
            $sigX = $this->width - $this->margin - $sigWidth;
            $developed_by_name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['username'] ?? 'System User');

            // Label: SYSTEM DEVELOPED BY:
            $label = 'SYSTEM DEVELOPED BY:';
            $labelSize = 9;
            $labelX = $sigX + ($sigWidth - strlen($label) * $labelSize * 0.48) / 2;
            $this->append($this->pdfText($labelX, $this->y, $label, $labelSize, 'F2', [0.2, 0.2, 0.2]));
            $this->y -= 22;

            // Horizontal signature line
            $this->line($sigX, $this->y, $sigX + $sigWidth, $this->y, [0, 0, 0], 0.8);
            $this->y -= 13; // drop below the line so name appears under it

            // Name centered below line
            $nameSize = 10;
            $nameX = $sigX + ($sigWidth - strlen($developed_by_name) * $nameSize * 0.48) / 2;
            $this->append($this->pdfText($nameX, $this->y, $developed_by_name, $nameSize, 'F2', [0, 0, 0]));
            $this->y -= 13;

            // Role label: Super Admin
            $roleLabel = 'Super Admin';
            $roleLabelSize = 8.5;
            $roleLabelX = $sigX + ($sigWidth - strlen($roleLabel) * $roleLabelSize * 0.48) / 2;
            $this->append($this->pdfText($roleLabelX, $this->y, $roleLabel, $roleLabelSize, 'F1', [0.35, 0.35, 0.35]));
        }
    }

    public function output(): string {
        $pageCount = count($this->pages);
        foreach ($this->pages as $idx => $content) {
            $pageNo = $idx + 1;
            $footer = $this->pdfText($this->width - $this->margin - 70, 18, "Page {$pageNo} of {$pageCount}", 7, 'F1', [0.35, 0.35, 0.35]);
            $this->pages[$idx] = $content . $footer;
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        $kids = [];
        foreach ($this->pages as $idx => $stream) {
            $pageObj = 5 + ($idx * 2);
            $contentObj = $pageObj + 1;
            $kids[] = "{$pageObj} 0 R";
            $objects[$pageObj] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$this->width} {$this->height}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentObj} 0 R >>";
            $objects[$contentObj] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
        return $pdf;
    }

    private function addPage(): void {
        $this->pages[] = '';
        $this->y = $this->height - $this->margin;
    }

    private function ensureSpace(float $needed): bool {
        if (($this->y - $needed) < ($this->margin + 26)) {
            $this->addPage();
            return true;
        }
        return false;
    }

    private function append(string $cmd): void {
        $idx = count($this->pages) - 1;
        $this->pages[$idx] .= $cmd;
    }

    private function title(string $title): void {
        $title = strtoupper(report_pdf_text($title));
        $size = 15;
        $x = max($this->margin, ($this->width - strlen($title) * $size * 0.52) / 2);
        $this->append($this->pdfText($x, $this->y, $title, $size, 'F2', [0.0, 0.16, 0.32]));
        $this->y -= 18;
        $this->line($this->margin, $this->y, $this->width - $this->margin, $this->y, [0.0, 0.16, 0.32], 0.8);
        $this->y -= 14;
    }

    private function lineText(string $text, float $size = 9, string $font = 'F1', array $color = [0, 0, 0], bool $center = false): void {
        $this->ensureSpace($size + 6);
        $x = $this->margin;
        if ($center) {
            $x = max($this->margin, ($this->width - strlen($text) * $size * 0.5) / 2);
        }
        $this->append($this->pdfText($x, $this->y, $text, $size, $font, $color));
        $this->y -= $size + 5;
    }

    private function table(string $title, array $headers, array $rows): void {
        if ($title !== '') {
            $this->ensureSpace(24);
            $this->append($this->pdfText($this->margin, $this->y, strtoupper($title), 10, 'F2', [0.0, 0.16, 0.32]));
            $this->y -= 15;
        }

        if (empty($headers)) {
            $headers = ['Details'];
        }
        if (empty($rows)) {
            $rows = [['No records found']];
        }

        $colCount = max(1, count($headers));
        $usable = $this->width - ($this->margin * 2);
        $fontSize = $colCount >= 13 ? 5.8 : ($colCount >= 10 ? 6.4 : 7.4);
        $lineHeight = $fontSize + 2.2;
        $widths = $this->columnWidths($headers, $rows, $usable, $fontSize);

        $drawHeader = function () use ($headers, $widths, $fontSize, $lineHeight): void {
            $wrapped = [];
            $maxLines = 1;
            foreach ($headers as $i => $header) {
                $lines = $this->wrap($header, $this->charsForWidth($widths[$i], $fontSize), 2);
                $wrapped[$i] = $lines;
                $maxLines = max($maxLines, count($lines));
            }
            $height = max(18, ($maxLines * $lineHeight) + 8);
            $this->ensureSpace($height + 12);
            $x = $this->margin;
            foreach ($headers as $i => $_) {
                $this->rect($x, $this->y, $widths[$i], $height, [0.90, 0.94, 0.98], [0.0, 0.16, 0.32]);
                $textY = $this->y - 7;
                foreach ($wrapped[$i] as $line) {
                    $this->append($this->pdfText($x + 3, $textY, strtoupper($line), $fontSize, 'F2', [0.0, 0.12, 0.25]));
                    $textY -= $lineHeight;
                }
                $x += $widths[$i];
            }
            $this->y -= $height;
        };

        $drawHeader();

        foreach ($rows as $row) {
            $row = array_pad(array_slice($row, 0, $colCount), $colCount, '');
            $wrapped = [];
            $maxLines = 1;
            foreach ($row as $i => $cell) {
                $lines = $this->wrap($cell, $this->charsForWidth($widths[$i], $fontSize), 4);
                $wrapped[$i] = $lines;
                $maxLines = max($maxLines, count($lines));
            }
            $height = max(17, ($maxLines * $lineHeight) + 7);
            if ($this->ensureSpace($height + 8)) {
                $drawHeader();
            }

            $x = $this->margin;
            foreach ($row as $i => $_) {
                $this->rect($x, $this->y, $widths[$i], $height, [1, 1, 1], [0.78, 0.82, 0.88]);
                $textY = $this->y - 7;
                foreach ($wrapped[$i] as $line) {
                    $this->append($this->pdfText($x + 3, $textY, $line, $fontSize, 'F1', [0.02, 0.07, 0.14]));
                    $textY -= $lineHeight;
                }
                $x += $widths[$i];
            }
            $this->y -= $height;
        }
    }

    private function columnWidths(array $headers, array $rows, float $usable, float $fontSize): array {
        $weights = [];
        foreach ($headers as $i => $header) {
            $weights[$i] = max(6, min(28, strlen($header) * 1.15));
        }
        $sampleRows = array_slice($rows, 0, 60);
        foreach ($sampleRows as $row) {
            foreach ($headers as $i => $_) {
                $cell = (string)($row[$i] ?? '');
                $weights[$i] = max($weights[$i], min(34, strlen($cell)));
            }
        }
        $total = max(1, array_sum($weights));
        $widths = [];
        foreach ($weights as $weight) {
            $widths[] = max(34, ($usable * $weight) / $total);
        }
        $sum = array_sum($widths);
        if ($sum > $usable) {
            $scale = $usable / $sum;
            foreach ($widths as $i => $width) {
                $widths[$i] = $width * $scale;
            }
        }
        return $widths;
    }

    private function charsForWidth(float $width, float $fontSize): int {
        return max(5, (int)floor(($width - 6) / ($fontSize * 0.48)));
    }

    private function wrap(string $text, int $maxChars, int $maxLines): array {
        $text = report_pdf_text($text);
        if ($text === '') {
            return [''];
        }
        $words = preg_split('/\s+/', $text) ?: [$text];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            while (strlen($word) > $maxChars) {
                $chunk = substr($word, 0, max(1, $maxChars - 1));
                $word = substr($word, strlen($chunk));
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $lines[] = $chunk . '-';
            }
            $try = $line === '' ? $word : $line . ' ' . $word;
            if (strlen($try) <= $maxChars) {
                $line = $try;
            } else {
                $lines[] = $line;
                $line = $word;
            }
            if (count($lines) >= $maxLines) {
                break;
            }
        }
        if ($line !== '' && count($lines) < $maxLines) {
            $lines[] = $line;
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
        }
        if (count($lines) === $maxLines && implode(' ', $lines) !== $text) {
            $last = $lines[$maxLines - 1];
            $lines[$maxLines - 1] = strlen($last) > 3 ? substr($last, 0, -3) . '...' : $last;
        }
        return $lines ?: [''];
    }

    private function rect(float $x, float $topY, float $w, float $h, array $fill, array $stroke): void {
        [$fr, $fg, $fb] = $fill;
        [$sr, $sg, $sb] = $stroke;
        $bottomY = $topY - $h;
        $this->append(sprintf("%.3F %.3F %.3F rg %.3F %.3F %.3F RG %.2F %.2F %.2F %.2F re B\n", $fr, $fg, $fb, $sr, $sg, $sb, $x, $bottomY, $w, $h));
    }

    private function line(float $x1, float $y1, float $x2, float $y2, array $color, float $width): void {
        [$r, $g, $b] = $color;
        $this->append(sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n", $r, $g, $b, $width, $x1, $y1, $x2, $y2));
    }

    private function pdfText(float $x, float $y, string $text, float $size, string $font, array $color): string {
        [$r, $g, $b] = $color;
        $text = str_replace(["\\", '(', ')'], ["\\\\", "\\(", "\\)"], report_pdf_text($text));
        return sprintf("%.3F %.3F %.3F rg BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $r, $g, $b, $font, $size, $x, $y, $text);
    }
}

$title = report_pdf_text($payload['title'] ?? 'Report');
$filename = report_pdf_filename((string)($payload['filename'] ?? $title . '_' . date('Ymd_His')));
$metaLines = [];
foreach (($payload['meta'] ?? []) as $line) {
    $clean = report_pdf_text($line);
    if ($clean !== '') {
        $metaLines[] = $clean;
    }
}
// Date printed line at the bottom of meta block
$metaLines[] = 'Date Printed: ' . date('M d, Y h:i A');

$sections = [];
foreach (($payload['sections'] ?? []) as $section) {
    if (!is_array($section)) {
        continue;
    }
    $sections[] = [
        'title' => $section['title'] ?? '',
        'headers' => is_array($section['headers'] ?? null) ? array_slice($section['headers'], 0, 24) : [],
        'rows' => is_array($section['rows'] ?? null) ? array_slice($section['rows'], 0, 2500) : [],
    ];
    if (count($sections) >= 20) {
        break;
    }
}

$pdf = new SimpleReportPdf();
$pdf->render($title, $metaLines, $sections);
$bytes = $pdf->output();

header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($bytes));
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo $bytes;
