# Paywall category: identity by term_id

**Status:** Draft — awaiting user review before plan.
**Date:** 2026-04-21
**Branch:** `feat/configurable-paywall-rules`

## Motivation

The plugin currently stores the paywall category as a mutable **name** string (`paywall_category`). Every save becomes a guessing game: is this a rename, a reassignment, a collision, or a first save? The orchestrator encodes that guessing in ~40 lines of branched logic; `on_update` re-derives intent independently; the two must agree, so a full-lifecycle test exists to pin the coordination. The latest concrete failure: if the sanitize layer rewrites an empty category input to `DEFAULT_CATEGORY` and the admin's real term exists while the default term was deleted, the orchestrator renames the admin's real category in place — and fires a "rename succeeded" notice about it.

Switching the setting to store a stable `term_id` collapses the decision tree: the admin is binding the paywall to a term, not editing the term's name.

## Goals

- Remove rename/collision semantics from the settings save path.
- Keep external-deletion healing (the `delete_term` guard).
- Keep the mode-switch ("every published post is now paywalled") notice.
- Net deletion: fewer lines, fewer tests, fewer failure modes.

## Non-goals

- Back-compat with the string-based option. No users; no migration.
- A "create new category" affordance on the settings page. Admin uses Settings → Categories.
- A "rename this category" affordance on the settings page. Same.
- Any change to wallet, price, mode, bot-detection, or request-time paywall logic beyond the one-liner in `DefaultPaywallRule::matches()`.

## Storage shape

`simple_x402_settings` option:

```
wallet_address          : string
default_price           : string (decimal)
paywall_mode            : 'category' | 'all-posts'
paywall_category_term_id: int          ← new; replaces paywall_category
```

`paywall_category` (string) is removed entirely.

`SettingsRepository` public surface:

- `paywall_category_term_id(): int` — returns stored int, or lazily resolves to the default term's id if stored is 0 / missing / points at a non-existent term.
- `set_paywall_category_term_id(int)` — partial update that bypasses full-option `sanitize()`; preserves unrelated fields. Used by the guard.
- `DEFAULT_CATEGORY = 'x402paywall'` constant stays — it's the **name** we create on activation and fall back to. It no longer appears in stored data.
- `paywall_category()` accessor is deleted.
- `set_paywall_category(string)` is deleted.

Activation:

- `Plugin::activate`: `$categories->ensure( DEFAULT_CATEGORY )`, look up the resulting `term_id`, and if the stored option has no `paywall_category_term_id`, write it. Idempotent across reactivations.

## Sanitize and save flow

`SettingsRepository::sanitize()` validates the int and does nothing else:

```
sanitize($input):
    wallet  = trim(input.wallet_address)
    price   = validate-or-default(input.default_price)
    mode    = validate-or-default(input.paywall_mode)
    term_id = (int) (input.paywall_category_term_id ?? 0)
    if term_id <= 0 OR !term_exists(term_id, 'category'):
        term_id = default_term_id()   // ensures default, returns its id
    return { wallet_address, default_price, paywall_mode, paywall_category_term_id }
```

No "empty → default name" rewrite. No rename detection. No collision detection. An invalid term_id falls back to the default silently — the dropdown only emits ids of existing terms, so invalid input means tampered POST.

## Orchestrator, guard, notifier

### `SettingsSaveOrchestrator` is **deleted**.

The only surviving save-time concern is the mode-switch notice. That moves to a tiny dedicated callback — a new `AllPostsModeNoticeEmitter` class with one `__invoke($old_value, $new_value)` method, registered on `update_option_simple_x402_settings`. Deletes the `pre_update_option_*` hook registration in `Plugin::boot`.

### `CategoryRepository`

- `ensure(string)` — kept. Used by activation and the guard.
- `rename(string, string)` — **deleted**. No caller.

### `PaywallCategoryGuard`

Compares by `term_id` instead of name:

```
__invoke($term_id, $tt_id, $taxonomy, $deleted_term):
    if $taxonomy !== 'category': return
    if $term_id !== $settings->paywall_category_term_id(): return
    $categories->ensure(DEFAULT_CATEGORY)
    $default_id = resolve default term's id
    $settings->set_paywall_category_term_id($default_id)
    $notifier->notify_paywall_category_deleted($deleted_term->name)
```

