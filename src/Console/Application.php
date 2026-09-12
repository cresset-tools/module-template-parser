<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

use Cresset\TemplateParser\Console\Command\CheckCommand;
use Cresset\TemplateParser\Console\Command\DiffCommand;
use Cresset\TemplateParser\Console\Command\ReplCommand;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;

/**
 * The standalone CLI.
 *
 * The command list is written twice: commands() here, and customCommands in
 * n98-magerun2.yaml, which names the Console\Magerun\* subclasses instead - magerun registers
 * classes and builds them itself, so it can inject the ObjectManager it has already booted.
 * Those subclasses only rename and inject, so anything that only works in one of the two
 * entrypoints is a bug; ConsoleTest::testEveryCommandIsAvailableInBothEntrypoints holds the
 * two lists together.
 */
class Application extends ConsoleApplication
{
    public const VERSION = '0.1.0-dev';

    public static function create(): self
    {
        $application = new self('template-parser', self::VERSION);
        $application->addCommands(self::commands());

        return $application;
    }

    /**
     * Every command this package provides.
     *
     * @return Command[]
     */
    public static function commands(): array
    {
        return [new ReplCommand(), new CheckCommand(), new DiffCommand()];
    }
}
