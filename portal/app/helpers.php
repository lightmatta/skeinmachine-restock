<?php
declare(strict_types=1);

/**
 * Global helper functions used across controllers and views.
 */

/** HTML-escape a string for safe output. */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an application URL for a route (query-string routing for portability). */
function url(string $route = 'home', array $params = []): string
{
    $params = array_merge(['r' => $route], $params);
    return 'index.php?' . http_build_query($params);
}

/** Format integer cents in the company transaction currency (default AUD). */
function money(int $cents, ?string $currency = null): string
{
    return \App\Currency::format($cents, $currency);
}

/** Compact wholesale FX estimate for a table cell (empty when not applicable). */
function fx_compact(int $cents): string
{
    return \App\Currency::compactEstimate($cents);
}

/** Block FX estimate + billed-in-company-currency disclaimer for totals. */
function fx_total_note(int $cents): string
{
    return \App\Currency::totalEstimate($cents);
}

/**
 * Order money: line subtotal, admin Additional Discount, and billable total.
 *
 * @param array<string,mixed> $order
 * @param list<array<string,mixed>> $items
 * @return array{subtotal:int,discount:int,total:int}
 */
function order_totals(array $order, array $items = []): array
{
    $subtotal = 0;
    foreach ($items as $it) {
        $subtotal += (int)($it['line_total_cents'] ?? 0);
    }
    if (!$items && isset($order['total_cents'])) {
        $subtotal = (int)$order['total_cents'] + max(0, (int)($order['manual_discount_cents'] ?? 0));
    }
    $discount = max(0, (int)($order['manual_discount_cents'] ?? 0));
    return [
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total'    => max(0, $subtotal - $discount),
    ];
}

/** Send a JSON response and stop. */
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Read and decode a JSON request body (falls back to form params). */
function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

/** Redirect to a route and stop. */
function redirect(string $route = 'home', array $params = []): void
{
    header('Location: ' . url($route, $params));
    exit;
}

/** Current CSRF token (created if missing). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Hidden input holding the CSRF token. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Validate a CSRF token from the request (form or JSON). */
function csrf_check(): bool
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '') {
        $body = request_json();
        $sent = $body['csrf'] ?? '';
    }
    return is_string($sent) && hash_equals($_SESSION['csrf'] ?? '', $sent);
}

/** Whether the current request expects a JSON body rather than a page. */
function wants_json(): bool
{
    $route = (string)($_GET['r'] ?? '');
    if (($_GET['format'] ?? '') === 'json' || $route === 'api' || $route === 'admin/api') {
        return true;
    }
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
}

/** Abort with an HTTP status and short message. */
function abort(int $status, string $message = ''): void
{
    http_response_code($status);
    $message = $message !== '' ? $message : 'Error ' . $status;
    if (wants_json()) {
        json_response(['error' => 'http_' . $status, 'message' => $message], $status);
    }
    try {
        // Rendered through the normal layout so error pages carry the header too.
        App\View::render('errors/error', [
            'title'   => 'Error ' . $status,
            'status'  => $status,
            'message' => $message,
        ]);
    } catch (\Throwable $ex) {
        echo e($message);
    }
    exit;
}

/** Human-friendly relative-ish timestamp. */
function fmt_datetime(?string $ts): string
{
    if (!$ts) {
        return '—';
    }
    $t = strtotime($ts . ' UTC');
    if ($t === false) {
        return e($ts);
    }
    return date('M j, Y g:i a', $t);
}

/** Register an inline JS snippet to be emitted at the end of the layout. */
function page_script(string $js): void
{
    $GLOBALS['__page_scripts'][] = $js;
}

/** Concatenated inline page scripts registered during view capture. */
function rendered_scripts(): string
{
    return implode("\n", $GLOBALS['__page_scripts'] ?? []);
}

