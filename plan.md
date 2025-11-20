# Brooklyn AI Trip Planner – Architect Phase Plan

## 1. Overview & Objectives
- **Goal:** Production-ready WordPress 6.8.3 plugin delivering AI-powered itineraries while respecting "no user data storage" policy.
- **Standards:** WordPress Plugin Handbook best practices (prefixing, namespaces, conditional loading, file security) + S.A.F.E. D.R.Y. A.R.C.H.I.T.E.C.T. principles.
- **Key Tenets:** Modular OOP design, dependency injection, rate limiting + caching guardrail, zero PII persistence, automated testing + deployments via WordPress Studio → GitHub CI.

## 2. Architecture & Security Strategy
- **Plugin Structure:** Namespaced `BrooklynAI\` classes under `/includes`, bootstrap file in plugin root registering hooks. Separate service classes for API clients (Pinecone, Supabase, Google Maps, Gemini).
- **Security Controls:**
  - Rate limit: 5 req/IP/hour via transients; enforced before engine invocation.
  - Nonce verification + capability checks on admin screens/forms.
  - Input sanitization using `sanitize_text_field`, `wp_kses_post`, `map_deep` for arrays.
  - Output escaping via `esc_html`, `esc_url`, `wp_json_encode`.
  - Deny direct access with `defined( 'ABSPATH' ) || exit;` guard in every PHP file.
  - No user data stored locally; only hashed request metadata logged to Supabase analytics per policy.
- **Data Flow:** Gutenberg block → Security Manager → Transient cache → Engine (K-Means → RAG → MBA → Filters → Gemini) → Response + analytics log.
- **Compliance:** No PII storage; Supabase tables contain anonymized IDs. All API keys stored in WordPress options table encrypted via `Secrets` API (or filtered via `apply_filters` for environment injection).

## 3. DevOps & Environment Strategy
- **Environments:** WordPress Studio (Dev), optional staging clone, production VisitBrooklyn site. Config split via `.env`/wp-config constants.
- **Tooling:**
  - PHPStan + PHPCS (WordPress Coding Standards).
  - PHPUnit for unit/integration tests (rate limiter, MBA boost logic, cache policy).
  - Jest/RTL + Playwright for block interactions.
  - GitHub Actions pipeline: lint → test → `npm run build` → package artifact.
- **Dependencies:** Composer (Gemini PHP client, Guzzle), NPM (`@wordpress/scripts`, React, Google Maps loader). Lockfiles committed.
- **Observability:** Supabase analytics_log + optional WP-CLI command to export aggregated insights. Errors routed to `error_log` + optional Sentry hook.

## 4. File & Module Structure
```
wp-content/plugins/brooklyn-ai-planner/
├── brooklyn-ai-planner.php          # Bootstrap, autoloader, hook registration
├── includes/
│   ├── class-autoloader.php
│   ├── class-security-manager.php
│   ├── class-cache-service.php
│   ├── class-supabase-client.php
│   ├── class-pinecone-client.php
│   ├── class-gemini-client.php
│   ├── class-googlemaps-client.php
│   ├── class-ingestor.php
│   └── class-engine.php
├── admin/
│   ├── class-settings-page.php
│   └── views/
├── src/                            # React block source
├── build/                          # Compiled block assets (ignored in zip)
├── languages/
├── tests/
│   ├── phpunit/...
│   └── e2e/
├── assets/ (css/js/images)
├── composer.json / composer.lock
├── package.json / package-lock.json
└── log.md (issue tracking per protocol)
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
- [ ] **Schema + migrations**
  - [ ] Execute provided Supabase SQL (venues, itinerary_transactions, association_rules, analytics_logs) and save scripts in `/docs/sql/`.
  - [ ] Enable `pgvector` extension + verify dimensions (768) match Gemini embeddings.
  - [ ] Document column-level descriptions + constraints for client handoff.
- [ ] **CSV ingestion pipeline**
  - [ ] Implement parser supporting 8–12k rows, streaming to avoid memory spikes; validate required columns.
  - [ ] For each venue: generate "vibe summary" via Gemini prompt, fetch embedding, then upsert into Supabase + Pinecone.
  - [ ] Batch network calls (e.g., 50 rows per chunk) with exponential backoff + logging of failures.
- [ ] **Synthetic itinerary generator**
  - [ ] Create script to synthesize 5k itineraries by borough/interest mix using Gemini; ensure no PII.
  - [ ] Store results into Supabase `itinerary_transactions` with session hashes + timestamp.
- [ ] **Tooling**
  - [ ] Add WP-CLI commands: `batp ingest venues`, `batp ingest synthetic`, each with dry-run flag, verbose output, and retry summary.
  - [ ] Build CLI `batp diagnostics connectivity` that pings Supabase + Pinecone + Google APIs, reporting latency + auth state.
- [ ] **Docs & logging**
  - [ ] Update README/plan with ingestion prerequisites (CSV schema, API quotas, expected runtime).
  - [ ] Log ingestion sessions (start/end time, row counts, failures) to `log.md`.

### Phase 4 – Recommendation Engine Core
- [ ] **Engine scaffold**
  - [ ] Build `BrooklynAI\Engine` with constructor injecting security, cache, Pinecone, Supabase, Google, Gemini clients.
  - [ ] Add method `generate_itinerary( $request )` orchestrating stages + returning normalized DTO.
  - [ ] Implement structured logging (context arrays) piped to `error_log` + Supabase analytics.
- [ ] **Stage 0 – Guardrails**
  - [ ] Check rate limit + nonce before processing; hash request payload and check transient cache.
  - [ ] Validate/sanitize input (interests array, time budget, coordinates) with descriptive WP_Errors.
