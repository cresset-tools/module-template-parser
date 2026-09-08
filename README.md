# mageos/template-parser

An experimental **parse-to-AST** replacement for Magento's regex-based template directive
filter, built to be evaluated independently of the core.

## Why

`Magento\Framework\Filter\Template` finds directives with regular expressions and scans its
own *output* a second time. Because a regex cannot express nesting, nested rendering is done
by re-running the whole engine over substrings, which means a child render sometimes holds a
directive belonging to its parent. The only channel back up is the output text, so deferred
directives are marked in-band with a per-request signature — and an in-band marker sitting in
the same buffer as attacker-controlled data can be relocated. That is the StyleSmuggler class
of bug, and the signing mechanism it subverts was itself added in 2022 to fix an earlier bug
of the same shape.

This engine removes the conditions rather than tightening the check:

| Property | How |
|---|---|
| A value is never source | Parse once, evaluate once. There is no second scan, so content introduced by a variable can never be executed — at any depth, under any modifier. |
| Nesting comes from a grammar | `DirectiveSpec` declares which directives take a body. A closing tag closes only a block that is actually open. |
| Deferral is structured | `Context::defer()` records work; `Context::absorb()` hands a child's entries up one level. Nothing travels in the output stream, so nothing needs to be signed. |
| Unknown constructs are inert | No handler means the directive round-trips as text. Nothing is guessed at, and there is no reflection-based dispatch. |
| Parsing is lossless | Every AST reproduces its source byte for byte, which is what makes shadow-mode comparison possible. |

## Strict by default

A template that cannot be parsed, names a directive that does not exist, or reads a variable
that is not in scope is a mistake worth surfacing while it is still being edited:

```
Unknown variable "custmer_name" in {{var custmer_name}}
  on line 1, column 6:

  1 | Dear {{var custmer_name}},
    |      ^
  2 | your order is ready.

  hint: did you mean {{var customer_name}}?
```

`{{if}}`, `{{depend}}` and `{{for}}` test **truthiness**, not existence — so a variable that
does not resolve at all is reported too. Silently taking the false branch is exactly how a
typo'd condition goes unnoticed:

```
Unknown variable "custmer" in {{if custmer}}
  hint: did you mean {{var customer}}?
```

A variable that *does* resolve is never an error; it is simply tested for truthiness.
`{{for}}` additionally reports a collection that resolves to something non-iterable.

Note the engine uses standard PHP truthiness. The legacy filter tests `resolve(...) == ''`,
which on PHP 8 makes `0`, `'0'` and `[]` **truthy** — and that flipped silently on the PHP 7
to 8 upgrade, since `0 == ''` used to be true. See `KnownDivergenceTest`.

### Nesting

The grammar nests to any depth — unlike the legacy filter, where each directive has its own
regex and so cannot contain *itself* (`{{if}}` inside `{{if}}` is a fatal `TypeError` in stock
Magento; only `{{depend}}` around `{{if}}` works, which is why core templates are written
that way and cap out at two levels).

Depth is bounded by policy rather than by accident, defaulting to 3:

```
Nesting limit exceeded: {{if}} would be 4 levels deep, limit is 3
  on line 1, column 39:

  1 | {{depend a}}{{if b}}{{if c}}{{if d}}X{{/if}}{{/if}}{{/if}}{{/depend}}
    |                                       ^

  hint: enclosing directives are {{depend}} > {{if}} > {{if}}; raise it with
        Options::withMaxNestingDepth() if intentional
```

This bound applies in lenient mode too: it limits input complexity rather than syntax
tolerance, so deeply nested input is refused rather than recovered. `{{template}}` includes
are separately protected against cycles — a template that includes itself raises
`TemplateCycleError` instead of recursing until the process dies.

Strictness has three independent axes:

```php
new TemplateEngine();                                        // strict (default)
TemplateEngine::lenient();                                   // legacy-compatible
TemplateEngine::withOptions(Options::strict()->withVariables(false));
TemplateEngine::withOptions(Options::strict()->withMaxNestingDepth(5));
```

