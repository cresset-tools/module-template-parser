<?php
declare(strict_types=1);

/*
 * A ceiling, so a runaway test dies with a fatal and a stack trace rather than taking the
 * machine with it.
 *
 * Not hypothetical. A mutation that made {{var}} re-parse its own output recursed without a
 * bound; the run was backgrounded on a tool timeout, kept going unattended for two and a half
 * hours, reached 48 GB and invoked the kernel OOM killer on a 62 GB box. bougie launches PHP
 * with `-d memory_limit=-1`, so nothing else was going to stop it.
 *
 * And a floor, because the suite peaks near 240 MB - LegacyParityTest's replay of the corpus
 * is most of it - and php-cli's stock 128M died a little under half way through, on exactly
 * the `php vendor/bin/phpunit` a contributor runs first.
 *
 * Here rather than in phpunit.xml because PHPUnit does not apply `memory_limit` from its
 * `<ini>` block - the setting is silently ignored there, which is worse than not having it.
 */
$limit = ini_get('memory_limit');
$bytes = static function (string $limit): int {
    $value = (int)$limit;
    return match (strtolower(substr(trim($limit), -1))) {
        'g' => $value * 1024 ** 3,
        'm' => $value * 1024 ** 2,
        'k' => $value * 1024,
        default => $value,
    };
};
if ($limit === '-1') {
    ini_set('memory_limit', '2G');
} elseif ($bytes((string)$limit) < 512 * 1024 ** 2) {
    ini_set('memory_limit', '512M');
}
unset($limit, $bytes);

require __DIR__ . '/magento-stubs.php';
// FakeDataObject stands in for Magento's DataObject; the parity replay and the
// resolver tests both need it, so it is loaded once here.
require __DIR__ . '/fixtures/legacy/ObjectFixtures.php';

spl_autoload_register(static function (string $class): void {
    foreach ([
        'MageOS\\TemplateParser\\Test\\'  => __DIR__ . '/',
        'MageOS\\TemplateParser\\Setup\\' => __DIR__ . '/../Setup/',
        'MageOS\\TemplateParser\\'        => __DIR__ . '/../src/',
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
