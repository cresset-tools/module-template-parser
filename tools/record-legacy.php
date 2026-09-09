<?php
/**
 * Records what the LEGACY Magento filter does for a corpus of templates, as golden
 * fixtures the test suite can replay without a Magento installation.
 *
 * Writes tests/fixtures/legacy/cases.json. Regenerate whenever the corpus changes:
 *
 *   MAGENTO_ROOT=/path/to/magento php tools/record-legacy.php
 *
 * Use a PRISTINE Magento checkout - see tools/harness.php.
 */
declare(strict_types=1);
// Marks this as a directly-invoked tool. Magento's DI compiler require_once's any
// file declaring a class it has not loaded, and tools/ is not on its exclusion list,
// so everything below must be inert when this file is merely included.
if (PHP_SAPI !== 'cli' || realpath($_SERVER['argv'][0] ?? '') !== __FILE__) {
    return;
}
define('CRESSET_TEMPLATE_PARSER_TOOL', true);
require __DIR__ . '/harness.php';
$base = MROOT . '/lib/internal/Magento/Framework/Filter';
require MROOT . '/lib/internal/Magento/Framework/Math/Random.php';
require MROOT . '/lib/internal/Magento/Framework/DataObject.php';
require MROOT . '/lib/internal/Magento/Framework/Escaper.php';
foreach (['/DirectiveProcessorInterface.php','/VariableResolverInterface.php','/Template/FilteringDepthMeter.php',
 '/Template/SignatureProvider.php','/Template/Tokenizer/AbstractTokenizer.php','/Template/Tokenizer/Parameter.php',
 '/Template/Tokenizer/Variable.php','/VariableResolver/StrictResolver.php','/DirectiveProcessor/Filter/FilterApplier.php',
 '/DirectiveProcessor/Filter/FilterPool.php','/DirectiveProcessor/VarDirective.php','/DirectiveProcessor/IfDirective.php','/DirectiveProcessor/ForDirective.php',
 '/DirectiveProcessor/DependDirective.php','/DirectiveProcessor/SimpleDirective.php','/DirectiveProcessor/LegacyDirective.php',
 '/DirectiveProcessor/TemplateDirective.php','/SimpleDirective/ProcessorPool.php','/Template.php'] as $f) { require $base . $f; }

use Magento\Framework\Stdlib\StringUtils; use Magento\Framework\Math\Random;
use Magento\Framework\Filter\Template as LegacyTemplate;
use Magento\Framework\Filter\Template\{FilteringDepthMeter, SignatureProvider};
use Magento\Framework\Filter\VariableResolver\StrictResolver;
use Magento\Framework\Filter\DirectiveProcessor\{IfDirective, DependDirective, TemplateDirective, SimpleDirective, LegacyDirective, VarDirective, ForDirective};
use Magento\Framework\Filter\DirectiveProcessor\Filter\{FilterApplier, FilterPool};
use Magento\Framework\Filter\SimpleDirective\ProcessorPool;
use Magento\Framework\Filter\Template\Tokenizer\{VariableFactory, ParameterFactory};

/**
 * The legacy filter as templates actually render through it.
 *
 * Base Framework\Filter\Template routes {{var}} via VarDirective, which neither escapes by
 * default nor understands modifiers (see the `|raw` defect). Email\Model\Template\Filter
 * overrides varDirective, and that is the filter email and CMS templates use - so it is the
 * behaviour worth recording. varDirective/explodeModifiers/applyModifiers below are copied
 * verbatim from that class.
 */
require __DIR__ . '/stubs/RecorderEmailLike.php.stub';

function legacy(array $vars, bool $neutralize = true): LegacyTemplate {
    $r = new StrictResolver(new VariableFactory());
    \Magento\Framework\App\ObjectManager::$registry[VarDirective::class] = new VarDirective($r, new FilterApplier(new FilterPool()));
    // Template::forDirective resolves this through the global ObjectManager. Leaving it
    // unbound made every {{for}} case record as a RuntimeException - a legacy fatal legacy
    // does not have - so the corpus simply had no {{for}} cases at all.
    \Magento\Framework\App\ObjectManager::$registry[ForDirective::class] = new ForDirective($r);
    $s = new SimpleDirective(new ProcessorPool(), new ParameterFactory(), $r, new FilterApplier(new FilterPool()));
    $t = new EmailLikeLegacy(new StringUtils(), [], ['depend'=>new DependDirective($r),'if'=>new IfDirective($r),
        'template'=>new TemplateDirective($r,new ParameterFactory()),'legacy'=>new LegacyDirective($s)],
        $r, $sig = new SignatureProvider(new Random()), new FilteringDepthMeter(),
        \Harness\neutralizerFor($sig, $neutralize));
    $t->setVariables($vars); return $t;
}

