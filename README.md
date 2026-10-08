# GameMatch 2.0

A PHP + MySQL gaming discovery and recommendation website for WT submission.

## Stack
HTML5, CSS3, JavaScript, PHP 8+, MySQL/MariaDB.

## Features
- 50-game catalog with PC, PlayStation, Xbox and Mobile/Nintendo coverage
- Search and filters by platform, genre, gameplay mode, store and budget
- Personalized recommendation engine
- Game detail pages with developer, publisher, release date, genres and modes
- Store rating snapshots with source labels
- Steam/Epic/PlayStation/Xbox offer comparison
- Cheapest-offer highlight and official store links
- Embedded YouTube trailer area
- Wishlist and community ratings/reviews
- Similar-game recommendations below each game
- Responsive UI and GameMatch logo

## Local XAMPP setup
1. Put the `GameMatch` folder in `C:\xampp\htdocs\`.
2. Start Apache and MySQL in XAMPP.
3. Import `database/gamematch.sql` into phpMyAdmin.
4. Open `http://localhost:8000/GameMatch/` if Apache is configured for port 8000.

## Important data note
Store prices and ratings are catalog snapshots included for the demo and can change at the official stores. The UI labels the rating source. The Buy buttons open the official store domains; they do not process purchases inside GameMatch.

## GameMatch 2.2 fixes
- Unique local fallback artwork for every catalog game.
- Store-feedback section with Steam review links and Epic store links where available.
- Robust URLs for login/register/logout and auth forms.
- Trailer section now uses reliable YouTube official-search links instead of broken search-embed iframes. Auth navigation/actions were made path-safe and no longer disabled by JavaScript. The external-review panel uses verified Steam review snapshots for selected games and links to the full Steam/Epic review/store pages.

## Vercel + TiDB deployment

This version preserves the full GameMatch 2.2 UI and features while adding a live Steam India price proxy.

### Vercel
- Keep `Dockerfile.vercel` in the repository root.
- Deploy using the Vercel Container deployment support.
- Set Production environment variables: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
- For TiDB Cloud Serverless TLS, keep the TiDB CA certificate as `ca.pem` in the repository root, or set `DB_SSL_CA` to a valid certificate path inside the container.

### Local XAMPP
If the DB environment variables are absent, the app falls back to `localhost:3306`, database `gamematch`, user `root`, blank password.

### Live Steam price
Game detail pages call `api/steam-price.php?appid=...` server-side. No Steam Web API key is required for the live public Store appdetails price endpoint. A `STEAM_WEB_API_KEY` is optional and is used for the official full catalog synchronization endpoint.


## Expanded game catalog
This build expands the catalog to approximately 150 curated games. Steam App IDs and Steam CDN cover URLs are included where available. The game-details page uses the live Steam India price API for current pricing; the database Steam offer price is a fallback for filtering/display.


## India mobile catalog
The expanded catalog includes popular mobile games. For India, **BATTLEGROUNDS MOBILE INDIA (BGMI)** is used as the Indian battle-royale title; PUBG Mobile is retained only as a global title and is not presented as the Indian version.


## GameMatch 3.0 - API/catalog synchronization

The catalog is now designed as an automatic synchronized cache instead of a manually maintained game list. The existing UI, recommendations, authentication, wishlist, ratings/reviews and price-comparison screens are preserved.

### Automatic game discovery
- **Steam:** `api/sync-games.php` discovers games and enriches them with Steam Store app details. If `STEAM_WEB_API_KEY` is configured, the official Steam IStoreService catalog is used. Without a key, the project falls back to the public Steam Store JSON search endpoint so the demo can still synchronize without manual game entry.
- **TiDB:** `external_catalog` stores discovered external IDs; `games` stores normalized game data; `catalog_sync_state` stores the synchronization cursor.
- **Other stores:** the normalized schema supports multiple store offers, but GameMatch does not fake an unrestricted public API for PlayStation/Xbox/Epic/Google Play. Where a verified API/partner feed is available, an adapter can write into `store_offers` without changing the UI.

### Automatic price refresh
`api/steam-price.php` still fetches the live Steam India price and now also updates the normalized Steam `store_offers` row. The existing store-comparison UI remains in place, so official store links and the cheapest listed offer are preserved.

