<?php
declare(strict_types=1);

require __DIR__ . '/magento-stubs.php';
// FakeDataObject stands in for Magento's DataObject; the parity replay and the
// resolver tests both need it, so it is loaded once here.
require __DIR__ . '/fixtures/legacy/ObjectFixtures.php';

spl_autoload_register(static function (string $class): void {
    foreach ([
        'Cresset\\TemplateParser\\Test\\' => __DIR__ . '/',
        'Cresset\\TemplateParser\\'       => __DIR__ . '/../src/',
    ] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
});
