# Tests

Two suites. Neither is shipped to the server (the deploy excludes `tests/`).

## Unit tests — `tests/unit/` (PHPUnit + Brain Monkey)

Fast, isolated tests of the plugin's logic with WordPress functions mocked (no DB,
no live site). Run in CI on every push (`.github/workflows/unit-tests.yml`).

Requirements: PHP 8.1+ with `dom`, `mbstring`, `libxml`, and Composer.

```bash
cd tests/unit
composer install
composer test        # vendor/bin/phpunit
```

Covers: human-order `Client` (request shaping + response parsing, mocked HTTP,
incl. `upload_file` with `DocumentTypeId 3` / `image/png` for screenshot references),
`Callback` (signed `ReferenceData` round-trip + payload parsing), `Writeback`
(translated-HTML parsing + Final-file selection), the YOOtheme `Layout` walker
(decode/collect/map/encode), the **VibeBoost** screenshot `Client` (URL encoding —
the secret token must stay inside the `url` param — defaults, and error paths), the
**Draft_Preview** token gate (`is_valid` match/wrong/disabled/expired + `preview_url`
`p` vs `page_id`), and the **Settings** feature toggles (defaults / sanitize / getters).

## End-to-end tests — `tests/e2e/` (Playwright)

Drive a real browser against a live WordPress site (admin login, Supertext pages,
bulk-action AI translation).

```bash
cd tests/e2e
cp .env.local.example .env.local   # fill SITE_URL / WP_USER / WP_PASS (gitignored)
npm install
npx playwright install chromium
npx playwright test
```

Notes:
- `.env.local` (credentials) and `node_modules/`, `test-results/` are gitignored.
- The AI translation test runs a real (slow, synchronous) translation; give it a
  generous timeout. It uses the post **"Playwright AI Test Post"** (German →
  English) and deletes any existing English translation first for a clean run.

### Exact steps each test runs

**`auth.setup.js` — login fixture (runs first):**
1. Go to `wp-login.php`.
2. Fill in `WP_USER` / `WP_PASS` and submit.
3. Save the authenticated session to `.auth/state.json` (reused by every test
   below, so they start logged in).

**`supertext.spec.js` → "Supertext admin pages" (4 smoke tests).** Each opens a
page, asserts it rendered, and screenshots it:

| Test | Steps |
|------|-------|
| Status page renders | goto `admin.php?page=supertext-polylang` → assert heading "Supertext for Polylang" + "Polylang patched" text → screenshot |
| Settings page | goto `…-settings` → assert "Translation Services (human)" heading + environment + email fields visible → screenshot |
| Orders page | goto `…-orders` → assert "Supertext Orders" heading → screenshot |
| Debug page | goto `…-debug` → assert "Order callbacks" heading → screenshot |

**`supertext.spec.js` → "AI translation via bulk action"** — the real end-to-end
test (*"Playwright AI Test Post" translates German → English*):
1. **Find the German source** — open the Posts list filtered by the title; pick the
   row whose German column shows the self-flag (the source, not the EN copy).
   Screenshot `list`.
2. **Delete any existing English translation** — if one exists, read its post ID and
   `DELETE` it via the REST API (`X-WP-Nonce`) for a clean run.
3. **Flush caches & wait for freshness** — trigger LiteSpeed "Purge All", then poll
   the list until the "add English translation" (+) icon reappears (so a stale object
   cache can't make the next step a no-op).
4. **Select + choose bulk action** — check the source row's checkbox → set Bulk
   actions to **Supertext AI Translation** → pick target language **English**.
   Screenshots `after-delete`, `before-apply`.
5. **Apply & verify** — click **Apply**, wait for the result URL
   (`supertext_created/errors=…`), read the admin notices, assert they mention
   "translation". Screenshot `result`.

**`supertext.spec.js` → "Settings — feature status, toggles & plugin detection":**
goto the Settings page → assert the status overview lists **Features & integrations**,
**Page screenshots (VibeBoost)** and **Integration: Gravity Forms**, that the two new
toggle checkboxes (`preview_links_enabled`, `screenshots_enabled`) are present, and
that the **Detect plugins** button renders. Screenshot `settings-features`.

**`supertext.spec.js` → "Secret preview link" (2 tests):**
1. *preview metabox renders* — open `post-new.php`, assert the **Supertext — Public
   preview link** meta box (`#supertext-preview`) with its enable checkbox + expiry
   field is present.
2. *enabling preview generates a secret token URL* — create a draft via REST, open the
   editor, dirty it via the block-editor data store (meta-box changes alone don't mark
   it dirty), tick **Enable public preview link** with a far-future expiry, **Save
   draft**, reload, and assert the meta box's readonly URL carries `?p=<id>` and a UUID
   `st_preview` token. Screenshots `preview-metabox`, `preview-enabled`, then deletes
   the draft.

   > The front-end token **gate** (a draft renders only with a valid, unexpired token)
   > is deliberately **not** asserted here: this demo serves draft posts publicly at
   > `?p=<id>` regardless of token, so an E2E check on it can't distinguish the gate.
   > That security property is covered in the unit layer (`DraftPreviewTest::is_valid`).

**`docs.spec.js` — documentation screenshots (separate spec):** loops over 4 admin
URLs (`status`, `settings`, `orders`, `mlang_settings`), freezes animations, and
saves a full-page PNG of each into `docs/images/`.

### Seeing the steps after a run

- **Step screenshots** — each numbered step writes a numbered PNG into
  `test-results/steps/` (e.g. `…-01-list.png`, `…-05-result.png`).
- **Playwright trace** — on failure a `trace.zip` is saved; replay every action
  step-by-step with `npx playwright show-trace <zip>`.
