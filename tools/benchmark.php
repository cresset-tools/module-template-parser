<?php
/**
 * Times this engine against the legacy filter over the same templates.
 *
 * Both sides are constructed ONCE, outside the timing loop: in Magento each is a DI
 * instance reused across a request, so construction is not what a merchant pays per email.
 * What is measured is the per-render cost, which is the honest comparison - this engine
 * parses on every render exactly as the legacy filter runs its regexes on every render.
 *
 * The legacy side reproduces Email\Model\Template\Filter's varDirective, because that is the
 * filter emails and CMS content actually render through - the base Framework\Filter\Template
 * has a defect where `{{var x|modifier}}` renders empty, which would make the two sides do
 * different work and the comparison meaningless. The directive surface is matched on both
 * sides for the same reason; templates using a directive only one side implements are
 * excluded and counted.
 *
 * Usage: MAGENTO_ROOT=/path/to/magento php tools/benchmark.php [iterations]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || realpath($_SERVER['argv'][0] ?? '') !== __FILE__) {
    return;
}
define('CRESSET_TEMPLATE_PARSER_TOOL', true);
// The same ceiling the test bootstrap sets, for the same reason: bougie launches
// PHP unlimited, and a tool that runs the whole corpus is where a runaway would hide.
if (ini_get('memory_limit') === '-1') {
    ini_set('memory_limit', '2G');
}
require __DIR__ . '/harness.php';
$base = MROOT . '/lib/internal/Magento/Framework/Filter';
require MROOT . '/lib/internal/Magento/Framework/Math/Random.php';
require MROOT . '/lib/internal/Magento/Framework/DataObject.php';
require MROOT . '/lib/internal/Magento/Framework/Escaper.php';
foreach (['/DirectiveProcessorInterface.php','/VariableResolverInterface.php','/Template/FilteringDepthMeter.php',
 '/Template/SignatureProvider.php','/Template/Tokenizer/AbstractTokenizer.php','/Template/Tokenizer/Parameter.php',
 '/Template/Tokenizer/Variable.php','/VariableResolver/StrictResolver.php','/DirectiveProcessor/Filter/FilterApplier.php',
 '/DirectiveProcessor/Filter/FilterPool.php','/DirectiveProcessor/VarDirective.php','/DirectiveProcessor/IfDirective.php',
 '/DirectiveProcessor/ForDirective.php','/DirectiveProcessor/DependDirective.php','/DirectiveProcessor/SimpleDirective.php',
 '/DirectiveProcessor/LegacyDirective.php','/DirectiveProcessor/TemplateDirective.php','/SimpleDirective/ProcessorPool.php',
 '/Template.php'] as $f) { require $base . $f; }
require PKGROOT . '/tests/bootstrap.php';

use Magento\Framework\Filter\Template as LegacyTemplate;
use Magento\Framework\Filter\Template\{FilteringDepthMeter, SignatureProvider};
use Magento\Framework\Filter\Template\Tokenizer\{VariableFactory, ParameterFactory};
use Magento\Framework\Filter\VariableResolver\StrictResolver;
use Magento\Framework\Filter\DirectiveProcessor\{IfDirective, DependDirective, ForDirective,
    TemplateDirective, SimpleDirective, LegacyDirective, VarDirective};
use Magento\Framework\Filter\DirectiveProcessor\Filter\{FilterApplier, FilterPool};
use Magento\Framework\Filter\SimpleDirective\ProcessorPool;
use Magento\Framework\Math\Random;
use Magento\Framework\Stdlib\StringUtils;
use Cresset\TemplateParser\{Options, Parser, TemplateEngine};
use Magento\Framework\Escaper;

/**
 * Email\Model\Template\Filter's varDirective, copied as the recorder copies it.
 *
 * Uniquely named: Magento's DI scanner includes every tools/ file that declares a class, so
 * two tools sharing a class name would collide.
 */
require __DIR__ . '/stubs/BenchmarkEmailLike.php.stub';

$iterations = max(1, (int)($argv[1] ?? 200));

$VARS = [
    'customer_name' => 'Jan Jansen', 'store_name' => 'Demo', 'store' => 'Demo',
    'name' => 'Jan', 'a' => 1, 'x' => 'X', 'y' => 'Y',
    'store_phone' => '123', 'store_hours' => '9-5',
    'logo_width' => '180', 'logo_height' => '50',
    'street1' => '1 Test St', 'city' => 'Andorra la Vella', 'postcode' => 'AD500',
    'items' => [['n' => 'a'], ['n' => 'b'], ['n' => 'c']],
];

$resolver = new StrictResolver(new VariableFactory());
\Magento\Framework\App\ObjectManager::$registry[VarDirective::class] =
    new VarDirective($resolver, new FilterApplier(new FilterPool()));
