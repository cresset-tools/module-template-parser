<?php
declare(strict_types=1);

/**
 * Records the port-backed directives against a real store.
 *
 *   php tools/record-store-ports.php            (run from inside a Magento/Mage-OS store)
 *
 * Twelve of the nineteen directives need a host to resolve at all - {{store}}, {{media}},
 * {{view}}, {{protocol}}, {{block}}, {{widget}}, {{layout}}, {{config}}, {{customvar}},
 * {{template}}, {{css}}, {{inlinecss}} - so tools/record-legacy.php, which builds a filter out
 * of a handful of required files and no application, cannot reach them. They were excluded
 * from the parity corpus by construction, and that is where every security bug adversarial
 * fuzzing has found in this package has been.
 *
 * This closes that by recording two things per case:
 *
 *   - the TAPE, every question the engine asked its ports and the answer it got. The port
 *     boundary is where this engine's responsibility ends, so the tape is precisely its own
 *     decisions: what it let through, what it refused by never asking, what it forwarded.
 *     A tape replays without a store, which is what makes this a fixture rather than a
 *     manual check.
 *   - what the LEGACY filter rendered for the same template on the same store, so a case
 *     where the two stopped agreeing shows up as a change in the committed file.
 *
 * Re-record after changing a guard, and read the diff. A tape that changed is the engine
 * having changed its mind about what reaches the host.
 */

// Magento's DI compiler require_once's any file under the package root; the guard keeps this
// out of that, as tools/record-legacy.php does.
if (PHP_SAPI !== 'cli' || realpath($_SERVER['argv'][0] ?? '') !== __FILE__) {
    return;
}

if (ini_get('memory_limit') === '-1') {
    ini_set('memory_limit', '2G');
}

