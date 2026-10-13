<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Console;

use MageOS\TemplateParser\Console\Command\CheckCommand;
use MageOS\TemplateParser\Console\Command\DiffCommand;
use MageOS\TemplateParser\Console\Command\ReplCommand;
use MageOS\TemplateParser\Console\Command\ShadowClearCommand;
use MageOS\TemplateParser\Console\Command\ShadowReportCommand;
use MageOS\TemplateParser\Console\Command\StatusCommand;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;

/**
 * The standalone CLI.
 *
 * The command list is written twice: commands() here, and the CommandListInterface entry in
 * etc/di.xml, which names the Console\Magento\* subclasses for bin/magento - and through it for
 * n98-magerun2, which lists Magento's own commands. The subclasses only rename and inject, so
 * anything that only works in one entrypoint is a bug;
 * ConsoleTest::testEveryCommandIsAvailableInEveryEntrypoint holds the two lists together.
 */
class Application extends ConsoleApplication
{
    public const VERSION = '0.3.0';

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
        return [
            new ReplCommand(),
            new CheckCommand(),
            new DiffCommand(),
            new ShadowReportCommand(),
            new ShadowClearCommand(),
            new StatusCommand(),
        ];
    }
}
