<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Config\Source;

use Cresset\TemplateParser\Magento\Config\EngineMode;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The stages an admin can choose for `system/template_engine/mode`.
 *
 * No Parser. Rendering through this engine is only safe with a fallback to the legacy filter
 * for whatever it refuses, and until that exists (issue #2) offering the option would put the
 * one choice that can break an email in the same dropdown as the two that cannot.
 */
class EngineModeOptions implements OptionSourceInterface
{
    /** @return list<array{value:string,label:mixed}> */
    public function toOptionArray(): array
    {
        return [
            ['value' => EngineMode::LEGACY, 'label' => __('Legacy')],
            ['value' => EngineMode::SHADOW, 'label' => __('Shadow (render both, serve legacy)')],
        ];
    }
}
