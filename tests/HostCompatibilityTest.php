<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use PHPUnit\Framework\TestCase;

/**
 * Nothing this module ships names, as code, a class that only some hosts have.
 *
 * Magento's DI reflects every constructor parameter's type before it builds an object, and
 * `setup:di:compile` reflects them all up front. One parameter typed against a class the host
 * lacks therefore breaks the whole plugin chain - not just the feature that uses it. The test
 * suite cannot see that by running, because tests/magento-stubs.php defines every class it
 * needs; so it reads the source instead. A class listed here may still be used by name, as a
 * string, behind class_exists().
 */
final class HostCompatibilityTest extends TestCase
{
    /** Classes missing from Magento Open Source 2.4.x or from some supported Mage-OS release. */
    private const NOT_ON_EVERY_HOST = [
        // Mage-OS main since 2026-09-08; absent from Magento Open Source and Mage-OS <= 3.4.0.
        'Magento\\Email\\Model\\Template\\Filter\\BlockDirectivePolicy',
    ];

    /** @return iterable<string, array{string}> */
    public static function shippedFiles(): iterable
    {
        $root = dirname(__DIR__);
        foreach (['src', 'Setup'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    $relative = substr($file->getPathname(), strlen($root) + 1);
                    yield $relative => [$relative];
                }
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('shippedFiles')]
    public function testNoHostSpecificClassIsNamedAsCode(string $file): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/' . $file);
        $named = [];
        foreach (\PhpToken::tokenize($source) as $token) {
            if (!$token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }
            $name = ltrim($token->text, '\\');
            foreach (self::NOT_ON_EVERY_HOST as $class) {
                if (strcasecmp($name, $class) === 0) {
                    $named[] = $class . ' on line ' . $token->line;
                }
            }
        }

        self::assertSame([], $named, "$file names a class not every supported host has; look it up by name instead");
    }

    public function testTheDiConfigurationDoesNotInjectOne(): void
    {
        foreach (glob(dirname(__DIR__) . '/etc/{,*/}di.xml', GLOB_BRACE) ?: [] as $file) {
            $xml = (string)file_get_contents($file);
            foreach (self::NOT_ON_EVERY_HOST as $class) {
                self::assertStringNotContainsString($class, $xml, basename(dirname($file)) . '/' . basename($file));
            }
        }
    }
}