\Magento\Framework\App\ObjectManager::$registry[ForDirective::class] = new ForDirective($resolver);
// A {{template}} include cannot be resolved by either side here: legacy needs Magento's
// config, and this engine needs a TemplateLoader port. Left to their own devices they fail
// DIFFERENTLY - legacy emits "{Error in template processing}", this engine leaves the
// directive verbatim - which excluded 35 of 49 templates from the comparison, including
// every Sales order, invoice and shipment email. Neither side implementing it is the like
// for like arrangement, so legacy's processor is stubbed to hand the construct back
// unchanged, matching an unregistered directive here.
\Magento\Framework\App\ObjectManager::$registry[TemplateDirective::class] =
    new class ($resolver, new ParameterFactory()) extends TemplateDirective {
        public function process(array $construction, LegacyTemplate $filter, array $templateVariables): string
        {
            return $construction[0];
        }
    };
$simple = new SimpleDirective(new ProcessorPool(), new ParameterFactory(), $resolver, new FilterApplier(new FilterPool()));
// No 'template' processor: this engine has no TemplateLoader port wired here, so including
// it would time legacy resolving an include against this engine leaving it verbatim.
$legacy = new BenchmarkEmailLikeLegacy(new StringUtils(), [], [
    'depend' => new DependDirective($resolver), 'if' => new IfDirective($resolver),
    'for' => new ForDirective($resolver),
    'legacy' => new LegacyDirective($simple),
], $resolver, $sig = new SignatureProvider(new Random()), new FilteringDepthMeter(),
    \Harness\neutralizerFor($sig));
$legacy->setVariables($VARS);

$engines = [
    'compatible' => TemplateEngine::compatible(),
    'lenient'    => TemplateEngine::lenient(),
];
foreach ($engines as $engine) {
    foreach (['trans', 'inlinecss'] as $name) {
        $engine->evaluator()->unregister($name);
    }
}
$parser = new Parser(options: Options::lenient());

/** @return array{0:float,1:mixed} seconds for $iterations runs, and the last result */
function time_it(callable $fn, int $iterations): array
{
    $fn();                                   // warm up: autoload, JIT, caches
    $out = null;
    $started = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $out = $fn();
    }
    return [(hrtime(true) - $started) / 1e9, $out];
}

$corpus = [];
foreach (glob(PKGROOT . '/tests/fixtures/corpus/*.html') ?: [] as $file) {
    $corpus[basename($file, '.html')] = (string)file_get_contents($file);
}
$corpus['synthetic: plain text'] = str_repeat("Dear customer, your order has shipped.\n", 20);
$corpus['synthetic: variables'] = str_repeat('{{var customer_name}} <{{var store_name}}> ', 40);
$corpus['synthetic: conditionals'] = str_repeat('{{if a}}{{var name}}{{else}}none{{/if}}', 40);
$corpus['synthetic: loop'] = str_repeat('{{for i in items}}{{var i.n}}{{/for}}', 40);

printf("iterations per template: %d\nPHP %s\n\n", $iterations, PHP_VERSION);
printf("%-52s %10s %10s %10s %8s\n", 'template', 'legacy', 'compatible', 'lenient', 'ratio');
printf("%s\n", str_repeat('-', 96));

$totals = ['legacy' => 0.0, 'compatible' => 0.0, 'lenient' => 0.0];
$rows = [];
$skipped = [];
$divergent = [];

// Legacy emits notices for the same constructs it records as quirks; they are not part of
// what is being timed, and printing thousands of them would dominate the run.
set_error_handler(static fn (): bool => true);

foreach ($corpus as $label => $source) {
    // Some real templates crash the legacy filter outright - that is the documented
    // behaviour this package exists to fix, but a template only one side can render is not
    // a comparison, so it is excluded and counted.
    try {
        $legacyOut = $legacy->filter($source);
        $ourOut = $engines['compatible']->render($source, $VARS);
        foreach ($engines as $engine) {
            $engine->render($source, $VARS);
        }
    } catch (\Throwable $e) {
        $skipped['one side raises'][] = $label;
        continue;
    }

    // Only time templates both engines render the SAME way. A speed number over templates
    // where one side is doing less work is not a speed number. Most exclusions here are
    // host-port directives - {{template}}, {{css}}, {{trans}} - which a standalone benchmark
    // has nothing to wire them to.
    if ($legacyOut !== $ourOut) {
        $skipped['output differs'][] = $label;
        $divergent[$label] = [$legacyOut, $ourOut];
        continue;
    }

    [$legacyTime, $legacyOut] = time_it(static fn () => $legacy->filter($source), $iterations);
    $row = ['legacy' => $legacyTime];
    foreach ($engines as $mode => $engine) {
        [$t, $out] = time_it(static fn () => $engine->render($source, $VARS), $iterations);
        $row[$mode] = $t;
        if ($mode === 'compatible') {
            $row['identical'] = $out === $legacyOut;
        }
    }
    foreach ($totals as $k => $_) {
        $totals[$k] += $row[$k];
    }
    $rows[$label] = $row;
}

restore_error_handler();

// Only the extremes and the synthetics are worth printing per template.
uasort($rows, static fn ($a, $b) => ($b['compatible'] / max($b['legacy'], 1e-9)) <=> ($a['compatible'] / max($a['legacy'], 1e-9)));
$show = array_slice($rows, 0, 3, true) + array_slice($rows, -3, 3, true)
    + array_filter($rows, static fn ($_, $k) => str_starts_with($k, 'synthetic'), ARRAY_FILTER_USE_BOTH);
