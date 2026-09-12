<?php
/**
 * Generates the measured tables in docs/directives.md.
 *
 * The reference documents a language nobody wrote down, so every claim in it is rendered
 * through the real filter before it is printed:
 *
 *   MAGENTO_ROOT=/path/to/magento php tools/document-language.php
 *
 * Same harness as tools/record-legacy.php - about twenty framework files loaded by path, no
 * composer install, no database. Use a PRISTINE checkout, for the same reason.
 *
 * The tool also compares this engine's compatible mode against every documented case and
 * refuses to write when they disagree, unless the case says it should. So the reference is a
 * third parity check as well as documentation: an example cannot be written down here
 * without also being true.
 */
declare(strict_types=1);
// Inert unless invoked directly - see tools/harness.php.
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
 '/DirectiveProcessor/Filter/FilterPool.php','/DirectiveProcessor/VarDirective.php','/DirectiveProcessor/IfDirective.php',
 '/DirectiveProcessor/ForDirective.php','/DirectiveProcessor/DependDirective.php','/DirectiveProcessor/SimpleDirective.php',
 '/DirectiveProcessor/LegacyDirective.php','/DirectiveProcessor/TemplateDirective.php','/SimpleDirective/ProcessorPool.php',
 '/Template.php'] as $f) { require $base . $f; }
require __DIR__ . '/stubs/RecorderEmailLike.php.stub';
require PKGROOT . '/vendor/autoload.php';

use Magento\Framework\Stdlib\StringUtils; use Magento\Framework\Math\Random;
use Magento\Framework\Filter\Template as LegacyTemplate;
use Magento\Framework\Filter\Template\{FilteringDepthMeter, SignatureProvider};
use Magento\Framework\Filter\VariableResolver\StrictResolver;
use Magento\Framework\Filter\DirectiveProcessor\{IfDirective, DependDirective, TemplateDirective, SimpleDirective, LegacyDirective, VarDirective, ForDirective};
use Magento\Framework\Filter\DirectiveProcessor\Filter\{FilterApplier, FilterPool};
use Magento\Framework\Filter\SimpleDirective\ProcessorPool;
use Magento\Framework\Filter\Template\Tokenizer\{VariableFactory, ParameterFactory};
use Cresset\TemplateParser\{Options, TemplateEngine};

/**
 * The filter as email and CMS templates actually render through it.
 *
 * Identical to the recorder's, plus a template processor: without one every {{template}}
 * include renders "{Error in template processing}", which would document the harness rather
 * than the directive.
 *
 * @param array<string,mixed> $vars
 * @param array<string,string> $includes config path => template text
 */
function documentedFilter(array $vars, array $includes = []): LegacyTemplate
{
    $r = new StrictResolver(new VariableFactory());
    \Magento\Framework\App\ObjectManager::$registry[VarDirective::class] = new VarDirective($r, new FilterApplier(new FilterPool()));
    \Magento\Framework\App\ObjectManager::$registry[ForDirective::class] = new ForDirective($r);
    $s = new SimpleDirective(new ProcessorPool(), new ParameterFactory(), $r, new FilterApplier(new FilterPool()));
    $t = new EmailLikeLegacy(new StringUtils(), [], ['depend' => new DependDirective($r), 'if' => new IfDirective($r),
        'template' => new TemplateDirective($r, new ParameterFactory()), 'legacy' => new LegacyDirective($s)],
        $r, $sig = new SignatureProvider(new Random()), new FilteringDepthMeter(),
        \Harness\neutralizerFor($sig, true));
    $t->setVariables($vars);

    if ($includes !== []) {
        $t->setTemplateProcessor(static function (string $path, array $params) use ($includes, $t): string {
            if (!isset($includes[$path])) {
                return '{Error in template processing}';
            }

            // AbstractTemplate::getTemplateContent() RENDERS the child through its own
            // filter - it does not paste the text into the parent. Returning the raw text
            // here left the include's own directives for the parent to neutralize, which
            // documents the harness instead of the directive.
            //
            // TemplateDirective has already merged the directive's parameters over the
            // parent's variables by the time this is called, so $params is the child's scope.
            $child = clone $t;
            $child->setVariables($params);

            return (string)$child->filter($includes[$path]);
        });
    }

    return $t;
}

/** @param array<string,mixed> $vars */
function renderLegacy(string $template, array $vars, array $includes = []): string
{
    set_error_handler(static fn (): bool => true);   // legacy emits notices; not the contract
    try {
        return (string)documentedFilter($vars, $includes)->filter($template);
    } catch (\Throwable $e) {
        return '!' . (new \ReflectionClass($e))->getShortName();
    } finally {
        restore_error_handler();
    }
}

