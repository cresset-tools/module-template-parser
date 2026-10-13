<?php
declare(strict_types=1);

/**
 * Records what the plain filter does with address formats, for AddressTemplateTest.
 *
 *   php tools/record-address-formats.php OUT.json      (from inside a store, set to Legacy)
 *
 * Renders through FilterManager::template() - the exact call Customer's DefaultRenderer and
 * Store's address Renderer make - so what is recorded is the plain Framework\Filter\Template
 * of that store. Record against a store whose framework is unpatched and whose template-engine
 * stage is Legacy, or the recording is of something else.
 *
 * The formats are the stock html, text, oneline and pdf formats from Magento_Customer's
 * config.xml, and the store address template. Each is rendered with ordinary data, with every
 * field empty, and with a set of directive-shaped values put into the fields a customer types:
 * the property under test is that a value is data, never template source.
 */

// Inert unless invoked directly - see tools/harness.php.
if (PHP_SAPI !== 'cli' || realpath($_SERVER['argv'][0] ?? '') !== __FILE__) {
    return;
}

use Magento\Framework\App\Bootstrap;

require getcwd() . '/app/bootstrap.php';
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

$config = simplexml_load_file(BP . '/vendor/mage-os/module-customer/etc/config.xml');
$formats = [];
foreach (['html', 'text', 'oneline', 'pdf'] as $code) {
    $formats[$code] = trim((string)$config->default->customer->address_templates->{$code});
}
$formats['store'] = (string)(new \ReflectionClassConstant(\Magento\Store\Model\Address\Renderer::class, 'DEFAULT_TEMPLATE'))->getValue();

$base = [
    'prefix' => 'Dr', 'firstname' => 'Jan', 'middlename' => '', 'lastname' => 'Jansen', 'suffix' => '',
    'company' => 'ACME', 'street' => "Main 1\nFloor 2", 'street1' => 'Main 1', 'street2' => 'Floor 2',
    'street3' => '', 'street4' => '', 'city' => 'Utrecht', 'region' => 'UT', 'postcode' => '1234 AB',
    'country' => 'Netherlands', 'telephone' => '+31 6', 'fax' => '', 'vat_id' => 'NL1',
    'name' => 'Store', 'street_line1' => 'S1', 'street_line2' => 'S2',
];
$values = [
    '{{var lastname}}', '{{var}}', '}}', '{{', '{{depend city}}x{{/depend}}', '{{/depend}}', '{{/if}}',
    '{{if city}}y{{else}}n{{/if}}', '{{trans "t"}}', '{{block id="footer"}}', '{{template config_path="a/b"}}',
    'a}}b{{c', '{{{{var city}}}}', '{{/var}}', '&amp; <b>', '0', '',
];
$fields = ['firstname', 'company', 'street1', 'street', 'city', 'name', 'street_line1'];

$cases = [];
foreach ($formats as $code => $format) {
    $cases["$code/ordinary"] = [$code, []];
    $cases["$code/empty"] = [$code, array_fill_keys(array_keys($base), '')];
    foreach ($values as $i => $value) {
        foreach ($fields as $field) {
            if (!str_contains($format, "{{var $field}}")) {
                continue;
            }
            $cases["$code/$field/$i"] = [$code, [$field => $value]];
        }
    }
}

$filters = $om->get(\Magento\Framework\Filter\FilterManager::class);
$out = ['formats' => $formats, 'base' => $base, 'cases' => []];
foreach ($cases as $name => [$code, $overrides]) {
    $out['cases'][$name] = [
        'format' => $code,
        'set' => $overrides,
        'legacy' => $filters->template($formats[$code], ['variables' => $overrides + $base]),
    ];
}

file_put_contents($argv[1], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
fwrite(STDERR, count($out['cases']) . " cases\n");