foreach ($show as $label => $r) {
    printf("%-52s %9.2fms %9.2fms %9.2fms %7.2fx\n", substr($label, 0, 52),
        $r['legacy'] * 1000, $r['compatible'] * 1000, $r['lenient'] * 1000,
        $r['compatible'] / max($r['legacy'], 1e-9));
}

printf("\n%-52s %9.2fms %9.2fms %9.2fms %7.2fx\n", sprintf('TOTAL (%d templates)', count($rows)),
    $totals['legacy'] * 1000, $totals['compatible'] * 1000, $totals['lenient'] * 1000,
    $totals['compatible'] / max($totals['legacy'], 1e-9));

$perRender = $totals['compatible'] / (max(count($rows), 1) * $iterations) * 1e6;
$legacyPer = $totals['legacy'] / (max(count($rows), 1) * $iterations) * 1e6;
$identical = count(array_filter($rows, static fn ($r) => $r['identical']));
printf("\nsame output as legacy: %d of %d timed templates\n", $identical, count($rows));
printf("per render: legacy %.1fus, compatible %.1fus (%+.1fus)\n",
    $legacyPer, $perRender, $perRender - $legacyPer);

// How much of our cost is parsing? That half is cacheable; legacy's regex work is not.
$parseTotal = 0.0;
$evalTotal = 0.0;
foreach (array_intersect_key($corpus, $rows) as $source) {
    [$t] = time_it(static fn () => $parser->parse($source), $iterations);
    $parseTotal += $t;
}
$evalTotal = $totals['lenient'] - $parseTotal;
printf("of which parsing: %.2fms of %.2fms lenient (%.0f%%), evaluation %.2fms\n",
    $parseTotal * 1000, $totals['lenient'] * 1000,
    100 * $parseTotal / max($totals['lenient'], 1e-9), max($evalTotal, 0) * 1000);
printf("peak memory: %.1f MB\n", memory_get_peak_usage(true) / 1048576);

printf("\nexcluded from timing:\n");
foreach ($skipped as $reason => $labels) {
    printf("  %-18s %d\n", $reason, count($labels));
}

/*
 * A floor, because "excluded" is how this harness reports a template that RAISED, and a
 * raising template looks the same whether the legacy filter genuinely crashes on it or this
 * harness is broken.
 *
 * It was broken, for a month, and said nothing: a stub referenced an unqualified `Escaper`
 * that resolved to no class, so every template using |escape raised and was quietly counted
 * as excluded. 27 templates were timed instead of 48, and the README carried the resulting
 * numbers as though they measured the corpus.
 */
const EXPECTED_TIMED_TEMPLATES = 48;

if (count($rows) < EXPECTED_TIMED_TEMPLATES) {
    fwrite(STDERR, sprintf(
        "\nonly %d of an expected %d templates were timed - the harness is broken, not the corpus.\n"
        . "Run with LIST_DIFF=1 to see which, and check the stubs load the classes they name.\n",
        count($rows),
        EXPECTED_TIMED_TEMPLATES
    ));
    exit(1);
}

if (getenv('LIST_DIFF')) {
    printf("\n%-54s %s\n", 'template', 'first differing construct');
    printf("%s\n", str_repeat('-', 96));
    $byCause = [];
    foreach ($divergent as $label => [$l, $c]) {
        $limit = min(strlen($l), strlen($c));
        for ($i = 0; $i < $limit && $l[$i] === $c[$i]; $i++) {}
        // Walk back to the directive that produced the divergence.
        $before = substr($l, 0, $i);
        $open = strrpos($before, '{{');
        $cause = $open === false ? '(before any directive)' : substr($l, $open, 30);
        if ($open !== false && preg_match('/\{\{\s*([a-zA-Z_]+)/', substr($l, $open), $m)) {
            $cause = '{{' . $m[1] . '}}';
        }
        // Legacy's own error strings are the clearer signal when present.
        foreach (['{Error in template processing}' => '{{template}} unresolved',
                  '&#123;Error in template processing}' => '{{template}} unresolved'] as $needle => $name) {
            if (str_contains(substr($l, max(0, $i - 40), 80), $needle)) { $cause = $name; }
        }
        $byCause[$cause][] = $label;
        printf("%-54s %s\n", substr($label, 0, 54), $cause);
    }
    printf("\nby cause:\n");
    foreach ($byCause as $cause => $ls) { printf("  %-28s %d\n", $cause, count($ls)); }
}

if (getenv('SHOW_DIFF')) {
    foreach ($rows as $label => $r) {
        if ($r['identical']) { continue; }
        $l = $legacy->filter($corpus[$label]);
        $c = $engines['compatible']->render($corpus[$label], $VARS);
        for ($i = 0; $i < min(strlen($l), strlen($c)); $i++) { if ($l[$i] !== $c[$i]) break; }
        printf("  %-46s legacy=%s\n%-48s ours  =%s\n", substr($label,0,46),
            var_export(substr($l, max(0,$i-10), 50), true), '', var_export(substr($c, max(0,$i-10), 50), true));
    }
}


