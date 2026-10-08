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

## Gravity Forms integration

Gravity Forms aren't posts — they live in Gravity Forms' own tables — so they can't ride
Polylang's post pipeline like YOOtheme does. Instead the integration
(`includes/Integrations/GravityForms/`) collects a form's visible strings, registers them
with Polylang, and swaps them at render time.

- **Collection** — `Fields::collect()` walks a form and returns its translatable strings
  keyed by a stable path (`title`, `button.text`, `field.7.label`, `field.7.choice.2.text`,
  `field.9.input.1.customLabel`), keyed by field *id* so the paths survive field reordering.
  Covered properties: the form title/description/button text, and per field its `label`,
  `description`, `placeholder`, `errorMessage` and `content`, each choice's `text`, and each
  input's placeholder and **sub-label**.
- **Sub-labels** — multi-input fields (Name, Email-with-confirmation, …) render an input's
  `customLabel` when the admin set one, and only fall back to the built-in `label`
  otherwise. Collection *and* render therefore target `customLabel` when present (that is
  what the visitor actually sees); inputs flagged `isHidden` (e.g. an unused name
  prefix/suffix) are skipped. See `Fields::sublabel_prop()`. Getting this wrong stores a
  translation that never displays — see `tests/unit/cases/GravityFormsFieldsTest.php`.
- **Storage** — strings are registered with Polylang (`pll_register_string`, grouped per
  form) and their translations live in Polylang's own per-language store (`PLL_MO`), keyed
  by the *source string value*. A Supertext AI translation, an edit in Polylang's grid and
  an edit here are all the same record. Registration is per-request and gated by a
  persistent opt-in flag (`Strings::is_enabled()` — the **Add to String Translations**
  button); front-end rendering does not depend on that flag.
