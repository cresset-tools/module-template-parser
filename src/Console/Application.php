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
 * Commands are built here and nowhere else, so the n98-magerun2 module can register exactly
 * the same list without this class - see commands(). Anything that only works in one of the
 * two entrypoints is a bug.
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
     * @param MagentoContext|null $context an already-booted Magento, when the host has one
     * @return Command[]
     */
    public static function commands(?MagentoContext $context = null): array
    {
        $commands = [new ReplCommand(), new CheckCommand(), new DiffCommand()];

        if ($context !== null) {
            foreach ($commands as $command) {
                /** @phpstan-ignore-next-line every command in this list uses MagentoAware */
                $command->setMagentoContext($context);
            }
        }

        return $commands;
    }
}
