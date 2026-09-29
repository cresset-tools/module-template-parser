<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Magerun;

use Cresset\TemplateParser\Console\Command\ShadowReportCommand as BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/** The standalone shadow:report command, renamed for magerun. See Magerun\CheckCommand. */
#[AsCommand(name: 'template-parser:shadow:report')]
class ShadowReportCommand extends BaseCommand
{
    use MagerunBridge;
}
