# Contributing

## Running the tests

```sh
composer install
vendor/bin/phpunit
```

Without a local PHP:

```sh
docker run --rm -v "$PWD":/m -w /m composer:2 sh -c 'composer install && vendor/bin/phpunit'
```

The `composer` image rather than `php:8.3-cli`: the suite needs the autoloader and Symfony
Console, so a bare PHP with a downloaded PHPUnit phar errors out on the console tests, and
`php:8.3-cli`'s default 128M `memory_limit` is below what the suite peaks at, which
`GuardTripwireTest` is what pushes it to.

12841 tests. 431 are skipped by design: they are the shapes compatible mode deliberately
refuses, listed in `LegacyParityTest::DELIBERATE_OVER_REFUSALS`.

### Mutation testing, without taking the machine down

Every guard here is checked by removing it and watching the suite fail. Two rules, both
learned the hard way:

- **Bound the mutation.** One that made `{{var}}` re-parse its own output recursed without a
  limit. Reproducing a defect does not require reproducing it unboundedly - the bounded
  version, one extra pass, is what legacy actually does and it fails the suite just as loudly.
- **Kill the run before restoring the file.** A tool timeout backgrounds a command rather than
  stopping it, so `cp` -ing the original back leaves a process running against a build that no
  longer exists. That one ran unattended for two and a half hours, reached 48 GB and invoked
  the kernel OOM killer.

`tests/bootstrap.php` now clamps an unlimited `memory_limit` to 2 GB, and the tools under
`tools/` do the same, so the failure mode is a fatal with a stack trace. bougie launches PHP
with `-d memory_limit=-1`; a deliberate lower limit on the command line is left alone.

## The parity corpus

`tools/record-legacy.php` runs the real Magento filter over a generated corpus and records
what it produced into `tests/fixtures/legacy/cases.json`. `LegacyParityTest` replays those
recordings, so the differential runs anywhere with no Magento installation.

The corpus is a matrix of value shapes against construct shapes, plus a no-variables pass and
the 45 real templates in `tests/fixtures/corpus/`. Those templates are harvested from the
Magento/Mage-OS tree and include `.html` files whose `{{` sequences are not directives at all,
such as translation strings and JS templates. 4788 cases in total, 804 of which crash the
stock filter. 751 carry a second expectation for the filter as it was before the
September 2026 StyleSmuggler hardening, which compatible mode can target with
`Options::withOutputNeutralizer(false)`.

### Re-recording

Point `MAGENTO_ROOT` at a Magento or Mage-OS checkout. The recorder loads about twenty
framework files by path, so a plain source checkout is enough - no composer install, no
database, no services:

```sh
git clone --depth 1 https://github.com/mage-os/mageos-magento2 /tmp/mageos
MAGENTO_ROOT=/tmp/mageos php tools/record-legacy.php
```

`tools/harness.php` supplies the framework leaves those classes touch. Everything under test
is Magento's own code.

**Record against a pristine checkout.** These fixtures capture the filter as it is. Recording
against a tree with a fix applied turns the differential into a comparison of two fixed
engines, and it does so silently.

Two mistakes have been made here before, both of which made the measurement circular and
neither of which failed any test at the time:

- Recording against a patched `Template.php`, so the StyleSmuggler fixture came back clean.
- Reimplementing `Escaper` in the recorder instead of calling Magento's. The reimplementation
  repeated the engine's own escaping bug, so the fixtures certified the bug as legacy truth.
  The recorder now calls the real `Escaper` and passes raw values under `strict_types`; do not
  add casts to `modifierEscape`.

The recorder asserts `FakeDataObject` still matches Magento's `DataObject` on 21 probes before
it writes anything, because recording against one object and replaying against another
measures nothing.

The `Parity drift` workflow does exactly this against Mage-OS `main` on a weekly schedule, so
fixtures going stale surfaces as a failing job rather than as an unnoticed assumption. It
re-records `cases.json` only, and asserts `stylesmuggler.json` was left alone.

### The store recording

`tests/fixtures/legacy/store-ports.json` covers the twelve directives that need a host, which
`record-legacy.php` cannot reach. It is recorded against a store with **sample data and real
orders**, because that is what `{{layout}}` needs to render anything: the five handles the
stock sales emails use produce between 289 bytes and 2.3KB of item table each, and a store
without orders agrees with the filter on all of them vacuously.

Each case is recorded in a process of its own. That costs about 45 seconds for the lot, and it
is not overhead to trim away. Magento's services are shared and stateful: a hostile case can
leave one in a mode that changes every render after it, and `{{store _type="../.."}}` does
exactly that — the filter hands the type to the URL model, which keeps it, and every later
legacy `{{store}}` in the process then fails with "Invalid base url type". Twenty-six cases
were recorded against a poisoned model before the isolation went in, each with a `legacy` value
that is not what that template does, and four more carried a theme an earlier case in the same
process had left behind. A run is reproducible byte for byte; if two runs differ,
something is leaking.

Re-record it from inside a store:

```sh
cd /path/to/store && php vendor/cresset-tools/module-template-parser/tools/record-store-ports.php
```

It writes next to the package it is run from, so copy the result back if the store holds a
copy rather than a symlink. Read the diff: a tape that changed is the engine having changed
its mind about what reaches the host, and that is either the point of your change or a bug.

