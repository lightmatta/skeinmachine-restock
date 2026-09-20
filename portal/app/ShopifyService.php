<?php
declare(strict_types=1);

namespace App;

/**
 * Shopify Admin API integration: pull products from a specific collection into
 * the portal catalog and keep them in sync via a "Refresh" action.
 *
 * Newer Dev Dashboard apps no longer expose a copyable Admin API access token.
 * The portal stores the app Client ID + Client secret and the store domain,
 * then uses Shopify's client-credentials grant to obtain and renew the token.
 *
 * Catalog fields (title, description, price, sku, stock, category, image)
 * refresh from Shopify. Admin-owned restock fields (vendor, min, goal, status)
 * are never overwritten on existing rows.
 */
class ShopifyService
{
    /**
     * Optional HTTP transport for tests: fn(string $method, string $url, array $headers, ?string $body): array{0:int,1:string}
     * @var null|callable
     */
    public static $transport = null;

    public static function config(): array
    {
        return [
            'domain'        => trim((string)Settings::get('shopify_domain', '')),
            'client_id'     => trim((string)Settings::get('shopify_client_id', '')),
            'client_secret' => trim((string)Settings::get('shopify_client_secret', '')),
            'api_version'   => trim((string)Settings::get('shopify_api_version', '2024-10')) ?: '2024-10',
            'collection_id' => trim((string)Settings::get('shopify_collection_id', '')),
        ];
    }

    public static function configured(): bool
    {
        $c = self::config();
        return $c['domain'] !== '' && $c['client_id'] !== '' && $c['client_secret'] !== '';
    }

    /** Normalise the store domain to a bare host (e.g. my-shop.myshopify.com). */
    public static function host(?string $domain = null): string
    {
        $domain = preg_replace('#^https?://#', '', $domain ?? self::config()['domain']);
        $domain = strtolower(rtrim((string)$domain, '/'));
        if ($domain !== '' && !str_contains($domain, '.')) {
            $domain .= '.myshopify.com';
        }
        return $domain;
    }

    public static function tokenEndpoint(): string
    {
        return 'https://' . self::host() . '/admin/oauth/access_token';
    }

