<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Which engine a store view renders through: the rollout stage, read from configuration.
 *
 * Store-view scope because that is the unit a merchant can reason about - one locale's
 * emails, one brand's CMS - and because a Shadow run is only evidence for the store view it
 * ran in: its variables, its templates, its theme.
 *
 * Anything this does not recognise reads as Legacy. A mistyped value, or a stage a newer
 * release added and an older one is reading back, must fall to the engine that renders
 * exactly as before, never to one that does something the merchant did not choose.
 */
class EngineMode
{
    public const XML_PATH = 'system/template_engine/mode';

    /** Percentage of Parser renders also rendered through legacy and compared. */
    public const XML_PATH_PARSER_SHADOW_RATE = 'system/template_engine/parser_shadow_rate';

    public const LEGACY = 'legacy';
    public const SHADOW = 'shadow';
    public const PARSER = 'parser';

    /** Every stage this release can run, in rollout order. */
    public const ALL = [self::LEGACY, self::SHADOW, self::PARSER];

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /** @param int|string|null $storeId null means whichever store is current */
    public function forStore(int|string|null $storeId): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH, ScopeInterface::SCOPE_STORE, $storeId);
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, self::ALL, true) ? $value : self::LEGACY;
    }

    /** @param int|string|null $storeId */
    public function isShadow(int|string|null $storeId): bool
    {
        return $this->forStore($storeId) === self::SHADOW;
    }

    /**
     * The share of Parser renders that are also compared against legacy, as a percentage.
     *
     * Read only for a store in Parser mode, so it costs nothing anywhere else. Anything that
     * is not a number reads as zero rather than the default: a value someone typed and got
     * wrong is better answered with no sampling than with a rate they did not choose, and the
     * shipped default is a number. Clamped, so 250 means everything rather than an error.
     *
     * @param int|string|null $storeId
     */
    public function parserShadowRate(int|string|null $storeId): float
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_PARSER_SHADOW_RATE, ScopeInterface::SCOPE_STORE, $storeId);
        if (is_string($value)) {
            $value = trim($value);
        }

        return is_numeric($value) ? max(0.0, min(100.0, (float)$value)) : 0.0;
    }
}
