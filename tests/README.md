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

Covers: human-order `Client` (request shaping + response parsing, mocked HTTP),
`Callback` (signed `ReferenceData` round-trip + payload parsing), `Writeback`
(translated-HTML parsing + Final-file selection), and the YOOtheme `Layout`
walker (decode/collect/map/encode).

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