Use lenient for content already stored in a database, and for shadow-mode comparison; strict
for anything being authored or validated.

### Compatible mode

`TemplateEngine::compatible()` reproduces the legacy filter's observable rendering, so it can
be switched on without changing what customers see — while keeping the safety properties,
which are structural and apply in every mode.

Measured parity over the directive surface both engines implement (`tools/parity.php`):
**138/138 identical**, across a matrix of value shapes × template shapes.

Quirks it reproduces:

| Quirk | Legacy behaviour |
|---|---|
| truthiness | `resolve(...) == ''`, so on PHP 8 `0`, `'0'` and `[]` are **truthy** |
| partial paths | member access is only attempted on an array/DataObject parent, so a **scalar** parent yields itself (`{{var store.frontend_name}}` renders the store) |
| missing keys | an array parent with a missing key yields nothing, *not* the parent |
| arrays | cast to the literal string `Array` |
| no variables | directives pass through verbatim (the template-validation path) |

#### Nesting the legacy filter cannot do

Verified against the unpatched filter, legacy manages **two levels with differing names** —
`{{depend}}` around `{{if}}`, or the reverse. Same-name nesting at any depth, and three
levels, both raise a `TypeError` and the mail never sends.

Compatible mode **refuses everything legacy cannot render**, so its capability matches
exactly. Verified against the real filter, that is four conditions:

| condition | example | why legacy dies |
|---|---|---|
| same-name nesting | `{{if}}` in `{{if}}` | lazy body hands on an unclosed inner directive |
| three levels | `{{depend}}>{{if}}>{{depend}}` | same |
| unclosed block | `a{{if a}}b` | the per-directive re-match finds nothing, passes null on |
| construct not starting with a letter | `{{100}}`, `{{ var x }}`, `{{}}` | no name captured, `ProcessorPool::get(null)` |

The last one is narrower than it looks. `CONSTRUCTION_PATTERN` is case-**insensitive**, so
`{{Password}}` and `{{Forgot Your Password?}}` do capture a name, fail to resolve, and come
back verbatim — those render here too. Only a digit, space, slash, underscore or punctuation
directly after `{{` produces the fatal.

`LegacyParityTest::testRefusalSetMatchesLegacyFatalSetExactly` asserts the set of refusals
equals the set of recorded legacy fatals — no more, no fewer. Refusing extra constructs would
be as much a regression as rendering ones legacy cannot.

```
Nesting limit exceeded is not the error here — this one is:

  {{if}} nested inside {{if}} - the legacy filter raises a TypeError here
    on line 2, column 12:
    ...
    hint: this renders here but not on the legacy filter; unset
          Options::$refuseLegacyIncompatible to allow it
```

Compatible means bug-for-bug. An engine that renders what the old one crashes on is a better
engine, not a compatible one — and `lenient` and `strict` are the modes for wanting that.
Keeping the capability identical also means a rollback to the legacy filter stays possible.

To render them anyway and be told which templates did it — the improvement, plus the
migration signal — opt out:

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

Quirks it deliberately does **not** reproduce:

- the security behaviour — a value is still never re-parsed as source, in any mode;
- the fatals — same-name nesting and empty directive names (`{{100}}`) render instead of
  raising, since no template can depend on crashing;
- reflection dispatch of arbitrary filter methods;
- `{{var x|modifier}}` rendering empty. That is a defect in `Framework\Filter\Template`
  (`varDirective` hands `VarDirective` a legacy-shaped construction, so the expression
  resolved is `" x|raw"`). `Email\Model\Template\Filter` overrides `varDirective` and
  handles modifiers correctly — and that is the filter templates actually render through.

## Using it as a Magento module

The package is a `magento2-module` with `registration.php` and `etc/`. **Installing it
changes no rendering behaviour** — `etc/di.xml` declares no preference for
`Magento\Framework\Filter\Template`.

