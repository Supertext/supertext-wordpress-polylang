# Supertext for Polylang

Adds **Supertext** as a native machine-translation service in **Polylang Pro**, so it
appears in *Languages → Settings → Machine Translation* right next to DeepL. Content is
translated directly through Supertext — **no DeepL proxy required**. It also adds
**human (professional)** translation orders directly from WordPress.

> 📘 **Looking for installation & usage instructions (with screenshots)?**
> See the **[User Guide](docs/README.md)**. This README covers the developer/architecture
> details.

## How it works

Polylang Pro's MT layer is built around three swappable interfaces. This plugin implements
all three:

| File | Implements | Role |
|------|-----------|------|
| `includes/Machine_Translation/Service.php` | `Service_Interface` | Identity, icon, language-code mapping, wiring |
| `includes/Machine_Translation/Client.php` | `Client_Interface` | The actual `translate()` / `get_usage()` / key check |
| `includes/Machine_Translation/Settings.php` | `Settings_Interface` | The settings form (API key + optional endpoint) |

The service is registered through the `pll_mt_services` filter (see the patch below).

### Translation protocol — Supertext AI **file** translation

The client uses Supertext's **AI file translation** endpoints
(`https://api.supertext.com/v1/`, `Authorization: Supertext-Auth-Key {key}`), not the
text endpoint. File translation accepts up to **1,000,000 characters per request**, so a
whole post (title + content + excerpt + metas) is sent as a *single* call — avoiding the
text endpoint's 10k-char / 5-req-per-second limits.

Because the file endpoint is **asynchronous**, `Client::translate()` performs the full
round-trip synchronously within Polylang's MT contract:

1. Serialize the entity's strings into one HTML document, each wrapped in
   `<div data-pll-id="N">…</div>` (Supertext preserves markup/attributes, translating only
   text nodes).
2. `POST /translate/ai/file` (multipart) → `file_id`.
3. Poll `GET /translate/ai/file/{file_id}/status` every ~2s until `done`
   (handling `translating` / `error` / `limit_exceeded` / `deleted`).
4. `GET /translate/ai/file/{file_id}/translation` → translated HTML.
5. Split by `data-pll-id` and map back onto the entries; `DELETE` the file.

> ⚠️ This blocks the request while polling. AI file translation is usually quick, but for
> very large content it can approach PHP execution limits. A future iteration could move
> this to a background job. Polling interval/timeout are filterable (see below).

`Translations` is called once per entity (post, linked term), so a single post translation
is a small number of file calls, comfortably under the file endpoint's 1-req-per-second cap.

#### Filters

| Filter | Default | Purpose |
|--------|---------|---------|
| `supertext_polylang_endpoint` | `https://api.supertext.com/v1/` | API base URL (also settable in the UI) |
| `supertext_polylang_auth_headers` | `Authorization: Supertext-Auth-Key {key}` | Auth header(s) |
| `supertext_polylang_file_fields` | `target_lang` (+ `source_lang`, `politeness`) | Adjust the multipart form fields |
| `supertext_polylang_language_code` | `PLL_Language::$w3c` (BCP-47) | Default/suggested code when a language is left unmapped |
| `supertext_polylang_poll_interval` | `2` (seconds) | Status poll interval |
| `supertext_polylang_poll_timeout` | `180` (seconds) | Max time to wait for `done` |
| `supertext_polylang_screenshot_endpoint` | `https://vibeboost.me/api/screenshot` | [VibeBoost Screenshots](https://vibeboost.me) capture endpoint used to attach a page screenshot (DocumentTypeId 3) to human orders |

Politeness is derived from the locale: `*_formal` → `more`, `*_informal` → `less`.

## YOOtheme Pro page-builder integration

YOOtheme stores each page's layout as JSON inside an HTML comment in `post_content`
(`<!-- {…} -->`). Translating that blob with any translator corrupts the JSON, so this
plugin translates it **field-by-field** via Polylang's export/import hooks
(`pll_export_post_fields`, `pll_after_post_export`, `pll_filter_translated_post`) — the same
pipeline used by both XLIFF and the Supertext MT path. Only **string-valued** props in the
translatable set are touched; structure, CSS, ids, and URLs are left intact. See
`includes/Integrations/YooTheme/`. The hooks register automatically — no configuration
needed.

#### YOOtheme filters

| Filter | Default | Purpose |
|--------|---------|---------|
| `supertext_polylang_yootheme_fields` | `['content','title','meta','alt','image_alt']` | Prop keys whose **string** values are translated |
| `supertext_polylang_yootheme_skip_content_types` | `['code']` | Element types whose `content` prop must NOT be translated (raw code) |

```php
// Translate an extra YOOtheme text prop (e.g. a custom element's "subtitle").
add_filter( 'supertext_polylang_yootheme_fields', function ( array $keys ): array {
    $keys[] = 'subtitle';
    return $keys;
} );
```

> All filters above are applied on every run with their built-in defaults, so the plugin
> works out of the box. Adding a callback (in an mu-plugin, theme `functions.php`, or this
> plugin) is only needed to *extend* the defaults.

## Required Polylang patch (one line)

Polylang Pro keeps its MT services in a **hardcoded const** with no registration hook:

```php
// src/modules/Machine_Translation/Factory.php
const SERVICES = array( Deepl::class );
```

To let plugins register a service, `Factory::get_classnames()` must expose a filter. This
single method feeds three consumers — the service picker, the option defaults, and the
**strict option storage schema** (`additionalProperties: false`, so without it our
`supertext` options are stripped on save). Change:

```php
public static function get_classnames(): array {
    return self::SERVICES;
}
```

to:

```php
public static function get_classnames(): array {
    /**
     * Filters the list of machine translation service class names.
     *
     * @param string[] $services List of service class names.
     */
    return apply_filters( 'pll_mt_services', self::SERVICES );
}
```

Without this patch the plugin loads but stays inert, and shows an admin notice saying so.

> ⚠️ This patches Polylang Pro's own code, so it must be re-applied after every Polylang
> update. It is a minimal, upstreamable change — ideally Polylang/WP Syntex adds the filter
> to core so no patch is needed.

## Activating Supertext

1. Apply the patch above and activate this plugin.
2. *Languages → Settings → Machine Translation* → enable machine translation.
3. Choose **Supertext**, enter the API key, and map each Polylang language to its Supertext
   code (the fields are pre-filled with BCP-47 suggestions; adjust where Supertext differs).
   Save.
4. `is_active()` becomes true once a key is stored; Supertext is then used for AI
   translation of posts/strings.

> Note: `Factory::get_active_service()` returns the **first** configured service. If a DeepL
> key is also set, DeepL (listed first) wins. Leave the DeepL key empty to use Supertext.

## Deployment

`deploy.ps1` commits/pushes and SFTP-syncs the repo root to the demo server's
`.../plugins/supertext-polylang` folder. `README.md`, `.git`, and the deploy scripts are
excluded from the sync. The **Polylang patch must be applied on the server too.**
