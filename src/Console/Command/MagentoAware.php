<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Command;

use Cresset\TemplateParser\Console\MagentoContext;
use Cresset\TemplateParser\Console\Mode;
use Cresset\TemplateParser\Magento\AllowlistedLayoutRenderer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared wiring for commands that may or may not have a store.
 *
 * The context is injected when something else already booted Magento - n98-magerun2 does,
 * and booting a second time inside it would be wrong - and detected otherwise. Commands only
 * ever call magento(), so neither of them has to know which happened.
 */
trait MagentoAware
{
    private ?MagentoContext $injectedMagento = null;

    public function setMagentoContext(MagentoContext $context): static
    {
        $this->injectedMagento = $context;

        return $this;
    }

    protected function magento(): MagentoContext
    {
        return $this->injectedMagento ??= MagentoContext::detect();
    }

    /**
     * The engine posture: how strictly this engine reads a template.
     *
     * Called the posture, not the mode, because "mode" now belongs to the rollout - Legacy,
     * Shadow, Parser in `system/template_engine/mode` - and the two sit side by side under
     * bin/magento. They are different axes: the posture is how the new engine behaves, the
     * rollout stage is whether a store view uses it. Parser mode runs the compatible posture,
     * which is why that is the default here.
     *
     * `--mode` still works for one release, with a warning, so scripts written against 0.2
     * keep running while they are updated - including `--mode=legacy`, which 0.2 accepted as a
     * spelling of compatible and `--posture` no longer does.
     */
    protected function addPostureOption(): static
    {
        $this->addOption(
            'posture',
            'p',
            InputOption::VALUE_REQUIRED,
            'Engine posture: ' . implode(', ', Mode::names()) . ' [default: compatible, what Parser mode runs]'
        );
        $this->addOption(
            'mode',
            'm',
            InputOption::VALUE_REQUIRED,
            'Deprecated spelling of --posture; removed in the next release'
        );

        return $this;
    }

    /**
     * Reads --posture, or the deprecated --mode, before any work starts.
     *
     * The warning goes to stderr so a `--format=json` run still prints only JSON.
     */
    protected function posture(InputInterface $input, OutputInterface $output): Mode
    {
        $posture = $input->getOption('posture');
        $legacy = $input->getOption('mode');

        if ($legacy !== null) {
            // 0.2 read "legacy" as compatible; honour that on the old option only.
            if (strtolower(trim((string)$legacy)) === 'legacy') {
                $legacy = Mode::Compatible->value;
            }
            $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errors->writeln('<comment>--mode is deprecated; use --posture. It will be removed in the next release.</comment>');

            if ($posture !== null && Mode::parse((string)$posture) !== Mode::parse((string)$legacy)) {
                throw new \InvalidArgumentException(sprintf(
                    '--posture=%s and --mode=%s disagree; pass only --posture.',
                    (string)$posture,
                    (string)$legacy
                ));
            }
        }

        return Mode::parse((string)($posture ?? $legacy ?? Mode::Compatible->value));
    }

    /**
     * Layout handles this run may render.
     *
     * Off by default and it stays that way: a layout handle decides which blocks get built,
     * so template text is not a trustworthy source for one. Naming them is how {{layout}}
     * resolves at all - without any, the directive stays unregistered and every stock sales
     * email reports as a difference, which is honest but not useful when what you wanted was
     * to see the order table.
     *
     * @return static
     */
    protected function addLayoutOption(): static
    {
        $this->addOption(
            'allow-layout-handle',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Layout handle {{layout}} may render; repeat for more, or "stock-email" for the ones stock emails use'
        );

        return $this;
    }

    /**
     * The handles the stock sales emails need, as a shorthand.
     *
     * Every one of these is already reachable from a template the store ships, so allowing
     * them grants nothing a stock installation does not already do.
     *
     * @return string[]
     */
    protected function layoutHandles(InputInterface $input): array
    {
        $handles = (array)$input->getOption('allow-layout-handle');

        if (in_array('stock-email', $handles, true)) {
            $handles = array_merge(array_diff($handles, ['stock-email']), AllowlistedLayoutRenderer::STOCK_EMAIL_HANDLES);
        }

        return array_values(array_unique(array_filter($handles, 'is_string')));
    }
}