### Environment variables
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` - TiDB/MySQL connection.
- `DB_SSL_CA` - optional CA path for TiDB TLS.
- `SYNC_SECRET` - optional secret for protecting the catalog sync endpoint.
- `STEAM_WEB_API_KEY` - optional Steam Web API key; when present, the official IStoreService catalog endpoint is preferred.
- `STEAM_SYNC_BATCH` - optional enrichment batch size (default 10, maximum 50).
- `ITAD_API_KEY` - optional IsThereAnyDeal API key for live PC-store price comparison (Steam/Epic and other supported PC shops). Keep it server-side only.

### Sync endpoint
Call `/api/sync-games.php` from Vercel Cron or manually. If `SYNC_SECRET` is set, use `?key=...` or the `X-Sync-Key` header. The included `vercel.json` schedules the sync daily on Vercel Hobby. You can also call `api/enrich-existing.php` once after migration to hydrate legacy games from Steam.

### Important platform limitation
Steam has a well-defined catalog/Store API path, while Google Play Catalog and console store catalog/price access have eligibility, authentication or partner restrictions. The project therefore keeps adapters/source fields ready for those stores rather than scraping or inventing API access.

### Price-comparison data
Existing Steam/Epic/PlayStation/Xbox offers remain available as the project's catalog fallback, while Steam prices are refreshed live. This avoids breaking the previous GameMatch price-comparison feature while external store APIs are connected.


## GameMatch 3.1 fixes

This release fixes:
- TiDB `ONLY_FULL_GROUP_BY` failure on `wishlist.php`
- Existing TiDB wishlist tables whose `wishlists.id` is not auto-increment
- Recommendation filtering so platform/genre/mode/age/budget are actually applied
- Recommendation pricing so it uses the cheapest available offer instead of `games.price`
- False `Free` labels when a real offer is unavailable
- Game details no longer depends on `store_offers.id`
- Steam sync now reuses existing rows by `steam_app_id` instead of creating duplicates
- Steam review percentage/count enrichment
- Cross-platform metadata preservation for the bootstrap catalog
- 50-game cross-platform catalog bootstrap

### Safe database migration

Run `database/migration_3.1_safe.sql` once against the existing `gamematch` database. It does not drop or truncate the main GameMatch tables.

After deployment, open:
`/api/enrich-existing.php?limit=50`

This fills developer, publisher, release date, cover, and Steam rating metadata for existing Steam-linked games. The endpoint is protected only if `SYNC_SECRET`/`CRON_SECRET` is configured.


## 3.2 catalog seeding
The catalog is embedded in `data/catalog_seed.json`. After deploying, open `/api/seed-catalog.php` once. It safely inserts only missing games and never deletes existing data. Optionally protect it with the `SEED_SECRET` Vercel environment variable.


## 3.3 browse/price fix
Browse Games no longer uses a legacy 20-game cap or PC-only predicate. Card prices prefer live available store offers and never call a game Free merely because the catalog price is zero; Free is shown only when an actual available offer has price 0.


## 3.4 null/price fixes
Null catalog fields are now safe in PHP, eliminating deprecated `explode(null)` warnings and `game_tags(NULL)` fatal errors. Game Details handles games without genres. Browse/home cards no longer call a zero/missing catalog price Free when no real store offer exists. Store-offer comparisons do not require an `id` column.


## 3.9 personalization
Logged-in users can mark every game as Played. GameMatch uses wishlist + played-game genres, platforms, modes and developers to rank personalized recommendations. Guests do not see or use personal recommendation/history features. The `played_games` table is also auto-created by the app if needed.


## 4.0 expanded catalog
Bundled catalog: 550 games covering PC, PlayStation, Xbox, Mobile and Nintendo Switch. The existing catalog is preserved and the seeder skips titles already in the database. New entries use a local placeholder cover until store/catalog metadata is enriched.


## GameMatch 4.1 updates
- Added Played Games navigation and dedicated played-games page.
- Added persistent dark/light theme toggle.
- Expanded the catalog with a large mobile-game collection.
- Added local generated thumbnails for catalog entries that lacked artwork.
- Mobile games receive official Google Play and App Store search links during catalog seeding.


## 4.2 mobile repair
Existing mobile records are repaired automatically with local thumbnails, official Google Play/App Store links, and verified store-rating snapshots for major titles. Unknown ratings are left unverified rather than invented.

## GameMatch 4.3 latest-game catalog
The bundled catalog has been expanded with a current 2026-focused set of major releases and upcoming titles, including Grand Theft Auto V Enhanced, Forza Horizon 6, Grand Theft Auto VI, Resident Evil Requiem, 007 First Light, Crimson Desert, Monster Hunter Wilds, DOOM: The Dark Ages, Clair Obscur: Expedition 33, Elden Ring Nightreign, Borderlands 4, Silent Hill f, Battlefield 6, Gears of War: E-Day, Phantom Blade Zero and more. Release dates/platforms should be treated as catalog metadata and can change for upcoming titles.

## GameMatch 4.4 — automatic metadata enrichment
- Game details now attempt to enrich incomplete catalog records from the public Steam Store catalog by title/app ID.
- Missing/placeholder covers, developer, publisher, release date, genres, description, Steam rating/review count, and Steam store links can be repaired when Steam has a matching listing.
- Existing curated values are preserved unless the field is missing/placeholder.
- 007 First Light includes its verified Steam App ID and cover metadata.
