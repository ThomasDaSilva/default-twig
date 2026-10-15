# Thelia back-office Twig template (`default-twig`)

A Bootstrap 5 / Twig / Stimulus port of the legacy Smarty back-office. It runs alongside `templates/backOffice/default/` during the transition.

## Activation

```bash
# fresh install
ddev exec php bin/install \
  --frontoffice_theme=flexy --backoffice_theme=default-twig \
  --pdf_theme=default --email_theme=default \
  --with-demo --with-admin \
  --admin_login=thelia --admin_password=thelia \
  --admin_first_name=thelia --admin_last_name=thelia \
  --admin_email=thelia@example.com

# already installed: switch active template
ddev exec bin/console template:set backOffice default-twig
ddev exec bin/console cache:warmup -e dev
```

## Architecture

```
templates/backOffice/default-twig/
├── _side_nav.html.twig      # sidebar (mirrors includes/main-menu.html)
├── _top_nav.html.twig       # top bar (logo, search, locale, profile, logout)
├── _footer.html.twig        # copyright + social
├── _thelia_logo.html.twig   # inline SVG logo (variant: dark|light)
├── base.html.twig           # base layout
├── auth-layout.html.twig    # login screen
├── home.html.twig           # dashboard
├── <domain>/                # one folder per business domain (catalog, customer, ...)
│   ├── list.html.twig
│   ├── edit.html.twig
│   ├── _create_modal.html.twig
│   └── _delete_modal.html.twig
├── components/              # reusable Twig components (BoDataTable, BoDashboard, ...)
├── form/
│   └── bo_form_theme.html.twig   # custom Bootstrap 5 form theme
├── config/packages/twig.yaml      # registers form_themes
├── assets/                  # SCSS + JS + img + flags, served through AssetMapper
│   ├── app.js               # entry point (loaded as an ES module)
│   ├── bootstrap.js         # starts Stimulus and registers every controller
│   ├── controllers/         # Stimulus controllers
│   ├── styles/
│   │   ├── main.scss
│   │   └── _variables.scss   # Bootstrap overrides + Thelia palette
│   ├── vendor/              # pinned third-party ES modules (see Assets below)
│   └── img/
│       ├── logo-thelia-34px.png
│       └── svgFlags/         # 256 country flags
└── src/
    ├── BackOfficeDefaultTwigBundle.php
    ├── Controller/
    │   ├── Catalog/         # Product, Category, Brand
    │   ├── Configuration/   # Language, Currency, Variable, Profile, ...
    │   ├── Customer/        # Customer, Address
    │   ├── Folder/          # Folder, Content
    │   ├── Module/          # Module, ModuleHook
    │   ├── Order/
    │   └── NewsletterController.php
    ├── DTO/                 # immutable data transfer objects
    ├── EventListener/
    │   ├── AdminContextRequestListener.php
    │   └── AdminLocaleListener.php   # syncs ?lang= with the Symfony locale
    ├── Form/                # Symfony forms (CustomerType, AddressType, ...)
    ├── Hook/Attribute/      # #[AsHook] custom attribute
    ├── Security/AdminVoter.php
    ├── Service/Admin/       # AdminFormAction, AdminAccessChecker, ...
    ├── Twig/                # BoUrl, BoData, BoHook extensions
    └── UiComponents/        # AsTwigComponent / AsLiveComponent
```

## Stack

- **Bootstrap 5.3** with overrides aligned on the thelia.net public palette (orange `#f26041`, soft slate text).
- **Bootstrap Icons** (1.13).
- **Symfony UX** (Stimulus, TwigComponent).
- **HTMX 2** for progressive enhancement.
- **Symfony forms** with the custom `bo_form_theme.html.twig` theme.
- **AssetMapper + symfonycasts/sass-bundle** for the assets — no Node.js, no bundler, no committed build.

## Working on the back-office

### Assets (AssetMapper, no Node.js)

