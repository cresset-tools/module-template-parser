# The template language

Magento's email, newsletter and CMS templates are written in a small directive language that
has never been written down. This is the reference: what each directive does, what it
accepts, and what it does with input it does not like — which, in this language, is usually
"renders nothing and says nothing".

**Every example here was rendered through the real filter to produce it.**
`tools/document-language.php` runs each one through `Magento\Framework\Filter\Template` (as
`Email\Model\Template\Filter` configures it) and writes the blocks below from the output. It
also renders each one through this engine's compatible mode and refuses to write the file if
they disagree, so an example cannot be written down here without also being true.

Read the blocks as `template` · `variables` · `→ what the filter renders`. `(nothing)` is the
empty string. A `#` comment says what the row is for, and where strict mode does something
different — usually raising instead of rendering nothing — it says that too.

The engine's own posture, its modes and its refusals are in [the README](../README.md); this
file is about the language, not about this implementation of it.

---

## `{{var}}`

Prints a variable. The parameter is a **path**, not an expression.

Output is HTML-escaped unless a modifier says otherwise. That default is the single most
important thing in this language, and the modifier section below is about the ways to lose it
by accident.

Two rules surprise people. A variable that does not exist renders as nothing, silently — no
warning, no marker, so a typo survives for years. And when the filter has been given **no
variables at all**, the four variable-reading directives — `var`, `if`, `depend` and `for` —
are passed through verbatim instead: that is the path Magento uses to validate a template
without rendering it, and it means an empty variable set is not the same as a missing
variable. Only those four. `{{trans}}` and `{{template}}` have no such short-circuit and run
as normal.

<!-- generated:var -->
```
{{var a}}           a="Ada"        → Ada
{{var a}}           a="<b>&</b>"   → &lt;b&gt;&amp;&lt;/b&gt;  # escaped by default
{{var a}}           a=null         → (nothing)                # a variable set to null renders as nothing
{{var nope}}        a=1            → (nothing)                # strict raises UnknownVariableError; an unknown variable renders as nothing, silently
{{var a}}           -              → {{var a}}                # strict raises UnknownVariableError; with NO variables at all the directive is passed through verbatim
{{trans "Hello"}}   -              → Hello                    # but only the variable-reading directives do that - {{trans}} still runs
{{var a}}           a=["x","y"]    → Array                    # strict: (nothing); an array is cast to the string "Array"
{{var a}}           a=0            → 0
{{var a}}           a=true         → 1
{{var a}}           a=false        → (nothing)                # false casts to the empty string
```
<!-- /generated -->

## Variable paths

A path walks arrays and objects with `.`.