/**
 * Directives where the two engines implement different surfaces, so a rendering comparison
 * would measure the surface rather than the engine. The base Magento filter has no `trans`
 * processor but does have `template`; this engine is the other way round until a host wires
 * the ports. Cases touching these are recorded but excluded from strict parity.
 */
const SURFACE_DIVERGENT = ['template','inlinecss','css','store','block','widget',
                           'media','config','customvar','protocol','view','filter','for'];

/*
 * `for` is on that list for a different reason from the rest, and a deliberate one.
 *
 * Legacy's ForDirective does not render its body: it runs preg_match_all over the raw text,
 * resolves each match as a variable name and str_replace()s the result in. So the body is
 * never escaped, a nested {{if}} is resolved as if it were a variable, `|raw` becomes part
 * of a property name, an item that is not an array is skipped, and a non-iterable collection
 * makes the whole construct come back verbatim.
 *
 * Reproducing that faithfully would mean not escaping loop variables, which is the exact
 * class of defect this package exists to remove. So {{for}} is a deliberate divergence: the
 * cases are still recorded, and still have to render safely, but they are not held to
 * rendering-equality with legacy.
 */

function parityEligible(string $tpl): bool {
    foreach (SURFACE_DIVERGENT as $name) {
        if (preg_match('/\{\{\/?' . $name . '\b/i', $tpl)) { return false; }
    }
    return true;
}

/** @return array{0:string,1:?string} [outcome, value] */
function record(string $tpl, array $vars, bool $neutralize = true): array {
    set_error_handler(static fn () => true);   // legacy emits notices; not part of the contract
    try { $out = ['ok', legacy($vars, $neutralize)->filter($tpl)]; }
    catch (\Throwable $e) { $out = ['throw', get_class($e)]; }
    restore_error_handler();
    return $out;
}

/**
 * Records a case against both halves of the StyleSmuggler hardening.
 *
 * Mage-OS's DirectiveOutputNeutralizer encodes `{{` in resolved directive output, which
 * changes observable rendering. Trees before and after the hardening are both in the field,
 * so both are recorded: `expected` is the current filter, and `pre_hardening` carries the
 * older behaviour when it differs.
 *
 * @param array<string,mixed> $vars
 */
function recordBoth(string $id, string $tpl, array $vars, ?string $object, bool $parity): array {
    [$outcome, $value] = record($tpl, $vars, true);
    [$plainOutcome, $plainValue] = record($tpl, $vars, false);

    $case = [
        'id' => $id, 'template' => $tpl,
        'variables' => $object === null ? $vars : [],
        'object' => $object,
        'outcome' => $outcome, 'expected' => $value, 'parity' => $parity,
    ];
    if ($plainOutcome !== $outcome || $plainValue !== $value) {
        $case['pre_hardening'] = ['outcome' => $plainOutcome, 'expected' => $plainValue];
    }

    return $case;
}

// ---- the corpus: value shapes x construct shapes ----
$values = [
    'true' => true, 'false' => false, 'zero' => 0, 'one' => 1, 'answer' => 42,
    'strzero' => '0', 'emptystr' => '', 'word' => 'x', 'null' => null,
    'floatzero' => 0.0, 'float' => 1.5, 'emptyarr' => [], 'list' => [1, 2],
    'assoc' => ['b' => 'deep'], 'assocempty' => ['b' => ''], 'html' => '<b>&</b>',
    'directive' => '{{var a}}', 'blockpayload' => '{{block class=Evil}}',
    'longstr' => str_repeat('ab', 40), 'spaces' => '  padded  ',
    'html' => '<b>&"x"</b>', 'entities' => 'Tom &amp; Jerry &nbsp;',
    // NOTE: invalid UTF-8 is deliberately absent - json_encode() cannot represent it, and
    // it is pinned directly in CompatibilityModeTest instead.
    'newlines' => "a\nb\nc",
    // Single braces at the edges: DirectiveOutputNeutralizer encodes those separately from
    // `{{`, because concatenation with a neighbour could otherwise form an opener.
    'brace_lead' => '{x', 'brace_trail' => 'x{', 'brace_both' => '{x{', 'brace_solo' => '{',
];

