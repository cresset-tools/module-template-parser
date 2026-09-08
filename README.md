# mageos/template-parser

A parser for Magento's `{{...}}` template directives. It builds an AST and evaluates it once,
instead of matching regexes against its own output the way `Magento\Framework\Filter\Template`
does.

```php
$engine = new TemplateEngine();

echo $engine->render('Dear {{var name}},', ['name' => 'Ada']);
// Dear Ada,
```

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

To adopt it, run `Magento\ShadowComparator` first. It renders through both engines and logs
where they differ, and always returns the *legacy* result, so enabling it changes nothing a
customer sees. Declare your own preference once shadow mode is quiet.

### Directive surface

The full stock surface is implemented. Directives needing nothing from the host are built in;
the rest go through narrow ports, which is what keeps the engine free of a Magento dependency
and unit-testable.

| Directive | Port | Magento implementation | Guard |
|---|---|---|---|
| `var`, `if`, `depend`, `for`, `else` | — | built in | — |
| `inlinecss` | — | built in | structured deferral, never emitted as text |
| `trans` | `Translator` | `PhraseTranslator` | |
| `block` | `BlockRenderer` | `LayoutBlockRenderer` | type checked before instantiation, resolving DI preferences and virtual types; `output=` is an allowlist |
| `widget` | `WidgetRenderer` | `TypeCheckedWidgetRenderer` | same, against `Widget\Block\BlockInterface`; optional type allowlist |
| `template` | `TemplateLoader` | `ConfigTemplateLoader` | config-path allowlist; include cycles, depth and total count all bounded |
| `layout` | `LayoutRenderer` | `AllowlistedLayoutRenderer` | handle allowlist required, area restricted to frontend/adminhtml |
| `config` | `ConfigReader` | `AllowlistedConfigReader` | Magento's `Variables::getAvailableVars()` allowlist, failing closed |
| `customvar` | `CustomVariableReader` | `VariableCustomVariableReader` | identifier-shaped codes only |
| `store`, `media`, `view`, `protocol` | `UrlBuilder` | `StoreUrlBuilder` | `PathGuard`: no traversal, scheme, absolute or protocol-relative path |
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

Two further properties are deliberate. A directive whose port is absent stays unregistered, so
the host grants capabilities one at a time rather than inheriting the whole surface. And
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
circular. 1657 cases are recorded, 253 of them constructs the legacy filter cannot render at
all. Wherever legacy renders, compatible mode produces byte-identical output.

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
| unknown modifiers | skipped, so `{{var x|typo}}` renders raw |
| unknown escape types | `escape:none` returns the value unescaped |

Unknown modifiers and unknown escape types are reproduced only in compatible mode. Everywhere
else they fail closed.

Quirks it does not reproduce:

- the security behaviour, which is structural: a value is never re-parsed as source, in any mode;
- reflection dispatch of arbitrary filter methods;
- `{{var x|modifier}}` rendering empty. That is a defect in `Framework\Filter\Template`, whose
  `varDirective` hands `VarDirective` a legacy-shaped construction so the expression resolved
  is `" x|raw"`. `Email\Model\Template\Filter` overrides `varDirective` and handles modifiers
  correctly, and that is the filter templates actually render through.

### What it refuses

The relationship is a superset, and the direction matters.

**Every construct the legacy filter cannot render is refused.** A construct the old filter died
on is one nobody has ever seen the output of, so rendering it would not be compatibility. Eight
conditions, each verified against the real filter:

| Condition | Example | Why legacy dies |
|---|---|---|
| same-name nesting | `{{if}}` in `{{if}}` | lazy body hands on an unclosed inner directive |
| three levels | `{{depend}}` > `{{if}}` > `{{depend}}` | same |
| unclosed block | `a{{if a}}b` | the per-directive re-match finds nothing, passes null on |
| name not starting with a letter | `{{100}}`, `{{ var x }}`, `{{}}` | no name captured, `ProcessorPool::get(null)` |
| name split by punctuation | `{{if_a}}` | the name is a greedy `[a-z]{0,10}`, so this is `if` with the parameter `_a` |
| padded closing tag | `{{/if }}` | `CONSTRUCTION_IF_PATTERN` allows the space, the closing backreference does not |
| modifier arguments | `{{var a\|nl2br:x}}` | passed through to `nl2br()`, a `TypeError` on `$use_xhtml` |
| member call on an array | `{{var a.getB()}}` where `a` is an array | `->getData()` on an array |

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

**Nine shapes are refused that legacy does render.** Each is a place where legacy's regex does
something by accident that this parser will not build in:

| Shape | What legacy does |
|---|---|
| `{{var.a}}`, `{{var_a}}`, `{{var2 a}}`, `{{depend.a}}` | punctuation after a name is read as a parameter separator, which makes `{{var.a}}` a live variable read |
| `{{if}}{{if}}{{/if}}`, its `{{depend}}` twin, `{{if}}{{depend}}x{{/if}}` | nesting collapses to `''` by accident of the lazy body match |
| `{{foo}}x{{/foo}}`, `{{var a}}Y{{/var}}` | the optional closing group swallows a body for a directive that has none |

`LegacyParityTest` asserts the two halves separately, because they are different claims:
`testEveryLegacyFatalIsRefused` allows no exceptions, and
`testExtraRefusalsAreOnlyTheDocumentedShapes` pins the nine so the list cannot grow without a
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
on PHP 8 makes `0`, `'0'` and `[]` truthy. That flipped silently on the PHP 7 to 8 upgrade,
since `0 == ''` used to be true. See `KnownDivergenceTest`.

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

## Status

Pre-1.0, not yet used in production, and the API may change.

Merchant templates live in databases and cannot be audited ahead of time. Run
`tools/differential.php` over your own content before switching anything.

## Testing

```sh
composer install
vendor/bin/phpunit
```

2544 tests. The parity corpus and the StyleSmuggler differential are the two that carry the
argument:

- `LegacyParityTest` replays the 1657 recorded cases, so the differential runs anywhere with
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
tools/             differential and fixture-recording scripts
```

## License

OSL 3.0. See [LICENSE.txt](LICENSE.txt), and [COPYING.txt](COPYING.txt) for the notice.

The templates under `tests/fixtures/corpus/` are not this package's source. They are copied
from Magento Open Source, remain copyright Magento, Inc., and are licensed OSL 3.0 and AFL
3.0; see [LICENSE_AFL.txt](LICENSE_AFL.txt).
