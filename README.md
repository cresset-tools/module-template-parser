# cresset-tools/module-template-parser

A parser for Magento's `{{...}}` template directives. It builds an AST and evaluates it once,
instead of matching regexes against its own output the way `Magento\Framework\Filter\Template`
does.

```php
use Cresset\TemplateParser\TemplateEngine;

$engine = new TemplateEngine();

echo $engine->render('Dear {{var name}},', ['name' => 'Ada']);
// Dear Ada,
```

Everything in this README lives under `Cresset\TemplateParser\`; later snippets leave the
`use` lines out.

Requires PHP 8.3, 8.4 or 8.5. The engine is plain PHP with no Magento dependency; the Magento
bindings sit behind ports in `src/Magento/`.

## Why

`Magento\Framework\Filter\Template` finds directives with regular expressions and scans its
own *output* a second time. Because a regex cannot express nesting, nested rendering is done
by re-running the whole engine over substrings, which means a child render sometimes holds a
directive belonging to its parent. The only channel back up is the output text, so deferred
directives are marked in-band with a per-request signature. An in-band marker sitting in the
same buffer as attacker-controlled data can be relocated, which is the StyleSmuggler class of
bug that Sansec reported. The signing mechanism it subverts was itself added in 2022 to fix an
earlier bug of the same shape.

This engine removes the conditions rather than tightening the check:

- **A value is never source.** Parse once, evaluate once. There is no second scan, so content
  introduced by a variable cannot be executed at any depth, under any modifier.
- **Nesting comes from a grammar.** `DirectiveSpec` declares which directives take a body. A
  closing tag closes only a block that is actually open.
- **Deferral is structured.** `Context::defer()` records work and `Context::absorb()` hands a
  child's entries up one level. Nothing travels in the output stream, so nothing needs signing.
- **Unknown constructs are inert.** No handler means the directive round-trips as text.
  Nothing is guessed at, and there is no reflection-based dispatch.
- **Parsing is lossless.** Every AST reproduces its source byte for byte, which is what makes
  shadow-mode comparison possible.

## Modes

Three presets, differing only in how much they refuse:

| Mode | Behaviour | Use it for |
|---|---|---|
| `new TemplateEngine()` | strict: unparseable input, unknown directives and unknown variables all raise | templates being authored or validated |
| `TemplateEngine::lenient()` | recovers instead of raising; unknown constructs render verbatim | content already stored in a database |
| `TemplateEngine::compatible()` | lenient, plus the legacy filter's rendering quirks | shadow comparison, and switching a store over |

Strictness has three independent axes, so you can mix them:

```php
TemplateEngine::withOptions(Options::strict()->withVariables(false));
TemplateEngine::withOptions(Options::strict()->withMaxNestingDepth(5));
```

The safety properties are structural and apply in every mode.

## Using it as a Magento module

The package is a `magento2-module` with `registration.php` and `etc/`.
**Installing it changes no rendering behaviour**: `etc/di.xml` declares no preference for
`Magento\Framework\Filter\Template`.

Adoption goes through a plugin, not a preference. Emails render through
`Magento\Email\Model\Template\Filter`, CMS extends that, and Newsletter extends
`Widget\Model\Template\FilterEmulate` — all concrete classes DI instantiates directly, so a
preference for the framework base class never applies. Declare the shipped plugin in a
project module against whichever filter you want to cover:

```xml
<type name="Magento\Email\Model\Template\Filter">
    <plugin name="cresset_template_parser" sortOrder="10"
            type="Cresset\TemplateParser\Magento\Plugin\TemplateFilterPlugin"/>
