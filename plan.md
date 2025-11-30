# Brooklyn AI Trip Planner – Architecture Pivot Plan
## From Custom DB + Pinecone → Google Maps Places API

**Date:** November 29, 2025
**Status:** Pre-Implementation (Ready for Execution)
**Branch:** `Dev-Branch`
**Scope:** Reduce complexity, eliminate data maintenance burden, improve freshness

---

## Executive Summary

### What's Changing

| Aspect | Old Architecture | New Architecture | Impact |
|--------|------------------|------------------|--------|
| **Business Data Source** | Custom Supabase venues table + manual uploads | Google Maps Places API (on-demand) | No data ingestion/maintenance; always fresh |
| **Search Layer** | Pinecone semantic vectors + K-means clustering | Google Places API filtered by type/location/radius | Simpler; no embeddings needed |
| **Ranking/Filtering** | Multi-stage (K-means → RAG → MBA → filters) | Direct Places API + lightweight post-filter | Faster; fewer API calls overall |
| **Route Optimization** | Gemini 2.0 ordering + distance calc | Google Directions API + Gemini polish | Better maps integration |
| **Data Persistence** | All venues stored in Supabase | Only itineraries/analytics stored | Lean DB; analytics-focused |

### What's Staying
- ✅ Gutenberg block UI (interests, location, time, filters)
- ✅ Rate limiting & security manager
- ✅ Analytics logging (clicks, itinerary metadata)
- ✅ Supabase for structured data (itineraries, logs)
- ✅ Share/export functionality
- ✅ Admin dashboard & reporting
- ✅ WCAG 2.1 AA accessibility
- ✅ Caching layer (for Gemini responses)

### What's Being Removed
- ❌ Venue ingestion pipeline (CSV import, Gemini enrichment)
- ❌ Pinecone (entire service + K-means clustering logic)
- ❌ `venues` table in Supabase (or repurpose as cache)
- ❌ Market Basket Analysis (Apriori SQL, MBA boost logic)
- ❌ Cluster centroid lookups
- ❌ Venue embedding generation & storage

### Cost & Complexity Impact

| Metric | Before | After | Savings |
|--------|--------|-------|---------|
| **Monthly API Cost** | Pinecone ($40) + Gemini ($80) + Maps ($100) | Gemini ($20) + Maps/Places ($150) | ~30% reduction |
| **Infrastructure** | 3 external services + data sync jobs | 2 external services | Significant reduction |
| **Maintenance** | Weekly clustering + daily MBA jobs | None | **Eliminated** |
| **Data Freshness** | 1 week lag | Real-time | **Instant** |
| **TTM** | 3-4 weeks | 1-2 weeks | **Accelerated** |

---

## 1. Overview & Objectives
- **Goal:** Production-ready WordPress 6.8.3 plugin delivering AI-powered itineraries using Google Places API for real-time venue discovery.
- **Standards:** WordPress Plugin Handbook best practices + S.A.F.E. D.R.Y. A.R.C.H.I.T.E.C.T. principles.
- **Key Tenets:** Simplified architecture, real-time data, zero maintenance overhead, zero PII persistence.

## 2. Architecture & Security Strategy (Updated)
- **Plugin Structure:** Namespaced `BrooklynAI\` classes under `/includes`, separate service classes for API clients (Google Places, Google Directions, Supabase, Gemini).
- **Security Controls:**
  - Rate limit: 5 req/IP/hour via transients; enforced before engine invocation.
  - Nonce verification + capability checks on admin screens/forms.
  - Input sanitization using `sanitize_text_field`, `wp_kses_post`, `map_deep` for arrays.
  - Output escaping via `esc_html`, `esc_url`, `wp_json_encode`.
  - Deny direct access with `defined( 'ABSPATH' ) || exit;` guard in every PHP file.
  - No user data stored locally; only hashed request metadata logged to Supabase analytics.
- **New Data Flow:** Gutenberg block → Security Manager → Cache check → Google Places API → Distance Matrix → Hard Filters → (Optional) Gemini Ordering → Directions API → Response + analytics log.
- **Compliance:** No PII storage; Supabase tables contain anonymized IDs only.

## 3. DevOps & Environment Strategy
- **Environments:** WordPress Studio (Dev), optional staging clone, production VisitBrooklyn site. Config split via `.env`/wp-config constants.
- **Tooling:**
  - PHPStan + PHPCS (WordPress Coding Standards).
  - PHPUnit for unit/integration tests (rate limiter, MBA boost logic, cache policy).
  - Jest/RTL + Playwright for block interactions.
  - GitHub Actions pipeline: lint → test → `npm run build` → package artifact.
- **Dependencies:** Composer (Gemini PHP client, Guzzle), NPM (`@wordpress/scripts`, React, Google Maps loader). Lockfiles committed.
- **Observability:** Supabase analytics_log + optional WP-CLI command to export aggregated insights. Errors routed to `error_log` + optional Sentry hook.

## 4. File & Module Structure (Updated)
```
wp-content/plugins/brooklyn-ai-planner/
├── brooklyn-ai-planner.php          # Bootstrap, autoloader, hook registration
├── includes/
│   ├── class-autoloader.php
│   ├── class-plugin.php             # Service container
│   ├── class-security-manager.php
│   ├── class-cache-service.php
│   ├── class-analytics-logger.php
│   ├── class-engine.php             # REFACTORED: Uses Places API
│   ├── clients/
│   │   ├── class-google-places-client.php    # NEW
│   │   ├── class-google-directions-client.php # NEW
│   │   ├── class-supabase-client.php
│   │   ├── class-gemini-client.php
│   │   └── class-pinecone-client.php         # DEPRECATED (to remove)
│   ├── ingestion/                            # DEPRECATED (to remove)
│   ├── mba/                                  # DEPRECATED (to remove)
│   ├── cli/
│   │   ├── class-batp-diagnostics-command.php # UPDATED
│   │   ├── class-batp-ingest-command.php     # DEPRECATED
│   │   └── class-batp-mba-command.php        # DEPRECATED
│   └── api/
│       ├── class-itinerary-api.php
│       └── class-events-api.php
├── admin/
│   ├── class-settings-page.php      # UPDATED: Remove Pinecone settings
│   ├── class-reports-page.php
│   └── views/
├── src/                             # React block source (minimal changes)
├── build/                           # Compiled block assets
├── tests/
│   ├── phpunit/
│   └── e2e/
├── docs/
│   ├── sql/                         # DB migrations
│   └── migration-guide.md           # NEW: Pinecone → Places migration
├── composer.json / composer.lock
├── package.json / package-lock.json
└── log.md
```

### New Pipeline Architecture
```
USER INPUT (Gutenberg Block)
├─ Location: (lat, lng) or auto-detect
├─ Interests: [Food, Parks, Art, ...]
├─ Time: 2-8 hours
└─ Filters: [Wheelchair accessible, Budget, ...]
        │
        ▼
