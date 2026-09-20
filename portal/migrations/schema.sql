-- HouseDye Portal schema (SQLite). Idempotent: uses IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS users (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    email                TEXT NOT NULL UNIQUE,
    password_hash        TEXT,
    role                 TEXT NOT NULL DEFAULT 'staff',      -- staff | admin
    status               TEXT NOT NULL DEFAULT 'active',     -- active | disabled
    first_name           TEXT,
    last_name            TEXT,
    fin_first_name       TEXT,
    fin_last_name        TEXT,
    phone                TEXT,
    office_address       TEXT,
    delivery_address     TEXT,
    company_website      TEXT,
    application_message  TEXT,
    preferred_currency   TEXT NOT NULL DEFAULT '',
    created_at           TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at           TEXT NOT NULL DEFAULT (datetime('now')),
    approved_at          TEXT
);

CREATE TABLE IF NOT EXISTS products (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sku          TEXT,
    title        TEXT NOT NULL,
    description  TEXT,
    category     TEXT,
    price_cents  INTEGER NOT NULL DEFAULT 0,
    stock        INTEGER NOT NULL DEFAULT 0,
    image_url    TEXT,                                  -- feature image (CSV Image Position 1)
    images_json  TEXT,                                  -- JSON gallery [{src,alt}, ...] from Shopify
    is_public    INTEGER NOT NULL DEFAULT 1,
    status       TEXT NOT NULL DEFAULT 'active',   -- active | inactive (admin-controlled; preserved across Shopify sync)
    shopify_product_id TEXT,                        -- set for products imported/synced from Shopify
    min_qty      INTEGER NOT NULL DEFAULT 0,        -- restock minimum (admin-editable; triggers Restock Orders)
    goal_qty     INTEGER NOT NULL DEFAULT 0,        -- goal stock level used to recommend reorder qty
    vendor_id    INTEGER,                           -- preferred supplier for restock
    spt          INTEGER NOT NULL DEFAULT 10,       -- retained for existing installs; hidden from products grid
    warehouse_stock INTEGER NOT NULL DEFAULT 0,     -- retained for existing installs; hidden from products grid
    colours      TEXT NOT NULL DEFAULT '',          -- retained for existing installs; hidden from products grid
    archived     INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS bundles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        TEXT NOT NULL,
    description  TEXT,
    image_url    TEXT,
    is_public    INTEGER NOT NULL DEFAULT 1,
    archived     INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS bundle_items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    bundle_id   INTEGER NOT NULL,
    product_id  INTEGER NOT NULL,
    min_qty     INTEGER NOT NULL DEFAULT 1,
    sort_order  INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (bundle_id) REFERENCES bundles(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS orders (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id        INTEGER NOT NULL,
    status         TEXT NOT NULL DEFAULT 'pending',   -- pending | provisioning | shipped | completed | cancelled
    payment_status TEXT NOT NULL DEFAULT 'pending',   -- pending | paid | refunded
    notes          TEXT,
    admin_notes    TEXT,
    tracking_url   TEXT,
    total_cents    INTEGER NOT NULL DEFAULT 0,
    manual_discount_cents INTEGER NOT NULL DEFAULT 0, -- admin dollar discount off the billable total
    archived       INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at     TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS order_items (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id         INTEGER NOT NULL,
    product_id       INTEGER,
    bundle_id        INTEGER,
    title            TEXT NOT NULL,
    qty              INTEGER NOT NULL DEFAULT 1,
    unit_price_cents INTEGER NOT NULL DEFAULT 0,
    line_total_cents INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS messages (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    thread_user_id   INTEGER NOT NULL,               -- conversation subject (usually the originator / recipient)
    sender           TEXT NOT NULL,                  -- client | admin | staff | system
    body             TEXT NOT NULL,
    read_by_admin    INTEGER NOT NULL DEFAULT 0,
    read_by_client   INTEGER NOT NULL DEFAULT 0,
    to_user_id       INTEGER,                        -- null = all admins; otherwise a specific user
    sender_user_id   INTEGER,                        -- the user who sent it (null for system)
    created_at       TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (thread_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS vendors (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    vendor_id    TEXT,                                  -- external / display vendor code
    name         TEXT NOT NULL,
    stock_urls   TEXT NOT NULL DEFAULT '',              -- one URL per line, or a JSON array
    notes        TEXT,
    archived     INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS vendor_products (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    vendor_id           INTEGER NOT NULL,
    matched_product_id  INTEGER,                        -- catalog products.id when matched
    vendor_product_id   TEXT,
    sku                 TEXT,
    title               TEXT NOT NULL,
    price_cents         INTEGER NOT NULL DEFAULT 0,
    stock               INTEGER NOT NULL DEFAULT 0,
    status              TEXT NOT NULL DEFAULT 'active', -- active | inactive
    source_url          TEXT,
    archived            INTEGER NOT NULL DEFAULT 0,
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
    FOREIGN KEY (matched_product_id) REFERENCES products(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS restock_schedules (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    vendor_id          INTEGER NOT NULL,
    product_id         INTEGER NOT NULL,
    vendor_product_id  INTEGER,
    title              TEXT NOT NULL,
    qty                INTEGER NOT NULL DEFAULT 0,
    starts_at          TEXT,
    ends_at            TEXT,
    created_at         TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at         TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(vendor_id, product_id),
    FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS work_orders (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id       INTEGER NOT NULL,
    product_id     INTEGER,
    title          TEXT NOT NULL,
    qty            INTEGER NOT NULL DEFAULT 1,
    staff_user_id  INTEGER,
    status         TEXT NOT NULL DEFAULT 'pending',  -- pending | stalled | complete | filled_from_stock
    notes          TEXT,
    notified_at    TEXT,
    parent_id      INTEGER,                          -- tray sub-task parent work order
    tray_no        INTEGER,                          -- 1-based tray index when parent_id is set
    starts_at      TEXT,                             -- Gantt schedule start (YYYY-MM-DD)
    ends_at        TEXT,                             -- Gantt schedule end (YYYY-MM-DD, inclusive)
    qty_was        INTEGER,                          -- parent qty before the last admin order-item edit
    qty_changed_at TEXT,                             -- when an admin last changed the originating order qty
    created_at     TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at     TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT
);

CREATE TABLE IF NOT EXISTS activity (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER,
    type        TEXT NOT NULL,
    description TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS api_tokens (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    token_hash  TEXT NOT NULL,
    label       TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    last_used   TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS admin_presence (
    id        INTEGER PRIMARY KEY CHECK (id = 1),
    last_seen TEXT
);

CREATE TABLE IF NOT EXISTS user_prefs (
    user_id  INTEGER NOT NULL,
    pref_key TEXT NOT NULL,
    value    TEXT NOT NULL,
    PRIMARY KEY (user_id, pref_key)
);

CREATE INDEX IF NOT EXISTS idx_orders_user ON orders(user_id);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);
CREATE INDEX IF NOT EXISTS idx_messages_thread ON messages(thread_user_id);
CREATE INDEX IF NOT EXISTS idx_products_public ON products(is_public, archived);
CREATE INDEX IF NOT EXISTS idx_work_orders_order ON work_orders(order_id);
CREATE INDEX IF NOT EXISTS idx_products_vendor ON products(vendor_id);
CREATE INDEX IF NOT EXISTS idx_vendor_products_vendor ON vendor_products(vendor_id);
CREATE INDEX IF NOT EXISTS idx_vendor_products_match ON vendor_products(matched_product_id);
CREATE INDEX IF NOT EXISTS idx_restock_schedules_vendor ON restock_schedules(vendor_id);
