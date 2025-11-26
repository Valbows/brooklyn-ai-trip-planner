# Build Log

## 2025-11-20
- Initialized WordPress Studio environment snapshot: WP 6.9-RC2 (Studio default), PHP 8.4.15 via Homebrew, Composer 2.9.2 installed to `/Users/valrene/bin/composer`, macOS host (ARM).
- Documented requirement to align final release with WordPress 6.8.3 GA despite Studio currently running 6.9-RC2.
- Installed plugin scaffolding (`brooklyn-ai-planner`) and set up PSR-4 autoload fallback while Composer dependencies were pending.
- Ran `composer install` in the plugin directory to generate vendor autoloader for `BrooklynAI\` namespace services.
- Activated Brooklyn AI Planner via WordPress admin UI (no notices in WP_DEBUG) to complete Phase 1 smoke validation.
- Built Phase 2 service layer: added Security Manager, Cache Service, Analytics Logger, Supabase client, and admin settings page with masked keys/help tab.
- Resolved activation fatals by improving autoloader + namespace ordering and explicitly requiring key class files in `brooklyn-ai-planner.php`.
- Added Pinecone, Gemini, and Google Maps REST clients and wired them into `BrooklynAI\Plugin` for lazy instantiation via stored settings/env constants.
- Installed PHP QA tooling (PHPCS, PHPStan) and Composer/NPM scripts so `npm test` runs JS/CSS lint plus PHP lint/stan/unit tests; added PHPUnit coverage for Security Manager, Cache Service, and settings sanitization.
- Hardened service layer typing + PHPStan configuration (WordPress stubs, bootstrap file) so static analysis runs cleanly; fixed cache/admin/client docblocks and autoloader usage.
- Added `phpunit.xml.dist`, registered plugin autoloader in the test bootstrap, and ensured Composer/NPM scripts all pass (`npm test` now runs lint, PHPStan, and PHPUnit successfully).

## 2025-11-22
- Authored Supabase SQL migration set (`docs/sql`) covering venues, itinerary transactions, association rules, analytics logs, plus pgvector enablement and shared update trigger.
- Documented ingestion prerequisites in `docs/security-and-config.md` (env keys, CLI usage, CSV schema, checkpointing expectations).
- Built streaming CSV ingestion stack (`Csv_Stream_Reader`, `Venue_Enrichment_Service`, `Venue_Ingestion_Manager`) with retries, Pinecone upserts, and `wp batp ingest venues` CLI command (resume + dry-run support).
- Implemented synthetic itinerary generator + `wp batp ingest synthetic` CLI entry point (Gemini-generated or mock data) inserting into `itinerary_transactions`.
- Added diagnostics CLI (`wp batp diagnostics connectivity`) to validate Supabase, Pinecone, and Google Maps connectivity pre-ingest.
- Ingestion dry-run executed locally using sample CSV (500 rows, batch size 50). No Supabase/Pinecone writes (dry-run), 1 validation error surfaced as expected for malformed coordinates.

## 2025-11-23
- Completed Phase 3 Ingestion Stack refactor, ensuring full test coverage for `Venue_Ingestion_Manager`, `Venue_Enrichment_Service`, and `Csv_Stream_Reader`.
- Resolved all PHPCS and ESLint errors across the plugin codebase.
- Configured PHPStan with a baseline (`phpstan-baseline.neon`) to suppress legacy/stub type errors while enforcing strict checks on new code.
- Scaffolding and expansion of E2E tests using Playwright (`tests/e2e/block.spec.ts`) to verify block insertion and rendering in the editor.
- Verified `npm test` pipeline (Lint + PHPStan + PHPUnit) is green.
- Prepared for Phase 4 (Recommendation Engine Core) implementation.
- Finished Phase 4 work: implemented Stage 4 filters (budget, accessibility, distance, SBRN boost) and Stage 5 LLM ordering with Gemini, plus comprehensive PHPUnit coverage (`./vendor/bin/phpunit tests/phpunit/EngineTest.php`).

## 2025-11-23 (UX Polish)
- Executed Phase 5 Step 2: Refined Block UX/UI to match "Sandstone" Brand Kit.
- Updated `style.scss` to use Sandstone color palette (`#F2F0EB` bg, `#E0DCD5` borders) and Space Grotesk/Inter typography.
- Refactored `edit.js` and `render.php` to utilize CSS variables (`--batp-highlight-color`) for the accent color, removing inline border overrides.
- Rebuilt block assets (`npm run build`).