$root = getcwd();
foreach ([$root . '/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

use Cresset\TemplateParser\Console\EngineFactory;
use Cresset\TemplateParser\Console\LegacyRenderer;
use Cresset\TemplateParser\Console\MagentoContext;
use Cresset\TemplateParser\Console\Mode;
use Cresset\TemplateParser\Console\StoreEmulator;
use Cresset\TemplateParser\Console\TemplateSubject;
use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\DirectiveSpec;
use Cresset\TemplateParser\Evaluator;
use Cresset\TemplateParser\HostDirectives;
use Cresset\TemplateParser\Parser;
use Cresset\TemplateParser\RenderPolicy;
use Cresset\TemplateParser\TemplateEngine;
use Cresset\TemplateParser\Testing\PortTape;
use Cresset\TemplateParser\Testing\TapedServices;

/**
 * What of a variable set can go in a fixture.
 *
 * The model's set holds live objects - the store, the template model itself - which no JSON
 * can carry and no replay needs: the tape already holds every answer those objects gave.
 * Recorded so a reader can see what was in scope, not so it can be reconstructed.
 *
 * @param array<string,mixed> $variables
 * @return array<string,mixed>
 */
function scalarsOnly(array $variables): array
{
    $out = [];
    foreach ($variables as $name => $value) {
        $out[$name] = is_scalar($value) || $value === null
            ? $value
            : '<' . (is_object($value) ? $value::class : gettype($value)) . '>';
    }

    return $out;
}

$magento = MagentoContext::detect($root);
if (!$magento->isAvailable()) {
    fwrite(STDERR, "record-store-ports: no store here. Run it from inside a Magento or Mage-OS install.\n");
    exit(1);
}

/**
 * The constructs, chosen for where the bugs have actually been.
 *
 * Every shape adversarial fuzzing found a defect in is here, plus the ordinary form of each
 * directive so a guard that starts refusing everything is as visible as one that stops
 * refusing anything.
 */
const CONSTRUCTS = [
    // {{media}} - concatenated onto the media base URL with nothing checked, on the filter.
    'media_plain'        => '{{media url="logo/logo.png"}}',
    'media_absent'       => '{{media}}',
    'media_empty'        => '{{media url=""}}',
    'media_traversal'    => '{{media url="../../app/etc/env.php"}}',
    'media_entity'       => '{{media url="&#46&#46/&#46&#46/app/etc/env.php"}}',
    'media_scheme'       => '{{media url="http://evil.example/x"}}',
    'media_markup'       => '{{media url="x&quot;&gt;&lt;script&gt;"}}',
    'media_var'          => '{{media url=$path}}',

    // {{view}} - design parameters become static-URL path segments.
    'view_plain'         => '{{view url="Magento_Email::logo_email.png"}}',
    'view_absent'        => '{{view}}',
    'view_area'          => '{{view url="css/email.css" area="adminhtml"}}',
    'view_area_walk'     => '{{view url="css/email.css" area="../../.."}}',
    'view_locale_walk'   => '{{view url="css/email.css" locale="../.."}}',
    'view_theme_walk'    => '{{view url="css/email.css" theme="../../.."}}',
    'view_module'        => '{{view url="css/email.css" module="Magento_Email"}}',
    'view_theme_model'   => '{{view url="css/email.css" themeModel="x"}}',
    'view_traversal'     => '{{view url="../../../secret"}}',

    // {{store}} - every route parameter is a path segment, key included.
    'store_plain'        => '{{store url="customer/account"}}',
    'store_empty'        => '{{store url=""}}',
    'store_absent'       => '{{store}}',
    'store_query'        => '{{store url="customer/account" _query_id="7" _query_token="abc"}}',
    'store_query_text'   => '{{store url="customer/account" _query_name=$name}}',
    'store_type'         => '{{store url="customer/account" _type="web"}}',
    'store_type_walk'    => '{{store url="customer/account" _type="../.."}}',
    'store_direct'       => '{{store direct_url="customer/account"}}',
    'store_direct_walk'  => '{{store direct_url="../../app/etc/env.php"}}',
    'store_route_param'  => '{{store url="customer/account" anything="../../.."}}',
    'store_escape_off'   => '{{store url="customer/account" _escape_params="0" q="a b"}}',
    'store_nosid'        => '{{store url="customer/account" _nosid="1"}}',

    // {{protocol}} - the pair takes ABSOLUTE urls; url= takes a host and a path.
    'protocol_bare'      => '{{protocol}}',
    'protocol_store'     => '{{protocol store="1"}}',
    'protocol_url'       => '{{protocol url="example.com/a"}}',
    'protocol_port'      => '{{protocol url="example.com:8080/a"}}',
    'protocol_url_markup' => '{{protocol url="a.example/x&quot;&gt;"}}',
    'protocol_pair'      => '{{protocol http="http://a.example/x" https="https://a.example/x"}}',
    'protocol_pair_rel'  => '{{protocol http="plain/page" https="secure/page"}}',
    'protocol_pair_swap' => '{{protocol http="https://a.example/x" https="https://a.example/x"}}',
    'protocol_pair_js'   => '{{protocol http=$js https=$js}}',

    // {{config}} - the allowlist is the whole security, and two paths render as names.
    'config_name'        => '{{config path="general/store_information/name"}}',
    'config_country'     => '{{config path="general/store_information/country_id"}}',
    'config_region'      => '{{config path="general/store_information/region_id"}}',
    'config_phone'       => '{{config path="general/store_information/phone"}}',
    'config_denied'      => '{{config path="admin/security/password_lifetime"}}',
    'config_absent'      => '{{config}}',

    // {{customvar}} - a code is whatever a merchant typed.
    'customvar_plain'    => '{{customvar code="promo_text"}}',
    'customvar_slash'    => '{{customvar code="checkout/tos"}}',
    'customvar_space'    => '{{customvar code="store hours"}}',
    'customvar_walk'     => '{{customvar code="../x"}}',
    'customvar_absent'   => '{{customvar}}',

    // {{css}} / {{inlinecss}} - both silent in a plain-text body.
    'css_plain'          => '{{css file="css/email.css"}}',
    'css_absent'         => '{{css}}',
    'css_traversal'      => '{{css file="../../app/etc/env.php"}}',
    'inlinecss_plain'    => '{{inlinecss file="css/email-inline.css"}}',
    'inlinecss_traversal' => '{{inlinecss file="../../app/etc/env.php"}}',

    // {{template}} - includes, and the config path that names them.
    // No case here resolves to a real template on purpose. A valid include returns a whole
    // document whose variables were live objects - `store`, `this` - and an object graph does
    // not survive a tape, so replaying one would compare a render against variables it no
    // longer has. `diff` renders every real template in the store end to end and is the tool
    // for that; the question here is what the INCLUDE DIRECTIVE asks its port for, which the
    // four cases below answer without dragging a document in.
    'template_missing'   => '{{template config_path="design/email/nope"}}',
    'template_empty'     => '{{template config_path=""}}',
    'template_absent'    => '{{template}}',
    'template_walk'      => '{{template config_path="../../etc/env"}}',

    // {{block}} / {{widget}} / {{layout}} - the class-instantiating three.
    'block_missing'      => '{{block class="No\\Such\\Klass"}}',
    'block_admin'        => '{{block class="Magento\\Backend\\Block\\Template"}}',
    'block_area'         => '{{block class="Magento\\Framework\\View\\Element\\Template" area="adminhtml" template="Magento_Backend::page/js/require_js.phtml"}}',
    'widget_missing'     => '{{widget type="No\\Such\\Widget"}}',
    // Widgets that actually RENDER on the CMS surface. A construct where both sides produce
    // nothing agrees vacuously and proves nothing - which is what {{widget}} coverage was
    // until now, and what {{layout}}'s corpus cases still are.
    'widget_cms_block'   => '{{widget type="Magento\\Cms\\Block\\Widget\\Block" template="widget/static_block/default.phtml" block_id="1"}}',
    // Not Page\Link: that block generates a random DOM id per render, so it can never agree
    // with anything, including itself. A nondeterministic construct is not a fixture.
    // The four stock item-table handles, against REAL orders. A handle that renders nothing
    // agrees with the filter vacuously and proves nothing, which is what {{layout}} coverage
    // was until this store had sample data in it: these produce ~2.3KB of item table each.
    'layout_order'       => '{{layout handle="sales_email_order_items" order_id="1"}}',
    'layout_order_two'   => '{{layout handle="sales_email_order_items" order_id="2"}}',
    'layout_invoice'     => '{{layout handle="sales_email_order_invoice_items" invoice_id="1" order_id="1"}}',
    'layout_shipment'    => '{{layout handle="sales_email_order_shipment_items" shipment_id="1" order_id="1"}}',
    'layout_ship_track'  => '{{layout handle="sales_email_order_shipment_track" shipment_id="1" order_id="1"}}',
    'layout_creditmemo'  => '{{layout handle="sales_email_order_creditmemo_items" creditmemo_id="1" order_id="2"}}',
    // No order at all, and a handle nobody allowed: the guard, and the empty case.
    'layout_no_order'    => '{{layout handle="sales_email_order_items"}}',
    'layout_denied'      => '{{layout handle="customer_account_edit" order_id="1"}}',
    'layout_area_admin'  => '{{layout handle="sales_email_order_items" order_id="1" area="adminhtml"}}',
    'layout_template'    => '{{layout handle="sales_email_order_items" order_id="1" template="Magento_Backend::page/js/require_js.phtml"}}',
    'layout_absent'      => '{{layout}}',
];

/** Variable sets, including the values that were live exploits. */
const VARIABLE_SETS = [
    'empty' => [],
    'values' => [
        'path' => 'catalog/product/cache/x.jpg',
        'name' => "O'Brien <o@example.com>",
        'js' => 'javascript&#58alert(document.domain)',
    ],
    'hostile' => [
        'path' => '../../app/etc/env.php',
        'name' => '"><script>alert(1)</script>',
        'js' => 'javascript&#58alert(document.domain)',
    ],
];

// The handles the stock sales emails use - the same list `--allow-layout-handle=stock-email`
// supplies, because the point is to measure what a store already does.
$factory = new EngineFactory($magento, [
    'sales_email_order_items',
    'sales_email_order_invoice_items',
    'sales_email_order_shipment_items',
    'sales_email_order_shipment_track',
    'sales_email_order_creditmemo_items',
]);
$stores = new StoreEmulator($magento);
// The SAME emulator. Magento's Emulation does not nest and its stop restores unconditionally,
// so a renderer holding its own would tear down the one this tool renders inside, and every
// case after the first would resolve {{css}} and {{view}} against no theme at all.
$legacy = new LegacyRenderer($magento, $stores);
$storeId = null;

// Which ports this host can supply. Built once for the shape only - the ports themselves are
// rebuilt inside the emulation below, because Asset\Repository caches its design defaults on
// first use and a set built outside resolves `{{view}}` against the wrong theme for the rest
// of the run. The Auditor builds its engine inside the emulation for the same reason.
$shape = [];
foreach (['blocks', 'translator', 'templates', 'config', 'customVariables', 'urls',
          'stylesheets', 'layouts', 'widgets', 'templateUrls'] as $port) {
    $shape[$port] = $factory->hostServices($storeId)->{$port} !== null;
}

$spec = new DirectiveSpec();
$cases = [];

/**
 * The one case this process was asked for, or null to drive the whole run.
 *
 * Magento's services are shared and stateful, and a hostile case can leave one in a mode that
 * changes every render after it. `{{store _type="../.."}}` does exactly that: the filter hands
 * the type to the URL model, which keeps it, and every subsequent legacy {{store}} in the
 * process then fails with "Invalid base url type". Fifteen cases were recorded against a
 * poisoned model before this - a `legacy` value that is not what that template does.
 *
 * So a case is recorded in a process of its own. Bootstrapping this store costs 0.06s, which
 * is a price worth paying for a fixture whose whole value is that its recorded values are
 * true.
 */
$only = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--case=')) {
        $only = substr($arg, 7);
    }
}

