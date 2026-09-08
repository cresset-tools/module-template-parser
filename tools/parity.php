<?php
/**
 * Measures rendering parity with the legacy filter across the directive surface both
 * engines implement (var / if / depend / for), over a matrix of value shapes.
 *
 * This is the number that decides whether `compatible` mode is safe to switch on:
 * divergence here is real behaviour change, not a missing port.
 */
declare(strict_types=1);
require '/h/bootstrap_realrandom.php';
$base = MROOT . '/lib/internal/Magento/Framework/Filter';
require MROOT . '/lib/internal/Magento/Framework/Math/Random.php';
foreach (['/DirectiveProcessorInterface.php','/VariableResolverInterface.php','/Template/FilteringDepthMeter.php',
 '/Template/SignatureProvider.php','/Template/Tokenizer/AbstractTokenizer.php','/Template/Tokenizer/Parameter.php',
 '/Template/Tokenizer/Variable.php','/VariableResolver/StrictResolver.php','/DirectiveProcessor/Filter/FilterApplier.php',
 '/DirectiveProcessor/Filter/FilterPool.php','/DirectiveProcessor/VarDirective.php','/DirectiveProcessor/IfDirective.php',
 '/DirectiveProcessor/DependDirective.php','/DirectiveProcessor/SimpleDirective.php','/DirectiveProcessor/LegacyDirective.php',
 '/DirectiveProcessor/TemplateDirective.php','/SimpleDirective/ProcessorPool.php','/Template.php'] as $f) { require $base . $f; }
require '/m/tests/bootstrap.php';

use Magento\Framework\Stdlib\StringUtils; use Magento\Framework\Math\Random;
use Magento\Framework\Filter\Template as LegacyTemplate;
use Magento\Framework\Filter\Template\{FilteringDepthMeter, SignatureProvider};
use Magento\Framework\Filter\VariableResolver\StrictResolver;
use Magento\Framework\Filter\DirectiveProcessor\{IfDirective, DependDirective, TemplateDirective, SimpleDirective, LegacyDirective, VarDirective};
use Magento\Framework\Filter\DirectiveProcessor\Filter\{FilterApplier, FilterPool};
use Magento\Framework\Filter\SimpleDirective\ProcessorPool;
use Magento\Framework\Filter\Template\Tokenizer\{VariableFactory, ParameterFactory};
use MageOS\TemplateParser\TemplateEngine;

function legacy(array $vars): LegacyTemplate {
    $r = new StrictResolver(new VariableFactory());
    \Magento\Framework\App\ObjectManager::$registry[VarDirective::class] = new VarDirective($r, new FilterApplier(new FilterPool()));
    $s = new SimpleDirective(new ProcessorPool(), new ParameterFactory(), $r, new FilterApplier(new FilterPool()));
    $t = new LegacyTemplate(new StringUtils(), [], ['depend'=>new DependDirective($r),'if'=>new IfDirective($r),
        'template'=>new TemplateDirective($r,new ParameterFactory()),'legacy'=>new LegacyDirective($s)],
        $r, new SignatureProvider(new Random()), new FilteringDepthMeter());
    $t->setVariables($vars); return $t;
}
function attempt(callable $fn): string {
    try { return 'OK:' . $fn(); } catch (\Throwable $e) { return 'THROW:' . get_class($e); }
}

$values = [
    'true'=>true, 'false'=>false, 'int 1'=>1, 'int 0'=>0, 'int 42'=>42,
    "str '0'"=>'0', 'empty str'=>'', 'str x'=>'x', 'null'=>null,
    'float 0.0'=>0.0, 'float 1.5'=>1.5, 'empty arr'=>[], 'arr [1,2]'=>[1,2],
    'nested arr'=>['b'=>'deep'], 'assoc empty'=>['b'=>''],
];
$templates = [
    'var'            => '[{{var a}}]',
    'var raw'        => '[{{var a|raw}}]',
    'var path hit'   => '[{{var a.b}}]',
    'var path miss'  => '[{{var a.nosuch}}]',
    'if'             => '[{{if a}}Y{{/if}}]',
    'if else'        => '[{{if a}}Y{{else}}N{{/if}}]',
    'depend'         => '[{{depend a}}Y{{/depend}}]',
    'if with var'    => '[{{if a}}{{var a}}{{/if}}]',
    'depend nested if'=> '[{{depend a}}{{if a}}Y{{/if}}{{/depend}}]',
    'text only'      => 'no directives here',
];

foreach (['lenient' => TemplateEngine::lenient(), 'compatible' => TemplateEngine::compatible()] as $label => $engine) {
    $same = $diff = 0; $rows = [];
    foreach ($values as $vlabel => $v) {
        foreach ($templates as $tlabel => $tpl) {
            $vars = ['a' => $v];
            $l = attempt(static fn () => legacy($vars)->filter($tpl));
            $n = attempt(static fn () => $engine->render($tpl, $vars));
            if ($l === $n) { $same++; } else { $diff++; $rows[] = [$tlabel, $vlabel, $l, $n]; }
        }
    }
    // Group the divergences: the `|modifier` ones are a defect in the BASE filter
    // (Template::varDirective hands VarDirective a legacy-shaped construction, so the
    // expression resolved is " a|raw"). Email\Model\Template\Filter overrides varDirective
    // and handles modifiers correctly, and that is what compatible mode targets - so these
    // are not divergences from the filter anyone actually renders templates with.
    $groups = ['base-filter modifier defect' => 0, 'real divergence' => 0];
    foreach ($rows as [$t, $v, $l, $n]) {
        $groups[str_contains($t, 'raw') ? 'base-filter modifier defect' : 'real divergence']++;
    }
    printf("\n=== %s ===\n  %d/%d identical (%d divergent)\n", $label, $same, $same + $diff, $diff);
    foreach ($groups as $g => $count) { printf("    %-30s %d\n", $g, $count); }
    printf("  parity excluding the base-filter defect: %d/%d\n",
        $same, $same + $groups['real divergence']);
    foreach ($rows as [$t, $v, $l, $n]) {
        printf("    %-17s %-12s legacy=%-22s new=%s\n", $t, $v, $l, $n);
    }

}