┌──────────────────────────────────────┐
│ STAGE 0: GUARDRAILS                  │
├──────────────────────────────────────┤
│ Rate limit check (5 req/hr)          │
│ Nonce validation                     │
│ Input sanitization                   │
│ Cache lookup (md5 hash)              │
└──────────────────┬───────────────────┘
                   │
        ┌──────────┴──────────┐
        │ Cache hit?          │
        │ Return cached       │
        └──────────┬──────────┘
                   │ Cache miss
                   ▼
┌──────────────────────────────────────┐
│ STAGE 1: PLACES API QUERIES          │
├──────────────────────────────────────┤
│ For each interest (Food, Parks):     │
│ ├─ nearbySearch (radius: 3km)        │
│ ├─ Filter: open_now=true             │
│ └─ Return top-20 results             │
│                                      │
│ Merge + dedupe by place_id           │
│ Result: ~30-50 candidates            │
└──────────────────┬───────────────────┘
                   │
                   ▼
┌──────────────────────────────────────┐
│ STAGE 2: DETAILS API                 │
├──────────────────────────────────────┤
│ Fetch details for top ~10 venues:    │
│ ├─ Opening hours                     │
│ ├─ Phone + website                   │
│ ├─ Business status                   │
│ ├─ Photos                            │
│ └─ Rating + reviews                  │
│                                      │
│ Filter out:                          │
│ ├─ Permanently closed                │
│ ├─ Won't be open in time window      │
│ └─ Doesn't match filters             │
│                                      │
│ Result: ~5-8 venues                  │
└──────────────────┬───────────────────┘
                   │
                   ▼
┌──────────────────────────────────────┐
│ STAGE 3: DISTANCE MATRIX             │
├──────────────────────────────────────┤
│ Calculate travel time from:          │
│ ├─ User location → each venue        │
│ └─ Venue → venue (sequencing)        │
│                                      │
│ Filter: Remove if total > time budget│
│ Result: ~3-5 venues + travel times   │
└──────────────────┬───────────────────┘
                   │
                   ▼
┌──────────────────────────────────────┐
│ STAGE 4: GEMINI ORDERING (Optional)  │
├──────────────────────────────────────┤
│ If >3 venues:                        │
│ ├─ Send to Gemini for optimal order  │
│ └─ Parse response + apply order      │
│                                      │
│ Result: Optimized sequence           │
└──────────────────┬───────────────────┘
                   │
                   ▼
┌──────────────────────────────────────┐
│ STAGE 5: DIRECTIONS API              │
├──────────────────────────────────────┤
│ Build multi-stop route:              │
│ origin → waypoint1 → ... → dest      │
│                                      │
│ Returns:                             │
│ ├─ Polyline (for map)                │
│ ├─ Step-by-step directions           │
│ └─ Google Maps URL (shareable)       │
└──────────────────┬───────────────────┘
                   │
                   ▼