foreach (VARIABLE_SETS as $vlabel => $variables) {
    foreach (CONSTRUCTS as $clabel => $template) {
        foreach ([false, true] as $plainText) {
            $id = sprintf('%s/%s%s', $clabel, $vlabel, $plainText ? '/plain' : '');
            if ($only !== null && $only !== $id) {
                continue;
            }

            $tape = new PortTape();

            // BOTH sides inside ONE emulation, which is how the Auditor does it and is not a
            // detail: `{{view}}` resolves its theme from the design in force, so a legacy
            // render outside the emulation reports `_view` while ours inside it reports
            // `Magento/luma`, and the engines are blamed for the arrangement. Every stock
            // template using {{view}} agrees under `diff`, which is what says this is the
            // right way round.
            [$render, $ours, $rendered] = $stores->around($storeId, static function () use (
                $spec, $factory, $storeId, $tape, $template, $variables, $plainText, $legacy
            ): array {
                // The filter, on the same store, through the model that sends the email.
                // HTML only: LegacyRenderer drives the email pipeline, and a plain-text
                // render would be a second pipeline this tool does not model.
                $render = $plainText ? null : $legacy->render($template, $variables, $storeId);

                // The variables the MODEL handed its filter, not the ones declared above: an
                // email template model adds `store`, `logo_url` and a dozen more of its own,
                // and rendering our side without them compares two different inputs.
                $rendered = $render?->variables ?: $variables;

                $options = Mode::Compatible->options();
                $evaluator = new Evaluator(spec: $spec, options: $options);
                HostDirectives::register(
                    $evaluator,
                    TapedServices::recording($factory->hostServices($storeId), $tape),
                    new Parser($spec, $options)
                );
                $engine = new TemplateEngine(new Parser($spec, $options), $evaluator);

                try {
                    $out = $engine->render(
                        $template,
                        context: new Context($rendered, RenderPolicy::unrestricted(), $plainText)
                    );
                } catch (\Throwable $e) {
                    return [$render, ['throw', (new ReflectionClass($e))->getShortName()], $rendered];
                }

                // Whatever the host does to a finished render it does to both sides. The
                // filter inlines its stylesheets at the end of filter(); this engine defers
                // that to its host, so without replaying it here every template carrying one
                // differs by the whole of its inlined CSS.
                if ($render?->finish !== null) {
                    $out = ($render->finish)($out);
                }

                return [$render, ['ok', $out], $rendered];
            });

            $legacyOutput = $render?->output;

            $cases[] = [
                'id' => $id,
                'template' => $template,
                'variables' => scalarsOnly($rendered),
                'plain_text' => $plainText,
                'surface' => 'email',
                'outcome' => $ours[0],
                'expected' => $ours[1],
                'tape' => $tape->entries(),
                'legacy' => $legacyOutput,
                'agreed' => $legacyOutput !== null && $ours[0] === 'ok' && $legacyOutput === $ours[1],
            ];
        }
    }
}

