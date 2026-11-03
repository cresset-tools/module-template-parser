<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use Magento\Framework\Interception\DefinitionInterface;
use PHPUnit\Framework\TestCase;

/**
 * The plugin chain as Magento's own PluginList answers it, not as a fake does.
 *
 * Every fake plugin list in this suite once answered getNext() with 'before', 'after' and
 * 'around' keys. Magento's answers with DefinitionInterface::LISTENER_* integers, so the code
 * written against the fakes read nothing on a store: the plugins inside this one never ran on
 * a Parser render, an around plugin inside was never refused, and template:check named no
 * plugin. This builds the chain with Magento's PluginListGenerator and Interceptor trait,
 * in a process of its own (fixtures/real-plugin-list.php), so the fakes cannot drift from it
 * again unnoticed.
 *
 * Needs MAGENTO_ROOT: a Magento or Mage-OS install with its vendor/ - skipped without one.
 */
final class RealPluginListTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function realChain(): array
    {
        $root = rtrim((string)getenv('MAGENTO_ROOT'), '/');
        if ($root === '' || !is_file($root . '/vendor/autoload.php')) {
            self::markTestSkipped('MAGENTO_ROOT is not set to a Magento install with vendor/autoload.php');
        }

        $command = [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/real-plugin-list.php'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['MAGENTO_ROOT' => $root] + getenv());
        self::assertIsResource($process);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        $status = proc_close($process);

        if (str_contains($out . $err, 'Composer detected issues in your platform')) {
            self::markTestSkipped('the install under MAGENTO_ROOT needs another PHP version than ' . PHP_VERSION);
        }
        self::assertSame(0, $status, $out . $err);

        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheStubsKeysAreMagentos(): void
    {
        self::assertSame(
            ['before' => DefinitionInterface::LISTENER_BEFORE, 'around' => DefinitionInterface::LISTENER_AROUND, 'after' => DefinitionInterface::LISTENER_AFTER],
            self::realChain()['constants']
        );
    }

    public function testThePluginsInsideThisOneAreFound(): void
    {
        $result = self::realChain();

        self::assertSame(['before' => [], 'after' => ['inside_after'], 'around' => null], array_diff_key($result['inside'], ['type' => 0]));
        self::assertSame('inside_around', $result['around_inside']['around']);
        self::assertNull($result['nothing_inside']);
    }

    public function testEveryPluginOnAPluggedDirectiveIsNamed(): void
    {
        self::assertSame(['var_after', 'var_after_inside', 'var_around'], self::realChain()['var']['var']['plugins']);
    }
}
