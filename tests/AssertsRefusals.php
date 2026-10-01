<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Port\RefusedByPort;

/**
 * A port refusing out loud, where it used to return '' or null.
 *
 * Every such refusal is a place this engine renders less than the legacy filter would, and a
 * silent one was served by Parser mode as if it were complete. Thrown, it is recorded as a
 * policy violation and Parser falls back to the filter.
 */
trait AssertsRefusals
{
    private static function assertRefused(callable $call, string $kind, ?string $name = null): void
    {
        try {
            $call();
        } catch (RefusedByPort $refused) {
            self::assertSame($kind, $refused->kind);
            if ($name !== null) {
                self::assertSame($name, $refused->name);
            }
            return;
        }

        self::fail(sprintf('expected the port to refuse (%s), and it answered instead', $kind));
    }
}