/** Coerce truthy values from mixed input to 0/1. */
function to_bool_int($v): int
{
    if (is_bool($v)) return $v ? 1 : 0;
    if (is_numeric($v)) return ((int)$v) ? 1 : 0;
    $v = strtolower(trim((string)$v));
    return in_array($v, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

/**
 * Ordered gallery for a product row: list of ['src'=>url,'alt'=>text].
 * @return list<array{src:string,alt:string}>
 */
function product_images(array $product, int $width = 0): array
{
    return \App\Catalog::images($product, $width);
}

/** Feature image URL (first CSV image / primary Shopify image). */
function product_feature_image(array $product): string
{
    return \App\Catalog::featureImage($product);
}

/** Rewrite a CDN image URL to a given width query parameter. */
function cdn_image_url(string $url, int $width): string
{
    return \App\Catalog::sizedUrl($url, $width);
}

/** Number of lines currently in the wholesale session cart. */
function cart_count(): int
{
    return \App\Controllers\WholesaleController::cartCount();
}

/**
 * Listing description: at most $limit words, with a "...more" toggle for the rest.
 */
function catalog_description(?string $text, int $limit = 20): string
{
    $text = trim(html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    if ($text === '') {
        return '';
    }
    $words = preg_split('/\s+/u', $text) ?: [];
    if (count($words) <= $limit) {
        return '<div class="muted catalog-desc">' . e($text) . '</div>';
    }
    $short = implode(' ', array_slice($words, 0, $limit));
    return '<div class="muted catalog-desc" data-full="' . e($text) . '" data-short="' . e($short) . '">'
        . '<span class="catalog-desc-body">' . e($short) . '</span>'
        . ' <button type="button" class="catalog-more" aria-expanded="false">...more</button>'
        . '</div>';
}

/** Stock pill for wholesale catalog cards, gated by the admin setting. */
function catalog_stock_badge(array $product, bool $showStock): string
{
    if (!$showStock) {
        return '';
    }
    $n = (int)($product['stock'] ?? 0);
    $cls = $n > 0 ? 'active' : 'cancelled';
    return '<span class="badge ' . $cls . '">' . $n . ' in stock</span>';
}

/** Thumbnail markup: feature photo when present, otherwise a placeholder icon. */
function catalog_thumb(array $item, string $fallbackIcon = 'yarn', int $width = 400): string
{
    $src = product_feature_image($item);
    if ($src === '' && !empty($item['feature_image'])) {
        $src = (string)$item['feature_image'];
    }
    $src = cdn_image_url($src, $width);
    $alt = (string)($item['title'] ?? '');
    if ($src !== '') {
        return '<div class="thumb has-image"><img src="' . e($src) . '" alt="' . e($alt) . '"></div>';
    }
    return '<div class="thumb">' . \App\Icons::get($fallbackIcon, 34) . '</div>';
}

/**
 * Home/catalog preview for a bundle. A single member uses the usual feature
 * thumb; two or more members tile every colour image (width=100) into a
 * gapless square mosaic.
 */
function catalog_bundle_preview(array $bundle): string
{
    $items = $bundle['items'] ?? [];
    if (count($items) <= 1) {
        return catalog_thumb($bundle, 'bundle');
    }
    $cells = [];
    foreach ($items as $it) {
        $src = product_feature_image($it);
        if ($src === '' && !empty($it['feature_image'])) {
            $src = (string)$it['feature_image'];
        }
        $alt = (string)($it['title'] ?? $bundle['title'] ?? '');
        if ($src === '') {
            $cells[] = '<span class="mosaic-cell mosaic-empty" title="' . e($alt) . '">'
                . \App\Icons::get('yarn', 18) . '</span>';
            continue;
        }
        $src = cdn_image_url($src, 100);
        $cells[] = '<img src="' . e($src) . '" alt="' . e($alt) . '" width="100" height="100">';
    }
    if (count($cells) <= 1) {
        return catalog_thumb($bundle, 'bundle');
    }
    $cols = \App\Catalog::mosaicCols(count($cells));
    return '<div class="thumb has-image mosaic" style="--mosaic-cols:' . (int)$cols . '">'
        . implode('', $cells)
        . '</div>';
}
