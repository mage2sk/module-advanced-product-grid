<?php
/**
 * Picks the right per-attribute strategy from a DI-configured map,
 * falling back to the generic strategy if no special handling is
 * registered for the attribute code.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit;

use Panth\AdvancedProductGrid\Model\InlineEdit\Strategy\StrategyInterface;

class StrategyResolver
{
    /**
     * @param array<string, StrategyInterface> $strategies
     */
    public function __construct(
        private readonly array $strategies,
        private readonly StrategyInterface $defaultStrategy
    ) {
    }

    public function resolve(string $attributeCode): StrategyInterface
    {
        return $this->strategies[$attributeCode] ?? $this->defaultStrategy;
    }
}