</type>
```

That is still inert until you enable `Magento\ShadowComparator`, which renders the template
through this engine, logs where it differs from the legacy output it was handed, and returns
the *legacy* result — so switching it on changes nothing a customer sees. It logs the policy
violations and legacy incompatibilities behind a divergence, not just a byte offset.

### Directive surface

The full stock surface is implemented. Directives needing nothing from the host are built in;
the rest go through narrow ports, which is what keeps the engine free of a Magento dependency
and unit-testable.

| Directive | Port | Magento implementation | Guard |
|---|---|---|---|
| `var`, `if`, `depend`, `for`, `else` | — | built in | — |
| `inlinecss` | — | built in | `PathGuard`; structured deferral, never emitted as text |
| `trans` | `Translator` *(optional)* | `PhraseTranslator` | arguments are escaped; a built-in handler renders it even with no port wired |
| `block` | `BlockRenderer` | `LayoutBlockRenderer` | type checked before instantiation, resolving DI preferences and virtual types; `output=` is an allowlist |
| `widget` | `WidgetRenderer` | `TypeCheckedWidgetRenderer` | same, against `Widget\Block\BlockInterface`; optional type allowlist |
| `template` | `TemplateLoader` | `ConfigTemplateLoader` | config-path allowlist; include cycles, depth and total count all bounded |
| `layout` | `LayoutRenderer` | `AllowlistedLayoutRenderer` | handle allowlist required, area restricted to frontend/adminhtml |
| `config` | `ConfigReader` | `AllowlistedConfigReader` | Magento's `Variables::getAvailableVars()` allowlist, failing closed |
| `customvar` | `CustomVariableReader` | `VariableCustomVariableReader` | identifier-shaped codes only |
| `store`, `media`, `view` | `UrlBuilder` | `StoreUrlBuilder` | `PathGuard` on the path and on forwarded parameters like `_direct`: no traversal, scheme, absolute or protocol-relative path |
| `protocol` | `UrlBuilder` | `StoreUrlBuilder` | a host/path shape check, not `PathGuard` — it blocks schemes and protocol-relative URLs but permits `..`, which cannot escape a host |
| `css` | `StylesheetLoader` | `AssetStylesheetLoader` | `PathGuard` |

## Per-render capability policy

Capability belongs to the template, not the application. A stock transactional email and a
merchant-edited CMS block reach the same filter and need different trust levels, which a
DI-time allowlist cannot express because it is fixed for the whole install.

**The default is restrictive.** `{{block}}`, `{{widget}}` and `{{layout}}` turn template text
into a PHP class being loaded and constructed, so they are refused unless granted, even when
the host has wired their ports. The safe set is enumerated rather than derived, so a directive
added later defaults to denied.

```php
// Grant one capability, narrowed to specific classes.
$policy = RenderPolicy::restricted()
    ->alsoAllowing(['block'])
    ->withAllowedBlocks([\Magento\Sales\Block\Order\Email\Items::class]);

// Cut the surface down further: substitution and conditionals, no reach into the host.
$policy = RenderPolicy::allowing(['var', 'if', 'depend']);

// Or opt out entirely, which is the legacy filter's posture.
$policy = RenderPolicy::unrestricted();

$context = new Context($variables, $policy);
$html = $engine->render($template, [], $context);