┌──────────────────────────────────────┐
│ BUILD RESPONSE + CACHE + LOG         │
├──────────────────────────────────────┤
│ {                                    │
│   venues: [...],                     │
│   directions: { polyline, legs },    │
│   maps_url: "https://...",           │
│   narrative: "Start at X..."         │
│ }                                    │
│                                      │
│ Cache for 24h                        │
│ Log to Supabase analytics            │
└──────────────────────────────────────┘
```

## 5. Phased Build Plan (Checklists)

### Phase 1 – Environment & Bootstrap
- [x] **Studio baseline**
  - [x] Verify WordPress Studio project uses WP 6.8.3, PHP 8.2, SQLite default, WP_DEBUG enabled. *(Studio currently at WP 6.9-RC2; noted delta in log and plan to align prod release with 6.8.3.)*
  - [x] Capture environment snapshot (versions, OS details) in `log.md`.
- [ ] **Version control + tooling**
  - [ ] Initialize Git repository + `.gitignore` tuned for WP Studio (ignore `node_modules`, `vendor`, build artifacts).
  - [x] Create `composer.json` (PSR-4 namespace, dependency stubs) + `package.json` (scripts for build/test) and commit lockfiles.
- [x] **Scaffolding + structure**
  - [x] Run `npx @wordpress/create-block brooklyn-ai-planner --variant=dynamic` under `wp-content/plugins/`.
  - [x] Rename/move files to match target tree, ensure plugin main file is `brooklyn-ai-planner.php` with required headers (`Plugin Name`, version, text domain).
  - [x] Add `defined( 'ABSPATH' ) || exit;` guards to every PHP entry file per WP best practices.
- [x] **Autoloading + hooks**
  - [x] Configure Composer PSR-4 autoload for `BrooklynAI\\` → `includes/` and run `composer dump-autoload`.
  - [x] Implement bootstrap loader instantiating service container, activation/deactivation hooks (flush rewrite, schedule events placeholder).
- [x] **Secrets & configuration**
  - [x] Define pattern for API key injection (WP constants + optional `.env` support via `wp-config.php`).
  - [x] Document CIS-inspired hardening (disable file edits, limit REST exposure) and store in repo docs.
- [x] **Smoke validation**
  - [x] Activate plugin in WordPress Studio UI; check `debug.log` for notices/warnings, fix any autoload or namespace issues.
  - [ ] Record baseline screenshots/log outputs for regression reference.

### Phase 2 – Security & Infrastructure Services
- [x] **Security manager**
  - [x] Build rate limiter using transients keyed by client IP (5 req/hr) + WP_Error response helper.
  - [x] Add nonce creation/validation helpers for block submissions + admin forms.
  - [x] Centralize sanitization utilities (arrays sanitized with `map_deep`, coords validated numerically).
- [x] **Caching & logging**
  - [x] Implement `Cache_Service` with hashed keys, TTL constants (Gemini 24h, pipeline 1h) and deletion helpers.
  - [x] Create analytics logger writing anonymized hashes + action metadata to Supabase via API client.
- [x] **API clients**
  - [x] Wrap Pinecone REST calls (cluster search, query) with configurable environment/project IDs.
  - [x] Wrap Supabase REST/RPC using service key + parameterized payloads, ensuring no raw SQL in PHP.
  - [x] Wrap Google Maps (Places, Distance Matrix) and Gemini clients (REST) with retry/backoff + structured error objects.
- [x] **Admin experience**
  - [x] Scaffold settings page (menu item under Settings) with sections for each API provider, using Settings API.
  - [x] Store credentials via `update_option` with autoload disabled; mask secrets in UI; enforce `manage_options` capability + nonce.
  - [x] Add contextual help tab describing environment separation + instructions to avoid user data storage.
- [x] **Testing + QA**
  - [x] Configure PHPUnit bootstrap loading WordPress test suite.
  - [x] Write tests for rate limiter (6th request blocked), cache TTL behavior, and client instantiation.
  - [x] Add Composer scripts for `phpcs`, `phpstan`, `phpunit`; wire into `npm test` meta command.

### Phase 3 – Data Ingestion & Supabase Integration
- [x] **Schema + migrations**
  - [x] Execute provided Supabase SQL (venues, itinerary_transactions, association_rules, analytics_logs) and save scripts in `/docs/sql/`.
  - [x] Enable `pgvector` extension + verify dimensions (768) match Gemini embeddings.
  - [x] Document column-level descriptions + constraints for client handoff.
- [x] **CSV ingestion pipeline**
  - [x] Implement parser supporting 8–12k rows, streaming to avoid memory spikes; validate required columns.
  - [x] For each venue: generate "vibe summary" via Gemini prompt, fetch embedding, then upsert into Supabase + Pinecone.
  - [x] Batch network calls (e.g., 50 rows per chunk) with exponential backoff + logging of failures.

- [x] **Ingress Layer (Stream Parser)**
  - `BrooklynAI\Ingestion\Csv_Stream_Reader` wraps `SplFileObject` to read chunked batches (configurable batch size, default 50).
  - Required columns enforced on read; malformed rows dispatched to an error channel with reason codes.
  - Normalizes arrays (`categories`, `tags`), addresses, and lat/lng precision before enrichment.
- [x] **Enrichment Pipeline**
  - `Venue_Enrichment_Service` orchestrates Gemini + Pinecone + Supabase clients via dependency injection from `Plugin`.
  - Steps per venue: prompt Gemini for vibe summary → request embedding (768 dims) → attach metadata (hours/accessibility JSON).
  - Applies rate-limit/backoff (retry with jitter up to 3 attempts) and short-circuits on irrecoverable errors.
- [x] **Batch Upsert Coordinator**
  - Aggregates enriched payloads and calls `Supabase_Client::upsert()` on `venues` with conflict target `slug`.
  - Sends vector payloads to Pinecone (`upsert` API) in the same batch; failures captured in `Batch_Result` DTO.
  - Records ingestion metrics (rows processed, success count, retries, failures) to `analytics_logs` + local `log.md` entry.
- [x] **WP-CLI Command Surface**
  - `Batp_Ingest_Command` registers subcommand `wp batp ingest venues` with options: `--file`, `--limit`, `--offset`, `--dry-run`, `--verbose`.
  - Dry-run executes parsing + validation without external API calls, returning a diff-style summary of pending inserts/updates.
  - CLI output includes progress bar, current batch stats, and final retry summary (success/failure tables).
- [x] **Resumability & Observability**
  - Checkpoint file (`.batp_ingest_state.json`) stores last successful slug + timestamp for resuming interrupted runs.
  - Structured logs (`error_log` + Supabase analytics) categorize events: `ingest.validation_error`, `ingest.batch_retry`, `ingest.completed`.
  - Metrics surfaced via future `batp diagnostics connectivity` command to confirm downstream dependency health before ingestion.

- [x] **Tooling**
  - [x] Add WP-CLI commands: `batp ingest venues`, `batp ingest synthetic`, each with dry-run flag, verbose output, and retry summary.
  - [x] Build CLI `batp diagnostics connectivity` that pings Supabase + Pinecone + Google APIs, reporting latency + auth state.
- [x] **Docs & logging**
  - [x] Update README/plan with ingestion prerequisites (CSV schema, API quotas, expected runtime).
  - [x] Log ingestion sessions (start/end time, row counts, failures) to `log.md`.

### Phase 4 – Recommendation Engine Core
- [x] **Engine scaffold**
  - [x] Build `BrooklynAI\Engine` with constructor injecting security, cache, Pinecone, Supabase, Google, Gemini clients.
  - [x] Add method `generate_itinerary( $request )` orchestrating stages + returning normalized DTO.
  - [x] Implement structured logging (context arrays) piped to `error_log` + Supabase analytics.
- [x] **Stage 0 – Guardrails**
  - [x] Check rate limit + nonce before processing; hash request payload and check transient cache.
  - [x] Validate/sanitize input (interests array, time budget, coordinates) with descriptive WP_Errors.
- [x] **Stage 1 – K-Means lookup**
  - [x] Call Pinecone centroid index with lat/lng + radius=5km; handle fallback if no centroid.
  - [x] Retrieve candidate venues from Supabase filtered by cluster_id; limit to ~200 entries.
- [x] **Stage 2 – RAG semantic search**
  - [x] Generate Gemini embeddings for interest text if needed.
  - [x] Query Pinecone semantic index scoped to cluster ID; enforce cosine >= 0.7 and paginate if necessary.
  - [x] Merge results with Stage 1 candidates, tracking scores + metadata.
- [x] **Stage 3 – MBA boost**
  - [x] For each top seed venue, query Supabase `association_rules` via RPC returning lift/confidence.
  - [x] Apply configurable boost (e.g., multiply by lift) when >1.5; dedupe venues.
- [x] **Stage 4 – Filters & constraints**
  - [x] Call Google Places for hours_json validation; drop venues closed in requested window.
  - [x] Use Distance Matrix to ensure travel time fits budget; compute cumulative durations.
  - [x] Apply accessibility/dietary/budget filters; apply SBRN member boost (1.2x) and log reasons.
- [x] **Stage 5 – LLM ordering**
  - [x] Build structured prompt describing user profile, constraints, candidate list; send to Gemini 2.0 Flash.
  - [x] Parse ordered itinerary, time allocations, and narrative text; handle API errors gracefully (retry/backoff).
- [x] **Caching + analytics**
  - [x] Cache successful Gemini responses for 24h keyed by md5(user_input) and store pointer in Supabase analytics.
  - [x] Log query hash, venues served, action type to analytics_log table.
- [x] **Testing**
  - [x] Create PHPUnit tests mocking each client to simulate success/failure paths.
  - [x] Add regression test verifying 6th rapid request triggers rate-limit WP_Error.

### Phase 5 – Gutenberg Block & UX Layer
- [x] **Design system & assets**
  - [x] Select UI kit (Shadcn/Tailwind or WP components) aligning with VisitBrooklyn branding guidelines; document tokens (colors/spacing/type).
  - [x] Prepare iconography + imagery (SVG markers) and store under `assets/` with attribution notes.
- [x] **Admin block (settings)**
  - [x] Build React settings UI using `@wordpress/data` + `@wordpress/components` for API key inputs, validation, and save notices.
  - [x] Add feature flag toggles (e.g., "Enable Advanced Filters", "Use Synthetic MBA") persisted in options.
- [x] **Frontend user journey**
  - [x] Implement location input with auto-detect (Geolocation API) fallback to manual entry; include permission prompts.
  - [x] Build interest chips (Art, Food, Parks, etc.) with multi-select + icons.
  - [x] Implement time window slider (30 min – 8 hr) with discrete stops + textual feedback.
  - [x] Add advanced filters for accessibility, dietary preferences, budget (P1 requirement) with tooltips.
  - [x] Render multi-stage status indicator (Security → Cache → Engine → Gemini) tied to reducer state.
- [x] **Maps & visualization**
  - [x] Load Google Maps via `@googlemaps/js-api-loader`, center on user location, and plot venue markers with custom styling.
  - [x] Draw route polylines + step annotations returned from Gemini; update dynamically when itinerary changes.
- [x] **State management & UX polish**
  - [x] Use `useReducer` for pipeline states; add context provider for child components.
  - [x] Handle error states (rate limit, API failure) with friendly messaging + retry buttons.
  - [x] Implement localization placeholders (`__()`) and ensure WCAG AA contrast + keyboard focus management.
- [x] **Testing**
  - [x] Write Jest/RTL tests for reducer transitions, form validation, and component rendering.
  - [x] Configure Playwright E2E to load block inside Gutenberg iframe, submit sample request, and validate map + itinerary output.

### Phase 6 – MBA Automation & Cron Jobs
- [x] **SQL + computation**
  - [x] Implement Supabase SQL function `generate_association_rules()` performing Apriori support/confidence/lift calculations with configurable thresholds.
  - [x] Store generated rules in `association_rules` table with indexes on venue pairs for fast lookup.
- [x] **Scheduling**
  - [x] Configure `pg_cron` job (if available) to run nightly; fallback to WP-Cron hitting Supabase RPC via secure endpoint.
  - [x] Persist cron metadata (last_run, status) in Supabase + WP options for dashboard visibility.
- [x] **Operational tooling**
  - [x] Add WP-CLI command `batp mba run` and `batp mba status` to trigger/inspect jobs with colorized output.
  - [x] Implement monitoring hook (Supabase webhook or WP admin notice) when job fails or exceeds runtime threshold.
  - [x] Enable logging of rule counts + runtime to Supabase analytics for trend tracking.
- [x] **Runbook**
  - [x] Document process for regenerating synthetic data, verifying cron completion, and troubleshooting Supabase errors; store in `/docs/runbook-mba.md`.

### Phase 6.5 – Analytics, Reporting & Refinement
- [x] **Advanced Analytics Tracking**
  - [x] Create `POST /brooklyn-ai/v1/events` endpoint to capture frontend user interactions.
  - [x] Update `view.js` to track and send events for:
    - [x] Website URL clicks (outbound).
    - [x] Phone number clicks (intent to call).
    - [x] Directions clicks.
  - [x] Extend `Analytics_Logger` to store these events in `analytics_logs` with metadata (venue_id, timestamp).
- [x] **Reporting Dashboard**
  - [x] Create `BrooklynAI\Admin\Reports_Page` and register submenu "BATP Reports".
  - [x] Implement SQL aggregations to show:
    - [x] Total Itineraries Generated (This Month).
    - [x] Total Click-throughs (Website/Phone).
  - [x] **Export Features:**
    - [x] "Email Report": Send an HTML summary of the current month's stats to the admin email.
    - [x] "Download PDF": Generate a PDF snapshot of the report (using a lightweight library or print stylesheet).
- [x] **UI Refinement**
  - [x] Update `style.scss`: Change "Plan Your Perfect Day" banner background to `#1649FF` (Blue) with a subtle gradient overlay as requested.