// Objects cannot be serialised into the fixture, so they are recorded by tag and rebuilt
// from the same factory on replay. The first corpus had none at all, which is why the
// DataObject resolution failure was invisible.
require PKGROOT . '/tests/fixtures/legacy/ObjectFixtures.php';

/*
 * The recording runs against the real DataObject; the test run replays against
 * FakeDataObject. That is only sound while they behave alike, so prove it here - this is the
 * one process that has both classes loaded. Recording one object's behaviour and replaying
 * another's would be a parity measurement of nothing.
 */
(static function (): void {
    $real = new \Magento\Framework\DataObject(ObjectFixtures::dataObjectContents());
    $fake = new FakeDataObject(ObjectFixtures::dataObjectContents());
    $drift = [];
    foreach (ObjectFixtures::equivalenceProbes() as $label => $probe) {
        $a = $probe($real);
        $b = $probe($fake);
        if ($a !== $b) {
            $drift[] = sprintf('  %-20s real=%s fake=%s', $label, json_encode($a), json_encode($b));
        }
    }
    if ($drift !== []) {
        fwrite(STDERR, "FakeDataObject has drifted from Magento's DataObject:\n"
            . implode("\n", $drift) . "\n");
        exit(1);
    }
    fwrite(STDERR, sprintf("FakeDataObject matches DataObject on %d probes\n",
        count(ObjectFixtures::equivalenceProbes())));
})();