The assets are served by Symfony **AssetMapper**: JavaScript and images are picked up
as they are from `assets/`, and the stylesheet is compiled by
[symfonycasts/sass-bundle](https://github.com/SymfonyCasts/sass-bundle), which downloads
a standalone `dart-sass` binary on first use. There is no `package.json`, no bundler and
no committed build output.

The bundle registers everything itself (asset paths under the `backoffice` namespace,
the Sass root file, the Bootstrap load path), so the only command to know is the one
that (re)builds the stylesheet:

```bash
# after editing anything under assets/styles/
ddev exec php bin/console sass:build

# or keep it running while working
ddev exec php bin/console sass:build --watch
```

`bin/install` runs it for you on a fresh install. JavaScript and images need no build
at all: edit the file, reload the page.

The Bootstrap Sass sources and its `bootstrap.esm.min.js` come from the `twbs/bootstrap`
Composer package (so the CSS and the JS can never drift apart), and the icon font from
`twbs/bootstrap-icons`. The remaining browser libraries are pinned ES module builds
committed under `assets/vendor/` and mapped to their bare import names by the importmap
the theme renders on its pages (`bo_importmap()`, see `src/Twig/ImportMapExtension.php`):

| Import name | File | Origin |
|---|---|---|
| `@hotwired/stimulus` | `assets/vendor/stimulus.js` | npm `@hotwired/stimulus@3.2.2`, `dist/stimulus.js` |
| `@popperjs/core` | `assets/vendor/popper.js` | jsDelivr ESM bundle of `@popperjs/core@2.11.8` |
| `htmx.org` | `assets/vendor/htmx.esm.js` | npm `htmx.org@2.0.10`, `dist/htmx.esm.js` |
| `chart.js` | `assets/vendor/chart.js` (+ `chunks/helpers.dataset.js`) | npm `chart.js@4.5.1`, `dist/` |
| `@kurkle/color` | `assets/vendor/color.esm.js` | npm `@kurkle/color@0.3.4`, `dist/color.esm.js` |
| `qrcode-generator` | `assets/vendor/qrcode.mjs` | npm `qrcode-generator@2.0.4`, `dist/qrcode.mjs` |

To bump one of them, replace the file with the same artifact from the newer release
(drop the trailing `//# sourceMappingURL=` line) and update this table.

**Adding a Stimulus controller**: drop the file in `assets/controllers/` and register it
in `assets/bootstrap.js` — AssetMapper has no build step, so there is no automatic
controller discovery.

```bash
# clear cache after editing a Twig template
ddev exec bin/console cache:clear -e dev
```

### Adding a new admin domain

The recipe used for each domain so far (Folder → Content → CustomerTitle → Country → State → Newsletter → Message):

1. **Form**: `src/Form/<Group>/<Name>Type.php`, a `final class extends AbstractType` with options `include_id` / `include_description`.
2. **Controller**: `src/Controller/<Group>/<Name>Controller.php` with `#[Route('/admin/...', name: 'admin.X.')]`. Inject `AdminFormAction`, `AdminAccessChecker`, `Environment`, `FormFactoryInterface`, `UrlGeneratorInterface`, `TokenProvider`, `TranslatorInterface`.
3. **Methods**: `list()` (GET), `create()` (POST), `updateView({id})` (GET), `processUpdate()` (POST), `delete()` (POST/GET), `updatePosition()` (POST/GET).
4. **Events**: `$this->action->submit(form: ..., eventFactory: ..., eventName: TheliaEvents::X_CREATE)` for forms; `$this->action->tokenAction(event: ..., eventName: ...)` for single-shot actions.
5. **Templates**: `list.html.twig` (DataTable + create modal), `edit.html.twig` (form_start + form_end).

### Responsive (phone and tablet)

The admin is usable from 375px up. Four rules hold the layout together:

- **Navigation**: the sidebar is `offcanvas-lg`, so it docks from `lg` (992px) and is a burger-driven
  drawer below that — a 768px tablet in portrait gets the full width for its content. The top bar uses
  `navbar-expand` (no breakpoint) so its items stay on one row at every width.
- **Lists**: every `BoDataTable` scrolls horizontally inside `.table-responsive`, with the actions
  column pinned right. Secondary columns are dropped below a breakpoint instead of being pushed out of
  view, via `hideBelow` on the column factories:

  ```twig
  column('id', 'ID'|trans, hideBelow: 'md'),                        {# phone #}
  column('position', 'Position'|trans, 'center', hideBelow: 'lg'),  {# phone + tablet portrait #}
  ```

  Convention: `md` for identifiers and thumbnails, `lg` for dates, positions and counts, `xl` for
  columns whose value is already readable elsewhere on the row or on the record's own page. The column
  that names the record is never hidden.
- **Key/value tables** (plain `<table>`, not `BoDataTable`) carry `.bo-table-wrap`: their cells wrap
  rather than being clipped, because they have nothing to scroll to.
- **Header rows** (`d-flex justify-content-between`) always carry `flex-wrap gap-2` so a title plus its
  action buttons reflow instead of overflowing. Control strips wider than the viewport go inside
  `.bo-scroll-x`.

Touch targets are enlarged below `lg` (row toggles, checkboxes, sortable headers, tree controls). Keep
new controls at 24px minimum in both dimensions.

### ACL

Resources live in `core/lib/Thelia/Core/Security/Resource/AdminResources.php`. Check with `is_granted('VIEW', 'admin.foo')` or `$this->access->check(self::RESOURCE, [], AccessManager::VIEW)`.

### Hooks (back-office)

Twig functions exposed by `BackOfficeDefaultTwigBundle\Twig\HookExtension`:

```twig
{{ safe_hook('main.head-css') }}        {# tolerant fallback for buggy listeners #}
{% for block in hook_block('home.block', { foo: bar }) %}
    <h2>{{ block.title }}</h2>
    {{ block.content|raw }}
{% endfor %}
{% if has_hook('product.tab') %}{% endif %}
```

Most hook names are kept iso with the legacy Smarty template. The few that were renamed are bridged
to their legacy name (see [Cohabitation & breaking changes](#cohabitation--breaking-changes)), so
third-party modules keep working unchanged.

#### Hook contract for third-party modules

The back-office emits ~200 native hooks. The **conventional extension points** a module can rely on
are emitted systematically:

| Convention | Emitted from | Example |
|---|---|---|
| `<screen>.top` / `.bottom` | every screen | `attributes.top`, `product-edit.bottom` |
| `<entities>.table-header` / `.table-row` | `BoDataTable` (every list) | `attributes.table-row` |
| `<entity>.create-form` | `BoCreateDialog` (derived from `testid`) | `brand.create-form` |
| `<entity>.delete-form` | `BoConfirmDialog` (derived from `testid`) | `brand.delete-form` |
| `<entity>.update-form` | edit screens | `feature.update-form` |
| `<entity>.tab` / `.tab-content` | tabbed edit screens | `product.tab` |

Hooks consumed by bundled modules (CustomerFamily, SEOne, HookAdminHome, VirtualProductControl,
TheliaBlocks) are all wired. A hook code that is **not** emitted is considered deprecated for the
Twig back-office. Open an issue if your module needs one that is missing. The `<screen>.js` /
`<entity>.edit-js` script hooks are emitted on a per-screen basis as screens are migrated.

On the product, category, folder and brand sheets, a tab returned by `<entity>.tab` reopens like a native one: a
module that saves a form of its tab redirects to the sheet with `current_tab` set to the `id` of its block. A
`current_tab` the sheet does not have opens the general tab.

#### Adding a section to the customer sheet

The customer sheet is made of collapsible sections (overview, addresses, orders, carts, personal data). A module
adds its own section, for internal notes, support messages or recently viewed products, through
one of these hooks. The context holds `customer`, `customer_id` and `id`, all the customer id.

| Hook | Kind | What the module returns |
|---|---|---|
| `customer.tab` | `hook_block` | `{ id, title, content }` for a section rendered with the page, or `{ id, title, href }` for a section whose body is fetched from `href` the first time it opens |
| `customer.tab-content` | `hook_cards` | HTML, shown in a card of its own in the *Modules* section |
| `customer-edit.actions` | `safe_hook` | a button next to the sheet navigation, e.g. to open an order for the customer |

A URL given as `href` answers a plain `GET` with an HTML fragment, and has to apply the customer
access check itself (`AdminResources::CUSTOMER`, `VIEW`). The title is escaped; the content is the
module's own markup and is rendered as is. A listener that throws is logged and its section left
out: the sheet still renders. No listener, no section.

```php
public static function getSubscribedHooks(): array
{
    return ['customer.tab' => [['type' => 'back', 'method' => 'onCustomerTab']]];
}

public function onCustomerTab(HookRenderBlockEvent $event): void
{
    $event->add([
        'id' => 'my-notes',
        'title' => $this->trans('Notes', [], MyModule::DOMAIN_NAME),
        'href' => '/admin/module/MyModule/customer-notes?customer_id='.(int) $event->getArgument('customer_id'),
    ]);
}
```

## Tests

- **PHPStan**: `ddev exec composer phpstan` (baseline 60 errors).
- **Coding style**: `ddev exec composer cs` / `ddev exec composer cs-diff`.
- **PHPUnit**: `ddev exec composer test`.
- **Playwright (BO Twig)**:
  ```bash
  cd tests/Playwright && BO_TEMPLATE=default-twig npx playwright test specs/backoffice
  ```

## Cohabitation & breaking changes

This template runs side by side with the legacy Smarty back-office. A few names diverge from the
legacy ones; here is how third-party modules are affected.

### Hooks: bridged, no change required

Renamed hooks are replayed under their legacy Smarty name by `Service\Hook\LegacyHookAliases`
(wired into `HookExtension`), so a module listening on the old name keeps contributing. Render
arguments follow the new (Twig) convention, so adapt listeners that read a renamed argument.

| Legacy Smarty hook           | Twig hook                    |
|------------------------------|------------------------------|
| `attribute-edit-form.bottom` | `attribute.update-form`      |
| `feature-edit-form.bottom`   | `feature.update-form`        |
| `administrator.update-form`  | `administrator.edit-form`    |
| `advanced-configuration`     | `advanced-configuration.top` |

The `wysiwyg.js` hook on the hook-edit screen keeps its legacy `wysiwyg-hook-edit-js` location.

### ACL: bridged

The advanced-configuration screen accepts both the new `admin.configuration.advanced` resource and
the legacy `admin.cache` one, so existing profiles keep access without a data migration.

### Routes: update your module

Renamed route names are **not** aliased. A module referencing an old name through `path()` / `url()`
must update it:

| Legacy route name                       | Twig route name           |
|-----------------------------------------|---------------------------|
| `admin.sale.reset`                      | `admin.sale.reset-status` |
| `admin.configuration.order-status.*`    | `admin.order-status.*`    |
| `admin.configuration.mailing-system.*`  | `admin.mailingSystem.*`   |

The ACL resource for the mailing system stays `admin.configuration.mailing-system`.

## License

LGPL-3.0+, same as Thelia core.
