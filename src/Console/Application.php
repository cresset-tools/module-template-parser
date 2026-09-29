<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

use Cresset\TemplateParser\Console\Command\CheckCommand;
use Cresset\TemplateParser\Console\Command\DiffCommand;
use Cresset\TemplateParser\Console\Command\ReplCommand;
use Cresset\TemplateParser\Console\Command\ShadowClearCommand;
use Cresset\TemplateParser\Console\Command\ShadowReportCommand;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;

/**
 * The standalone CLI.
 *
 * The command list is written three times: commands() here; customCommands in
 * n98-magerun2.yaml, which names the Console\Magerun\* subclasses - magerun registers classes
 * and builds them itself, so it can inject the ObjectManager it has already booted; and the
 * CommandListInterface entry in etc/di.xml, which names the Console\Magento\* subclasses for
 * bin/magento. The subclasses only rename and inject, so anything that only works in some of
 * the entrypoints is a bug; ConsoleTest::testEveryCommandIsAvailableInEveryEntrypoint holds
 * the three lists together.
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
        return [
            new ReplCommand(),
            new CheckCommand(),
            new DiffCommand(),
            new ShadowReportCommand(),
            new ShadowClearCommand(),
        ];
    }
}
