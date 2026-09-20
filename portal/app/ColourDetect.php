<?php
declare(strict_types=1);

namespace App;

/**
 * Dominant-colour analysis for product feature images.
 *
 * Pipeline (K-Means on a tiny RGB sample, white background ignored):
 *  1. Fetch the feature image at width=50
 *  2. Downsample to 50×50 and drop near-white pixels
 *  3. Cluster remaining pixels (k ≤ 8, prefer 3 names)
 *  4. Map cluster centres to simple colour names
 *  5. Flag variegated when more than two distinct colours are found
 */
class ColourDetect
{
    public const FETCH_WIDTH = 50;
    public const PREFERRED_MAX = 3;
    public const HARD_MAX = 8;
    public const WHITE_MIN = 240;
    public const MIN_SHARE = 0.06;

    /**
     * Named colours used for mapping (RGB). Keys must be Catalog slugs
     * (or aliases Catalog::parseColours understands).
     *
     * @return array<string,array{0:int,1:int,2:int}>
     */
    public static function colourDb(): array
    {
        return [
            'red'    => [229, 57, 53],
            'coral'  => [255, 111, 97],
            'orange' => [251, 140, 0],
            'gold'   => [212, 175, 55],
            'yellow' => [253, 216, 53],
            'green'  => [67, 160, 71],
            'teal'   => [0, 137, 123],
            'blue'   => [30, 136, 229],
            'navy'   => [26, 35, 126],
            'purple' => [142, 36, 170],
            'pink'   => [236, 64, 122],
            'brown'  => [109, 76, 65],
            'grey'   => [117, 117, 117],
            'black'  => [33, 33, 33],
        ];
    }

    public static function analyseProduct(array $product): string
    {
        $src = Catalog::featureImage($product);
        if ($src === '') {
            return '';
        }
        $url = Catalog::sizedUrl($src, self::FETCH_WIDTH);
        $bytes = self::fetchBytes($url);
        if ($bytes === '') {
            return '';
        }
        return self::analyseBytes($bytes);
    }

    /**
     * Resolve colours for a Shopify/CSV upsert.
     * Existing admin-edited colours are kept unless detect-on-import is on.
     */
    public static function resolveImportColours(?string $existing, string $sourceColours, array $product): string
    {
        $source = Catalog::coloursCsv($sourceColours);
        if (!Settings::detectColoursOnImport()) {
            return $existing !== null ? $existing : $source;
        }
        $detected = self::analyseProduct($product);
        if ($detected !== '') {
            return $detected;
        }
        if ($existing !== null && $existing !== '') {
            return $existing;
        }
        return $source;
    }

