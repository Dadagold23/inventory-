-- ============================================================
-- 010: Barcode management (Phase B)
-- Primary scannable code on products + alias/mapping table that
-- keeps internally-allocated in-store codes distinguishable from
-- externally-allocated GS1 identifiers. Additive only.
-- All string columns use utf8mb4_unicode_ci (server convention).
-- ============================================================

ALTER TABLE products
    ADD COLUMN barcode VARCHAR(40) NULL DEFAULT NULL AFTER sku,
    ADD UNIQUE KEY uq_products_barcode (barcode);

CREATE TABLE IF NOT EXISTS product_barcodes (
    barcode_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    symbology ENUM('ean13','ean8','upc_a','code128','qr','other') NOT NULL DEFAULT 'code128',
    source ENUM('system','gs1','manual','import') NOT NULL DEFAULT 'system',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (barcode_id),
    UNIQUE KEY uq_product_barcodes_code_company (code, company_id),
    KEY idx_product_barcodes_product (product_id),
    CONSTRAINT fk_pb_product FOREIGN KEY (product_id) REFERENCES products (product_id) ON DELETE CASCADE,
    CONSTRAINT fk_pb_company FOREIGN KEY (company_id) REFERENCES companies (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-store codes: GS1 reserves prefixes 200-299 for internal use. Backfill
-- of product barcodes is performed by scripts/backfill_barcodes.php
-- (check digits require computation outside plain SQL).