foreach (ObjectFixtures::TAGS as $tag) {
    $values['@' . $tag] = ObjectFixtures::make($tag);
}
$constructs = [
    'text'            => 'plain text only',
    'var'             => '[{{var a}}]',
    'var_path'        => '[{{var a.b}}]',
    'var_path_miss'   => '[{{var a.zzz}}]',
    'var_deep_miss'   => '[{{var a.b.c.d}}]',
    'if'              => '[{{if a}}Y{{/if}}]',
    'if_else'         => '[{{if a}}Y{{else}}N{{/if}}]',
    'depend'          => '[{{depend a}}Y{{/depend}}]',
    'if_var'          => '[{{if a}}{{var a}}{{/if}}]',
    'depend_var'      => '[{{depend a}}{{var a}}{{/depend}}]',
    'depend_if'       => '[{{depend a}}{{if a}}Y{{/if}}{{/depend}}]',
    'if_depend'       => '[{{if a}}{{depend a}}Y{{/depend}}{{/if}}]',
    'two_vars'        => '[{{var a}}|{{var a}}]',
    'var_missing'     => '[{{var nosuchvar}}]',
    'if_missing'      => '[{{if nosuchvar}}Y{{else}}N{{/if}}]',
    'surrounding'     => 'pre {{var a}} post',
    'multiline'       => "line1\n{{if a}}\nline2\n{{/if}}\nline3",
    'adjacent'        => '{{var a}}{{if a}}Y{{/if}}{{var a}}',
    'unknown_dir'     => '[{{nosuchdirective x=1}}]',
    'known_unimpl'    => '[{{layout handle=x}}]',
    'prose'           => '{{Forgot Your Password?}}',
    'css_like'        => 'a{{color:red}}b',
    'stray_close'     => 'a{{/if}}b',
    'unclosed'        => 'a{{if a}}b',
    'empty_braces'    => 'a{{}}b',
    'html_around'     => '<p class="x">{{var a}}</p>',
    // Modifiers - the first corpus had none, which is why the escaping bugs hid.
    'var_raw'         => '[{{var a|raw}}]',
    'var_escape'      => '[{{var a|escape}}]',
    'var_nl2br'       => '[{{var a|nl2br}}]',
    'var_escape_html' => '[{{var a|escape:html}}]',
    'var_esc_nl2br'   => '[{{var a|escape|nl2br}}]',
    'var_unknown_mod' => '[{{var a|zzz}}]',
    'var_empty_mod'   => '[{{var a|}}]',
    'var_path_mod'    => '[{{var a.b|raw}}]',
    // Case-insensitive directive names, which legacy's /i patterns accept.
    'upper_var'       => '[{{VAR a}}]',
    'mixed_if'        => '[{{If a}}Y{{/if}}]',
    // Loops. Legacy's surface differs (see SURFACE_DIVERGENT) but the cases are recorded so
    // the divergence is measured rather than assumed.
    'for'             => '[{{for i in a}}X{{/for}}]',
    'for_var'         => '[{{for i in a}}{{var i}}{{/for}}]',
    'for_path'        => '[{{for i in a}}{{var i.b}}{{/for}}]',
    'for_loop_index'  => '[{{for i in a}}{{var loop.index}}{{/for}}]',
    'for_close_space' => '[{{for i in a}}X{{/for }}]',
    // Closing tags with trailing whitespace: CONSTRUCTION_IF_PATTERN accepts these but the
    // backreference in CONSTRUCTION_PATTERN does not, so legacy raises a TypeError.
    'if_close_space'  => '[{{if a}}Y{{/if }}]',
    'dep_close_space' => '[{{depend a}}Y{{/depend }}]',
    // The name is captured as [a-z]{0,10}, so a non-letter after it still dispatches.
    'var_dot'         => '[{{var.a}}]',
    'var_underscore'  => '[{{var_a}}]',
    'var_digit'       => '[{{var2 a}}]',
    'if_underscore'   => '[{{if_a}}]',
    'depend_dot'      => '[{{depend.a}}Y{{/depend}}]',
    // Same-name nesting is only fatal when the parameters are not both empty.
    'nest_empty_if'   => '{{if}}{{if}}{{/if}}',
    'nest_empty_dep'  => '{{depend}}{{depend}}{{/depend}}',
    'cross_unclosed'  => '{{if}}{{depend}}x{{/if}}',
    'unknown_paired'  => '{{foo}}x{{/foo}}',
    'var_paired'      => '{{var a}}Y{{/var}}',
    // Modifier arguments reaching an internal function, and escape types on non-strings.
    'nl2br_param'     => '[{{var a|nl2br:x}}]',
    'esc_htmlent'     => '[{{var a|escape:htmlentities}}]',
    'esc_url'         => '[{{var a|escape:url}}]',
    'esc_unknown'     => '[{{var a|escape:none}}]',
    'esc_empty_type'  => '[{{var a|escape:}}]',
    // Three distinct names nested three deep. Legacy renders all six orderings; a depth
    // bound of two refused them, which is why none of these were ever in the corpus.
    'nest3_ifdepfor'  => '{{if a}}{{depend a}}{{for i in a}}X{{/for}}{{/depend}}{{/if}}',
    'nest3_ifforedep' => '{{if a}}{{for i in a}}{{depend a}}X{{/depend}}{{/for}}{{/if}}',
    'nest3_depiffor'  => '{{depend a}}{{if a}}{{for i in a}}X{{/for}}{{/if}}{{/depend}}',
    'nest3_depforif'  => '{{depend a}}{{for i in a}}{{if a}}X{{/if}}{{/for}}{{/depend}}',
    'nest3_forifdep'  => '{{for i in a}}{{if a}}{{depend a}}X{{/depend}}{{/if}}{{/for}}',
    'nest3_fordepif'  => '{{for i in a}}{{depend a}}{{if a}}X{{/if}}{{/depend}}{{/for}}',
    // A directive name plus exactly one more letter: the shape the prefix-split regex
    // used to backtrack on and refuse.
    'name_plus_one'   => '[{{ifx a}}]',
    'name_plural'     => '[{{vars}}]',
    'name_plural_blk' => '[{{blocks}}]',
    // Upper case, which legacy's /i patterns and reflection dispatch both accept.
    'upper_var_dot'   => '[{{VAR.a}}]',
    // Member access shapes: a getter on an array parent, whitespace, a leading dot, args.
    'getter_call'     => '[{{var a.getB()}}]',
    'nonget_call'     => '[{{var a.foo()}}]',
    'getter_args'     => '[{{var a.getB("x")}}]',
    'var_ws_path'     => '[{{var a . b}}]',
    'var_lead_dot'    => '[{{var .a}}]',
    // {{trans}}. Excluded from parity until the recorder grew a transDirective, so none of
    // these rules were ever measured - and four of them were wrong here.
    'trans'           => '[{{trans "T"}}]',
    'trans_arg'       => '[{{trans "T %a" a=$a}}]',
    // No `$`, so `a` is a literal and stays the letter a, even though a variable `a` exists.
    'trans_arg_lit'   => '[{{trans "T %a" a=a}}]',
    'trans_arg_quot'  => '[{{trans "T %a" a="a"}}]',
    'trans_arg_miss'  => '[{{trans "T %a" a=$nosuchvar}}]',
    // An integer argument key stands for the NEXT placeholder up.
    'trans_num_key'   => '[{{trans "T %1 %2" 1=$a}}]',
    'trans_zero_key'  => '[{{trans "T %0 %1" 0=$a}}]',
    // The default modifier is escape, and it applies to the text as well as the arguments.
    'trans_amp'       => '[{{trans "Tom & Jerry %a" a=$a}}]',
    'trans_raw'       => '[{{trans "T %a" a=$a|raw}}]',
    'trans_esc_url'   => '[{{trans "T %a" a=$a|escape:url}}]',
    'trans_unknown'   => '[{{trans "T & %a" a=$a|zzz}}]',
    // Malformed bodies, all of which render nothing rather than being treated as the text.
    'trans_pipe_text' => '[{{trans "a|b"}}]',
    'trans_unquoted'  => '[{{trans T %a}}]',
    'trans_unterm'    => '[{{trans "T}}]',
    'trans_no_space'  => '[{{trans "T %a"a=$a}}]',
    'trans_backslash' => '[{{trans "a\b \"q\" %a" a=$a}}]',
    'trans_empty'     => '[{{trans ""}}]',
    'trans_zero_text' => '[{{trans "0"}}]',
    'trans_in_if'     => '[{{if a}}{{trans "T %a" a=$a}}{{/if}}]',
    // Parameter tokenizing. getValue() stops on the whitespace after '=', tokenize() records
    // nothing for a word with no '=' at all, and at the end of the blob the cursor cannot
    // advance so the '=' itself becomes the value.
    'trans_empty_arg' => '[{{trans "T %a %b" a= b=$a}}]',
    'trans_arg_eof'   => '[{{trans "T %a" a=}}]',
    'trans_arg_word'  => '[{{trans "T %a" a=$a b}}]',
    'trans_arg_space' => '[{{trans "T %a" a = $a}}]',
    // AbstractTokenizer::setString() rawurldecodes, before tokenizing and before the
    // variable path is split - so an encoded '=' makes a parameter and an encoded '.' makes
    // a path segment.
    'trans_enc_eq'    => '[{{trans "T %a" a%3D$a}}]',
    'var_enc_dot'     => '[{{var a%2Eb}}]',
    'var_enc_val'     => '[{{trans "T %a" a=%24a}}]',
    // Dots inside a method's arguments are not path separators.
    'getter_arg_path' => '[{{var a.getB($a.b)}}]',
    'getter_arg_arr'  => '[{{var a.getUrl($a,\'x/y/\',[_query:[id:$a.b],_nosid:1])}}]',
];