    public static function analyseBytes(string $bytes): string
    {
        if ($bytes === '' || !function_exists('imagecreatefromstring')) {
            return '';
        }
        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return '';
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 1 || $h < 1) {
            imagedestroy($im);
            return '';
        }
        $tw = min(self::FETCH_WIDTH, $w);
        $th = min(self::FETCH_WIDTH, $h);
        if ($tw !== $w || $th !== $h) {
            $small = imagecreatetruecolor($tw, $th);
            imagecopyresampled($small, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
            imagedestroy($im);
            $im = $small;
            $w = $tw;
            $h = $th;
        }

        $pixels = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($im, $x, $y);
                $r = ($rgb >> 16) & 255;
                $g = ($rgb >> 8) & 255;
                $b = $rgb & 255;
                if ($r >= self::WHITE_MIN && $g >= self::WHITE_MIN && $b >= self::WHITE_MIN) {
                    continue;
                }
                $pixels[] = [$r, $g, $b];
            }
        }
        imagedestroy($im);
        if (!$pixels) {
            return 'white';
        }

        $unique = [];
        foreach ($pixels as $p) {
            $key = (int)floor($p[0] / 16) . ':' . (int)floor($p[1] / 16) . ':' . (int)floor($p[2] / 16);
            $unique[$key] = true;
        }
        $k = min(self::HARD_MAX, max(1, count($unique)));
        $clusters = self::kmeans($pixels, $k);
        usort($clusters, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        $total = count($pixels);
        $named = [];
        foreach ($clusters as $c) {
            if (($c['count'] / $total) < self::MIN_SHARE && count($named) >= 1) {
                continue;
            }
            $name = self::nearestName($c['rgb']);
            if ($name === '' || isset($named[$name])) {
                continue;
            }
            $named[$name] = $c['count'];
            if (count($named) >= self::HARD_MAX) {
                break;
            }
        }
        if (!$named) {
            $named[self::nearestName($clusters[0]['rgb'])] = $clusters[0]['count'];
        }

        $names = array_keys($named);
        $distinct = count($names);
        if (count($names) > self::PREFERRED_MAX) {
            $names = array_slice($names, 0, self::PREFERRED_MAX);
        }
        if ($distinct > 2 && !in_array('variegated', $names, true)) {
            $names[] = 'variegated';
        }
        return Catalog::coloursCsv(implode(',', $names));
    }

    /** @param list<array{0:int,1:int,2:int}> $pixels */
    private static function nearestName(array $rgb): string
    {
        $best = '';
        $bestD = PHP_FLOAT_MAX;
        foreach (self::colourDb() as $name => $ref) {
            $dr = $rgb[0] - $ref[0];
            $dg = $rgb[1] - $ref[1];
            $db = $rgb[2] - $ref[2];
            $d = $dr * $dr + $dg * $dg + $db * $db;
            if ($d < $bestD) {
                $bestD = $d;
                $best = $name;
            }
        }
        return $best;
    }

    /**
     * @param list<array{0:int,1:int,2:int}> $pixels
     * @return list<array{rgb:array{0:int,1:int,2:int},count:int}>
     */
    private static function kmeans(array $pixels, int $k): array
    {
        $n = count($pixels);
        $k = max(1, min($k, $n));
        $centres = [];
        $step = max(1, (int)floor($n / $k));
        for ($i = 0; $i < $k; $i++) {
            $centres[] = $pixels[min($n - 1, $i * $step)];
        }
        $assign = array_fill(0, $n, 0);
        for ($iter = 0; $iter < 8; $iter++) {
            $changed = false;
            for ($i = 0; $i < $n; $i++) {
                $best = 0;
                $bestD = PHP_FLOAT_MAX;
                for ($c = 0; $c < $k; $c++) {
                    $dr = $pixels[$i][0] - $centres[$c][0];
                    $dg = $pixels[$i][1] - $centres[$c][1];
                    $db = $pixels[$i][2] - $centres[$c][2];
                    $d = $dr * $dr + $dg * $dg + $db * $db;
                    if ($d < $bestD) {
                        $bestD = $d;
                        $best = $c;
                    }
                }
                if ($assign[$i] !== $best) {
                    $assign[$i] = $best;
                    $changed = true;
                }
            }
            $sum = array_fill(0, $k, [0, 0, 0, 0]);
            for ($i = 0; $i < $n; $i++) {
                $c = $assign[$i];
                $sum[$c][0] += $pixels[$i][0];
                $sum[$c][1] += $pixels[$i][1];
                $sum[$c][2] += $pixels[$i][2];
                $sum[$c][3]++;
            }
            for ($c = 0; $c < $k; $c++) {
                if ($sum[$c][3] < 1) {
                    $centres[$c] = $pixels[random_int(0, $n - 1)];
                    continue;
                }
                $centres[$c] = [
                    (int)round($sum[$c][0] / $sum[$c][3]),
                    (int)round($sum[$c][1] / $sum[$c][3]),
                    (int)round($sum[$c][2] / $sum[$c][3]),
                ];
            }
            if (!$changed) {
                break;
            }
        }
        $counts = array_fill(0, $k, 0);
        foreach ($assign as $c) {
            $counts[$c]++;
        }
        $out = [];
        for ($c = 0; $c < $k; $c++) {
            if ($counts[$c] < 1) {
                continue;
            }
            $out[] = ['rgb' => $centres[$c], 'count' => $counts[$c]];
        }
        return $out ?: [['rgb' => $pixels[0], 'count' => $n]];
    }

    public static function fetchBytes(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url) && is_file($url)) {
            $bin = @file_get_contents($url);
            return $bin === false ? '' : $bin;
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'HouseDye-ColourDetect/1.0',
            ]);
            $bin = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($bin) && $bin !== '' && $code < 400) {
                return $bin;
            }
        }
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'follow_location' => 1, 'header' => "User-Agent: HouseDye-ColourDetect/1.0\r\n"]]);
        $bin = @file_get_contents($url, false, $ctx);
        return $bin === false ? '' : $bin;
    }
}