`$deleted_term` still typed as `\WP_Term` in production (test bootstrap will stub `WP_Term` instead of `stdClass`).

### `SettingsChangeNotifier`

Shrinks to two methods:

- `notify_paywall_category_deleted(string $name)` — kept.
- `notify_mode_switched_to_all_posts()` — kept, invoked by the new mode-switch emitter.

**Deleted:** `notify_rename_succeeded`, `notify_rename_collision`, `notify_existing_category_rejected`.

## UI

`SettingsPage::render()` swaps the text input for `wp_dropdown_categories()`:

```php
wp_dropdown_categories( array(
    'name'             => $option . '[paywall_category_term_id]',
    'id'               => 'sx402-category',
    'taxonomy'         => 'category',
    'hide_empty'       => false,
    'show_option_none' => false,
    'selected'         => $this->settings->paywall_category_term_id(),
    'hierarchical'     => true,
) );
```

- Default `x402paywall` term is always in the list (activation ensures it).
- No "create new" affordance; a description line links to Settings → Categories.
- Mode=all-posts still disables the control via `admin-settings.js`; script keeps its shape, just targets `select#sx402-category` instead of `input#sx402-category`.

## Runtime call sites

One production read-site: `DefaultPaywallRule::matches()`.

```php
// Before
has_term( $this->settings->paywall_category(), 'category', $post_id );
// After
has_term( $this->settings->paywall_category_term_id(), 'category', $post_id );
```

`has_term` accepts term_id directly.

## What gets deleted

Production:

- `src/Services/SettingsSaveOrchestrator.php` (whole file).
- `CategoryRepository::rename()` method.
- `SettingsRepository::paywall_category()`, `set_paywall_category(string)` methods.
- `SettingsChangeNotifier::notify_rename_succeeded`, `notify_rename_collision`, `notify_existing_category_rejected` methods.
- `add_filter('pre_update_option_…', [$orchestrator, 'on_pre_update'], …)` wiring in `Plugin::boot`.

Tests:

- `tests/Unit/SettingsSaveOrchestratorTest.php` (whole file — replaced by a much smaller `AllPostsModeNoticeEmitterTest`).
- Any `SettingsRepositoryTest` cases exercising `paywall_category` string accessors / `set_paywall_category(string)`.
- Any `SettingsChangeNotifierTest` cases for the three removed methods.

Added:

- `SettingsRepositoryTest` cases for `paywall_category_term_id()` (stored, zero, invalid → default fallback; ensures default term exists once).
- `PaywallCategoryGuardTest` updated to use term_id equality semantics and a `WP_Term` stub.
- `AllPostsModeNoticeEmitterTest` — covers the preserved notice.
- `DefaultPaywallRuleTest` updated to pass a real term_id through the `has_term` mock.
- `SettingsPageTest` — renders the dropdown with the expected selected id.

## Migration

None. The `paywall_category` string in any development-environment stored option becomes dead data and is simply dropped by the new `sanitize()` return shape the next time the option is written. Developers with local state can clear the option or re-save settings once.

## Risks

- **Dropdown only shows existing terms.** An admin who wants a brand-new category has to create it in Settings → Categories first. Documented in the UI description.
- **Orphan between `delete_term` and the guard.** `delete_term` fires after the term is gone but before the guard resolves the default. During that window, `paywall_category_term_id()` returns the lazily-resolved default. Acceptable: no request-path code can observe the intermediate state in a single request, and the guard runs synchronously.
- **Default term deleted while it IS the stored value.** Guard re-`ensure()`s it (creates a new row with the same name, new term_id) and writes that new id back. Same behavior as today.

## Out of scope / deferred

- Storing term_id also removes the theoretical reason to notify on "rename" — if a future feature wants to surface "the bound category's name changed", that's an *external* event (Settings → Categories), and a separate notification could hook `edited_term` if desired. Not in this change.
- Any migration path. If the plugin ships to real users later, a follow-up release can add a forward-only migration.
