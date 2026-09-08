<?php
/**
 * Renders every corpus template through BOTH engines and reports divergence.
 *
 * This is the shadow-mode tool: you cannot audit the templates sitting in merchant
 * databases ahead of time, so the only honest way to size a migration is to run both
 * engines over real content and measure where they differ.
 *
 * Usage: php tools/differential.php   (expects the Magento tree mounted at /repo)
 */
declare(strict_types=1);

require __DIR__ . '/harness.php';
$base = MROOT . '/lib/internal/Magento/Framework/Filter';
require MROOT . '/lib/internal/Magento/Framework/Math/Random.php';
foreach (['/DirectiveProcessorInterface.php','/VariableResolverInterface.php','/Template/FilteringDepthMeter.php',
 '/Template/SignatureProvider.php','/Template/Tokenizer/AbstractTokenizer.php','/Template/Tokenizer/Parameter.php',
 '/Template/Tokenizer/Variable.php','/VariableResolver/StrictResolver.php','/DirectiveProcessor/Filter/FilterApplier.php',
 '/DirectiveProcessor/Filter/FilterPool.php','/DirectiveProcessor/VarDirective.php','/DirectiveProcessor/IfDirective.php',
 '/DirectiveProcessor/DependDirective.php','/DirectiveProcessor/SimpleDirective.php','/DirectiveProcessor/LegacyDirective.php',
 '/DirectiveProcessor/TemplateDirective.php','/SimpleDirective/ProcessorPool.php','/Template.php'] as $f) { require $base . $f; }
require PKGROOT . '/tests/bootstrap.php';

use Magento\Framework\Stdlib\StringUtils;
use Magento\Framework\Math\Random;
use Magento\Framework\Filter\Template as LegacyTemplate;
use Magento\Framework\Filter\Template\{FilteringDepthMeter, SignatureProvider};
use Magento\Framework\Filter\VariableResolver\StrictResolver;
use Magento\Framework\Filter\DirectiveProcessor\{IfDirective, DependDirective, TemplateDirective, SimpleDirective, LegacyDirective, VarDirective};
use Magento\Framework\Filter\DirectiveProcessor\Filter\{FilterApplier, FilterPool};
use Magento\Framework\Filter\SimpleDirective\ProcessorPool;
use Magento\Framework\Filter\Template\Tokenizer\{VariableFactory, ParameterFactory};
use Cresset\TemplateParser\TemplateEngine;

function legacyFilter(array $vars): LegacyTemplate {
    $r = new StrictResolver(new VariableFactory());
    \Magento\Framework\App\ObjectManager::$registry[VarDirective::class] =
        new VarDirective($r, new FilterApplier(new FilterPool()));
    $simple = new SimpleDirective(new ProcessorPool(), new ParameterFactory(), $r, new FilterApplier(new FilterPool()));
    $f = new LegacyTemplate(new StringUtils(), [], [
        'depend' => new DependDirective($r), 'if' => new IfDirective($r),
        'template' => new TemplateDirective($r, new ParameterFactory()), 'legacy' => new LegacyDirective($simple),
    ], $r, new SignatureProvider(new Random()), new FilteringDepthMeter());
    $f->setVariables($vars);
    return $f;
}

$VARS = [
    'customer_name' => 'Jan Jansen', 'store_name' => 'Demo', 'store' => 'Demo',
    'name' => 'Jan', 'x' => 'X', 'y' => 'Y', 'items' => ['a', 'b'],
    'street1' => '1 Test St', 'city' => 'Andorra la Vella', 'postcode' => 'AD500',
];

// Mode under test: `compatible` aims for bug-for-bug rendering parity with legacy.
$mode = getenv('MODE') ?: 'compatible';
$engine = $mode === 'compatible' ? TemplateEngine::compatible() : TemplateEngine::lenient();
printf("mode             : %s\n", $mode);
// Match the legacy surface under comparison: base Framework\Filter\Template implements
// var/if/depend/for only. Unregister the rest so both sides leave them verbatim.
foreach (['trans', 'inlinecss', 'else'] as $name) {
    $engine->evaluator()->unregister($name);
}
$files = glob(PKGROOT . '/tests/fixtures/corpus/*') ?: [];
$same = $diff = $legacyThrew = $newThrew = 0;
$divergences = [];

foreach ($files as $file) {
    $src = (string)file_get_contents($file);
    try { $a = legacyFilter($VARS)->filter($src); } catch (\Throwable $e) { $legacyThrew++; continue; }
    try { $b = $engine->render($src, $VARS); } catch (\Throwable $e) { $newThrew++; continue; }
    if ($a === $b) { $same++; continue; }
    $diff++;
    $divergences[basename($file)] = [$a, $b];
}

printf("corpus templates : %d\n", count($files));
printf("identical output : %d\n", $same);
printf("divergent        : %d\n", $diff);
printf("legacy threw     : %d\n", $legacyThrew);
printf("new engine threw : %d\n", $newThrew);
printf("engine surface   : %s\n", implode(', ', $engine->evaluator()->registered()));

// Classify the divergences so they are actionable rather than a wall of diff.
$classes = [];
foreach ($divergences as $name => [$a, $b]) {
    $class = 'other';
    if (trim($a) === trim($b)) {
        $class = 'whitespace only';
    } elseif (preg_match('/\{\{(template|layout|css|store|block|widget)/', $a . $b, $m)) {
        $class = 'unimplemented directive: ' . $m[1];
    } elseif (str_replace('{{else}}', '', $a) === str_replace('{{else}}', '', $b)) {
        $class = 'else handling';
    }
    $classes[$class][] = $name;
}
// Show the unclassified ones in full - those are the candidates for a real engine difference.
echo "\n--- unclassified divergences (first 2) ---\n";
$shown = 0;
foreach ($divergences as $name => [$a, $b]) {
    if (preg_match('/\{\{(template|layout|css|store|block|widget)/', $a . $b)) { continue; }
    if (trim($a) === trim($b)) { continue; }
    if ($shown++ >= 2) { break; }
    echo "FILE: $name\n";
    $la = preg_split('/\R/', $a); $lb = preg_split('/\R/', $b);
    for ($i = 0; $i < max(count($la), count($lb)); $i++) {
        if (($la[$i] ?? null) !== ($lb[$i] ?? null)) {
            echo "  legacy: ", var_export($la[$i] ?? null, true), "\n";
            echo "  new   : ", var_export($lb[$i] ?? null, true), "\n";
        }
    }
}

echo "\ndivergence classes:\n";
foreach ($classes as $class => $names) {
    printf("  %-38s %d  e.g. %s\n", $class, count($names), $names[0]);
}