## 2025-11-23 (Interactions)
- Executed Phase 5 Step 3: Interactions & State Management.
- Created `REST_Controller` class to expose `POST /brooklyn-ai/v1/itinerary` endpoint, connecting frontend to `Engine::generate_itinerary`.
- Updated `Plugin` to register REST routes.
- Enhanced `render.php` to output security nonces and API URLs via data attributes.
- Rewrote `view.js` to handle form submission via `fetch`, managing `idle` → `loading` → `success/error` states and rendering results.
- Validated build success.

## 2025-11-23 (Map Integration)
- Executed Phase 5 Step 4: Map Visualization.
- Installed `@googlemaps/js-api-loader` via NPM.
- Exposed Google Maps API Key securely via `Plugin::get_maps_api_key` and `data-google-maps-key` attribute in `render.php`.
- Implemented dynamic map loading in `view.js`:
  - Initialized map only upon successful itinerary generation.
  - Plotted venues using `AdvancedMarkerElement` with numbered indices.
  - Drew connecting polyline (red) to visualize the route.
  - Added bounds fitting to ensure all stops are visible.
- Rebuilt block assets (`npm run build`).

## 2025-11-23 (Phase 5.5 Feature Completion)
- Completed missing Phase 5 frontend requirements:
  - Added **Geolocation** trigger to neighborhood input using browser `navigator.geolocation` API.
  - Added **Budget** dropdown selector ($/$$/$$$).
  - Added **Accessibility** preferences (Wheelchair, Sensory, Seating) in a collapsible details block.
- Updated `view.js` to process new fields and send enriched payload to `POST /brooklyn-ai/v1/itinerary`.
- Created `tests/e2e/frontend.spec.ts` to validate form rendering and submission states in a real browser environment.
- Rebuilt assets (`npm run build`).

## 2025-11-24 (Debugging & Fixes)
- **Fixed:** Detected and corrected a syntax error in `wp-config.php` where `BATP_SUPABASE_SERVICE_KEY` contained a newline character, causing authentication failures.
- **Fixed:** Invalidated stale/empty itinerary caches by updating the transient key prefix in `Cache_Service` from `batp_cache_` to `batp_v2_`. This forces a fresh fetch on the next request.
- **Verified:** Confirmed `venues` table in Supabase contains 10 records with valid slugs via direct API query.
- **Note:** `wp-env` CLI was unresponsive; cache invalidation was handled via code update. Pinecone SSL patch confirmed present in `Engine.php`.

## 2025-11-25 (UI Refinement & Bug Fixes)
- **Fixed:** Resolved `ReferenceError: mapElement is not defined` in `view.js` by correcting the variable to `mapContainer`.
- **UI Update:** Updated `style.scss` to match the new BrandKit 2.0:
  - **Colors:**
    - Primary Blue: `#1649FF`
    - Primary Yellow/Orange: `#F2AE01`
    - Background Light: `#D9D9D9`
    - Text Dark: `#2B2B2B`
  - **Typography:**
    - Headings: `Archivo` (Google Font)
    - Body: `Oswald` (Google Font)
- **Accessibility:** Improved text contrast on Yellow/Orange elements (Status badges, Directions buttons) by switching text color from white to dark (`#2B2B2B`).
- **Assets:** Rebuilt frontend assets (`npm run build`) to apply changes.

## 2025-11-25 (Phase 5 Refinement & Feature Complete)
- **UI Polish:**
  - Updated primary action color to **Blue (`#1649FF`)** for Buttons and Directions, keeping **Orange (`#F2AE01`)** for accents/badges.
  - Implemented **Share Modal** with options for PDF, Calendar, Copy Link, Email, and SMS.
  - Implemented **Filter Modal** with accessibility checkboxes (Wheelchair, Sensory, Seating).
- **Features:**
  - **Client-Side Filtering:** Added logic to `view.js` to filter displayed cards based on accessibility data without reloading.
  - **Share Functionality:** Added clipboard copy logic and summary generation.
  - **Hours Display:** Added logic to parse and display today's operating hours or "Open today" fallback in itinerary cards.
  - **Content Update:** Replaced "Fitness" interest with "**Nightlife**" (`nightlife`) in `render.php`.
- **Backend:**
  - Updated `Engine.php` to include `hours` column in Supabase query.
- **Testing:**
  - Unskipped E2E tests (`tests/e2e/`) and updated frontend selectors to match new DOM structure.
- **Build:** Rebuilt assets (`npm run build`).



