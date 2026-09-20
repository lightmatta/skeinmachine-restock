<?php
declare(strict_types=1);

namespace App;

/**
 * Minimal PDF 1.4 writer for Restock Request Forms.
 * All body text is black; tables use black outlines.
 */
class Pdf
{
    /**
     * @param array{title?:string,subtitle?:string,meta?:list<string>,headers?:list<string>,rows?:list<list<string>>,footer?:string} $doc
     */
    public static function build(array $doc): string
    {
        $pageW = 595.28;
        $pageH = 841.89;
        $margin = 42;
        $pages = [];
        $ops = '';

        $flush = static function () use (&$pages, &$ops): void {
            $pages[] = $ops;
            $ops = '';
        };

        $add = static function (string $s) use (&$ops): void {
            $ops .= $s;
        };

        $text = static function (float $x, float $y, string $str, int $size, bool $bold = false) use ($add): void {
            $font = $bold ? 'F2' : 'F1';
            $safe = self::escape($str);
            $add(sprintf("0 0 0 rg BT /%s %d Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n", $font, $size, $x, $y, $safe));
        };

        $fill = static function (float $x, float $y, float $w, float $h, string $rgb) use ($add): void {
            $add(sprintf("%s rg %.2f %.2f %.2f %.2f re f\n", $rgb, $x, $y, $w, $h));
        };

        $title = (string)($doc['title'] ?? 'Report');
        $subtitle = (string)($doc['subtitle'] ?? '');
        $meta = $doc['meta'] ?? [];
        $headers = $doc['headers'] ?? [];
        $rows = $doc['rows'] ?? [];
        $footer = (string)($doc['footer'] ?? '');

        $usable = $pageW - 2 * $margin;
        $fill($margin, $pageH - 34, $usable, 8, '0 0 0');
        $text($margin, $pageH - $margin - 8, $title, 18, true);
        $y = $pageH - $margin - 30;
        if ($subtitle !== '') {
            $text($margin, $y, $subtitle, 12, true);
            $y -= 18;
        }
        foreach ($meta as $line) {
            $text($margin, $y, (string)$line, 10, false);
            $y -= 14;
        }
        $y -= 10;

        $n = max(1, count($headers));
        if ($n === 7) {
            $widths = [68, 62, $usable - 68 - 62 - 78 - 40 - 54 - 36, 78, 40, 54, 36];
        } elseif ($n === 6) {
            $widths = [78, 78, $usable - 78 - 78 - 44 - 62 - 44, 44, 62, 44];
        } elseif ($n === 5) {
            $widths = [86, 86, $usable - 86 - 86 - 48 - 70, 48, 70];
        } else {
            $even = $usable / $n;
            $widths = array_fill(0, $n, $even);
        }
        $colX = [];
        $x = $margin;
        foreach ($widths as $w) {
            $colX[] = $x;
            $x += $w;
        }
        $clips = $n === 7 ? [10, 10, 22, 12, 5, 7, 5] : ($n === 6 ? [12, 12, 28, 6, 8, 6] : array_fill(0, $n, 22));
        $rowH = 18;
        $headerH = 20;

        $drawHeader = static function (float $headerY) use ($add, $text, $fill, $margin, $usable, $headers, $colX, $headerH): void {
            $bottom = $headerY - 5;
            $fill($margin, $bottom, $usable, $headerH, '0.93 0.93 0.93');
            $add("0 0 0 RG 0.8 w\n");
            $add(sprintf("%.2f %.2f %.2f %.2f re S\n", $margin, $bottom, $usable, $headerH));
            foreach ($headers as $i => $h) {
                $text(($colX[$i] ?? $margin) + 4, $headerY, (string)$h, 8, true);
                if ($i > 0) {
                    $add(sprintf("%.2f %.2f m %.2f %.2f l S\n", $colX[$i], $bottom, $colX[$i], $bottom + $headerH));
                }
            }
        };

        $drawHeader($y);
        $y -= $headerH + 2;

        foreach ($rows as $row) {
            if ($y < $margin + 48) {
                $flush();
                $y = $pageH - $margin;
                $drawHeader($y);
                $y -= $headerH + 2;
            }
            $bottom = $y - 5;
            $add("0 0 0 RG 0.6 w\n");
            $add(sprintf("%.2f %.2f %.2f %.2f re S\n", $margin, $bottom, $usable, $rowH));
            foreach ($row as $i => $cell) {
                $max = $clips[$i] ?? 20;
                $text(($colX[$i] ?? $margin) + 4, $y, self::clip((string)$cell, $max), 8, false);
                if ($i > 0) {
                    $add(sprintf("%.2f %.2f m %.2f %.2f l S\n", $colX[$i], $bottom, $colX[$i], $bottom + $rowH));
                }
            }
            $y -= $rowH;
        }

        $y -= 14;
        if ($footer !== '') {
            $text($margin, max($margin, $y), $footer, 9, false);
        }
        $flush();

        return self::wrap($pages, $pageW, $pageH);
    }

    private static function clip(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, max(1, $max - 3)) . '...';
    }

    private static function escape(string $s): string
    {
        $s = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
        return $s;
    }

    /**
     * @param list<string> $pages
     */
    private static function wrap(array $pages, float $pageW, float $pageH): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $font1 = 3;
        $font2 = 4;
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $next = 5;
        $pageIds = [];
        foreach ($pages as $content) {
            $stream = $content;
            $cid = $next++;
            $pid = $next++;
            $objects[$cid] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
            $objects[$pid] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> >>',
                $pageW,
                $pageH,
                $cid,
                $font1,
                $font2
            );
            $pageIds[] = $pid;
            $kids[] = $pid . ' 0 R';
        }
        $objects[2] = '<< /Type /Pages /Count ' . count($pageIds) . ' /Kids [' . implode(' ', $kids) . '] >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= 'xref
0 ' . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= 'trailer
<< /Size ' . ($maxId + 1) . ' /Root 1 0 R >>
startxref
' . $xref . '
%%EOF';
        return $pdf;
    }
}
