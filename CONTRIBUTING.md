# Contributing

## Running the tests

```sh
composer install
vendor/bin/phpunit
```

Without a local PHP:

```sh
docker run --rm -v "$PWD":/m php:8.3-cli sh -c \
  'php -r "copy(\"https://phar.phpunit.de/phpunit-12.phar\",\"/tmp/phpunit.phar\");"; \
   cd /m && php /tmp/phpunit.phar'
```

4524 tests. 307 are skipped by design: they are the shapes compatible mode deliberately
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
such as translation strings and JS templates. 3176 cases in total, 323 of which crash the
stock filter. 364 carry a second expectation for the filter as it was before the
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

The recorder asserts `FakeDataObject` still matches Magento's `DataObject` on 19 probes before
it writes anything, because recording against one object and replaying against another
measures nothing.

The `Parity drift` workflow does exactly this against Mage-OS `main` on a weekly schedule, so
fixtures going stale surfaces as a failing job rather than as an unnoticed assumption. It
re-records `cases.json` only, and asserts `stylesmuggler.json` was left alone.

## What the suite covers

| Test | Covers |
|---|---|
| `LegacyParityTest` | the recorded corpus, in both directions: every legacy fatal is refused, and the extra refusals are exactly the ten documented shapes |
| `ParitySensitivityTest` | the canary. It mis-configures the engine and asserts the same corpus then *fails* |
| `StyleSmugglerDifferentialTest` | the vulnerability, as a paired differential |
| `MalformedTemplateTest` | 15 broken templates asserted to raise a specific error in strict mode; 9 of them are refused in compatible mode and 6 render, and 10 hostile inputs are asserted inert |
| `CorpusTest` | the parser never throws, never loses content, and never executes anything absent from the template |
| `GuardTripwireTest`, `MagentoGuardTest`, `SecurityRegressionTest` | one test per security guard, each written against a mutation that removed it |
| `MagentoIntegrationTest` | the adoption path: the plugin, the adapter's policy, and shadow mode |
| `TemplateIncludeTest` | `{{template}}` semantics: scope, parameters, nesting, cycles, inheritance and output |
| `MagentoUrlAdapterTest` | the adapters behind `{{store}}`, `{{media}}`, `{{view}}`, `{{protocol}}`, `{{css}}` and `{{customvar}}` - the directives that carry merchant-authored content rather than shipped templates |
| `KnownDivergenceTest` | the deliberate behavioural differences from the legacy filter |

### The sensitivity canary

Green assertions mean nothing if the corpus cannot tell a correct engine from a broken one, so
each deliberate mis-configuration must produce divergences over the 2199 rendering-comparable
cases:

| Engine | Divergences |
|---|---|
| compatible (control) | 0 |
| lenient (legacy quirks off) | 686 |
| strict (default) | 1038 |

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

## Reporting a security issue

Do not open a public issue. See [SECURITY.md](SECURITY.md).
