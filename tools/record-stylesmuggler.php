<?php
/**
 * Records what the LEGACY filter does with the StyleSmuggler payload.
 *
 * This is the case the whole engine exists for, so it must be captured as evidence rather
 * than asserted from memory. Reproduces the real two-stage flow: the address formatter
 * renders the poisoned fields, and its output is then handed to the email template as a
 * variable. Both stages share one SignatureProvider and one FilteringDepthMeter, exactly as
 * they do inside a single request.
 *
 * A benign canary replaces layout->createBlock: it records the class name and constructs
 * nothing, so this never builds the attacker's object.
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
foreach (['/DirectiveProcessorInterface.php','/VariableResolverInterface.php','/Template/FilteringDepthMeter.php',
 '/Template/SignatureProvider.php','/Template/Tokenizer/AbstractTokenizer.php','/Template/Tokenizer/Parameter.php',
 '/Template/Tokenizer/Variable.php','/VariableResolver/StrictResolver.php','/DirectiveProcessor/Filter/FilterApplier.php',
 '/DirectiveProcessor/Filter/FilterPool.php','/DirectiveProcessor/VarDirective.php','/DirectiveProcessor/IfDirective.php',
 '/DirectiveProcessor/DependDirective.php','/DirectiveProcessor/SimpleDirective.php','/DirectiveProcessor/LegacyDirective.php',
 '/DirectiveProcessor/TemplateDirective.php','/SimpleDirective/ProcessorPool.php','/Template.php'] as $f) { require $base . $f; }

use Magento\Framework\Stdlib\StringUtils; use Magento\Framework\Math\Random;
use Magento\Framework\Filter\Template as LegacyTemplate;
use Magento\Framework\Filter\Template\{FilteringDepthMeter, SignatureProvider};
use Magento\Framework\Filter\VariableResolver\StrictResolver;
use Magento\Framework\Filter\DirectiveProcessor\{IfDirective, DependDirective, TemplateDirective, SimpleDirective, LegacyDirective, VarDirective};
use Magento\Framework\Filter\DirectiveProcessor\Filter\{FilterApplier, FilterPool};
use Magento\Framework\Filter\SimpleDirective\ProcessorPool;
use Magento\Framework\Filter\Template\Tokenizer\{VariableFactory, ParameterFactory};

final class Canary { public static array $hits = []; }

/** Email\Model\Template\Filter's blockDirective, with the canary standing in for createBlock. */
class SmugglerEmailLikeLegacy extends LegacyTemplate {
    protected $_modifiers = ['nl2br' => ''];
    public function __construct(...$a) { parent::__construct(...$a); $this->_modifiers['escape'] = [$this, 'esc']; }
    public function esc($v, $t = 'html') { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
    public function varDirective($c) {
        if (count($this->templateVars) == 0) { return $c[0]; }
        [$d, $m] = $this->explodeModifiers($c[2] . ($c['filters'] ?? ''), 'escape');
        return $this->applyModifiers($this->getVariable($d, ''), $m);
    }
    protected function explodeModifiers($v, $def = null) {
        $p = $v !== null ? explode('|', $v, 2) : []; return 2 === count($p) ? $p : [$v, $def];
    }
    protected function applyModifiers($v, $mods) {
        foreach (($mods !== null ? explode('|', $mods) : []) as $part) {
            if (empty($part)) { continue; }
            $params = explode(':', $part); $mod = array_shift($params);
            if (isset($this->_modifiers[$mod])) {
                $cb = $this->_modifiers[$mod] ?: $mod; array_unshift($params, $v); $v = $cb(...$params);
            }
        }
        return $v;
    }
    public function blockDirective($c) {
        $p = $this->getParameters($c[2]);
        if (isset($p['class'])) { Canary::$hits[] = $p['class']; return '[BLOCK-RENDERED]'; }
        return '';
    }
}

$sig = new SignatureProvider(new Random());
$depth = new FilteringDepthMeter();
$str = new StringUtils();
$resolver = new StrictResolver(new VariableFactory());
\Magento\Framework\App\ObjectManager::$registry[VarDirective::class] = new VarDirective($resolver, new FilterApplier(new FilterPool()));
$procs = static function () use ($resolver) {
    $simple = new SimpleDirective(new ProcessorPool(), new ParameterFactory(), $resolver, new FilterApplier(new FilterPool()));
    return ['depend' => new DependDirective($resolver), 'if' => new IfDirective($resolver),
            'template' => new TemplateDirective($resolver, new ParameterFactory()), 'legacy' => new LegacyDirective($simple)];
};

// The stock html address format, verbatim from Customer/etc/config.xml.
preg_match('#<html><!\[CDATA\[(.*?)\]\]></html>#s',
    (string)file_get_contents(MROOT . '/app/code/Magento/Customer/etc/config.xml'), $m);
$addressFormat = $m[1];

$BLOCK  = '{{block class=Magento\Email\Block\Adminhtml\Template\Preview}}';
$MIRROR = '{{if postcode}}{{var postcode}}{{/if}}';
$street = $MIRROR . '{{/var}}' . $MIRROR . '{{if city}}' . $BLOCK . '{{/if}}';

$addressVars = [
    'firstname' => 'Jan', 'lastname' => 'Jansen', 'street1' => $street,
    'city' => 'Andorra la Vella', 'region' => '', 'postcode' => '{{var postcode}}',
    'country' => 'Andorra', 'telephone' => '', 'company' => '', 'fax' => '', 'vat_id' => '',
    'prefix' => '', 'middlename' => '', 'suffix' => '', 'street2' => '', 'street3' => '', 'street4' => '',
];
$emailTemplate = 'Billing Address:<br>{{var billingAddressHtml|raw}}';

// Stage 1: the address formatter.
Canary::$hits = [];
$addr = new LegacyTemplate($str, [], $procs(), $resolver, $sig, $depth);
$addr->setVariables($addressVars);
$addressHtml = $addr->filter($addressFormat);
$hitsAfterAddress = Canary::$hits;

// Stage 2: the email render, taking that output as a variable.
Canary::$hits = [];
$email = new SmugglerEmailLikeLegacy($str, [], $procs(), $resolver, $sig, $depth);
$email->setVariables(['billingAddressHtml' => $addressHtml]);
$emailOutput = $email->filter($emailTemplate);

$signature = $sig->get();
$record = [
    'description'          => 'StyleSmuggler: signature confusion in Framework\\Filter\\Template',
    'address_format'       => $addressFormat,
    'address_variables'    => $addressVars,
    'email_template'       => $emailTemplate,
    'legacy' => [
        'signature'                 => $signature,
        'address_html'              => $addressHtml,
        'signature_in_address_html' => str_contains($addressHtml, $signature),
        'blocks_during_address'     => $hitsAfterAddress,
        'blocks_during_email'       => Canary::$hits,
        'email_output'              => $emailOutput,
    ],
];
file_put_contents(PKGROOT . '/tests/fixtures/legacy/stylesmuggler.json',
    json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

printf("legacy signature minted        : %s\n", $signature);
printf("signature reached address html : %s\n", $record['legacy']['signature_in_address_html'] ? 'YES' : 'no');
printf("blocks instantiated (address)  : %s\n", $hitsAfterAddress ? implode(', ', $hitsAfterAddress) : 'none');
printf("blocks instantiated (email)    : %s\n", Canary::$hits ? implode(', ', Canary::$hits) : 'none');
printf("-> recorded to tests/fixtures/legacy/stylesmuggler.json\n");