### Directive surface

The whole stock surface is implemented. Directives needing nothing from the host are built
in; the rest go through narrow ports, so the engine itself has no Magento dependency and
stays unit-testable.

| Directive | Port | Magento implementation | Guard |
|---|---|---|---|
| `var` `if` `depend` `for` `else` | — | built in | — |
| `inlinecss` | — | built in | structured deferral, never emitted as text |
| `trans` | `Translator` | `PhraseTranslator` | |
| `block` | `BlockRenderer` | `LayoutBlockRenderer` | type checked **before** instantiation, resolving DI preferences and virtual types; `output=` is an allowlist |
| `widget` | `WidgetRenderer` | `TypeCheckedWidgetRenderer` | same, against `Widget\Block\BlockInterface`; optional type allowlist |
| `template` | `TemplateLoader` | `ConfigTemplateLoader` | config-path prefix allowlist; include cycles refused |
| `layout` | `LayoutRenderer` | `AllowlistedLayoutRenderer` | handle allowlist **required**, area restricted to frontend/adminhtml |
| `config` | `ConfigReader` | `AllowlistedConfigReader` | Magento's `Variables::getAvailableVars()` allowlist, failing **closed** |
| `customvar` | `CustomVariableReader` | `VariableCustomVariableReader` | identifier-shaped codes only |
| `store` `media` `view` `protocol` | `UrlBuilder` | `StoreUrlBuilder` | `PathGuard` — no traversal, scheme, absolute or protocol-relative path |
| `css` | `StylesheetLoader` | `AssetStylesheetLoader` | `PathGuard` |

### Per-render capability policy

Capability belongs to the template, not the application. A stock transactional email and a
merchant-edited CMS block reach the same filter and deserve different trust — which a
DI-time allowlist cannot express, because it is fixed for the whole install.

**The default is restrictive.** `{{block}}`, `{{widget}}` and `{{layout}}` — the directives
that turn template text into a PHP class being loaded and constructed — are refused unless
granted, even when the host has wired their ports. The safe set is enumerated rather than
derived, so a directive added later defaults to *denied*.

```php
// Grant one capability, narrowed to specific classes.
$policy = RenderPolicy::restricted()
    ->alsoAllowing(['block'])
    ->withAllowedBlocks([\Magento\Sales\Block\Order\Email\Items::class]);

// Cut the surface down further: substitution and conditionals, no reach into the host.
$policy = RenderPolicy::allowing(['var', 'if', 'depend']);

// Or opt out entirely - the legacy filter's posture.
$policy = RenderPolicy::unrestricted();

$context = new Context($variables, $policy);
$html = $engine->render($template, [], $context);

foreach ($context->violations() as $v) {
    $logger->warning($v->describe());   // policy refused block "..." (line 4, column 12)
}
```

The allowlist is checked **before** the port, so a refused class is never constructed — the
same discipline as the type check, and for the same reason. `{{widget}}` shares the block
allowlist, since a widget is a block by another name.

The policy is consulted only once a handler exists, so it removes a capability the host
granted and never changes the output for a directive nobody wired up — which would otherwise
have silently broken compatible mode's parity.

A violation renders nothing and is recorded, rather than throwing: a policy violation should
not take down an order email, but it must not pass unnoticed either. For template validation
or CI, `Options::withFailOnPolicyViolation(true)` makes it fatal.

A nested `{{template}}` inherits the policy, so an include cannot widen it.

Two things are deliberate here.

**A capability not granted is not available.** A directive whose port is absent stays
unregistered, so it is reported in strict mode and rendered verbatim otherwise. The host
grants capabilities one at a time rather than inheriting the whole surface.

**Guards run in the handler, before the port.** `PathGuard` and the identifier checks are
applied by the directive handlers, not left to each implementation, so a host cannot forget
one. `FullDirectiveSurfaceTest` asserts the port is *never reached* for rejected input —
refusing after the fact is the mistake that made `BlockFactory` exploitable.