/** @param array<string,mixed> $vars */
function renderOurs(Options $options, string $template, array $vars): string
{
    try {
        return TemplateEngine::withOptions($options)->render($template, $vars);
    } catch (\Throwable $e) {
        return '!' . (new \ReflectionClass($e))->getShortName();
    }
}

/** Short, readable rendering of a variable set for the doc's middle column. */
function describeVars(array $vars): string
{
    if ($vars === []) {
        return '-';
    }
    $parts = [];
    foreach ($vars as $name => $value) {
        $parts[] = $name . '=' . (is_object($value)
            ? 'DataObject'
            : (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    return implode(' ', $parts);
}

/** Output as the doc shows it: one line, empty made visible, long values clipped. */
function describeOutput(string $value): string
{
    if ($value === '') {
        return '(nothing)';
    }
    $shown = str_replace(["\n", "\r", "\t"], ['\n', '', '\t'], $value);

    return strlen($shown) > 58 ? substr($shown, 0, 55) . '...' : $shown;
}

/**
 * One documented example.
 *
 * `divergent` marks a case where this engine deliberately does not reproduce legacy - the
 * generator refuses to write any other disagreement, so a documented example cannot quietly
 * stop being true.
 */
function example(string $template, array $vars = [], ?string $note = null, bool $divergent = false, array $includes = []): array
{
    return ['template' => $template, 'vars' => $vars, 'note' => $note, 'divergent' => $divergent, 'includes' => $includes];
}

$customer = new \Magento\Framework\DataObject(['name' => 'Ada', 'address_1' => 'Main St', 'rp_token' => 'tok', 'nested' => ['q' => 'DEEP']]);

$sections = [
'var' => [
    example('{{var a}}', ['a' => 'Ada']),
    example('{{var a}}', ['a' => '<b>&</b>'], 'escaped by default'),
    example('{{var a}}', ['a' => null], 'a variable set to null renders as nothing'),
    example('{{var nope}}', ['a' => 1], 'an unknown variable renders as nothing, silently'),
    example('{{var a}}', [], 'with NO variables at all the directive is passed through verbatim'),
    example('{{trans "Hello"}}', [], 'but only the variable-reading directives do that - {{trans}} still runs'),
    example('{{var a}}', ['a' => ['x', 'y']], 'an array is cast to the string "Array"'),
    example('{{var a}}', ['a' => 0]),
    example('{{var a}}', ['a' => true]),
    example('{{var a}}', ['a' => false], 'false casts to the empty string'),
],
'paths' => [
    example('{{var a.b}}', ['a' => ['b' => 'deep']], 'array key'),
    example('{{var a.b}}', ['a' => ['x' => 1]], 'a missing key yields nothing, not the parent'),
    example('{{var c.name}}', ['c' => $customer], 'an object is read through getData()'),
    example('{{var c.getName()}}', ['c' => $customer], 'a getter maps to getData("name") - it is never called'),
    example('{{var c.getAddress1()}}', ['c' => $customer], 'a run of digits is its own segment, so this reads address_1'),
    example('{{var c.getName("ignored")}}', ['c' => $customer], 'arguments are parsed and dropped'),
    example('{{var c.nested/q}}', ['c' => $customer], 'a `/` inside a key is a path INSIDE the DataObject - getData() walks it, hasData() does not know the syntax'),
    example('{{var a . b}}', ['a' => ['b' => 'deep']], 'whitespace anywhere in a path is skipped'),
    example('{{var .a}}', ['a' => 'Ada'], 'a leading dot is no action at all'),
    example('{{var a..b}}', ['a' => ['b' => 'deep']]),
    example('{{var a%2Eb}}', ['a' => ['b' => 'deep']], 'the path is rawurldecode()d before it is split'),
    example('{{var a.b}}', ['a' => 'scalar'], 'member access is not attempted on a scalar, so the parent itself is the result'),
    example('{{var a.b.c}}', ['a' => ['b' => ['c' => 'deep']]], 'paths nest freely'),
    example('{{var a b}}', ['ab' => 'AB'], 'whitespace ANYWHERE in a name is skipped, not just at the edges'),
    example('{{var a()}}', ['a' => 'Ada'], 'a call at the HEAD of a path is the variable itself - the type is ignored there'),
    example('{{var c.get()}}', ['c' => $customer], '.get() maps to getData("") and hands back the whole data bag'),
    example('{{var a.getB(}}', ['a' => ['b' => 1]], 'an UNCLOSED call is still a call - on an array parent legacy raises, so this is refused', divergent: true),
],
'modifiers' => [
    example('{{var a|raw}}', ['a' => '<b>'], 'the default escape is replaced, not added to'),
    example('{{var a|escape}}', ['a' => '<b>']),
    example('{{var a|nl2br}}', ['a' => "<b>\nx"], 'nl2br REPLACES escape, so this is unescaped on the filter'),
    example('{{var a|escape|nl2br}}', ['a' => "<b>\nx"], 'ask for both to get both'),
    example('{{var a|typo}}', ['a' => '<b>'], 'an unknown modifier is skipped - and takes the escaping with it'),
    example('{{var a|escape:html}}', ['a' => '<b>&"x"']),
    example('{{var a|escape:htmlentities}}', ['a' => '<b>&"x"']),
    example('{{var a|escape:url}}', ['a' => 'a b/c']),
    example('{{var a|escape:none}}', ['a' => '<b>'], 'an unrecognised escape type disables escaping'),
    example('{{var a|escape }}', ['a' => '<b>'], 'WHITESPACE in a modifier name makes it unrecognised too - so this does not escape, though it looks like it does'),
    example('{{var a| escape}}', ['a' => '<b>'], 'either side of the name'),
    example('{{var a|}}', ['a' => '<b>'], 'an empty modifier is skipped, and so is the default with it'),
],
'if' => [
    example('{{if a}}Y{{/if}}', ['a' => 1]),
    example('{{if a}}Y{{else}}N{{/if}}', [], 'with NO variables at all the whole construction comes back verbatim, {{else}} and body included - the template-validation path'),
    example('{{if a}}Y{{else}}N{{/if}}', ['a' => '']),
    example('{{if a}}Y{{else}}N{{/if}}', ['a' => 0], 'the test is `== \'\'`, and on PHP 8 that is false for 0'),
    example('{{if a}}Y{{else}}N{{/if}}', ['a' => '0'], 'the string zero is truthy too'),
    example('{{if a}}Y{{else}}N{{/if}}', ['a' => []], 'and so is the empty array'),
    example('{{if a}}Y{{else}}N{{/if}}', ['a' => null]),
    example('{{if a.b}}Y{{else}}N{{/if}}', ['a' => ['b' => 'x']], 'a path works'),
    example('{{if 1}}Y{{else}}N{{/if}}', ['a' => 1], 'a LITERAL does not: `1` is read as the name of a variable'),
    example('{{if a == 1}}Y{{else}}N{{/if}}', ['a' => 1], 'nor does a comparison - the whole string is one variable name'),
    example('{{if !a}}Y{{else}}N{{/if}}', ['a' => 0], 'nor negation'),
    example('{{if "x"}}Y{{else}}N{{/if}}', ['a' => 1], 'nor a quoted literal'),
],
'depend' => [
    example('{{depend a}}Y{{/depend}}', ['a' => 'x']),
    example('{{depend a}}Y{{/depend}}', ['a' => ''], 'same truthiness rule as {{if}}, but with no {{else}}'),
    example('{{depend a}}Y{{/depend}}', ['a' => 0], 'and the same PHP 8 truthiness'),
    example('{{depend nope}}Y{{/depend}}', ['a' => 1], 'a variable nobody set drops the block'),
],
'for' => [
    example('{{for i in a}}[{{var i.b}}]{{/for}}', ['a' => [['b' => 1], ['b' => 2]]], 'the usual shape: a list of rows'),
    example('{{for i in a}}[{{var i}}]{{/for}}', ['a' => ['x', 'y']], 'a list of SCALARS yields nothing - an item that is not an array is skipped', divergent: true),
    example('{{for i in a}}[{{var loop.index}}]{{/for}}', ['a' => [['b' => 1], ['b' => 2]]], 'loop.index is injected, and counts from ZERO'),
    example('{{for i in a}}{{if i.b}}Y{{/if}}{{/for}}', ['a' => [['b' => 1]]], 'the body is not RENDERED - every {{...}} in it is resolved as a VARIABLE NAME, so this prints i.b rather than taking a branch', divergent: true),
    example('{{for i in a}}[{{var i.b}}]{{/for}}', ['a' => []]),
    example('{{for i in a}}[{{var i}}]{{/for}}', ['a' => 'notalist'], 'a non-iterable collection comes back verbatim', divergent: true),
],
'trans' => [
    example('{{trans "Hello"}}'),
    example('{{trans "Tom & Jerry"}}', [], 'the default modifier is escape, and it applies to the TEXT as well'),
    example('{{trans "Hi %n" n=$a}}', ['a' => 'Ada'], 'a `$` makes an argument a variable'),
    example('{{trans "Hi %n" n=a}}', ['a' => 'Ada'], 'without one it is a literal, even when a variable of that name exists'),
    example('{{trans "Hi %n" n=$nope}}', [], 'an argument that will not resolve renders as nothing'),
    example('{{trans "Hi %1" 1=$a}}', ['a' => 'Ada'], 'an INTEGER key stands for the next placeholder up, so %1 is never filled'),
    example('{{trans "Hi %2" 1=$a}}', ['a' => 'Ada'], 'which makes this the one that works'),
    example('{{trans "Hi %n" n=$a|raw}}', ['a' => '<b>'], '|raw turns the escaping off'),
    example('{{trans "a|b"}}', [], 'the split on `|` happens first, so a pipe in the text truncates it and the whole directive renders nothing'),
    // The one place this engine does MORE than the filter rather than less.
    example('{{trans "a {{b}}"}}', [], 'a quoted text may hold `{{` here; the filter stops at the first `}}` wherever it is and renders the leftovers as text', divergent: true),
    example("{{trans 'has }} inside'}}", [], 'and `}}` likewise', divergent: true),
    example('{{trans Hello}}', [], 'the text has to be quoted'),
    example('{{trans "Hi"x=1}}', [], 'and separated from its arguments by whitespace'),
],
'template' => [
    example('{{template config_path="greet"}}', ['a' => 'Ada'], 'the include is rendered with the parent\'s variables',
        includes: ['greet' => 'Hi {{var a}}']),
    example('{{template config_path="greet" a="Bob"}}', ['a' => 'Ada'], 'a parameter COLLIDING with a parent variable becomes an array of both - legacy merges recursively',
        includes: ['greet' => 'Hi {{var a}}']),
    example('{{template config_path="greet" who="Bob"}}', ['a' => 'Ada'], 'a parameter that does not collide is just a variable',
        includes: ['greet' => 'Hi {{var who}}']),
    example('{{template config_path="greet" who=$a}}', ['a' => 'Ada'], 'a `$` parameter resolves before the include runs',
        includes: ['greet' => 'Hi {{var who}}']),
    example('{{template}}', [], 'a missing config_path renders this literal - the leading brace is encoded by the neutralizer', includes: ['greet' => 'x']),
    example('{{template config_path="nope"}}', [], 'and so does a path that resolves to no template', includes: ['greet' => 'x']),
],
'parameters' => [
    example('{{trans "T %a" a="x y"}}', [], 'a quoted value may contain spaces'),
    example('{{trans "T %a %b" a= b=$x}}', ['x' => 'X'], 'whitespace after `=` ends the value - it does not swallow the next parameter'),
    example('{{trans "T %a" a=}}', [], 'at the very end of a directive the cursor cannot advance, so the `=` becomes the value'),
    example('{{trans "T [%a]" a=1\\ b=2}}', [], 'a backslash escapes the next character in an UNQUOTED value too, so the escaped space does not end it and `a` swallows the rest'),
    example('{{trans "T [%a]" a="x\\"y"}}', [], 'and it keeps the backslash, unless what follows is another backslash'),
    example('{{trans "T %a" a=$x b}}', ['x' => 'X'], 'a TRAILING word with no `=` is dropped'),
    example('{{trans "T [%ab]" a b=1}}', [], 'but one before another parameter is not - the name accumulates across the whitespace'),
    example('{{trans "T 50% off" =X}}', [], 'an empty key is the placeholder `%` itself, so it rewrites every `%` in the text'),
    example('{{trans "T %a" a%3D$x}}', ['x' => 'X'], 'the blob is rawurldecode()d first, so an encoded `=` makes a parameter'),
],
'nesting' => [
    example('{{if a}}{{depend a}}{{for i in a}}[{{var i.b}}]{{/for}}{{/depend}}{{/if}}', ['a' => [['b' => 'x']]], 'three distinct names nest, if > depend > for'),
    example('{{depend a}}{{if a}}{{for i in a}}[{{var i.b}}]{{/for}}{{/if}}{{/depend}}', ['a' => [['b' => 'x']]], 'depend > if > for'),
    example('{{for i in a}}{{if i.b}}[{{var i.b}}]{{/if}}{{/for}}', ['a' => [['b' => 'x']]], 'but {{for}} OUTERMOST does not nest - its body is scanned, not rendered', divergent: true),
    example('{{if a}}{{depend a}}{{for i in a}}[{{var i.b}}]{{/for}}{{/depend}}{{/if}}', ['a' => 1], 'and a non-iterable collection leaves the innermost {{for}} verbatim, so nothing nests through it', divergent: true),
    example('{{if a}}{{if a}}Y{{/if}}{{/if}}', ['a' => 1], 'a directive cannot contain ITSELF - the inner close ends the outer', divergent: true),
    example('{{depend a}}{{if a}}Y{{/if}}{{/depend}}', ['a' => 1]),
],
'neutralizer' => [
    example('{{var a}}', ['a' => '{{block class=Evil}}'], 'a resolved value carrying `{{` comes back encoded'),
    example('{{var a}}', ['a' => '{x'], 'a single brace at an edge is encoded too'),
    example('{{var a}}', ['a' => 'a{b'], 'one in the middle is left alone'),
],
];

$blocks = [];
$disagreements = [];
$stale = [];

foreach ($sections as $name => $cases) {
    $rows = [];
    foreach ($cases as $case) {
        $legacy = renderLegacy($case['template'], $case['vars'], $case['includes']);
        $compatible = renderOurs(Options::compatible(), $case['template'], $case['vars']);
        $strict = renderOurs(Options::strict(), $case['template'], $case['vars']);

        // An include is a host capability, so a portless engine cannot be held to it.
        $comparable = $case['includes'] === [];
        if ($comparable && $legacy !== $compatible && !$case['divergent']) {
            $disagreements[] = sprintf(
                "  %-46s legacy=%s  ours=%s",
                $case['template'],
                var_export($legacy, true),
                var_export($compatible, true)
            );
        }
        // A case marked divergent that has stopped diverging is a claim the docs would
        // still be making about a difference nobody has any more.
        if ($comparable && $legacy === $compatible && $case['divergent']) {
            $stale[] = '  ' . $case['template'] . ' is marked divergent but now agrees';
        }

        // No template port is wired here, so this column would read "strict raises
        // UnknownDirectiveError" on every row - the missing port, not the directive.
        $hideStrict = $name === 'template';
        $rows[] = [
            $case['template'],
            describeVars($case['vars']),
            describeOutput($legacy),
            !$hideStrict && $strict !== $legacy && $strict !== $compatible ? describeOutput($strict) : '',
            $case['note'] ?? '',
        ];
    }
    $blocks[$name] = $rows;
}

if ($disagreements !== [] || $stale !== []) {
    fwrite(STDERR, "Refusing to write: the reference would not be true.\n");
    fwrite(STDERR, implode("\n", [...$disagreements, ...$stale]) . "\n");
    exit(1);
}

/** @param list<array{0:string,1:string,2:string,3:string,4:string}> $rows */
function renderBlock(array $rows): string
{
    $w = static fn (int $i): int => max(array_map(static fn (array $r): int => strlen($r[$i]), $rows));
    [$t, $v, $o] = [$w(0), $w(1), $w(2)];

    $lines = [];
    foreach ($rows as [$template, $vars, $output, $strict, $note]) {
        $line = sprintf('%-' . $t . 's   %-' . $v . 's   → %s', $template, $vars, $output);
        $tail = [];
        if ($strict !== '') {
            $tail[] = str_starts_with($strict, '!')
                ? 'strict raises ' . substr($strict, 1)
                : 'strict: ' . $strict;
        }
        if ($note !== '') {
            $tail[] = $note;
        }
        if ($tail !== []) {
            $line = sprintf('%-' . ($t + $v + $o + 9) . 's  # %s', $line, implode('; ', $tail));
        }
        $lines[] = rtrim($line);
    }

    return implode("\n", $lines);
}

$doc = PKGROOT . '/docs/directives.md';
if (!is_file($doc)) {
    fwrite(STDERR, "docs/directives.md does not exist yet - write the prose first.\n");
    exit(1);
}

$text = (string)file_get_contents($doc);
$written = 0;
foreach ($blocks as $name => $rows) {
    $open = '<!-- generated:' . $name . ' -->';
    $close = '<!-- /generated -->';
    $pattern = '/' . preg_quote($open, '/') . '.*?' . preg_quote($close, '/') . '/s';
    if (preg_match($pattern, $text) !== 1) {
        fwrite(STDERR, "no $open marker in docs/directives.md\n");
        exit(1);
    }
    $text = (string)preg_replace(
        $pattern,
        $open . "\n```\n" . renderBlock($rows) . "\n```\n" . $close,
        $text,
        1
    );
    $written += count($rows);
}

file_put_contents($doc, $text);
fwrite(STDERR, sprintf("documented %d examples across %d sections -> docs/directives.md\n", $written, count($blocks)));