- [x] **Pinecone/Infrastructure Fix**
  - [x] **Diagnosis:** Investigate the root cause of SSL/TLS connection failures (currently bypassed via `sslverify => false`).
  - [x] **Resolution:** Implement a permanent fix (e.g., bundling CA certificates or updating environment config) to ensure secure, verified connections.
  - [x] **Validation:** Verify vector search reliability and remove temporary patches.

### Phase 6.6 – Pinecone API Migration (Serverless)
**Objective:** Migrate from legacy pod-based Pinecone API to modern serverless API for improved reliability and performance.

**Reference:** [Pinecone Documentation](https://docs.pinecone.io/guides/get-started/overview)

**Key API Changes (per Pinecone Docs):**
- **Control Plane URL:** `https://api.pinecone.io` (global, replaces `controller.{env}.pinecone.io`)
- **Data Plane URL:** `https://{INDEX_HOST}/query` (unique per index, e.g., `docs-example-4zo0ijk.svc.us-east1-aws.pinecone.io`)
- **Headers Required:**
  - `Api-Key: YOUR_API_KEY`
  - `Content-Type: application/json`
  - `X-Pinecone-Api-Version: 2025-10`
- **Deprecated:** `project` and `environment` parameters (replaced by direct `INDEX_HOST`)

#### Step 1: Configuration Update
- [x] Add new setting: `BATP_PINECONE_INDEX_HOST` (the unique DNS host, e.g., `my-index-abc123.svc.us-east1-aws.pinecone.io`)
- [x] Update Settings Page UI with "Index Host URL" field, helper text, and validation
- [x] Keep `BATP_PINECONE_API_KEY` (still required for authentication)
- [x] Deprecate `BATP_PINECONE_PROJECT` and `BATP_PINECONE_ENVIRONMENT` (mark as legacy, keep for backward compat)
- [x] Update `wp-config.php` documentation with new constant format

#### Step 2: Pinecone_Client Refactor
- [x] Update constructor: `__construct( string $api_key, string $index_host, bool $verify_ssl = true )`
- [x] Set control plane URL to `https://api.pinecone.io` (constant)
- [x] Refactor `index_url()` to return `https://{$this->index_host}/{$path}` directly
- [x] Add `X-Pinecone-Api-Version: 2025-10` header to all requests
- [x] Add `describe_index( string $index_name )` method to fetch host via control plane if not provided
- [x] Maintain SSL verification with bundled CA cert (`certs/cacert.pem`)

#### Step 3: Plugin Integration
- [x] Update `Plugin::pinecone()` to use new `BATP_PINECONE_INDEX_HOST` setting
- [x] Update `Engine.php` to work with new client interface (query/upsert unchanged)
- [x] Update `Venue_Ingestion_Manager` for upsert operations (endpoint: `POST /vectors/upsert`)
- [x] Update diagnostics CLI command to test new API format and display index info

#### Step 4: Testing & Validation
- [x] Update PHPUnit tests for new client constructor signature
- [x] Test connectivity via `wp batp diagnostics connectivity`
- [x] Verify vector query returns correct results (`POST /query`)
- [x] Test venue ingestion with new API (`POST /vectors/upsert`)
- [x] Verify SSL certificate verification works with bundled cert

#### Step 5: Documentation & Cleanup
- [x] Update `docs/security-and-config.md` with new configuration format:
  ```php
  define( 'BATP_PINECONE_API_KEY', 'pcsk_...' );
  define( 'BATP_PINECONE_INDEX_HOST', 'my-index-abc123.svc.us-east1-aws.pinecone.io' );
  ```
- [x] Add migration notes to `log.md`
- [x] Remove deprecated `project`/`environment` code paths after verification

### Phase 7 – QA, Hardening, & Release
- [ ] **Automated quality gates**
  - [x] Run PHPCS (WordPress-Core + Extra + Docs), PHPStan level 8, PHPUnit suite, Jest, and Playwright locally + in GitHub Actions.
  - [x] Ensure coverage thresholds met (e.g., 80% for engine + security modules) and document gaps.
- [ ] **Security validation**
  - [ ] Execute Composer audit, `npm audit`, and optional PHP Security Checker; address/blockers documented.
  - [ ] Pen-test endpoints for CSRF, nonce bypass, and injection; confirm no direct file access.
- [ ] **Privacy compliance**
  - [ ] Inspect Supabase tables/logs to confirm only hashed session IDs + aggregated analytics stored.
  - [ ] Run data export script to prove zero PII retention; archive results in project docs.
- [ ] **End-to-end testing**
  - [ ] Perform manual flow: ingest sample CSV → generate itinerary via block → confirm analytics log entry + map rendering.
  - [ ] Test rate-limit scenario and error fallback messaging in the block.
- [ ] **Build & packaging**
  - [ ] Execute `npm run build` + `composer install --no-dev`; verify `build/` assets enqueued correctly.
  - [ ] Create release zip excluding `node_modules`, `src`, `tests`, `.git`; document checksum.
  - [ ] Smoke-test install on clean WP instance (WordPress Studio clone) and validate plugin activation.
- [ ] **Release management**
  - [ ] Update `log.md` with final issues/resolutions + lessons learned.
  - [ ] Prepare deployment checklist + admin user guide (API key setup, ingestion instructions).
  - [ ] Tag Git release, attach artifact, and note tested environments.

---

## Phase 8 – Google Places API Migration
**Objective:** Replace Pinecone + custom venues database with Google Maps Places API for real-time venue discovery.

**Status:** ✅ Core Migration Complete

### Sprint 1: Remove Legacy Infrastructure (Days 1-2)

#### Step 8.1: Remove Pinecone Client & Dependencies ✅
- [x] Delete `includes/clients/class-pinecone-client.php`
- [x] Remove Pinecone instantiation from `class-plugin.php`
- [x] Remove Pinecone settings from `admin/class-settings-page.php`
- [x] Remove `BATP_PINECONE_API_KEY` and `BATP_PINECONE_INDEX_HOST` from config docs
- [x] Update `.env.example` to remove Pinecone variables

#### Step 8.2: Remove MBA & Ingestion Pipeline ✅
- [x] Delete `includes/mba/` directory (MBA_Generator, etc.)
- [x] Delete `includes/ingestion/` directory (CSV parser, enrichment service)
- [x] Delete `includes/cli/class-batp-mba-command.php`
- [x] Delete `includes/cli/class-batp-ingest-command.php`
- [x] Remove `wp_clear_scheduled_hook( 'batp_generate_mba_rules' )` from plugin deactivation
- [x] Remove MBA-related admin dashboard sections

#### Step 8.3: Clean Up Engine.php (Stages 1-3) ✅
- [x] Remove K-means clustering stage (`stage_kmeans_lookup`)
- [x] Remove RAG semantic search stage (`stage_semantic_rag`)
- [x] Remove MBA boost stage (`stage_mba_boost`)
- [x] Remove venue embedding generation calls
- [x] Keep: Rate limiting, caching, hard filters, Gemini ordering

**Commit:** `refactor: remove Pinecone, MBA, and ingestion infrastructure` ✅

---

### Sprint 2: Build Google API Clients (Days 2-3)

#### Step 8.4: Create Google_Places_Client ✅
- [x] Create `includes/clients/class-google-places-client.php`
- [x] Implement `nearby_search( $lat, $lng, $type, $radius, $open_now )`
- [x] Implement `text_search( $query, $lat, $lng, $radius )`
- [x] Implement `get_details( $place_id, $fields )`
- [x] Add proper error handling with `WP_Error`
- [x] Add request logging for debugging

```php
// Key methods:
public function nearby_search( float $lat, float $lng, string $type, int $radius = 3000, bool $open_now = true ): array|WP_Error
public function get_details( string $place_id, array $fields = null ): array|WP_Error
public function text_search( string $query, float $lat = null, float $lng = null, int $radius = null ): array|WP_Error
```

#### Step 8.5: Create Google_Directions_Client ✅
- [x] Create `includes/clients/class-google-directions-client.php`
- [x] Implement `get_multi_stop_route( $origin, $waypoints, $mode )`
- [x] Implement `distance_matrix( $origins, $destinations, $mode )`
- [x] Build shareable Google Maps URL helper
- [x] Extract polyline for map rendering

```php
// Key methods:
public function get_multi_stop_route( array $origin, array $waypoints, string $mode = 'walking' ): array|WP_Error
public function distance_matrix( array $origins, array $destinations, string $mode = 'walking' ): array|WP_Error
private function build_maps_url( array $origin, array $waypoints, array $destination, string $mode ): string
```

#### Step 8.6: Update Plugin Bootstrap ✅
- [x] Add `google_places()` method to `class-plugin.php`
- [x] Add `google_directions()` method to `class-plugin.php`
- [x] Remove `pinecone()` method
- [x] Update constructor to instantiate new clients

**Commit:** `feat: add Google Places and Directions API clients` ✅

---

### Sprint 3: Rewrite Engine Logic (Days 3-4)

#### Step 8.7: Refactor Engine.php with New Pipeline ✅
- [x] Create interest-to-Places-type mapping:
  ```php
  private function map_interest_to_places_type( string $interest ): string {
      $mapping = [
          'food'          => 'restaurant',
          'art'           => 'museum',
          'parks'         => 'park',
          'shopping'      => 'shopping_mall',
          'fitness'       => 'gym',
          'coffee'        => 'cafe',
          'entertainment' => 'movie_theater',
          'drinks'        => 'bar',
          'nightlife'     => 'night_club',
      ];
      return $mapping[ strtolower( $interest ) ] ?? 'point_of_interest';
  }
  ```

- [x] Implement new `generate_itinerary()` flow:
  1. Rate limit + cache check (unchanged)
  2. Query Places API for each interest
  3. Dedupe results by `place_id`
  4. Fetch details for top ~10 candidates
  5. Apply hard filters (hours, distance, accessibility, budget)
  6. (Optional) Gemini ordering if >3 venues
  7. Get multi-stop directions
  8. Build response + cache + log

- [x] Implement `apply_hard_filters()`:
  - Check `business_status === 'OPERATIONAL'`
  - Check `opening_hours.open_now`
  - Distance Matrix for travel time filtering
  - Accessibility filter (wheelchair_accessible_entrance if available)
  - Budget filter via `price_level` (1-4 scale)

- [x] Implement `normalize_venues()` for consistent response structure

#### Step 8.8: Update Gemini Ordering (Optional Stage) ✅
- [x] Simplify LLM prompt for venue ordering
- [x] Keep narrative generation
- [x] Make truly optional (skip if ≤3 venues)

**Commit:** `refactor: rewrite Engine for Google Places API pipeline` ✅

---

### Sprint 4: Frontend & Analytics Updates (Day 4)

#### Step 8.9: Update Frontend Click Tracking
- [ ] Update `view.js` to track `place_id` instead of internal venue ID
- [ ] Update analytics logging endpoint for `place_id`
- [ ] Ensure directions URL uses Google Maps link from API

#### Step 8.10: Update Analytics Schema
- [ ] Add `place_id VARCHAR(255)` column to `analytics_logs`
- [ ] Update `itinerary_transactions` to store `place_ids[]` array
- [ ] Create migration script in `docs/sql/`

```sql
-- Migration: Add place_id support
ALTER TABLE analytics_logs ADD COLUMN place_id VARCHAR(255);
CREATE INDEX idx_analytics_logs_place_id ON analytics_logs(place_id);

-- Update itinerary_transactions
ALTER TABLE itinerary_transactions ADD COLUMN place_ids VARCHAR[];
ALTER TABLE itinerary_transactions ADD COLUMN venue_names VARCHAR[];
```

**Commit:** `feat: update analytics for place_id tracking`

---

### Sprint 5: Admin & CLI Updates (Day 5)

#### Step 8.11: Update Settings Page
- [ ] Remove Pinecone API Key field
- [ ] Remove Pinecone Index Host field
- [ ] Keep Google Maps API Key (already present)
- [ ] Add helper text: "Single API key for Places + Directions + Distance Matrix"
- [ ] Update validation to test Places API connectivity

#### Step 8.12: Update Diagnostics CLI ✅
- [x] Remove Pinecone connectivity test
- [x] Add Google Places API test
- [x] Add Google Directions API test
- [x] Add Gemini API test
- [x] Update output format

```bash
wp batp diagnostics connectivity
# Output:
# ✓ Supabase: Connected (45ms)
# ✓ Gemini: Connected (120ms)
# ✓ Google Places: Connected (85ms)
# ✓ Google Directions: Connected (92ms)
# ✓ Google Distance Matrix: Connected (88ms)
```

#### Step 8.13: Remove/Deprecate MBA CLI
- [ ] Delete `wp batp mba run` command
- [ ] Delete `wp batp mba status` command
- [ ] Update help text

**Commit:** `admin: update settings and diagnostics for Places API`

---

### Sprint 6: Testing & Documentation (Days 5-6)

#### Step 8.14: Update PHPUnit Tests
- [ ] Remove Pinecone client tests
- [ ] Add Google_Places_Client unit tests (mock responses)
- [ ] Add Google_Directions_Client unit tests
- [ ] Update Engine tests for new pipeline
- [ ] Verify rate limiting still works

#### Step 8.15: Update Playwright E2E Tests
- [ ] Update test to verify itinerary generation with Places data
- [ ] Verify map renders with correct markers
- [ ] Verify directions link works
- [ ] Test error states (no venues found, API failure)

#### Step 8.16: Documentation
- [ ] Create `docs/migration-guide.md` (Pinecone → Places)
- [ ] Update `README.md` with new architecture
- [ ] Update `docs/security-and-config.md`:
  ```php
  // Required configuration:
  define( 'BATP_GOOGLE_MAPS_API_KEY', 'YOUR_KEY' ); // Places + Directions + Distance Matrix
  define( 'BATP_GEMINI_API_KEY', 'YOUR_KEY' );
  define( 'BATP_SUPABASE_URL', 'YOUR_URL' );
  define( 'BATP_SUPABASE_SERVICE_KEY', 'YOUR_KEY' );
  ```
- [ ] Update `log.md` with migration notes

**Commit:** `docs: update for Google Places API architecture`

---

### Sprint 7: QA & Release (Day 6)

#### Step 8.17: End-to-End Testing
- [ ] Manual test: Full itinerary flow (location → interests → filters → generate)
- [ ] Verify venue data displays (name, address, phone, website, hours, photos)
- [ ] Verify map with markers and route polyline
- [ ] Verify shareable Google Maps URL
- [ ] Test on mobile viewport
- [ ] Test rate limiting (6th request blocked)
- [ ] Test error fallback (API down scenario)

#### Step 8.18: Performance Validation
- [ ] Measure response time (<2 sec target)
- [ ] Verify caching works (24h TTL)
- [ ] Monitor Google API usage in console

#### Step 8.19: Release
- [ ] Run full test suite: `composer test && npm test`
- [ ] Build production assets: `npm run build`
- [ ] Create release commit
- [ ] Tag version: `v2.0.0-places`
- [ ] Update changelog

**Commit:** `release: v2.0.0 - Google Places API integration`

---

### Sprint 8: UI/UX Enhancements (CURRENT)

**Status:** ✅ Complete

#### Step 8.20: Fix Analytics & Reporting
- [x] Debug Supabase 400 error on analytics insert (schema mismatch)
- [x] Ensure `itinerary_generated` events log correctly
- [x] Add click tracking for "Website" button clicks
- [x] Add click tracking for "Directions" button clicks
- [x] Verify Reports page displays new data
- [x] Fixed double-encoding of metadata in Analytics_Logger
- [x] Fixed cache-hit bypass of analytics logging
- [x] Updated Reports page to use direct queries instead of broken RPC
- [x] Fixed double-counting by removing frontend itinerary_generated tracking

#### Step 8.21: Fix Map Marker Rendering
- [x] Debug why first venue (index 0) is not appearing on map
- [x] Verify all itinerary items have valid lat/lng coordinates
- [x] Ensure marker numbering matches list order (1, 2, 3...)
- [x] Test map with varying venue counts (1-5 venues)

#### Step 8.22: Enhance Location Input
- [x] Add neighborhood dropdown/autocomplete (Williamsburg, Park Slope, etc.)
- [x] Implement "Use My Location" geolocation button
- [x] Pre-defined lat/lng coordinates for 18 Brooklyn neighborhoods
- [ ] Persist last used location in localStorage (deferred)

#### Step 8.23: Enhance Business Descriptions
- [x] Generate richer descriptions using venue types + rating + price level
- [x] Format: "Rated X★ • $$ • type1, type2" or fallback text
- [x] Show "Open Now" / "Check Hours" status based on opening_hours

#### Step 8.24: Editable Itinerary
- [x] Add "Remove" button (×) to each itinerary item
- [x] Implement remove functionality (filter out from state)
- [x] Re-number items after add/remove
- [ ] Add "Add Venue" button to show unused candidates (deferred)
- [ ] Modal/drawer to select from remaining candidates (deferred)

#### Step 8.25: Full Multi-Stop Directions
- [x] Build Google Maps URL with all waypoints in sequence
- [x] Format: `https://www.google.com/maps/dir/?api=1&origin=...&destination=...&waypoints=...|...|...&travelmode=walking`
- [x] "Get Full Route Directions" button at top of itinerary list
- [x] Open in new tab with all itinerary stops pre-loaded

**Commit:** `feat: UI/UX enhancements - analytics, map fix, location input, editable itinerary`

---

## Interest-to-Places Type Mapping Reference

| User Interest | Google Places Type | Notes |
|---------------|-------------------|-------|
| Food | `restaurant` | General dining |
| Art | `museum` | Also consider `art_gallery` |
| Parks | `park` | Outdoor spaces |
| Shopping | `shopping_mall` | Also `store` for boutiques |
| Fitness | `gym` | Also `health` |
| Coffee | `cafe` | Coffee shops |
| Entertainment | `movie_theater` | Also `amusement_park` |
| Drinks | `bar` | Bars and pubs |
| Nightlife | `night_club` | Late-night venues |
| Culture | `museum` | Combine with `art_gallery` |
| History | `museum` | Historical sites |
| Music | `night_club` | Live music venues |

---

## Configuration Reference (Post-Migration)

```php
// wp-config.php
define( 'BATP_GOOGLE_MAPS_API_KEY', 'AIza...' );  // Enable: Places, Directions, Distance Matrix, Maps JS
define( 'BATP_GEMINI_API_KEY', 'YOUR_KEY' );
define( 'BATP_SUPABASE_URL', 'https://xxx.supabase.co' );
define( 'BATP_SUPABASE_SERVICE_KEY', 'eyJ...' );

// REMOVED:
// define( 'BATP_PINECONE_API_KEY', '...' );
// define( 'BATP_PINECONE_INDEX_HOST', '...' );
```

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Google Places API quota exceeded | Low | High | Monitor usage; set budget alerts; implement request queuing |
| Limited accessibility data from Places | High | Medium | Document limitations; use user-provided filters |
| Gemini ordering adds latency | Medium | Low | Make optional; aggressive caching |
| Places API returns inconsistent data | Medium | Medium | Normalize all responses; handle missing fields gracefully |

---

## Success Criteria

### Functional
- [ ] Trip planner generates itineraries using Places API
- [ ] All venues are current (Google's responsibility)
- [ ] Filtering works (distance, hours, accessibility, budget)
- [ ] Directions URL opens multi-stop route in Google Maps
- [ ] Analytics tracks clicks/calls/shares with `place_id`

### Performance
- [ ] Itinerary generation: <2 sec average response time
- [ ] Map rendering: smooth with 3-5 markers
- [ ] No perceived latency increase vs. old system

### Operational
- [ ] Zero data ingestion/sync jobs required
- [ ] Admin dashboard reflects real-time metrics
- [ ] Diagnostics command confirms all APIs healthy
- [ ] Monthly API spend within budget (~$150-200)

---

## 6. Open Questions / Resolved
- ~~Confirmation on staging environment requirements.~~ → Using WordPress Studio
- ~~API key provisioning timeline.~~ → Using existing Google Maps key (enable Places API)
- ~~Supabase tier capabilities.~~ → pgvector no longer needed; analytics only
- **NEW:** Confirm Google Maps API billing account has Places + Directions enabled
