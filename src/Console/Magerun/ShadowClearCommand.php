<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Magerun;

use Cresset\TemplateParser\Console\Command\ShadowClearCommand as BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/** The standalone shadow:clear command, renamed for magerun. See Magerun\CheckCommand. */
#[AsCommand(name: 'template-parser:shadow:clear')]
class ShadowClearCommand extends BaseCommand
{
    use MagerunBridge;
}
