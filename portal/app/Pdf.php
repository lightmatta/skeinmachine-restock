<?php
declare(strict_types=1);

namespace App;

/**
 * Minimal PDF 1.4 writer for single-page (or multi-page) A4 reports.
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
        $margin = 48;
        $pages = [];
        $y = $pageH - $margin;
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
            $add(sprintf("BT /%s %d Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n", $font, $size, $x, $y, $safe));
        };

        $rule = static function (float $x, float $y, float $w, float $h, string $rgb = '0.12 0.12 0.12') use ($add): void {
            $add(sprintf("%s rg %.2f %.2f %.2f %.2f re f\n", $rgb, $x, $y, $w, $h));
        };

        $title = (string)($doc['title'] ?? 'Report');
        $subtitle = (string)($doc['subtitle'] ?? '');
        $meta = $doc['meta'] ?? [];
        $headers = $doc['headers'] ?? [];
        $rows = $doc['rows'] ?? [];
        $footer = (string)($doc['footer'] ?? '');

        $rule($margin, $pageH - 36, $pageW - 2 * $margin, 10, '0.18 0.18 0.18');
        $text($margin, $y - 6, $title, 18, true);
        $y -= 28;
        if ($subtitle !== '') {
            $text($margin, $y, $subtitle, 12, true);
            $y -= 18;
        }
        foreach ($meta as $line) {
            $text($margin, $y, (string)$line, 10, false);
            $y -= 14;
        }
        $y -= 8;
        $rule($margin, $y + 8, $pageW - 2 * $margin, 0.8, '0.75 0.75 0.75');

        $colX = [$margin, $margin + 90, $margin + 190, $margin + 360, $margin + 430];
        $usable = $pageW - 2 * $margin;
        if (count($headers) === 5) {
            $colX = [$margin, $margin + 86, $margin + 186, $margin + 348, $margin + 428];
        }
        $rowH = 16;
        $headerY = $y;
        $rule($margin, $headerY - 4, $usable, 18, '0.95 0.95 0.95');
        foreach ($headers as $i => $h) {
            $text($colX[$i] ?? $margin, $headerY, (string)$h, 9, true);
        }
        $y = $headerY - 22;

        foreach ($rows as $row) {
            if ($y < $margin + 40) {
                $flush();
                $y = $pageH - $margin;
            }
            foreach ($row as $i => $cell) {
                $text($colX[$i] ?? $margin, $y, self::clip((string)$cell, 42), 9, false);
            }
            $y -= $rowH;
        }

        $y -= 10;
        if ($footer !== '') {
            $text($margin, max($margin, $y), $footer, 10, false);
        }
        $flush();

        return self::wrap($pages, $pageW, $pageH);
    }

    private static function clip(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max - 1) . '…';
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
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $next = 5;
        $contentIds = [];
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
            $contentIds[] = $cid;
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