/*
 * The CMS surface.
 *
 * Email\Model\Template\Filter extends the framework base and therefore has no widgetDirective
 * at all, so {{widget}} can never be COMPARED there - only observed as a capability this engine
 * adds. Cms\Model\Template\Filter extends Widget\Model\Template\Filter and does implement it.
 * Without this pass {{widget}} had no surface anywhere that could tell agreement from
 * disagreement, and neither did the difference between the two surfaces.
 *
 * Once per construct: a CMS render takes no variables and has no plain-text mode.
 */
foreach (CONSTRUCTS as $clabel => $template) {
    if ($only !== null && $only !== $clabel . '/cms') {
        continue;
    }

    $tape = new PortTape();

    [$render, $ours] = $stores->around($storeId, static function () use (
        $spec, $factory, $storeId, $tape, $template, $legacy
    ): array {
        $render = $legacy->render($template, [], $storeId, TemplateSubject::KIND_CMS);

        $options = Mode::Compatible->options();
        $evaluator = new Evaluator(spec: $spec, options: $options);
        HostDirectives::register(
            $evaluator,
            TapedServices::recording($factory->hostServices($storeId), $tape),
            new Parser($spec, $options)
        );
        $engine = new TemplateEngine(new Parser($spec, $options), $evaluator);

        try {
            return [$render, ['ok', $engine->render($template, context: new Context([], RenderPolicy::unrestricted()))]];
        } catch (\Throwable $e) {
            return [$render, ['throw', (new ReflectionClass($e))->getShortName()]];
        }
    });

    $legacyOutput = $render?->output;

    $cases[] = [
        'id' => $clabel . '/cms',
        'template' => $template,
        'variables' => [],
        'plain_text' => false,
        'surface' => 'cms',
        'outcome' => $ours[0],
        'expected' => $ours[1],
        'tape' => $tape->entries(),
        'legacy' => $legacyOutput,
        'agreed' => $legacyOutput !== null && $ours[0] === 'ok' && $legacyOutput === $ours[1],
    ];
}

