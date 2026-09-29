# Changelog

Notable changes to `cresset/module-template-parser`.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[semantic versioning](https://semver.org/spec/v2.0.0.html). Pre-1.0, so the public API may
still move between minor versions.

Entries say what changed and why it mattered. A line that only names a file has been left out.

## [Unreleased]

### Added

- A per-store-view setting chooses the engine: **Stores › Configuration › Advanced › System ›
  Template Engine** (`system/template_engine/mode`), with **Legacy**, the default, and
  **Shadow**. Rolling out is now a configuration change per store view instead of two `di.xml`
  entries in a project module, and a Shadow run is evidence for the store view it ran in.
  Parser is not offered until it can fall back to legacy on a refusal (#2).

- Shadow records every comparison in a `cresset_template_shadow` table, one row per store view
  and template, counting agreements, divergences, refusals and crashes, with when the template
  last diverged and how many renders have been clean since. The template is named by where it
  came from — `email:sales_email_order_template`, `cms_block:7` — by plugins on the email and
  CMS models, so a report can say which template to open. Agreements are counted too, so "no
  divergences" can be told apart from "nothing rendered". This is what the Shadow report in #1
  reads.

- `bin/magento template:check`, `template:diff` and `template:repl`: the commands are
  registered with Magento's `CommandList`, so a store runs them without the standalone binary
  or magerun. They take the ObjectManager bin/magento already booted, and building them does
  nothing else, because bin/magento builds every registered command on every run.

- `shadow:report` (`template:shadow:report`, `template-parser:shadow:report`) reads the Shadow
  table and says, per store view, how many templates and renders were compared, how many
  diverged, were refused or crashed, and since when the store view has been clean. Each
  divergence comes with its causes and the `diff` command that reproduces it. The exit code is
  the rollout gate: 0 clean, 1 something diverged or crashed, and 2 when nothing in scope was
  compared at all — so an empty table is never reported as a clean one. `--since` counts only
  divergences after a fix; `--template` takes `*` patterns such as `cms_block:*`.

- `shadow:clear` forgets recorded results for a store view or template, so what is measured
  next starts over. It refuses to run without `--store`, `--template` or an explicit `--all`.

- `check` warns about directives a module added as `fooDirective()` methods on a template
  filter. The legacy filter dispatches those by reflection and this engine never does, so in
  compatible mode such a directive renders as its own text — silently, until now. Found
  without calling anything: the store's filter classes are resolved through the ObjectManager,
  and a method counts when a non-Magento class declares it, which catches overrides of stock
  directives too.

### Changed

- The module wires `TemplateFilterPlugin` on `Magento\Email\Model\Template\Filter` itself,
  which covers the CMS, Widget and Newsletter filters too. Installing it still changes no
  rendering: under Legacy the plugin reads the setting and returns. It finds the render's
  store without calling the filter's `getStoreId()`, which fills an unset store from the
  current one and keeps it — on the shared CMS filters that would have pinned every later
  render to the first store that rendered.

### Removed

- Shadow's `info` lines in `system.log` (`template-parser shadow: divergence` and
  `... engine raised`), and the SHA-256 of the template they carried. A hash could not be traced
  back to a template, and a general-purpose log could not be reported on per store view; the
  table replaces both. `ShadowComparator::compare()` now returns a `ShadowOutcome` instead of
  the legacy string, and no longer takes a logger.

- `ShadowComparator`'s `enabled` argument. Whether a render is compared is the setting's to
  decide, per store view; a project module that set `enabled` should drop that entry and set
  the stage instead. A project module that declared the plugin itself should drop that too, or
  every render is intercepted twice.

## [0.2.0] - 2026-09-29

### Changed

- The Composer package is `cresset/module-template-parser`, matching the other Cresset
  packages, and it is on Packagist. 0.1.0 was published as `cresset-tools/module-template-parser`
  and is not renamed; require the new name from 0.2.0. The GitHub repository and the
  `Cresset\TemplateParser` namespace are unchanged.
- Compatible mode renders an unknown paired construction verbatim, as the legacy filter does,
  instead of refusing it. `{{foo}}x{{/foo}}` is one construction to CONSTRUCTION_PATTERN and an
  unknown one comes back as written, in any letter case and with a mismatched-case closer —
  124 recorded cases, all verbatim. A body holding one of our directives is still refused:
  legacy resolves an inner `{{var}}` there but not an inner `{{if}}`, and guessing which is
  which would risk rendering something the filter does not. The deliberate over-refusals go
  from 17 spellings to 13.

## [0.1.0] - 2026-09-13

First release.

### Added

- A parse-to-AST template engine for the `{{…}}` language, replacing
  `Magento\Framework\Filter\Template`'s regex passes. The whole stock directive surface is
  implemented — `var`, `if`, `depend`, `for`, `else`, `trans`, `template`, `inlinecss`, `css`,
  `block`, `widget`, `layout`, `media`, `store`, `view`, `protocol`, `config`, `customvar` —
  with the guards in the directive handlers rather than in each host adapter, so a host cannot
  forget one.
- Three modes. `compatible` reproduces the legacy filter, including quirks that are defects;
  `strict` and `lenient` exist so a host that does not need a rollback path is not stuck with
  them.
- `RenderPolicy`, a per-render capability bound. Capability belongs to the template, not the
  application: a stock transactional email and a merchant-edited CMS block reach the same
  filter and need different trust levels. Class-instantiating directives are denied by
  default.
- Host ports (`UrlBuilder`, `BlockRenderer`, `WidgetRenderer`, `LayoutRenderer`,
  `TemplateLoader`, `StylesheetLoader`, `ConfigReader`, `CustomVariableReader`,
  `Translator`, `TemplateUrlBuilder`, `CustomDirectiveRenderer`) with Magento adapters for
  each. The engine itself has no Magento dependency.
- A Magento integration that does not require a DI preference: emails, CMS and newsletter all
  render through concrete subclasses DI instantiates directly, so the adoption path is a
  plugin plus `ShadowComparator`, which compares both engines in production and returns the
  legacy result until you say otherwise.
- The `template-parser` CLI: `check` validates templates and says what to fix, `diff` renders
  every template in a store through both engines and reports where they differ, `repl` tries
  directives interactively. An n98-magerun2 bridge for the same commands.
- `{{mydir}}` — a directive a module registers through `SimpleDirective\ProcessorPool` —
  renders, through a `CustomDirectiveRenderer` port. It needed a third directive kind:
  Magento's pattern makes the body optional, so one registration gives a template both
  `{{mydir "v"}}` and `{{mydir}}body{{/mydir}}`. Nesting one in itself is refused, because the
  filter's lazy body strands the outer closing tag and raises.
- `check` and `diff` ask the store what its `SimpleDirective\ProcessorPool` and `FilterPool`
  hold, and say so when a template uses one. A registration this engine has no handler for is
  otherwise invisible: an unknown directive comes back as its own text and an unknown modifier
  is skipped, which is exactly what the filter does on a store without that extension.
- `docs/directives.md`, generated from a live legacy filter rather than written by hand. The
  generator refuses to write the file when an example disagrees with the filter and the
  disagreement has not been declared.
- A recorded corpus — 4788 cases, 804 of them constructs the legacy
  filter cannot render at all — replayed by the test suite, so parity is measured against
  recorded behaviour rather than asserted. Plus a benchmark against the legacy filter and
  mutation tripwires for the guards.

### Changed

- `{{layout}}` requires a handle allowlist. A layout handle decides which blocks get built,
  and template text is not a trustworthy source for one.
- `{{layout}}` drops `template` and `module_name`, which `setDataUsingMethod` would turn into
  `setTemplate()` on every block in the handle — arbitrary `.phtml` execution chosen by
  template text. The legacy filter forwards them.
- `{{block}}` drops an `area` that is not `frontend`, and reuses the store's own
  `BlockDirectivePolicy` deny list rather than reimplementing it, so a rule added to
  `Magento_Email`'s `di.xml` applies here too.
- A quoted parameter may contain `{{` and `}}`. The legacy filter cannot express that; a
  lexer has no reason to inherit the limitation. This and the `{{for}}` body are the two
  places the engine does *more* rather than less, and both directions of this one are recorded
  as corpus cases with the equality declared rather than kept out of the corpus.
- One missing brace at top level reads as text followed by the intact directive, rather than
  as the regex's single run-on construct. Where that costs the filter a *paired* directive it
  raises instead of rendering, and those are refused here rather than rendered.
- Plain-text mode is honoured: `{{customvar}}` reads a variable's text value, `{{css}}` and
  `{{inlinecss}}` render nothing, as the filter does.

### Fixed

- `{{layout}}` discarded every parameter it was given. Every stock order, invoice, shipment
  and credit-memo email ends in a `{{layout}}` — `{{layout handle="sales_email_order_items"
  order_id=$order_id}}` and its invoice, shipment and credit-memo counterparts — so the item
  table was being built for no order at all — and without registering the root
  block for output, `getOutput()` returned nothing for any handle whose XML lacks
  `output="1"`.
- `{{protocol http= https=}}` was guarded as though it took relative paths, so every value the
  store accepts rendered empty. `{{protocol}}` with neither parameter returned empty rather
  than the scheme, which is the form `{{protocol}}://{{store url=''}}` needs.
- `{{config}}` returned a country *code* and a numeric region id where the filter renders
  their names.
- `{{customvar}}` held its code to an identifier shape, refusing any code a merchant had
  namespaced with a separator — Magento validates a variable code for uniqueness and nothing
  else.
- `{{media}}` and `{{view}}` refused an absent path instead of rendering the base URL, which
  `{{media url=$p}}` with `$p` unset reaches by typo.
- A `{{` inside a quoted parameter whose closer happened to fall outside the quotes was
  re-scanned from the inner brace, producing a refusal that claimed a crash the filter does
  not have.
- Refusal messages claimed a legacy `TypeError` in 154 cases where the filter renders
  perfectly well.
- The parser allocated a match table for the whole source to report a single refusal: 4 MB of
  input cost 527 MB, a fatal at Magento's usual limit, from a template a merchant can paste
  into a CMS block. Nesting cost `O(depth²)`.
- `bin/template-parser diff` reported differences that were the tool's own: it never set
  `setUseAbsoluteLinks`, never applied store emulation, and replaced an empty variable
  read-back with the caller's set — so its "today" column matched no pipeline the store runs.
- The documented adoption path did not work. Following the README produced an adapter with
  **no host ports wired at all** — every Magento adapter was written, tested and connected to
  nothing — so `{{css}}`, `{{template}}` and the rest raised "No handler registered", and the
  adapter defaulted to strict rather than compatible mode, so an unknown variable raised where
  the filter renders empty. Shadow mode over the 48 stock email templates failed 203 times
  before this and reports zero now.
- `TemplateFilterPlugin` kept a single slot of captured state, and `filter()` is re-entrant: a
  `{{template}}` include's child model overwrote the parent's variables before the parent's
  comparison ran, so the parent was compared against the child's scope.
- `{{filter}}` was listed as a stock directive and is not one. Magento's two extension points
  are easy to confuse: `SimpleDirective\ProcessorPool` registers arbitrary *named* directives
  (`mydir` → `{{mydir}}`), and `DirectiveProcessor\Filter\FilterPool` registers *modifiers*
  (`foofilter` → `{{var x|foofilter}}`). Neither produces a `{{filter}}`. Listing it made
  `knownNames()` — which `diff`'s notes and the render policy are built from — claim a
  directive nobody can write.
- Every legacy-fatal refusal fired inside a `{{for}}` body, where none of them is true.
  `ForDirective` never renders its body — it substitutes each construct with the variable
  resolution of that construct's parameter text — so nothing there reaches a directive
  processor and nothing there can crash. `{{}}`, `{{/if}}`, `{{var.a}}` and `{{var1 x}}` all
  render on the filter and were refused here with a message asserting a `TypeError` that
  cannot happen in that position. The one real exception — a nested loop, which strands the
  outer `{{/for}}` and does raise — was then tested by name prefix, so `{{for2 a}}` was
  refused too. `LOOP_PATTERN` is matched now: `{{format x in rows}}` opens a loop to the
  filter and `{{for2 a}}` does not.
- `{{css}}` resolved its design at render time where the filter carries a snapshot, so the
  same template got a different stylesheet depending on who rendered it and when. The design
  is passed now — `Port\StylesheetLoader::load()` takes it, `Context` carries it, and the
  plugin captures `setDesignParams()` the way it captures the variables.
- `check` and `diff` no longer warn about `FilterPool` modifiers. It was a false positive: a
  registered modifier never reaches `{{var}}` on any surface this package replaces, because
  `Email\Model\Template\Filter::varDirective` uses its own modifier map, so both engines skip
  it identically.

### Security

Nothing here has been released, so none of this reached a deployed store.

- `PathGuard` required the trailing semicolon on an HTML character reference; browsers do not,
  for numeric ones. A value carrying `&#58` — no semicolon — passed as a relative path and
  arrived at the browser as a scheme, so `{{protocol}}` could emit a working `javascript:`
  URI under the default restricted policy, in every mode, recording no violation. Traversal
  and protocol-relative forms rode the same gap.
- Route parameters were guarded by a list of three names, while `Url::_getRoutePath()`
  appends *every* one to the path as `$key . '/' . $value . '/'` — the key included.
  `{{view}}` was worse: `area`, `theme` and `locale` are concatenated into the static URL with
  nothing checked, and none of them was on that list.
- `_escape_params` was forwarded from the template. `storeDirective` overwrites it with the
  store code, and `Url` escapes route parameters only while it is truthy, so a template could
  turn the escaping off for every route parameter beside it.
- `{{block}}` had no deny concept at all — only an allowlist that neither `di.xml` nor the CLI
  ever set — which made this engine strictly *more* permissive than the filter it replaces:
  of the 919 block classes a stock store denies, it rendered real admin HTML for 11. The
  `area` parameter let template text resolve a block's template out of the adminhtml theme.
- The `{{block}}` allowlist matched case-sensitively, and PHP class names do not.
- `TemplateFilterAdapter` let host exceptions escape, where
  `Email\Model\Template\Filter::filter()` catches them and substitutes an error string —
  turning a degraded email into a 500. The engine's own diagnostics are re-thrown rather than
  swallowed, being the product.