`legacy` and `agreed` are assertions, not context:
`StorePortParityTest::testWhatAgreedWithTheFilterStillAgrees` replays each agreeing case from
its tape and holds the result to the recorded `legacy` byte for byte, offline. They were
context once, when every case shared one process and an isolated `{{css}}` or `{{view}}` could
resolve a different theme than the same directive inside a real template —
`AbstractTemplate::getProcessedTemplate()` applies its own design config and cancels it again.
A process per case and a recorded `design_params` closed that. `template-parser diff` renders
whole templates end to end and remains the end-to-end measure; this file is where the guards
are.

Anything below the port boundary — `StoreUrlBuilder`, `AssetStylesheetLoader` and the rest of
`src/Magento/` — is invisible to a tape by construction, and needs its own unit test.
Verified: deleting the country-name substitution or the custom-variable truthiness quirk passes
`StorePortParityTest` and fails the suite.

One number moves when you re-record: `StorePortParityTest::testTheAgreementSetHasNotShrunk`
pins how many cases agree with the filter. It is meant to be edited deliberately — read the
fixture diff, satisfy yourself the change is one you intended, then update it. The count
exists precisely so that a guard which starts refusing something the filter renders cannot
erode the agreeing set one case at a time in silence.

## What the suite covers

| Test | Covers |
|---|---|
| `LegacyParityTest` | the recorded corpus, in both directions: every legacy fatal is refused, and the extra refusals are exactly the seventeen documented shapes |
| `ParitySensitivityTest` | the canary. It mis-configures the engine and asserts the same corpus then *fails* |
| `StyleSmugglerDifferentialTest` | the vulnerability, as a paired differential |
| `MalformedTemplateTest` | 15 broken templates asserted to raise a specific error in strict mode; 9 of them are refused in compatible mode and 6 render, and 10 hostile inputs are asserted inert |
| `CorpusTest` | the parser never throws, never loses content, and never executes anything absent from the template |
| `GuardTripwireTest`, `MagentoGuardTest`, `SecurityRegressionTest` | one test per security guard, each written against a mutation that removed it |
| `MagentoIntegrationTest` | the adoption path: the plugin, the adapter's policy, and shadow mode |
| `TemplateIncludeTest` | `{{template}}` semantics: scope, parameters, nesting, cycles, inheritance and output |
| `MagentoUrlAdapterTest` | the adapters behind `{{store}}`, `{{media}}`, `{{view}}`, `{{protocol}}`, `{{css}}` and `{{customvar}}` - the host directives that build a URL, load a stylesheet or read a merchant variable, none of which instantiates a class |
| `KnownDivergenceTest` | the deliberate behavioural differences from the legacy filter |

### The sensitivity canary

Green assertions mean nothing if the corpus cannot tell a correct engine from a broken one, so
each deliberate mis-configuration must produce divergences over the 2710 rendering-comparable
cases:

| Engine | Divergences |
|---|---|
| compatible (control) | 0 |
| lenient (legacy quirks off) | 1103 |
| standard truthiness (quirks off, refusal left on) | 1103 |
| strict (default) | 1519 |

Only the control's 0 is asserted exactly. The others are asserted as floors — 40, 20 and 20 —
because the point is that the corpus still *notices*, and a figure pinned to the byte would
fail on every case added to the corpus. The two 1103s are the same cases, not merely the same
count: `Options::compatible()` is `lenient()` plus the quirks, the legacy-incompatible refusal
and the output neutralizer, so turning the quirks back off leaves only the latter two between
them — and neither changes an outcome once the deliberate over-refusals are filtered out.
`withVariables(false)` changes nothing either, compatible mode having never had strict
variables on. The row stays because it is a separate switch, and either one going quiet is the
signal this test exists for.

## Benchmarking

```sh
MAGENTO_ROOT=/tmp/mageos php tools/benchmark.php [iterations]
```

Times both engines over the same templates. It compares output first and times only the
templates that match byte for byte, because a template one side renders differently is one
side doing different work. The legacy side reproduces `Email\Model\Template\Filter`'s
`varDirective` rather than using the base `Framework\Filter\Template`, whose
`{{var x|modifier}}` defect would otherwise show up as free speed.

## Guards and tests

Every security guard needs a test that fails when the guard is deleted. A mutation pass over
this package once found 55 of 133 single-point deletions leaving the suite green, including
every check in three of the `src/Magento` adapters, which no test instantiated at all.

If you add a guard, add the tripwire with it, and verify the tripwire by breaking the guard and
watching it go red. `GuardTripwireTest` names the specific deletion each test exists to catch.

Two assertion habits to avoid, both of which produced tests that could not fail:

- `assertIsString()` on a method declared `: string`, and `assertNotNull()` on a non-nullable
  return. 250 tests once passed against a renderer gutted to `return ''`.
- Fixture values containing nothing the code under test would change, such as asserting
  escaping with a value that has no special characters in it.

## The changelog

[CHANGELOG.md](CHANGELOG.md) records what changed and why it mattered, not what was touched.
A line that only names a file is not an entry; a line that says which template rendered wrong,
or what a guard let through, is.

Not every commit needs one. A change a reader of the package would notice — a behaviour, a
guard, a public API, a divergence from the legacy filter — does. Refactors and test-only
changes do not.

The corpus figures in the README and in this file are checked against the corpus itself by
`LegacyParityTest::testTheProseMatchesTheCorpus`, so adding cases fails the suite until both
are updated with them. The changelog's own count is written as "at the time of writing" and is
not checked, a changelog being historical by nature.

## Reporting a security issue

Do not open a public issue. See [SECURITY.md](SECURITY.md).