Legacy has no such guards: `mediaDirective` is literally
`getBaseUrl(MEDIA) . $params['url']`, and `protocolDirective` is
`$protocol . '://' . $params['url']`.

`Magento\ShadowComparator` runs the new engine alongside the legacy filter and logs
divergence. It always returns the **legacy** result, so enabling it changes nothing a
customer sees — it turns an unauditable compatibility question into measured data. Adopt the
engine by declaring your own preference once shadow mode is quiet.

## Layout

```
src/Lexer/      source -> tokens (conservative: prose stays prose)
src/Ast/        TextNode, DirectiveNode, RootNode
src/Parser.php  tokens -> AST, LENIENT (recover) or STRICT (reject)
src/Evaluator.php  AST -> string, explicit handler table
src/Context.php    scope + structured deferral
tools/differential.php   renders a corpus through both engines and reports divergence
```

## Testing

```
docker run --rm -v "$PWD":/m php:8.3-cli sh -c \
  'php -r "copy(\"https://phar.phpunit.de/phpunit-12.phar\",\"/tmp/phpunit.phar\");"; \
   cd /m && php /tmp/phpunit.phar'
```

### Differential corpus

`tools/record-legacy.php` runs the **real Magento filter** over a 591-case corpus and records
what it produced into `tests/fixtures/legacy/cases.json`. `LegacyParityTest` replays those
recordings, so the differential runs anywhere — no Magento installation needed — and drift in
compatible mode shows up as a failing case rather than a surprise in production.

The corpus is 20 value shapes x 26 construct shapes, plus a no-variables pass and the 45 real
harvested templates. **64 of the 591 cases crash the stock filter** — same-name nesting, empty
directive names, prose containing braces — and those are asserted to render here instead.

`ParitySensitivityTest` is the canary: it deliberately mis-configures the engine and asserts
the same corpus then *fails*. A suite of green assertions means nothing if the corpus cannot
tell a correct engine from a broken one, so each mutation must produce divergences:

| engine | divergences |
|---|---|
| compatible (control) | 0 of 483 |
| lenient (legacy quirks off) | 119 |
| strict (default) | 219 |

### The StyleSmuggler case, as a paired differential

`tools/record-stylesmuggler.php` runs the payload through the **real unpatched Magento
filter** and records the outcome; `StyleSmugglerDifferentialTest` asserts both halves:

1. the recording really is the vulnerable behaviour — legacy mints a per-request signature,
   that signature ends up bracketing the attacker's `{{block}}`, and the email render
   executes `Magento\Email\Block\Adminhtml\Template\Preview`;
2. this engine, given the identical two-stage flow (address formatter, then email render
   taking its output as a variable), executes nothing — in every mode.

The first half matters as much as the second. Without it, "nothing executed" could mean the
engine is sound *or* that the payload was malformed.

**Record against a pristine checkout.** These fixtures capture the filter as it is; recording
against a tree with a fix applied silently turns the differential into a comparison of two
fixed engines.

`MalformedTemplateTest` is the negative corpus: 15 deliberately broken templates asserted to
raise a *specific* error in strict mode and to render without raising in compatible mode, plus
10 hostile inputs asserted inert.

`tests/fixtures/corpus/` holds 45 real templates harvested from the Magento/Mage-OS tree,
including `.html` files whose `{{` sequences are **not** directives (translation strings, JS
templates). The corpus suite asserts the parser never throws, never loses content, and never
executes anything that was not written in the template.

`KnownDivergenceTest` pins the deliberate behavioural differences from the legacy filter.

## Status

A test implementation. It does not implement the directives that need Magento internals
(`block`, `template`, `layout`, `widget`, `store`, `css`, …) — the host registers those via
`Evaluator::register()`. `tools/differential.php` exists precisely because the templates that
matter live in merchant databases and cannot be audited ahead of time: the only honest way to
size a migration is to run both engines over real content and measure where they differ.
