<?php
declare(strict_types=1);

/*
 * A ceiling, so a runaway test dies with a fatal and a stack trace rather than taking the
 * machine with it.
 *
 * Not hypothetical. A mutation that made {{var}} re-parse its own output recursed without a
 * bound; the run was backgrounded on a tool timeout, kept going unattended for two and a half
 * hours, reached 48 GB and invoked the kernel OOM killer on a 62 GB box. bougie launches PHP
 * with `-d memory_limit=-1`, so nothing else was going to stop it. The suite peaks near 130 MB.
 *
 * Here rather than in phpunit.xml because PHPUnit does not apply `memory_limit` from its
 * `<ini>` block - the setting is silently ignored there, which is worse than not having it.
 *
 * Only the unlimited case is touched. A deliberate lower limit, set to reproduce something,
 * is left exactly as it was.
 */
if (ini_get('memory_limit') === '-1') {
    ini_set('memory_limit', '2G');
}

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