Objects are reached through `getData()` and nothing else. `{{var c.getName()}}` does not call
`getName()` — it is rewritten to `getData('name')`, and its arguments are parsed and thrown
away. That is deliberate on Magento's part: the resolver's own comment reads *"Strict mode
should not call getter methods except DataObject's getData"*. The one exception is
`getUrl` on a template model, which is described under [ports](#directives-that-need-the-host).

The rewriting rule is `getFooBar()` → `foo_bar`, where a run of digits counts as its own
segment — so `getAddress1()` reads `address_1`, not `address1`.

Whitespace anywhere in a path is skipped, a leading dot is nothing at all, and the whole
expression is `rawurldecode()`d before it is split — so `%2E` is a path separator.

The last rule is the one that produces mysterious output: member access is only *attempted*
when the parent is an array or a DataObject. On a scalar parent the access never happens and
the cursor never advances, so **the parent itself is the result**. `{{var store.frontend_name}}`
renders the store when `store` is a string.

<!-- generated:paths -->
```
{{var a.b}}                    a={"b":"deep"}         → deep      # array key
{{var a.b}}                    a={"x":1}              → (nothing)  # strict raises UnknownVariableError; a missing key yields nothing, not the parent
{{var c.name}}                 c=DataObject           → Ada       # an object is read through getData()
{{var c.getName()}}            c=DataObject           → Ada       # a getter maps to getData("name") - it is never called
{{var c.getAddress1()}}        c=DataObject           → Main St   # a run of digits is its own segment, so this reads address_1
{{var c.getName("ignored")}}   c=DataObject           → Ada       # arguments are parsed and dropped
{{var a . b}}                  a={"b":"deep"}         → deep      # whitespace anywhere in a path is skipped
{{var .a}}                     a="Ada"                → Ada       # a leading dot is no action at all
{{var a..b}}                   a={"b":"deep"}         → deep
{{var a%2Eb}}                  a={"b":"deep"}         → deep      # the path is rawurldecode()d before it is split
{{var a.b}}                    a="scalar"             → scalar    # strict raises UnknownVariableError; member access is not attempted on a scalar, so the parent itself is the result
{{var a.b.c}}                  a={"b":{"c":"deep"}}   → deep      # paths nest freely
{{var a b}}                    ab="AB"                → AB        # whitespace ANYWHERE in a name is skipped, not just at the edges
{{var a()}}                    a="Ada"                → Ada       # a call at the HEAD of a path is the variable itself - the type is ignored there
{{var c.get()}}                c=DataObject           → Array     # strict: (nothing); .get() maps to getData("") and hands back the whole data bag
{{var a.getB(}}                a={"b":1}              → !Error    # strict raises UnknownVariableError; an UNCLOSED call is still a call - on an array parent legacy raises, so this is refused
```
<!-- /generated -->

## Modifiers

`{{var x|modifier}}`, applied left to right. The modifier list **replaces** the default
`escape` rather than adding to it, which is where unescaped output comes from:

- `{{var x|nl2br}}` is unescaped, because `nl2br` is now the whole list.
- `{{var x|typo}}` is unescaped, because an unknown modifier is skipped — and the default was
  already gone.
- `{{var x|}}` is unescaped, for the same reason.
- `{{var x|escape:none}}` is unescaped, because an unrecognised escape *type* falls through.
- **`{{var x|escape }}` is unescaped**, because the modifier is looked up as `escape ` — with
  the space — and that is not a modifier the filter knows. A space on either side of the name
  does it, and the template looks entirely correct.

To get escaping and something else, ask for both: `{{var x|escape|nl2br}}`.

The implemented modifiers are `escape` and `nl2br`; the escape types are `html` (the
default), `htmlentities` and `url`. **`raw` is not one of them** — no `raw` filter exists in
Magento at all. It works by being an unknown modifier that gets skipped, which is the same
fail-open path as `|typo` above: it does not strip the escaping so much as replace it with
nothing. This engine reproduces all four unescaped cases in
compatible mode and fails closed everywhere else, which is what the `strict:` comments show.

<!-- generated:modifiers -->
```
{{var a|raw}}                   a="<b>"         → <b>                         # the default escape is replaced, not added to
{{var a|escape}}                a="<b>"         → &lt;b&gt;
{{var a|nl2br}}                 a="<b>\nx"      → <b><br />\nx                # strict: &lt;b&gt;<br />\nx; nl2br REPLACES escape, so this is unescaped on the filter
{{var a|escape|nl2br}}          a="<b>\nx"      → &lt;b&gt;<br />\nx          # ask for both to get both
{{var a|typo}}                  a="<b>"         → <b>                         # strict: &lt;b&gt;; an unknown modifier is skipped - and takes the escaping with it
{{var a|escape:html}}           a="<b>&\"x\""   → &lt;b&gt;&amp;&quot;x&quot;
{{var a|escape:htmlentities}}   a="<b>&\"x\""   → &lt;b&gt;&amp;&quot;x&quot;
{{var a|escape:url}}            a="a b/c"       → a%20b%2Fc
{{var a|escape:none}}           a="<b>"         → <b>                         # strict: &lt;b&gt;; an unrecognised escape type disables escaping
{{var a|escape }}               a="<b>"         → <b>                         # strict: &lt;b&gt;; WHITESPACE in a modifier name makes it unrecognised too - so this does not escape, though it looks like it does
{{var a| escape}}               a="<b>"         → <b>                         # strict: &lt;b&gt;; either side of the name
{{var a|}}                      a="<b>"         → <b>                         # strict: &lt;b&gt;; an empty modifier is skipped, and so is the default with it
```
<!-- /generated -->

## `{{if}}` and `{{else}}`

**The parameter is a variable path and nothing else.** There is no expression language: no
literals, no comparisons, no negation, no function calls. `IfDirective::process` hands the
entire parameter to the variable resolver, so an operator is just more characters in a
variable name, and a name nobody set resolves to nothing and takes the false branch.

`{{if 1}}` is therefore **false**. So is `{{if a == 1}}`, whatever `a` holds, because the
variable being looked up is called `a == 1`. Both render silently, which is how a condition
can be wrong for years without anyone noticing. Strict mode raises instead, which is usually
how someone finds out.

Truthiness is `resolve(...) == ''` — a loose comparison against the empty string. On PHP 8
that makes `0`, `'0'` and `[]` all **true**. On PHP 7 it did not: `0 == ''` was true there, so
a template written before the upgrade may have changed meaning during it.

`{{else}}` divides an `{{if}}`. It is not a directive in its own right, and `{{depend}}` does
not accept one.

<!-- generated:if -->
```
{{if a}}Y{{/if}}                 a=1           → Y
{{if a}}Y{{else}}N{{/if}}        -             → {{if a}}Y{{else}}N{{/if}}  # strict raises UnknownVariableError; with NO variables at all every directive passes through - the template-validation path
{{if a}}Y{{else}}N{{/if}}        a=""          → N
{{if a}}Y{{else}}N{{/if}}        a=0           → Y                         # strict: N; the test is `== ''`, and on PHP 8 that is false for 0
{{if a}}Y{{else}}N{{/if}}        a="0"         → Y                         # strict: N; the string zero is truthy too
{{if a}}Y{{else}}N{{/if}}        a=[]          → Y                         # strict: N; and so is the empty array
{{if a}}Y{{else}}N{{/if}}        a=null        → N
{{if a.b}}Y{{else}}N{{/if}}      a={"b":"x"}   → Y                         # a path works
{{if 1}}Y{{else}}N{{/if}}        a=1           → N                         # strict raises UnknownVariableError; a LITERAL does not: `1` is read as the name of a variable
{{if a == 1}}Y{{else}}N{{/if}}   a=1           → N                         # strict raises UnknownVariableError; nor does a comparison - the whole string is one variable name
{{if !a}}Y{{else}}N{{/if}}       a=0           → N                         # strict raises UnknownVariableError; nor negation
{{if "x"}}Y{{else}}N{{/if}}      a=1           → N                         # strict raises UnknownVariableError; nor a quoted literal
```
<!-- /generated -->

## `{{depend}}`

The same truthiness test as `{{if}}`, with no `{{else}}` branch. Use it to drop a block of
markup when a value is absent.

<!-- generated:depend -->
```
{{depend a}}Y{{/depend}}      a="x"   → Y
{{depend a}}Y{{/depend}}      a=""    → (nothing)  # same truthiness rule as {{if}}, but with no {{else}}
{{depend a}}Y{{/depend}}      a=0     → Y         # strict: (nothing); and the same PHP 8 truthiness
{{depend nope}}Y{{/depend}}   a=1     → (nothing)  # strict raises UnknownVariableError; a variable nobody set drops the block
```
<!-- /generated -->

## `{{for}}`

`{{for item in collection}}…{{/for}}`. The collection argument is a variable path, under the
same rule as `{{if}}`.

This directive is not what it looks like. **The body is never rendered.** `ForDirective`
scans the raw body text for `{{…}}` constructions, resolves each one *as a variable name*, and
string-replaces the result. So a nested `{{if i.b}}Y{{/if}}` inside a loop body does not take
a branch — the whole construction is read as the variable `i.b` and replaced with its value.

Three consequences worth knowing:

- **Nothing in the body is escaped.** The substitution is a raw `str_replace`.
- **An item that is not an array or a DataObject is skipped**, so looping a list of strings
  produces nothing at all.
- **A body containing no `{{…}}` at all produces nothing**, because the loop only appends text
  when the scan matches.

A `loop` variable is injected alongside the item, carrying `index` — counting from **zero**.

A collection that is not iterable makes the whole construction come back verbatim.

This engine renders the body properly instead, which is a deliberate divergence: reproducing
the scan faithfully would mean not escaping loop variables, and that is the class of defect
the package exists to remove. `loop.index` is provided, zero-based, so templates that print it
keep working.

<!-- generated:for -->
```
{{for i in a}}[{{var i.b}}]{{/for}}          a=[{"b":1},{"b":2}]   → [1][2]                            # the usual shape: a list of rows
{{for i in a}}[{{var i}}]{{/for}}            a=["x","y"]           → (nothing)                         # a list of SCALARS yields nothing - an item that is not an array is skipped
{{for i in a}}[{{var loop.index}}]{{/for}}   a=[{"b":1},{"b":2}]   → [0][1]                            # loop.index is injected, and counts from ZERO
{{for i in a}}{{if i.b}}Y{{/if}}{{/for}}     a=[{"b":1}]           → 1                                 # the body is not RENDERED - every {{...}} in it is resolved as a VARIABLE NAME, so this prints i.b rather than taking a branch
{{for i in a}}[{{var i.b}}]{{/for}}          a=[]                  → (nothing)
{{for i in a}}[{{var i}}]{{/for}}            a="notalist"          → {{for i in a}}[{{var i}}]{{/for}}  # strict raises TemplateTypeError; a non-iterable collection comes back verbatim
```
<!-- /generated -->

## `{{trans}}`

`{{trans "text" arg=$value}}` — translate the text, substitute `%arg` placeholders, escape the
result.

The text must be a **quoted string**, and its arguments must be separated from it by
whitespace. Anything else renders nothing at all rather than being treated as the text.

The body is split on its first `|` before any of that happens, to find the modifiers — so a
pipe inside the text truncates the string, leaves it unterminated, and the whole directive
renders nothing. `{{trans "a|b"}}` is empty.

Arguments follow the [parameter rules](#parameters) below, with one addition that catches
everyone: **an integer argument key stands for the next placeholder up.** Magento's
`Phrase\Renderer\Placeholder` adds one to an integer key, because `__('%1', $a)` numbers its
positional arguments from one while PHP numbers the array from zero — and a template's
arguments go through the same code. So `{{trans "%1" 1=$x}}` never fills `%1`, and
`{{trans "%2" 1=$x}}` is the one that works. Named arguments are unaffected.

The default modifier is `escape`, and it applies to the **whole result** — the translated text
as well as the substituted values. `|raw` turns it off.

<!-- generated:trans -->
```
{{trans "Hello"}}            -         → Hello
{{trans "Tom & Jerry"}}      -         → Tom &amp; Jerry  # the default modifier is escape, and it applies to the TEXT as well
{{trans "Hi %n" n=$a}}       a="Ada"   → Hi Ada          # a `$` makes an argument a variable
{{trans "Hi %n" n=a}}        a="Ada"   → Hi a            # without one it is a literal, even when a variable of that name exists
{{trans "Hi %n" n=$nope}}    -         → Hi              # strict raises UnknownVariableError; an argument that will not resolve renders as nothing
{{trans "Hi %1" 1=$a}}       a="Ada"   → Hi %1           # an INTEGER key stands for the next placeholder up, so %1 is never filled
{{trans "Hi %2" 1=$a}}       a="Ada"   → Hi Ada          # which makes this the one that works
{{trans "Hi %n" n=$a|raw}}   a="<b>"   → Hi <b>          # |raw turns the escaping off
{{trans "a|b"}}              -         → (nothing)       # the split on `|` happens first, so a pipe in the text truncates it and the whole directive renders nothing
{{trans Hello}}              -         → (nothing)       # the text has to be quoted
{{trans "Hi"x=1}}            -         → (nothing)       # and separated from its arguments by whitespace
```
<!-- /generated -->

## `{{template}}`

`{{template config_path="design/email/header_template"}}` includes another template. The value
is a **configuration path**; the template it names is looked up through that config, not by
filename.

The include is rendered as a child template with its own filter, not pasted into the parent.
Its scope is the directive's parameters merged over the parent's variables — and the merge is
`array_merge_recursive`, so a parameter whose name **collides** with an existing variable
produces an *array of both values* rather than overriding it. That array then casts to the
string `Array`, which is the usual way this is discovered.

A `config_path` that is **absent** renders the literal `{Error in template processing}`, and
so does a template with no include processor wired. A path that is present but resolves to no
template is the host's business, not the directive's — inside Magento that route ends in
`getTemplateType()` raising. The row below shows this file's own harness returning the literal
for an unknown path, which is the one example here that is not the filter's own behaviour.

<!-- generated:template -->
```
{{template config_path="greet"}}             a="Ada"   → Hi Ada                              # the include is rendered with the parent's variables
{{template config_path="greet" a="Bob"}}     a="Ada"   → Hi Array                            # a parameter COLLIDING with a parent variable becomes an array of both - legacy merges recursively
{{template config_path="greet" who="Bob"}}   a="Ada"   → Hi Bob                              # a parameter that does not collide is just a variable
{{template config_path="greet" who=$a}}      a="Ada"   → Hi Ada                              # a `$` parameter resolves before the include runs
{{template}}                                 -         → &#123;Error in template processing}  # a missing config_path renders this literal - the leading brace is encoded by the neutralizer
{{template config_path="nope"}}              -         → &#123;Error in template processing}  # and so does a path that resolves to no template
```
<!-- /generated -->

## Parameters

Every directive that takes `name=value` pairs shares one tokenizer, and its rules are
particular:

- The whole blob is `rawurldecode()`d **before** it is split, so an encoded `=` creates a
  parameter: `a%3D$x` is `a=$x`.
- A value starting with `$` is a **variable path**, resolved before the directive runs.
  Anything else is a literal — even when a variable of that name exists.
- A value may be quoted to contain spaces.
- A backslash escapes the next character — in **unquoted** values as well as quoted ones. So
  `a=1\ b=2` is *one* parameter whose value is `1\ b=2`: the escaped space does not end it,
  and `b` never becomes a parameter at all. The backslash is kept unless what follows is
  another backslash, so `a="x\"y"` holds a backslash and a quote.
- Whitespace immediately after `=` ends the value. `a= b=$x` is two parameters, not one.
- Whitespace here means `trim()`'s set, which **includes NUL and excludes form feed** — so
  `a=1\0b=2` is two parameters and `a=1\fb=2` is one.
- A **trailing** word with no `=` is dropped. One that comes before another parameter is not:
  the name accumulates across the whitespace, so `a b=1` is the single parameter `ab`.
- An empty key is the placeholder `%` itself, so `{{trans "50% off" =X}}` rewrites every `%`
  in the text.
- At the very end of a directive the tokenizer cannot step past the last character, so the
  `=` itself becomes the value: `{{trans "%s" s=}}` prints `=`.

None of this is guessable, which is why `ParameterParser` is a line-by-line port of
`Tokenizer\Parameter` rather than a scanner written to be reasonable. A reasonable one
disagreed with it five ways, and each disagreement handed a directive parameters the filter
never produced — `{{block class=Foo\ template=x.phtml}}` is one garbage class on the filter,
and was a class plus a live `template` here.

<!-- generated:parameters -->
```
{{trans "T %a" a="x y"}}      -       → T x y         # a quoted value may contain spaces
{{trans "T %a %b" a= b=$x}}   x="X"   → T  X          # whitespace after `=` ends the value - it does not swallow the next parameter
{{trans "T %a" a=}}           -       → T =           # at the very end of a directive the cursor cannot advance, so the `=` becomes the value
{{trans "T [%a]" a=1\ b=2}}   -       → T [1\ b=2]    # a backslash escapes the next character in an UNQUOTED value too, so the escaped space does not end it and `a` swallows the rest
{{trans "T [%a]" a="x\"y"}}   -       → T [x\&quot;y]  # and it keeps the backslash, unless what follows is another backslash
{{trans "T %a" a=$x b}}       x="X"   → T X           # a TRAILING word with no `=` is dropped
{{trans "T [%ab]" a b=1}}     -       → T [1]         # but one before another parameter is not - the name accumulates across the whitespace
{{trans "T 50% off" =X}}      -       → T 50X off     # an empty key is the placeholder `%` itself, so it rewrites every `%` in the text
{{trans "T %a" a%3D$x}}       x="X"   → T X           # the blob is rawurldecode()d first, so an encoded `=` makes a parameter
```
<!-- /generated -->

## Nesting

Directive names are matched case-insensitively, and the legacy pattern captures a name of up
to ten lowercase letters with an optional closing tag matched by backreference. That has one
sharp consequence: **a directive cannot contain another of the same name.** The inner closing
tag ends the outer construction, and what is left over is usually a fatal.

Different names nest, but not freely: `{{for}}` scans its body rather than rendering it, so
nothing nests *through* a `{{for}}` and a `{{for}}` whose collection is not a list of rows
comes back verbatim, taking whatever was inside it along. Three distinct names reach three
levels when the innermost is not a `{{for}}` — or when it is one with a collection it can
actually walk.

<!-- generated:nesting -->
```
{{if a}}{{depend a}}{{for i in a}}[{{var i.b}}]{{/for}}{{/depend}}{{/if}}   a=[{"b":"x"}]   → [x]                                                        # three distinct names nest, if > depend > for
{{depend a}}{{if a}}{{for i in a}}[{{var i.b}}]{{/for}}{{/if}}{{/depend}}   a=[{"b":"x"}]   → [x]                                                        # depend > if > for
{{for i in a}}{{if i.b}}[{{var i.b}}]{{/if}}{{/for}}                        a=[{"b":"x"}]   → x                                                          # but {{for}} OUTERMOST does not nest - its body is scanned, not rendered
{{if a}}{{depend a}}{{for i in a}}[{{var i.b}}]{{/for}}{{/depend}}{{/if}}   a=1             → &#123;&#123;for i in a}}[&#123;&#123;var i.b}}]&#123;&#...  # strict raises TemplateTypeError; and a non-iterable collection leaves the innermost {{for}} verbatim, so nothing nests through it
{{if a}}{{if a}}Y{{/if}}{{/if}}                                             a=1             → !TypeError                                                 # strict: Y; a directive cannot contain ITSELF - the inner close ends the outer
{{depend a}}{{if a}}Y{{/if}}{{/depend}}                                     a=1             → Y
```
<!-- /generated -->

## The output neutralizer

Since the September 2026 StyleSmuggler hardening, Mage-OS encodes `{{` in resolved directive
output, so a value can never be re-parsed as source by a later pass. A single `{` at either
edge of the output is encoded too, because concatenation with a neighbour could otherwise form
an opener. One in the middle is left alone.

This changes observable rendering, so it is part of the language now. Trees from before the
hardening are still in the field; this engine can target either.

It is **not aware of the template type.** `Template::filter()` calls the neutralizer on every
resolved directive, and `isPlainTemplateMode()` gates only `{{css}}` and `{{inlinecss}}` — so a
plain-text email whose variable holds a `{{` delivers the literal characters `&#123;&#123;` to
the recipient, where an HTML one at least renders them back as braces. Worth knowing before
assuming an entity is a safe way to write a brace: in a plain-text template it is not.

<!-- generated:neutralizer -->
```
{{var a}}   a="{{block class=Evil}}"   → &#123;&#123;block class=Evil}}  # strict: {{block class=Evil}}; a resolved value carrying `{{` comes back encoded
{{var a}}   a="{x"                     → &#123;x                        # strict: {x; a single brace at an edge is encoded too
{{var a}}   a="a{b"                    → a{b                            # one in the middle is left alone
```
<!-- /generated -->

## Directives that need the host

The rest of the surface — `block`, `widget`, `layout`, `config`, `customvar`, `store`,
`media`, `view`, `protocol`, `css`, `inlinecss` — reaches into the application, so it cannot
be rendered by the harness that generates this file and has no measured block here. What each
one accepts, which port serves it and what guards it is in the
[README's directive surface table](../README.md#directive-surface).

Two behaviours in that group belong to the language rather than to the port:

- **`{{var this.getUrl($store, 'route', [_nosid:1])}}`** is the one method call the resolver
  really invokes, and only when the receiver is a template model. Its arguments are parsed —
  quoted strings, bare words, numbers as floats, and `[key:value]` arrays that nest — and any
  `$name` among them is resolved first. The store argument is overwritten with the scope's own
  `store` before the call, so a template cannot aim it elsewhere. This is where every "log
  into your account" link in every stock email comes from.
- **`{{inlinecss file=…}}`** does not render. It collects a stylesheet, and the filter rewrites
  the finished document through Emogrifier once the render is over.

## How this file stays true

`tools/document-language.php` regenerates every block above from a live filter:

```sh
git clone --depth 1 https://github.com/mage-os/mageos-magento2 /tmp/mageos
MAGENTO_ROOT=/tmp/mageos php tools/document-language.php
```

It refuses to write when this engine's compatible mode disagrees with the filter on a
documented example, and equally when an example marked as a deliberate divergence has quietly
stopped diverging. Both failures have already caught wrong claims in this file — including one
that sent me to fix the engine instead.

Regenerate after changing the case list, and commit the result; CI re-runs it and fails on
drift, exactly as it does for the parity fixtures.
