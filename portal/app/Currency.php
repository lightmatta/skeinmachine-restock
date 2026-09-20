<?php
declare(strict_types=1);

namespace App;

/**
 * Company transaction currency plus optional wholesale display-currency
 * estimates. Ledger amounts stay in the company currency (default AUD).
 */
class Currency
{
    public const DEFAULT = 'AUD';
    private const CACHE_TTL = 4 * 3600;
    private const RATES_URL = 'https://api.frankfurter.dev/v1/latest?from=';

    /** @var null|callable(string):?string */
    public static $transport = null;

    /** @return array<string,string> ISO code => English name */
    public static function codes(): array
    {
        return [
            'AUD' => 'Australian Dollar',
            'USD' => 'US Dollar',
            'EUR' => 'Euro',
            'GBP' => 'Pound Sterling',
            'NZD' => 'New Zealand Dollar',
            'CAD' => 'Canadian Dollar',
            'JPY' => 'Japanese Yen',
            'SGD' => 'Singapore Dollar',
            'HKD' => 'Hong Kong Dollar',
            'CHF' => 'Swiss Franc',
        ];
    }

    public static function normalize(?string $code, string $fallback = self::DEFAULT): string
    {
        $code = strtoupper(trim((string)$code));
        return isset(self::codes()[$code]) ? $code : $fallback;
    }

    public static function company(): string
    {
        return self::normalize(Settings::get('currency', self::DEFAULT), self::DEFAULT);
    }

    /** Wholesale client's preferred display currency, else the company currency. */
    public static function viewer(): string
    {
        if (!Auth::isWholesale()) {
            return self::company();
        }
        $user = Auth::dbUser();
        $pref = strtoupper(trim((string)($user['preferred_currency'] ?? '')));
        if ($pref !== '' && isset(self::codes()[$pref])) {
            return $pref;
        }
        return self::company();
    }

    public static function format(int $cents, ?string $code = null): string
    {
        $code = self::normalize($code ?? self::company());
        return '$' . number_format($cents / 100, 2) . ' ' . $code;
    }

    /** @return array<string,float> */
    public static function rates(string $base): array
    {
        $base = self::normalize($base);
        $cachedBase = (string)Settings::get('fx_base', '');
        $fetched = (int)Settings::get('fx_rates_fetched_at', '0');
        $json = (string)Settings::get('fx_rates_json', '');
        $fresh = $cachedBase === $base && $json !== '' && (time() - $fetched) < self::CACHE_TTL;
        if ($fresh) {
            $decoded = json_decode($json, true);
            return is_array($decoded) ? self::floatMap($decoded) : [];
        }
        $fetchedRates = self::downloadRates($base);
        if ($fetchedRates) {
            $fetchedRates[$base] = 1.0;
            Settings::set('fx_base', $base);
            Settings::set('fx_rates_json', json_encode($fetchedRates));
            Settings::set('fx_rates_fetched_at', (string)time());
            return $fetchedRates;
        }
        if ($cachedBase === $base && $json !== '') {
            $decoded = json_decode($json, true);
            return is_array($decoded) ? self::floatMap($decoded) : [];
        }
        return [$base => 1.0];
    }

    public static function rate(string $from, string $to): ?float
    {
        $from = self::normalize($from);
        $to = self::normalize($to);
        if ($from === $to) {
            return 1.0;
        }
        $rates = self::rates($from);
        if (!isset($rates[$to])) {
            return null;
        }
        $n = (float)$rates[$to];
        return $n > 0 ? $n : null;
    }

    public static function convert(int $cents, string $from, string $to): ?int
    {
        $rate = self::rate($from, $to);
        if ($rate === null) {
            return null;
        }
        return (int)round($cents * $rate);
    }

    /**
     * Compact “≈ $12.00 USD” for table cells. Empty when no conversion applies.
     */
    public static function compactEstimate(int $cents): string
    {
        $est = self::converted($cents);
        if ($est === null) {
            return '';
        }
        return '<div class="fx-compact">≈ ' . e(self::format($est['cents'], $est['code'])) . '</div>';
    }

    /**
     * Block estimate + billing disclaimer for cart/order/print totals.
     */
    public static function totalEstimate(int $cents): string
    {
        $est = self::converted($cents);
        if ($est === null) {
            return '';
        }
        $home = self::company();
        $rate = $est['rate'];
        $rateTxt = rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.');
        return '<div class="fx-estimate">'
            . '<div class="fx-amount">≈ ' . e(self::format($est['cents'], $est['code'])) . '</div>'
            . '<p class="help">Convenience estimate only. This transaction is billed in '
            . e($home) . '. Exchange rates may vary at the time of payment'
            . ' (indicative 1 ' . e($home) . ' = ' . e($rateTxt) . ' ' . e($est['code']) . ').'
            . '</p></div>';
    }

    /** Seed cached rates (tests). */
    public static function primeRates(string $base, array $rates): void
    {
        $base = self::normalize($base);
        $rates[$base] = 1.0;
        Settings::set('fx_base', $base);
        Settings::set('fx_rates_json', json_encode(self::floatMap($rates)));
        Settings::set('fx_rates_fetched_at', (string)time());
    }

    /** @return array{cents:int,code:string,rate:float}|null */
    private static function converted(int $cents): ?array
    {
        $home = self::company();
        $view = self::viewer();
        if ($view === $home) {
            return null;
        }
        $rate = self::rate($home, $view);
        if ($rate === null) {
            return null;
        }
        return [
            'cents' => (int)round($cents * $rate),
            'code'  => $view,
            'rate'  => $rate,
        ];
    }

    /** @return array<string,float> */
    private static function downloadRates(string $base): array
    {
        $url = self::RATES_URL . rawurlencode($base);
        $raw = null;
        if (self::$transport) {
            $raw = (self::$transport)($url);
        } else {
            $raw = self::httpGet($url);
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['rates']) || !is_array($decoded['rates'])) {
            return [];
        }
        return self::floatMap($decoded['rates']);
    }

    private static function httpGet(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
            $raw = @file_get_contents($url, false, $ctx);
            return is_string($raw) ? $raw : null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $status >= 400) {
            return null;
        }
        return $raw;
    }

    /** @param array<mixed,mixed> $in @return array<string,float> */
    private static function floatMap(array $in): array
    {
        $out = [];
        foreach ($in as $k => $v) {
            $code = strtoupper((string)$k);
            if (!isset(self::codes()[$code])) {
                continue;
            }
            $n = (float)$v;
            if ($n > 0) {
                $out[$code] = $n;
            }
        }
        return $out;
    }
}
