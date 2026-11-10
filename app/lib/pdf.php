<?php
declare(strict_types=1);

/*
 * A small PDF writer for the script downloads: text in the four standard fonts (no font files to embed), filled
 * rectangles for highlights, lines for crossing out, several pages, page numbers. No library needed.
 *
 * Text is set in Windows-1252 (the standard fonts' encoding), so accented Latin letters, curly quotes and dashes
 * print; anything else (other alphabets) prints as "?".
 */

final class TinyPdf
{
    public const W = 612.0;   // US Letter, in points
    public const H = 792.0;
    private const FONTS = ['R' => 'Helvetica', 'B' => 'Helvetica-Bold', 'I' => 'Helvetica-Oblique', 'M' => 'Courier'];

    /** Character widths (per 1000) for ASCII; other characters use a typical width. */
    private const WR = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];
    private const WB = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];
    private const HIGH = [0x85 => 1000, 0x91 => 222, 0x92 => 222, 0x93 => 333, 0x94 => 333, 0x96 => 556, 0x97 => 1000, 0xA0 => 278];

    /** @var string[] content stream of each page */
    private array $pages = [];
    private int $cur = -1;

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->cur = count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function setPage(int $i): void
    {
        $this->cur = $i;
    }

    /** UTF-8 to the fonts' encoding. */
    public static function enc(string $s): string
    {
        $s = str_replace(["\r", "\n", "\t"], ' ', $s);
        $out = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s) : false;
        return $out === false ? (string)mb_convert_encoding($s, 'Windows-1252', 'UTF-8') : $out;
    }

    /** Width of (already encoded) text in points. */
    public function width(string $enc, string $font, float $size): float
    {
        if ($font === 'M') {
            return strlen($enc) * 600 * $size / 1000;
        }
        $t = $font === 'B' ? self::WB : self::WR;
        $w = 0;
        $n = strlen($enc);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($enc[$i]);
            $w += $c >= 32 && $c < 127 ? $t[$c - 32] : (self::HIGH[$c] ?? 556);
        }
        return $w * $size / 1000;
    }

    /** Break text into lines no wider than $max points. Returns encoded lines. */
    public function wrap(string $text, string $font, float $size, float $max): array
    {
        $words = preg_split('/ +/', trim(self::enc($text))) ?: [''];
        $lines = [];
        $line = '';
        foreach ($words as $w) {
            $try = $line === '' ? $w : "$line $w";
            if ($line !== '' && $this->width($try, $font, $size) > $max) {
                $lines[] = $line;
                $line = $w;
            } else {
                $line = $try;
            }
            // A single word longer than the line: cut it.
            while ($this->width($line, $font, $size) > $max && strlen($line) > 1) {
                $cut = strlen($line) - 1;
                while ($cut > 1 && $this->width(substr($line, 0, $cut), $font, $size) > $max) {
                    $cut--;
                }
                $lines[] = substr($line, 0, $cut);
                $line = substr($line, $cut);
            }
        }
        $lines[] = $line;
        return $lines;
    }

    private static function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        return sprintf('%.3f %.3f %.3f', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }

    /** Text at x, baseline y (from the top of the page). $enc is already encoded. */
    public function text(float $x, float $y, string $enc, string $font = 'R', float $size = 10, string $color = '#1c2025'): void
    {
        $esc = strtr($enc, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
        $this->pages[$this->cur] .= sprintf("BT /F%s %.2f Tf %s rg %.2f %.2f Td (%s) Tj ET\n", $font, $size, self::rgb($color), $x, self::H - $y, $esc);
    }

    /** A filled rectangle; y is its top edge, from the top of the page. */
    public function rect(float $x, float $y, float $w, float $h, string $color): void
    {
        $this->pages[$this->cur] .= sprintf("%s rg %.2f %.2f %.2f %.2f re f\n", self::rgb($color), $x, self::H - $y - $h, $w, $h);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color, float $width = 0.8): void
    {
        $this->pages[$this->cur] .= sprintf("%s RG %.2f w %.2f %.2f m %.2f %.2f l S\n", self::rgb($color), $width, $x1, self::H - $y1, $x2, self::H - $y2);
    }

    public function output(string $title): string
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $fontIds = [];
        $n = 3;
        foreach (self::FONTS as $k => $name) {
            $fontIds[$k] = $n;
            $objs[$n++] = "<< /Type /Font /Subtype /Type1 /BaseFont /$name /Encoding /WinAnsiEncoding >>";
        }
        $res = '<< /Font << ' . implode(' ', array_map(fn($k) => "/F$k {$fontIds[$k]} 0 R", array_keys(self::FONTS))) . ' >> >>';
        $kids = [];
        foreach ($this->pages as $content) {
            $z = function_exists('gzcompress') ? gzcompress($content) : false;
            $stream = $z !== false ? "<< /Length " . strlen($z) . " /Filter /FlateDecode >>\nstream\n$z\nendstream" : "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream";
            $cid = $n++;
            $objs[$cid] = $stream;
            $pid = $n++;
            $objs[$pid] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0f %.0f] /Resources %s /Contents %d 0 R >>', self::W, self::H, $res, $cid);
            $kids[] = "$pid 0 R";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $info = $n++;
        $esc = strtr(self::enc($title), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
        $objs[$info] = "<< /Title ($esc) /Producer (Previously Noted) /CreationDate (D:" . gmdate('YmdHis') . "Z) >>";
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($n) . "\n0000000000 65535 f \n";
        for ($i = 1; $i < $n; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size $n /Root 1 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }
}

/**
 * The script as a PDF. $rows as built by the script.export action (tc, end, speaker, line, kind, state, was).
 * Changed lines are highlighted: rewritten in yellow (with what it was underneath), new lines in green, crossed-out
 * lines grey and struck through.
 */
function script_pdf(string $heading, string $title, string $stamp, string $content, array $rows): string
{
    $pdf = new TinyPdf();
    $L = 48.0;               // left margin
    $R = TinyPdf::W - 48;    // right edge
    $TX = 132.0;             // where the text column starts
    $TW = $R - $TX - 6;      // text column width
    $top = 56.0;
    $bottom = TinyPdf::H - 52;
    $size = 10.5;
    $lead = 14.5;
    $colors = ['rewritten' => '#fbe7a1', 'new' => '#cdebd2', 'crossed out' => '#e6e6e6'];
    $labels = ['rewritten' => 'REWRITTEN', 'new' => 'NEW LINE', 'crossed out' => 'CROSSED OUT'];
    $labelColors = ['rewritten' => '#8a6200', 'new' => '#1e6b34', 'crossed out' => '#6b6b6b'];

    $pdf->addPage();
    $y = $top;
    $pdf->text($L, $y, TinyPdf::enc($title), 'B', 17);
    $y += 18;
    $pdf->text($L, $y, TinyPdf::enc($heading . ' · exported ' . $stamp), 'R', 9, '#5c636b');
    $y += 16;
    if ($content !== 'original') {
        // Legend.
        $x = $L;
        foreach (['rewritten' => 'Rewritten (what it was is underneath)', 'new' => 'New line', 'crossed out' => 'Crossed out'] as $k => $t) {
            $pdf->rect($x, $y - 8.5, 10, 10, $colors[$k]);
            $enc = TinyPdf::enc($t);
            $pdf->text($x + 14, $y, $enc, 'R', 8.5, '#5c636b');
            $x += 14 + $pdf->width($enc, 'R', 8.5) + 16;
        }
        $y += 8;
    }
    $pdf->line($L, $y, $R, $y, '#d0d3d7', 0.6);
    $y += 18;

    $newPage = function () use ($pdf, $top, &$y) {
        $pdf->addPage();
        $y = $top;
    };
    $lastSpeaker = null;
    if (!$rows) {
        $pdf->text($TX, $y, TinyPdf::enc($content === 'changes' ? 'No lines have been changed yet.' : 'The transcript is empty.'), 'I', $size, '#5c636b');
    }
    foreach ($rows as $r) {
        $kind = $r['kind'];
        $changed = $kind !== 'same';
        $text = $kind === 'crossed out' ? $r['was'] : $r['line'];
        $lines = $pdf->wrap($text, 'R', $size, $TW);
        $was = $kind === 'rewritten' && $r['was'] !== '' ? $pdf->wrap('was: ' . $r['was'], 'I', 8.5, $TW) : [];
        $speaker = (string)$r['speaker'];
        $showSpeaker = $speaker !== '' && $speaker !== $lastSpeaker;
        $need = ($showSpeaker ? 14 : 0) + count($lines) * $lead + count($was) * 11.5 + 6;
        if ($y + min($need, 4 * $lead) > $bottom) {
            $newPage();
            $showSpeaker = $speaker !== '';
        }
        if ($showSpeaker) {
            $pdf->text($TX, $y, TinyPdf::enc(mb_strtoupper($speaker)), 'B', 8, '#5c636b');
            $y += 13;
        }
        $lastSpeaker = $speaker;
        $first = true;
        foreach ($lines as $ln) {
            if ($y + $lead > $bottom + 4) {
                $newPage();
            }
            if ($changed) {
                $pdf->rect($TX - 6, $y - 10.5, $TW + 12, $lead, $colors[$kind]);
            }
            if ($first) {
                $pdf->text($L, $y, TinyPdf::enc($r['tc']), 'M', 8.5, '#5c636b');
                if ($changed) {
                    $lab = $labels[$kind] . ($r['state'] === 'draft' ? ' · DRAFT' : '');
                    $pdf->text($L, $y + 10, TinyPdf::enc($lab), 'B', 6, $labelColors[$kind]);
                }
                $first = false;
            }
            $pdf->text($TX, $y, $ln, 'R', $size, $kind === 'crossed out' ? '#6b6b6b' : '#1c2025');
            if ($kind === 'crossed out') {
                $pdf->line($TX, $y - 3.4, $TX + $pdf->width($ln, 'R', $size), $y - 3.4, '#6b6b6b', 0.8);
            }
            $y += $lead;
        }
        foreach ($was as $ln) {
            if ($y + 11.5 > $bottom + 4) {
                $newPage();
            }
            $pdf->rect($TX - 6, $y - 9.5, $TW + 12, 11.5, $colors['rewritten']);
            $pdf->text($TX, $y - 1, $ln, 'I', 8.5, '#6b5a2a');
            $y += 11.5;
        }
        // Room under a one-line change for its label, so the label reads with its own line.
        $y += $changed && count($lines) === 1 && !$was ? 10 : 5;
    }

    // Page numbers.
    $n = $pdf->pageCount();
    for ($i = 0; $i < $n; $i++) {
        $pdf->setPage($i);
        $foot = TinyPdf::enc("$heading · $title · page " . ($i + 1) . " of $n");
        $pdf->text($L, TinyPdf::H - 30, $foot, 'R', 7.5, '#8a9098');
    }
    return $pdf->output("$heading · $title");
}