- **Render** — `Integration::translate_form()` hooks `gform_pre_render` and
  `gform_pre_validation`, and on a non-default language rewrites each visible string through
  `Fields::apply_callback()` with `pll__()`. Untranslated strings fall back to the source.

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
3. Choose **Supertext**, enter the API key (no Supertext account yet? create one at
   *[supertext.com](https://www.supertext.com/person/en/account/signin)*; then generate the key at
   *[Supertext → Integrations → API](https://www.supertext.com/en/integrations/api)*, requires the Admin role), and map each Polylang language to its Supertext
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

<!-- supertext-plugins:start (shared list, keep identical in every Supertext plugin repo) -->
## Supertext plugins for other systems

Supertext offers AI and professional translation plugins for these systems:

### Content management systems (CMS)

| System | Plugin | Type of integration | What it does |
| --- | --- | --- | --- |
| Adobe Experience Manager | [supertext-aem-connector](https://github.com/Supertext/supertext-aem-connector) | Translation connector: two AEM content packages for AEM's Translation Integration Framework. | Sends AEM translation projects to Supertext and imports the results |
| Contao | [Contao-Supertext-Translation](https://github.com/Supertext/Contao-Supertext-Translation) | Contao bundle (Composer) that adds a back-end action. | *Translate with Supertext* in the site structure: pages or whole websites into other languages |
| Craft CMS | [CraftCms-Supertext-Translation](https://github.com/Supertext/CraftCms-Supertext-Translation) | Craft plugin (Composer) with a panel on the entry page. | Translates entries into your other sites, Matrix and rich text included |
| Directus | [Directus-Supertext-Translation](https://github.com/Supertext/Directus-Supertext-Translation) | Directus extension bundle (npm): interface, endpoint, Flow operation and module. | *Translate with Supertext* box on the item form, fills the Translations field |
| django CMS | [djangoCMS-Supertext-Translation](https://github.com/Supertext/djangoCMS-Supertext-Translation) | Django app (Python package) that adds a toolbar entry. | Translates pages and their plugins from the toolbar |
| Drupal | [tmgmt_supertext_ai](https://www.drupal.org/project/tmgmt_supertext_ai) | Drupal module: a translator provider for the Translation Management Tool (TMGMT), by MD Systems. | Translates TMGMT jobs with Supertext AI |
| Ghost | [Ghost-Supertext-Translation](https://github.com/Supertext/Ghost-Supertext-Translation) | Separate connector service (Ghost has no admin plugins): works through internal tags, webhooks and the Admin API. | Tag a post `#translate-…` and a translated draft appears |
| Grav | [Grav-Supertext-Translation](https://github.com/Supertext/Grav-Supertext-Translation) | Grav 2 plugin with an Admin2 panel. | Supertext panel in the page editor, Markdown kept intact |
| Joomla | [Joomla-Supertext-Translation](https://github.com/Supertext/Joomla-Supertext-Translation) | Joomla system plugin (installable package). | Translates articles into linked, unpublished language versions |
| Magnolia | [Magnolia-Supertext-Translation](https://github.com/Supertext/Magnolia-Supertext-Translation) | Magnolia module with a *Translate with Supertext* action in the Pages app. | Translates pages, areas and components into the site's other languages |
| Neos | [Neos-Supertext-Translation](https://github.com/Supertext/Neos-Supertext-Translation) | Neos package (Composer) that hooks into the content repository; no new UI. | Translates automatically when an editor creates a page in another language |
| Orchard Core | [OrchardCore-Supertext-Translation](https://github.com/Supertext/OrchardCore-Supertext-Translation) | Orchard Core module (.NET) with an admin page and a localization hook. | Translates content items into other cultures, on demand or on localization |
| Payload CMS | [Payload-Supertext-Translation](https://github.com/Supertext/Payload-Supertext-Translation) | Payload plugin (npm) added to `payload.config`. | *Translate* button for localized collections and globals |
| Silverstripe | [Silverstripe-Supertext-Translation](https://github.com/Supertext/Silverstripe-Supertext-Translation) | Silverstripe module (Composer) on top of Fluent. | Supertext tab translates pages and Elemental blocks into Fluent locales |
| Strapi | [Strapi-Supertext-Translation](https://github.com/Supertext/Strapi-Supertext-Translation) | Strapi 5 plugin (npm) with a Content Manager panel. | Translates entries into other locales from the Content Manager |
| TYPO3 | [Typo3-Supertext-Translation](https://github.com/Supertext/Typo3-Supertext-Translation) | TYPO3 extension (Composer) that hooks into TYPO3's own localization; no new UI. | Translates pages and content elements as editors localize them |
| Umbraco | [Umbraco-Supertext-Translation](https://github.com/Supertext/Umbraco-Supertext-Translation) | Umbraco package (NuGet) with a backoffice extension. | *Translate with Supertext* for pages, block lists and grids included |
| Wagtail | [Wagtail-Supertext-Translation](https://github.com/Supertext/Wagtail-Supertext-Translation) | Python package: a machine translator for wagtail-localize. | Translates pages and snippets inside wagtail-localize's editor |
| WordPress (Polylang) | [supertext-wordpress-polylang](https://github.com/Supertext/supertext-wordpress-polylang) | WordPress plugin: a machine-translation service for Polylang Pro, plus professional translation orders. | AI translation next to DeepL in Polylang, and human translation orders |

### Product information management (PIM)

| System | Plugin | Type of integration | What it does |
| --- | --- | --- | --- |
| Akeneo PIM | [Akeneo-Supertext-Translation](https://github.com/Supertext/Akeneo-Supertext-Translation) | Akeneo PIM bundle with a *Translate with Supertext* action on the product page. | Translates products and product models into your other locales |
| AtroPIM | [AtroPIM-Supertext-Translation](https://github.com/Supertext/AtroPIM-Supertext-Translation) | AtroCore module with a button on the product and a mass action in the list. | Translates products and other AtroCore records into your other languages |
| Pimcore | [Pimcore-Supertext-Translation](https://github.com/Supertext/Pimcore-Supertext-Translation) | Pimcore bundle (Composer) with a Pimcore Studio panel. | *In development:* translates documents and data objects into the other languages |

### E-commerce

| System | Plugin | Type of integration | What it does |
| --- | --- | --- | --- |
| PrestaShop | [PrestaShop-Supertext-Translation](https://github.com/Supertext/PrestaShop-Supertext-Translation) | PrestaShop module with a bulk action in the back-office lists. | Translates products, categories and CMS pages into your shop's other languages |
| Shopify | [Shopify-Supertext-Translation](https://github.com/Supertext/Shopify-Supertext-Translation) | Shopify app in the Shopify admin. | Translates products, collections, pages and blog posts into all your shop's languages |

### Design files (XLIFF round trip)

| Application | Plugin | Type of integration | What it does |
| --- | --- | --- | --- |
| Adobe InDesign | [Adobe-InDesign-Translation](https://github.com/Supertext/Adobe-InDesign-Translation) | InDesign scripts (ExtendScript). | Exports all text to XLIFF 1.2 for any CAT tool and imports the translations with formatting intact |
| Adobe Illustrator | [Adobe-Illustrator-Translation](https://github.com/Supertext/Adobe-Illustrator-Translation) | Illustrator scripts (ExtendScript). | Exports all text to XLIFF 1.2 for any CAT tool and imports the translations with formatting intact |
| CorelDRAW | [CorelDRAW-Supertext-Translation](https://github.com/Supertext/CorelDRAW-Supertext-Translation) | CorelDRAW VBA macro. | Exports all text to XLIFF 1.2 for any CAT tool and imports the translations with formatting intact |
<!-- supertext-plugins:end -->
