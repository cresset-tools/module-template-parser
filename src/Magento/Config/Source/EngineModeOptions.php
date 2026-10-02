<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Config\Source;

use Cresset\TemplateParser\Magento\Config\EngineMode;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The stages an admin can choose for `system/template_engine/mode`, in rollout order.
 *
 * Parser is offered because it cannot do worse than legacy on anything this engine declines:
 * every refusal and every exception falls back to the legacy filter for that render. What it
 * can do is serve different output without raising, which is what a Shadow run beforehand -
 * and the sampled comparison Parser keeps running - measures.
 */
class EngineModeOptions implements OptionSourceInterface
{
    /** @return list<array{value:string,label:mixed}> */
    public function toOptionArray(): array
    {
        return [
            ['value' => EngineMode::LEGACY, 'label' => __('Legacy')],
            ['value' => EngineMode::SHADOW, 'label' => __('Shadow (render both, serve legacy)')],
            ['value' => EngineMode::PARSER, 'label' => __('Parser (serve the new engine, fall back to legacy)')],
        ];
    }
}