    public static function tokenRequestBody(): string
    {
        $c = self::config();
        return http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
        ]);
    }

    /** Cached Admin API token is still usable (refresh 60s before expiry). */
    public static function cachedTokenValid(): bool
    {
        $token = trim((string)Settings::get('shopify_token', ''));
        $expires = (int)Settings::get('shopify_token_expires_at', '0');
        return $token !== '' && $expires > (time() + 60);
    }

    /** Drop a cached token so the next API call requests a new one. */
    public static function forgetCachedToken(): void
    {
        Settings::set('shopify_token', '');
        Settings::set('shopify_token_expires_at', '0');
    }

    /**
     * Admin API access token from Shopify's client-credentials grant.
     * Cached in settings until shortly before expires_in (normally 24 hours).
     */
    public static function accessToken(bool $forceRefresh = false): string
    {
        if (!$forceRefresh && self::cachedTokenValid()) {
            return trim((string)Settings::get('shopify_token', ''));
        }
        return self::refreshAccessToken();
    }

    public static function refreshAccessToken(): string
    {
        if (!self::configured()) {
            throw new \RuntimeException('Set the store domain, Client ID, and Client secret first.');
        }
        [$status, $raw] = self::request(
            'POST',
            self::tokenEndpoint(),
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            self::tokenRequestBody()
        );
        if ($status !== 200) {
            $hint = self::tokenErrorHint($status, $raw);
            throw new \RuntimeException('Shopify token request failed (HTTP ' . $status . ')' . $hint);
        }
        $data = json_decode($raw, true);
        $token = is_array($data) ? trim((string)($data['access_token'] ?? '')) : '';
        if ($token === '') {
            throw new \RuntimeException('Shopify token response did not include an access_token.');
        }
        $expiresIn = is_array($data) ? (int)($data['expires_in'] ?? 86399) : 86399;
        if ($expiresIn < 60) {
            $expiresIn = 86399;
        }
        Settings::set('shopify_token', $token);
        Settings::set('shopify_token_expires_at', (string)(time() + $expiresIn));
        if (!empty($data['scope'])) {
            Settings::set('shopify_token_scope', (string)$data['scope']);
        }
        return $token;
    }

    /** Low-level GET against the Shopify Admin REST API. Returns [status, body]. */
    private static function get(string $path, bool $retried = false): array
    {
        $c = self::config();
        $url = 'https://' . self::host($c['domain']) . '/admin/api/' . $c['api_version'] . '/' . ltrim($path, '/');
        [$status, $body] = self::request('GET', $url, [
            'X-Shopify-Access-Token: ' . self::accessToken(),
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        if ($status === 401 && !$retried) {
            self::forgetCachedToken();
            self::accessToken(true);
            return self::get($path, true);
        }
        return [$status, $body];
    }

    /**
     * @return array{0:int,1:string}
     */
    private static function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        if (self::$transport) {
            $out = (self::$transport)($method, $url, $headers, $body);
            return [(int)$out[0], (string)$out[1]];
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Shopify request failed: ' . $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, (string)$raw];
    }

    private static function tokenErrorHint(int $status, string $raw): string
    {
        $data = json_decode($raw, true);
        $msg = '';
        if (is_array($data)) {
            if (isset($data['error_description']) && is_string($data['error_description'])) {
                $msg = $data['error_description'];
            } elseif (isset($data['error']) && is_string($data['error'])) {
                $msg = $data['error'];
            } elseif (isset($data['errors'])) {
                $msg = is_string($data['errors']) ? $data['errors'] : (string)json_encode($data['errors']);
            }
        }
        if ($msg === '' && $raw !== '') {
            $msg = trim(strip_tags($raw));
            if (strlen($msg) > 180) {
                $msg = substr($msg, 0, 180) . '…';
            }
        }
        if ($msg !== '') {
            return ': ' . $msg;
        }
        if ($status === 401 || $status === 403) {
            return ': check Client ID, Client secret, and that the app is installed on this shop.';
        }
        return '';
    }

    /** Verify the credentials by requesting a token, then the shop record. */
    public static function testConnection(): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'Set the store domain, Client ID, and Client secret first.'];
        }
        try {
            self::accessToken(true);
            [$status, $body] = self::get('shop.json');
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($status !== 200) {
            return ['ok' => false, 'error' => 'Shopify returned HTTP ' . $status . ' (check domain, credentials and scopes).'];
        }
        $data = json_decode($body, true);
        $shop = $data['shop']['name'] ?? 'connected';
        return ['ok' => true, 'shop' => $shop];
    }

    /** Collection title from Shopify, or empty when the collection cannot be read. */
    public static function fetchCollectionTitle(string $collectionId): string
    {
        $collectionId = trim($collectionId);
        if ($collectionId === '') {
            return '';
        }
        [$status, $body] = self::get('collections/' . rawurlencode($collectionId) . '.json');
        if ($status !== 200) {
            return '';
        }
        $data = json_decode($body, true);
        return trim((string)($data['collection']['title'] ?? ''));
    }

    /** Fetch and normalise products from a collection (or the settings default). */
    public static function fetchCollectionProducts(?string $collectionId = null): array
    {
        $c = self::config();
        $cid = trim((string)($collectionId ?? $c['collection_id']));
        $path = 'products.json?limit=250';
        if ($cid !== '') {
            $path .= '&collection_id=' . urlencode($cid);
        }
        [$status, $body] = self::get($path);
        if ($status !== 200) {
            throw new \RuntimeException('Shopify returned HTTP ' . $status . ' while fetching products.');
        }
        $data = json_decode($body, true);
        return self::normalize($data['products'] ?? []);
    }

    /** Map raw Shopify products to the portal's product fields. */
    public static function normalize(array $products): array
    {
        $out = [];
        foreach ($products as $p) {
            $variant = $p['variants'][0] ?? [];
            $gallery = [];
            foreach (($p['images'] ?? []) as $im) {
                $src = trim((string)($im['src'] ?? ''));
                if ($src === '') {
                    continue;
                }
                $gallery[] = ['src' => $src, 'alt' => (string)($im['alt'] ?? '')];
            }
            $image = (string)($p['image']['src'] ?? '');
            if ($image === '' && $gallery) {
                $image = $gallery[0]['src'];
            }
            if ($image !== '' && !$gallery) {
                $gallery[] = ['src' => $image, 'alt' => ''];
            }
            $price = isset($variant['price']) ? (int)round(((float)$variant['price']) * 100) : 0;
            $stock = 0;
            foreach (($p['variants'] ?? []) as $v) {
                $stock += (int)($v['inventory_quantity'] ?? 0);
            }
            $st = strtolower((string)($p['status'] ?? 'active'));
            $out[] = [
                'shopify_product_id' => (string)($p['id'] ?? ''),
                'title'       => (string)($p['title'] ?? 'Untitled'),
                'description' => trim(strip_tags((string)($p['body_html'] ?? ''))),
                'category'    => (string)($p['product_type'] ?? ''),
                'sku'         => (string)($variant['sku'] ?? ''),
                'price_cents' => $price,
                'stock'       => $stock,
                'status'      => $st === 'active' ? 'active' : 'inactive',
                'image_url'   => (string)$image,
                'images_json' => $gallery ? json_encode($gallery, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
                'colours'     => self::coloursFromShopify($p),
            ];
        }
        return $out;
    }

    /**
     * Upsert normalised products into the catalog by Shopify id.
     * Stock, title, SKU, price and images refresh. Vendor, min, goal and
     * active/inactive status on existing rows stay under admin control.
     * Returns ['created' => n, 'updated' => n].
     */
    public static function upsertProducts(array $items, ?int $sourceId = null, ?int $vendorId = null): array
    {
        $pdo = Database::pdo();
        $created = 0; $updated = 0;

        $find = $pdo->prepare('SELECT id, colours FROM products WHERE shopify_product_id = ? LIMIT 1');
        $update = $pdo->prepare(
            "UPDATE products SET title = ?, description = ?, category = ?, sku = ?,
                    price_cents = ?, stock = ?, image_url = ?, images_json = ?,
                    source_id = COALESCE(?, source_id), vendor_id = COALESCE(?, vendor_id),
                    updated_at = datetime('now')
             WHERE id = ?"
        );
        $insert = $pdo->prepare(
            "INSERT INTO products (sku, title, description, category, price_cents, stock, image_url, images_json,
                                   is_public, status, shopify_product_id, min_qty, goal_qty, spt, warehouse_stock, colours,
                                   source_id, vendor_id)
             VALUES (?,?,?,?,?,?,?,?,1,?,?,0,0,?,0,?,?,?)"
        );
        $defaultSpt = Settings::productSpt();

        foreach ($items as $it) {
            if (($it['shopify_product_id'] ?? '') === '') continue;
            $find->execute([$it['shopify_product_id']]);
            $existing = $find->fetch() ?: null;
            $colours = ColourDetect::resolveImportColours(
                $existing ? (string)($existing['colours'] ?? '') : null,
                (string)($it['colours'] ?? ''),
                $it
            );
            if ($existing) {
                $update->execute([
                    $it['title'], $it['description'], $it['category'], $it['sku'],
                    $it['price_cents'], $it['stock'],
                    $it['image_url'], $it['images_json'] ?? '',
                    $sourceId, $vendorId, (int)$existing['id'],
                ]);
                $updated++;
            } else {
                $insert->execute([
                    $it['sku'], $it['title'], $it['description'], $it['category'],
                    $it['price_cents'], $it['stock'], $it['image_url'], $it['images_json'] ?? '',
                    $it['status'] ?? 'active', $it['shopify_product_id'],
                    $defaultSpt, $colours, $sourceId, $vendorId,
                ]);
                $created++;
            }
        }
        return ['created' => $created, 'updated' => $updated];
    }

    /** Full sync: fetch from Shopify then upsert. */
    public static function sync(): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'Shopify is not configured. Add the store domain, Client ID, and Client secret in Settings.'];
        }
        try {
            $items = self::fetchCollectionProducts();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $result = self::upsertProducts($items);
        Settings::set('shopify_last_sync', date('c'));
        return array_merge(['ok' => true, 'fetched' => count($items)], $result);
    }

    public static function periodicEnabled(): bool
    {
        return Settings::get('shopify_periodic_sync', '0') === '1';
    }

    public static function periodicMinutes(): int
    {
        return max(1, min(24 * 60, (int)Settings::get('shopify_periodic_minutes', '60')));
    }

    /** Restart the periodic clock from now (called when settings are saved). */
    public static function schedulePeriodicFromNow(): void
    {
        if (!self::periodicEnabled()) {
            Settings::set('shopify_periodic_next_at', '0');
            return;
        }
        Settings::set('shopify_periodic_next_at', (string)(time() + self::periodicMinutes() * 60));
    }

    /**
     * Update stock only for catalog products that match the configured collection.
     * Does not create products or change titles/prices/images.
     */
    public static function upsertStock(array $items): array
    {
        $pdo = Database::pdo();
        $upd = $pdo->prepare(
            "UPDATE products SET stock = ?, updated_at = datetime('now') WHERE shopify_product_id = ?"
        );
        $updated = 0;
        foreach ($items as $it) {
            $sid = (string)($it['shopify_product_id'] ?? '');
            if ($sid === '') {
                continue;
            }
            $upd->execute([(int)($it['stock'] ?? 0), $sid]);
            $updated += $upd->rowCount() > 0 ? 1 : 0;
        }
        return ['updated' => $updated, 'fetched' => count($items)];
    }

    public static function syncStock(): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'Shopify is not configured. Add the store domain, Client ID, and Client secret in Settings.'];
        }
        try {
            $items = self::fetchCollectionProducts();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $result = self::upsertStock($items);
        Settings::set('shopify_last_stock_sync', date('c'));
        return array_merge(['ok' => true], $result);
    }

    /**
     * Run a stock pull if Periodic Sync is on and the next-run time has arrived.
     * Cheap no-op when disabled or not yet due. $force runs even if the checkbox
     * is off (CLI / tests) and ignores the wait clock.
     */
    public static function tickPeriodic(bool $force = false): array
    {
        if (Sources::automatedEnabled()) {
            $lock = (int)Settings::get('shopify_periodic_lock', '0');
            if (!$force && $lock > time() - 120) {
                return ['ok' => true, 'ran' => false, 'reason' => 'locked'];
            }
            Settings::set('shopify_periodic_lock', (string)time());
            try {
                $result = Sources::tickDue($force);
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'error' => $e->getMessage(), 'ran' => false];
            } finally {
                Settings::set('shopify_periodic_lock', '0');
            }
            if (!isset($result['ran'])) {
                $result['ran'] = (bool)($result['ok'] ?? false);
            }
            return $result;
        }
        if (!self::periodicEnabled() && !$force) {
            return ['ok' => true, 'ran' => false, 'reason' => 'off'];
        }
        $next = (int)Settings::get('shopify_periodic_next_at', '0');
        if (!$force) {
            if ($next <= 0) {
                self::schedulePeriodicFromNow();
                return [
                    'ok'      => true,
                    'ran'     => false,
                    'reason'  => 'scheduled',
                    'next_at' => (int)Settings::get('shopify_periodic_next_at', '0'),
                ];
            }
            if ($next > time()) {
                return ['ok' => true, 'ran' => false, 'reason' => 'waiting', 'next_at' => $next];
            }
        }
        $lock = (int)Settings::get('shopify_periodic_lock', '0');
        if (!$force && $lock > time() - 120) {
            return ['ok' => true, 'ran' => false, 'reason' => 'locked', 'next_at' => $next];
        }
        Settings::set('shopify_periodic_lock', (string)time());
        try {
            $result = self::syncStock();
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            Settings::set('shopify_periodic_lock', '0');
        }
        Settings::set('shopify_periodic_next_at', (string)(time() + self::periodicMinutes() * 60));
        $result['ran'] = (bool)($result['ok'] ?? false);
        $result['next_at'] = (int)Settings::get('shopify_periodic_next_at', '0');
        return $result;
    }

    /** Colours metafield / tags → portal colour slugs (plus variegated). */
    private static function coloursFromShopify(array $p): string
    {
        $chunks = [];
        foreach ((array)($p['metafields'] ?? []) as $mf) {
            $key = strtolower((string)($mf['key'] ?? ''));
            if (!in_array($key, ['colours', 'colors', 'colour', 'color'], true)) {
                continue;
            }
            $val = $mf['value'] ?? '';
            $chunks[] = is_array($val) ? implode(',', $val) : (string)$val;
        }
        $chunks[] = (string)($p['tags'] ?? '');
        return Catalog::coloursCsv(implode(',', $chunks));
    }
}