foreach ($context->violations() as $v) {
    $logger->warning($v->describe());   // policy refused block "..." (line 4, column 12)
}
```

The allowlist is checked before the port, so a refused class is never constructed.
`{{widget}}` shares the block allowlist, since a widget is a block by another name.

The policy is consulted only after a handler is found. It can therefore remove a capability
the host granted, but it never changes the output for a directive nobody wired up, which would
break compatible mode's parity.

A violation renders nothing and is recorded rather than thrown: a policy violation should not
take down an order email, but it must not pass unnoticed either. For template validation or
CI, `Options::withFailOnPolicyViolation(true)` makes it fatal. A nested `{{template}}` inherits
the policy, so an include cannot widen it.

Two further properties are deliberate. A directive that needs a port and has none stays
unregistered, so the host grants capabilities one at a time rather than inheriting the whole
surface. (`{{trans}}` is the exception: it has a built-in handler and renders with or without
a `Translator`, since substitution needs nothing from the host.) And
`PathGuard` and the identifier checks are applied by the directive handlers rather than left
to each implementation, so a host cannot forget one; `FullDirectiveSurfaceTest` asserts the
port is never reached for rejected input. Refusing after the fact is the mistake that made
`BlockFactory` exploitable.

The legacy filter has no such guards: `mediaDirective` is literally
`getBaseUrl(MEDIA) . $params['url']`, and `protocolDirective` is
`$protocol . '://' . $params['url']`.

## Compatible mode

`TemplateEngine::compatible()` reproduces the legacy filter's observable rendering, so it can
be switched on without changing what customers see.

Parity is measured against the real filter. `tools/record-legacy.php` runs an unpatched
Magento tree over a corpus and records what it produced; it calls Magento's own `Escaper`
rather than reimplementing it, because reimplementing the escaper once made the measurement
circular.

**2308 cases recorded, 313 of them constructs the legacy filter cannot render at all. Over
the 1334 cases where both engines render and the surfaces are comparable, output is
byte-identical.**

Take that as measured, not proven. Every round of adversarial fuzzing so far has found a new
class of divergence, and the honest reading is that the corpus bounds what is known rather
than what is true. Two properties are asserted absolutely and are worth more than the
headline number:

- **Nothing the legacy filter crashes on is rendered here.** A construct the old filter died
  on is one nobody has ever seen the output of, so rendering it would be inventing behaviour,
  not reproducing it.
- **Where both render, they agree byte for byte** — for every case in the corpus.

Everything else is a superset of refusals, enumerated below. Before switching a store over,
run `Magento\ShadowComparator` against your own templates; the corpus cannot contain them.

Quirks it reproduces:

| Quirk | Legacy behaviour |
|---|---|
| truthiness | `resolve(...) == ''`, so on PHP 8 `0`, `'0'` and `[]` are truthy |
| partial paths | member access is only attempted on an array or DataObject parent, so a scalar parent yields itself (`{{var store.frontend_name}}` renders the store) |
| missing keys | an array parent with a missing key yields nothing, not the parent |
| arrays | cast to the literal string `Array` |
| no variables | directives pass through verbatim, which is the template-validation path |
| getter keys | `getAddress1()` reads `address_1`, because a run of digits is its own segment |
| member access | only through `getData()`; a real getter is never called |
| unknown modifiers | skipped, so `{{var x\|typo}}` renders raw |
| unknown escape types | `escape:none` returns the value unescaped |

Unknown modifiers and unknown escape types are reproduced only in compatible mode. Everywhere
else they fail closed.

#### Which legacy filter?

Mage-OS shipped StyleSmuggler hardening in September 2026. Part of it,
`Template\DirectiveOutputNeutralizer`, encodes `{{` in resolved directive output so it can
never be re-parsed by a later pass — which changes observable rendering:

```
{{var a}}  with  a = '{{block class=Evil}}'
  before the hardening:  [{{block class=Evil}}]
  after:                 [&#123;&#123;block class=Evil}}]
```

Both trees are in the field, so compatible mode targets either. It follows the current filter
by default; for a tree from before the hardening:

```php
TemplateEngine::withOptions(Options::compatible()->withOutputNeutralizer(false));
```

The corpus records both, and 288 cases carry a second expectation for the older behaviour.
This engine needs none of it — a value is never re-parsed here whatever the setting — so the
flag does nothing outside compatible mode.

Quirks it does not reproduce:

- the security behaviour, which is structural: a value is never re-parsed as source, in any mode;
- reflection dispatch of arbitrary filter methods;
- `{{var x|modifier}}` rendering empty. That is a defect in `Framework\Filter\Template`, whose
  `varDirective` hands `VarDirective` a legacy-shaped construction so the expression resolved
  is `" x|raw"`. `Email\Model\Template\Filter` overrides `varDirective` and handles modifiers
  correctly, and that is the filter templates actually render through.

### What it refuses

The relationship is a superset, and the direction matters.

**Every construct the legacy filter cannot render is refused.** Twelve conditions, each
verified against the real filter:

| Condition | Example | Why legacy dies |
|---|---|---|
| same-name nesting | `{{if}}` in `{{if}}` | lazy body hands on an unclosed inner directive |
| unclosed block | `a{{if a}}b` | the per-directive re-match finds nothing, passes null on |
| stray closing tag | `a{{/if}}b` | same |
| name not starting with a letter | `{{100}}`, `{{ var x }}`, `{{}}` | no name captured, `ProcessorPool::get(null)` |
| name split by punctuation | `{{if_a}}` | the name is a greedy `[a-z]{0,10}`, so this is `if` with the parameter `_a` |
| padded closing tag | `{{/if }}` | `CONSTRUCTION_IF_PATTERN` allows the space, the closing backreference does not |
| modifier arguments | `{{var a\|nl2br:x}}` | passed through to `nl2br()`, a `TypeError` on `$use_xhtml` |
| member call on an array | `{{var a.getB()}}` where `a` is an array | `->getData()` on an array |
| `\|nl2br` on a non-string | `{{var a\|nl2br}}`, `a=0` | `nl2br()` under `strict_types` |
| `\|escape:htmlentities` on a non-string | `{{var a\|escape:htmlentities}}`, `a=0` | `htmlentities()` under `strict_types` |
| `\|escape:url` on a non-string | `{{var a\|escape:url}}`, `a=0` | `rawurlencode()` under `strict_types` |
| an array holding a non-`Stringable` object | `{{var a}}`, `a=['o'=>new stdClass]` | `Escaper::escapeHtml` recurses and casts each element — and `escape` is the default modifier, so no modifier need be written |

Nesting is bounded by repeated names, not depth. A directive cannot contain itself at any
distance, but three distinct names nest fine: all six orderings of `{{if}}`, `{{depend}}` and
`{{for}}` render three deep on the real filter.

The `{{100}}` row is narrower than it looks. `CONSTRUCTION_PATTERN` is case-insensitive, so
`{{Password}}` and `{{Forgot Your Password?}}` do capture a name, fail to resolve, and come
back verbatim. Those render here too.

```text
{{if}} nested inside {{if}} - the legacy filter raises a TypeError here
  on line 1, column 10:

  1 | {{if a}}X{{if b}}Y{{/if}}{{/if}}
    |          ^

  hint: this renders here but not on the legacy filter; unset
        Options::$refuseLegacyIncompatible to allow it
```

**Ten shapes are refused that legacy does render.** Each is a place where legacy's regex does
something by accident that this parser will not build in:

| Shape | What legacy does |
|---|---|
| `{{var.a}}`, `{{var_a}}`, `{{var2 a}}`, `{{depend.a}}`, `{{VAR.a}}` | punctuation after a name is read as a parameter separator, which makes `{{var.a}}` a live variable read — case-insensitively, so `{{VAR.a}}` too |
| `{{if}}{{if}}{{/if}}`, its `{{depend}}` twin, `{{if}}{{depend}}x{{/if}}` | nesting collapses to `''` by accident of the lazy body match |
| `{{foo}}x{{/foo}}`, `{{var a}}Y{{/var}}` | the optional closing group swallows a body for a directive that has none |

`LegacyParityTest` asserts the two halves separately, because they are different claims:
`testEveryLegacyFatalIsRefused` allows no exceptions, and
`testExtraRefusalsAreOnlyTheDocumentedShapes` pins the ten so the list cannot grow without a
test failing.

Compatible means bug-for-bug; `lenient` and `strict` are the modes for wanting the
improvement. It is also what keeps a rollback to the legacy filter possible. To render the
refused constructs anyway and log which templates did it, opt out:

```php
$context = new Context($vars);
$engine  = TemplateEngine::withOptions(
    Options::compatible()->withRefuseLegacyIncompatible(false)
);
$engine->render($template, [], $context);

foreach ($context->incompatibilities() as $i) {
    $logger->info('no longer runnable on the legacy filter: ' . $i->describe());
}
```

### `{{for}}` is a deliberate divergence

Legacy's `ForDirective` does not render its body. It runs `preg_match_all` over the raw text,
resolves each match as a variable name and `str_replace`s the result in. So the body is never
escaped, a nested `{{if}}` is resolved as if it were a variable name, `|raw` becomes part of a
property name, an item that is not an array is skipped, and a non-iterable collection makes the
whole construct come back verbatim.

Reproducing that faithfully would mean not escaping loop variables, which is the class of
defect this package exists to remove. `{{for}}` is therefore recorded and required to render
safely, but is not held to rendering-equality with legacy.

## Strict mode

A template that cannot be parsed, names a directive that does not exist, or reads a variable
that is not in scope is a mistake worth surfacing while the template is still being edited:

```text
Unknown variable "custmer_name" in {{var custmer_name}}
  on line 1, column 6:

  1 | Dear {{var custmer_name}},
    |      ^
  2 | your order is ready.

  hint: did you mean {{var customer_name}}?
```

`{{if}}`, `{{depend}}` and `{{for}}` test truthiness, not existence, so a variable that does
not resolve at all is reported too. Silently taking the false branch is how a typo'd condition
goes unnoticed:

```text
Unknown variable "custmer" in {{if custmer}}
  hint: did you mean {{var customer}}?
```

A variable that *does* resolve is never an error; it is tested for truthiness. `{{for}}` also
reports a collection that resolves to something non-iterable.

The engine uses standard PHP truthiness. The legacy filter tests `resolve(...) == ''`, which
on PHP 8 makes `0`, `'0'` and `[]` all truthy — so `{{if qty}}` runs its true branch for a
zero quantity.

Only `0` and `0.0` are new. `0 == ''` was true on PHP 7 and became false in PHP 8, so those
two silently flipped on upgrade; `'0'` and `[]` never equalled `''` on either version and
have always been truthy here. Measured on 7.4.33 and 8.3.33. See `KnownDivergenceTest`.

### Nesting

The grammar nests to any depth, unlike the legacy filter, where each directive has its own
regex and so cannot contain *itself*. `{{if}}` inside `{{if}}` is a fatal `TypeError` in stock
Magento; only `{{depend}}` around `{{if}}` works, which is why core templates are written that
way and cap out at two levels.

Depth is bounded by policy rather than by accident, defaulting to 3 and settable per render:

```php
// Engine-wide default.
TemplateEngine::withOptions(Options::strict()->withMaxNestingDepth(5));

// Or for one render, since depth is a property of the content, not the installation.
$policy = RenderPolicy::restricted()->withMaxNestingDepth(4);
$engine->render($template, $variables, null, $policy);
```

```text
Nesting limit exceeded: {{if}} would be 4 levels deep, limit is 3
  on line 1, column 29:

  1 | {{depend a}}{{if b}}{{if c}}{{if d}}X{{/if}}{{/if}}{{/if}}{{/depend}}
    |                             ^

  hint: enclosing directives are {{depend}} > {{if}} > {{if}}; raise it with
        Options::withMaxNestingDepth() if intentional
```

An included `{{template}}` inherits the render's bound rather than the engine default, so a
nested template cannot buy itself more depth than its caller had. The bound applies in lenient
mode too: it limits input complexity rather than syntax tolerance, so deeply nested input is
refused rather than recovered. Includes are separately bounded against cycles, against depth,
and against total count, since five levels of fan-out is not five renders.

## The command line tool

```sh
vendor/bin/template-parser repl        # try directives interactively
vendor/bin/template-parser check       # will these templates render?
vendor/bin/template-parser diff        # do they render the same as today?
```

Run from inside a store it finds `app/etc/env.php`, boots Magento and wires every port it
can, so `{{block}}`, `{{media}}`, `{{config}}` and the rest resolve against the real
application. Run anywhere else it degrades to the built-in directives and still checks syntax.

```
$ template-parser repl
  mode    compatible - reproduces the legacy filter, refuses what it could not render
  store   connected
  17 directives wired

compatible> {{media url="wysiwyg/banner.jpg"}}
http://shop.example/media/wysiwyg/banner.jpg
compatible> {{media url="../../../app/etc/env.php"}}
(empty)
compatible> :mode strict
compatible> {{var custmer_name}}
Unknown variable "custmer_name" in {{var custmer_name}}
  hint: did you mean {{var customer_name}}?
```

`:help` lists the rest — `:set`, `:vars`, `:store`, `:stores`, `:directives`, `:mode`.

### Checking templates

`check` renders everything it can find and says what stops it, with advice rather than just a
diagnostic. `--source` picks where to look: `codebase` (files in app/code, vendor, app/design),
`email`, `cms`, `newsletter`, or `all`.

```sh
template-parser check --source=codebase --mode=strict --fail-on=error
template-parser check --source=all --format=json > findings.json
```

The exit code is what makes it useful in CI: non-zero at or above `--fail-on`, which defaults
to `error` so a first run over a decade of templates is not a wall of red.

### Diffing against the filter you run today

`diff` renders each template through **both** engines and reports the ones whose output
differs. This is the number that decides whether a migration is safe, and it needs a store —
the templates that matter are in a merchant's database, not the repository.

```sh
template-parser diff --source=email --store=1
template-parser diff --source=all --format=json --fail-on-divergence
```

`--store` sets the store context, so `{{trans}}` resolves in that store view's language and
`{{config}}` in its scope. It emulates rather than just switching the store id, because
translations and design follow the emulation and not the id.

### Inside n98-magerun2

The same commands, against the store magerun already booted:

```sh
ln -s /path/to/module-template-parser ~/.n98-magerun2/modules/template-parser
n98-magerun2 template-parser:check --source=email
```

The shipped `n98-magerun2.yaml` registers them. Nothing is reimplemented for magerun — the
subclasses only rename the commands into magerun's shared namespace and hand over its
ObjectManager instead of booting a second one.

### With bougie

```sh
bougie tool run cresset-tools/module-template-parser check --source=codebase
bougie run -- vendor/bin/n98-magerun2 template-parser:diff --source=email
```

## Speed

Faster than the legacy filter, which is nice to have on top of the strictness benefits.

`tools/benchmark.php` renders the same templates through both engines, each constructed once
outside the timing loop, since in Magento both are DI instances reused across a request. It
times **only** templates where the two produce byte-identical output — a speed number over
templates where one side is doing less work is not a speed number.

```
300 iterations per template, PHP 8.5, 48 of 48 templates byte-identical output

                                         legacy  compatible   ratio
Newsletter...unsub_success               2.03ms      1.88ms   0.93x   flat
ProductAlert...price_alert               2.86ms      2.66ms   0.93x   flat
Customer...password_new                 12.68ms      4.20ms   0.33x   nested
Customer...account_new_confirmation     12.27ms      3.65ms   0.30x   nested
synthetic: loop                        168.90ms    118.39ms   0.70x
synthetic: conditionals                117.66ms     74.66ms   0.63x
synthetic: variables                    88.81ms     55.91ms   0.63x
synthetic: plain text                    0.52ms      0.32ms   0.62x

TOTAL                                  984.94ms    547.74ms   0.56x
per render: legacy 68us, compatible 38us      peak memory 2.0 MB
```

The total is stable across runs at 0.55–0.56x. Individual flat templates are within noise of
each other, so their relative order shifts between runs; the flat-versus-nested gap does not.

About **1.8x faster**, and the reason is structural rather than clever: the legacy filter runs
a regex pass per directive processor over the whole string, and re-runs the entire engine over
substrings to handle nesting, so a template with a nested directive is scanned several times.
This lexes and parses once, then walks the tree. The spread is the tell — a flat template like
`unsub_success` is 0.93x, barely a win, while `password_new`, which nests, is 0.33x. The win
is proportional to how much re-scanning the old engine was doing.

59% of the remaining time is parsing, and that half is cacheable — an AST keyed by template
hash would remove it. The legacy filter's regex work is not cacheable in the same way, since
it interleaves matching with resolution.

Caveats worth stating. Both engines are timed on the same machine, same PHP, same run. One
corpus template is excluded because the legacy filter crashes on it. Neither side resolves
`{{template}}` includes — legacy needs Magento's config and this engine needs a
`TemplateLoader` port — so legacy's include processor is stubbed to leave the construct
alone, matching an unregistered directive here; without that the two fail differently and 35
of the 48 templates, including every Sales order and invoice email, drop out of the
comparison. Reproduce with `MAGENTO_ROOT=/path/to/magento php tools/benchmark.php`.

## Status

Pre-1.0, not yet used in production, and the API may change.

Merchant templates live in databases and cannot be audited ahead of time, and the parity
corpus bounds what is known rather than what is true — each round of adversarial fuzzing has
found a further class of divergence. Run shadow mode over your own content before switching
anything, and read [what compatible mode refuses](#what-it-refuses) first: it is a superset,
and ten shapes that render on the legacy filter are refused here by design.

## Testing

```sh
composer install
vendor/bin/phpunit
```

3475 tests. The parity corpus and the StyleSmuggler differential are the two that carry the
argument:

- `LegacyParityTest` replays the 2308 recorded cases, so the differential runs anywhere with
  no Magento installation, and drift in compatible mode shows up as a failing case rather than
  a surprise in production.
- `StyleSmugglerDifferentialTest` asserts both halves of the vulnerability: that the recording
  really is the vulnerable behaviour, and that this engine executes nothing given the identical
  input. Without the first half, "nothing executed" could mean the engine is sound or that the
  payload was malformed.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the corpus layout, how to re-record fixtures, and
what the rest of the suite covers.

## Repository layout

```text
src/Lexer/         source -> tokens (conservative: prose stays prose)
src/Ast/           TextNode, DirectiveNode, RootNode
src/Parser.php     tokens -> AST, lenient (recover) or strict (reject)
src/Evaluator.php  AST -> string, explicit handler table
src/Context.php    scope, policy and structured deferral
src/Magento/       adapters binding the ports to Magento
src/Console/       the CLI: commands, sources, and the Magento bridge
bin/               template-parser entrypoint
tools/             differential, benchmark and fixture-recording scripts
```

## License

OSL 3.0. See [LICENSE.txt](LICENSE.txt), and [COPYING.txt](COPYING.txt) for the notice.

The templates under `tests/fixtures/corpus/` are not this package's source. They are copied
from Magento Open Source, remain copyright Magento, Inc., and are licensed OSL 3.0 and AFL
3.0; see [LICENSE_AFL.txt](LICENSE_AFL.txt).
