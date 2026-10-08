-- GameMatch 3.0 safe migration for an existing database.
-- Does NOT drop games/users/wishlists/ratings/store_offers.

CREATE TABLE IF NOT EXISTS external_catalog (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(40) NOT NULL,
  external_id VARCHAR(190) NOT NULL,
  name VARCHAR(255) NOT NULL,
  is_game TINYINT(1) DEFAULT NULL,
  enriched TINYINT(1) NOT NULL DEFAULT 0,
  last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  enriched_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_external_catalog (source, external_id),
  INDEX idx_external_enriched (source, enriched, id)
);

ALTER TABLE store_offers
  ADD COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST,
  ADD COLUMN source VARCHAR(40) NOT NULL DEFAULT 'catalog',
  ADD COLUMN external_id VARCHAR(190) DEFAULT NULL,
  ADD COLUMN is_available TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN last_checked_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE store_offers
  ADD KEY idx_offer_game_price (game_id, price),
  ADD KEY idx_offer_store (store_name),
  ADD UNIQUE KEY uq_offer_source_external (game_id, store_name, external_id);

-- Existing offers are historical catalog entries.
UPDATE store_offers SET source='legacy' WHERE source='catalog';
UPDATE store_offers SET is_available=1 WHERE is_available IS NULL;
UPDATE store_offers SET last_checked_at=updated_at WHERE last_checked_at IS NULL;