// A child has exactly one case by now, and prints it for the driver to collect.
if ($only !== null) {
    if ($cases === []) {
        fwrite(STDERR, "no such case: " . $only . "\n");
        exit(1);
    }
    echo json_encode($cases[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

/*
 * Driving mode: one child process per case, so no case can see what another left behind.
 *
 * The children are this same file with `--case=`, which keeps the case definitions in one
 * place. A child prints one JSON object; anything else on its stdout is a bug in the child and
 * is reported rather than swallowed, because a case silently missing from the fixture is a
 * case silently not tested.
 */
if ($only === null) {
    $ids = [];
    foreach (VARIABLE_SETS as $vlabel => $variables) {
        foreach (CONSTRUCTS as $clabel => $template) {
            foreach ([false, true] as $plainText) {
                $ids[] = sprintf('%s/%s%s', $clabel, $vlabel, $plainText ? '/plain' : '');
            }
        }
    }
    foreach (CONSTRUCTS as $clabel => $template) {
        $ids[] = $clabel . '/cms';
    }

    $cases = [];
    $failed = [];
    foreach ($ids as $id) {
        $command = sprintf(
            '%s %s --case=%s 2>/dev/null',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__FILE__),
            escapeshellarg($id)
        );
        $raw = (string)shell_exec($command);
        $decoded = json_decode(trim($raw), true);
        if (!is_array($decoded) || !isset($decoded['id'])) {
            $failed[$id] = trim(substr($raw, 0, 160));
            continue;
        }
        $cases[] = $decoded;
    }

    if ($failed !== []) {
        fwrite(STDERR, sprintf("FATAL: %d case(s) produced no result:\n", count($failed)));
        foreach (array_slice($failed, 0, 5, true) as $id => $why) {
            fwrite(STDERR, sprintf("  %s: %s\n", $id, $why === '' ? '(no output)' : $why));
        }
        exit(1);
    }
}

$out = [
    'recorded_against' => [
        'note' => 'Values here are this store\'s. Re-recording elsewhere will change them; what '
            . 'must not change without a reason is the shape of each tape.',
        'agreement' => 'The `legacy` and `agreed` fields are CONTEXT, not an assertion. '
            . 'AbstractTemplate::getProcessedTemplate() applies its own design config and '
            . 'cancels it again, so in a long-lived CLI process the design state a directive '
            . 'sees depends on what ran before it - Asset\\Repository caches its defaults on '
            . 'first use, and an isolated {{css}} or {{view}} can resolve a different theme '
            . 'here than the same directive inside a real template. `template-parser diff` is '
            . 'the end-to-end parity measure and renders whole templates; this file exists for '
            . 'the tapes, which are store-independent and are where the guards live.',
        'ports' => $shape,
    ],
    'cases' => $cases,
];

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    fwrite(STDERR, 'FATAL: json_encode failed: ' . json_last_error_msg() . PHP_EOL);
    exit(1);
}

$target = dirname(__DIR__) . '/tests/fixtures/legacy/store-ports.json';
file_put_contents($target, $json);

$agreed = count(array_filter($cases, static fn ($c) => $c['agreed']));
$comparable = count(array_filter($cases, static fn ($c) => $c['legacy'] !== null));
printf(
    "recorded %d cases over %d constructs: %d/%d agree with the filter -> %s\n",
    count($cases),
    count(CONSTRUCTS),
    $agreed,
    $comparable,
    $target
);