- [ ] **Stage 1 – K-Means lookup**
  - [ ] Call Pinecone centroid index with lat/lng + radius=5km; handle fallback if no centroid.
  - [ ] Retrieve candidate venues from Supabase filtered by cluster_id; limit to ~200 entries.
- [ ] **Stage 2 – RAG semantic search**
  - [ ] Generate Gemini embeddings for interest text if needed.
  - [ ] Query Pinecone semantic index scoped to cluster ID; enforce cosine >= 0.7 and paginate if necessary.
  - [ ] Merge results with Stage 1 candidates, tracking scores + metadata.
- [ ] **Stage 3 – MBA boost**
  - [ ] For each top seed venue, query Supabase `association_rules` via RPC returning lift/confidence.
  - [ ] Apply configurable boost (e.g., multiply by lift) when >1.5; dedupe venues.
- [ ] **Stage 4 – Filters & constraints**
  - [ ] Call Google Places for hours_json validation; drop venues closed in requested window.
  - [ ] Use Distance Matrix to ensure travel time fits budget; compute cumulative durations.
  - [ ] Apply accessibility/dietary/budget filters; apply SBRN member boost (1.2x) and log reasons.
- [ ] **Stage 5 – LLM ordering**
  - [ ] Build structured prompt describing user profile, constraints, candidate list; send to Gemini 2.0 Flash.
  - [ ] Parse ordered itinerary, time allocations, and narrative text; handle API errors gracefully (retry/backoff).
- [ ] **Caching + analytics**
  - [ ] Cache successful Gemini responses for 24h keyed by md5(user_input) and store pointer in Supabase analytics.
  - [ ] Log query hash, venues served, action type to analytics_log table.
- [ ] **Testing**
  - [ ] Create PHPUnit tests mocking each client to simulate success/failure paths.
  - [ ] Add regression test verifying 6th rapid request triggers rate-limit WP_Error.

### Phase 5 – Gutenberg Block & UX Layer
- [ ] **Design system & assets**
  - [ ] Select UI kit (Shadcn/Tailwind or WP components) aligning with VisitBrooklyn branding guidelines; document tokens (colors/spacing/type).
  - [ ] Prepare iconography + imagery (SVG markers) and store under `assets/` with attribution notes.
- [ ] **Admin block (settings)**
  - [ ] Build React settings UI using `@wordpress/data` + `@wordpress/components` for API key inputs, validation, and save notices.
  - [ ] Add feature flag toggles (e.g., "Enable Advanced Filters", "Use Synthetic MBA") persisted in options.
- [ ] **Frontend user journey**
  - [ ] Implement location input with auto-detect (Geolocation API) fallback to manual entry; include permission prompts.
  - [ ] Build interest chips (Art, Food, Parks, etc.) with multi-select + icons.
  - [ ] Implement time window slider (30 min – 8 hr) with discrete stops + textual feedback.
  - [ ] Add advanced filters for accessibility, dietary preferences, budget (P1 requirement) with tooltips.
  - [ ] Render multi-stage status indicator (Security → Cache → Engine → Gemini) tied to reducer state.
- [ ] **Maps & visualization**
  - [ ] Load Google Maps via `@googlemaps/js-api-loader`, center on user location, and plot venue markers with custom styling.
  - [ ] Draw route polylines + step annotations returned from Gemini; update dynamically when itinerary changes.
- [ ] **State management & UX polish**
  - [ ] Use `useReducer` for pipeline states; add context provider for child components.
  - [ ] Handle error states (rate limit, API failure) with friendly messaging + retry buttons.
  - [ ] Implement localization placeholders (`__()`) and ensure WCAG AA contrast + keyboard focus management.
- [ ] **Testing**
  - [ ] Write Jest/RTL tests for reducer transitions, form validation, and component rendering.
  - [ ] Configure Playwright E2E to load block inside Gutenberg iframe, submit sample request, and validate map + itinerary output.

### Phase 6 – MBA Automation & Cron Jobs
- [ ] **SQL + computation**
  - [ ] Implement Supabase SQL function `generate_association_rules()` performing Apriori support/confidence/lift calculations with configurable thresholds.
  - [ ] Store generated rules in `association_rules` table with indexes on venue pairs for fast lookup.
- [ ] **Scheduling**
  - [ ] Configure `pg_cron` job (if available) to run nightly; fallback to WP-Cron hitting Supabase RPC via secure endpoint.
  - [ ] Persist cron metadata (last_run, status) in Supabase + WP options for dashboard visibility.
- [ ] **Operational tooling**
  - [ ] Add WP-CLI command `batp mba run` and `batp mba status` to trigger/inspect jobs with colorized output.
  - [ ] Implement monitoring hook (Supabase webhook or WP admin notice) when job fails or exceeds runtime threshold.
  - [ ] Enable logging of rule counts + runtime to Supabase analytics for trend tracking.
- [ ] **Runbook**
  - [ ] Document process for regenerating synthetic data, verifying cron completion, and troubleshooting Supabase errors; store in `/docs/runbook-mba.md`.

### Phase 7 – QA, Hardening, & Release
- [ ] **Automated quality gates**
  - [ ] Run PHPCS (WordPress-Core + Extra + Docs), PHPStan level 8, PHPUnit suite, Jest, and Playwright locally + in GitHub Actions.
  - [ ] Ensure coverage thresholds met (e.g., 80% for engine + security modules) and document gaps.
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

## 6. Open Questions / Next Inputs Needed
- Confirmation on staging environment requirements.
- API key provisioning timeline + quota expectations.
- Branding/design system selection for block UI.
- Supabase tier capabilities (pgvector, pg_cron availability).