$cases = [];
foreach ($values as $vlabel => $v) {
    foreach ($constructs as $clabel => $tpl) {
        $isObject = str_starts_with($vlabel, '@');
        $cases[] = recordBoth(
            "$clabel/$vlabel",
            $tpl,
            ['a' => $v],
            $isObject ? substr($vlabel, 1) : null,
            parityEligible($tpl)
        );
    }
}
// no-variables path, which legacy treats specially
foreach ($constructs as $clabel => $tpl) {
    $cases[] = recordBoth("$clabel/novars", $tpl, [], null, parityEligible($tpl));
}
// the real harvested templates, rendered with a realistic variable set
$realVars = ['customer_name'=>'Jan Jansen','store_name'=>'Demo','name'=>'Jan','a'=>1,
             'store_phone'=>'123','store_hours'=>'9-5','logo_width'=>'180','logo_height'=>'50'];
foreach (glob(PKGROOT . '/tests/fixtures/corpus/*.html') ?: [] as $file) {
    $src = (string)file_get_contents($file);
    $cases[] = recordBoth('real/' . basename($file), $src, $realVars, null, parityEligible($src));
}

$json = json_encode($cases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    // Silently writing an empty fixture would make the parity suite pass vacuously.
    fwrite(STDERR, 'FATAL: json_encode failed: ' . json_last_error_msg() . PHP_EOL);
    exit(1);
}
file_put_contents(PKGROOT . '/tests/fixtures/legacy/cases.json', $json);

$throws = count(array_filter($cases, static fn ($c) => $c['outcome'] === 'throw'));
$parity = count(array_filter($cases, static fn ($c) => $c['parity']));
printf("recorded %d cases: %d render, %d legacy fatals, %d parity-eligible -> tests/fixtures/legacy/cases.json\n",
    count($cases), count($cases) - $throws, $throws, $parity);
