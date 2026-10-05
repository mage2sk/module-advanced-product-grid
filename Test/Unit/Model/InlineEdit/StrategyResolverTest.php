<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model\InlineEdit;

use Panth\AdvancedProductGrid\Model\InlineEdit\Strategy\StrategyInterface;
use Panth\AdvancedProductGrid\Model\InlineEdit\StrategyResolver;
use PHPUnit\Framework\TestCase;

class StrategyResolverTest extends TestCase
{
    public function testMappedCodeReturnsItsStrategyAndOthersFallBack(): void
    {
        $qty = $this->createStub(StrategyInterface::class);
        $default = $this->createStub(StrategyInterface::class);
        $resolver = new StrategyResolver(['qty' => $qty], $default);

        $this->assertSame($qty, $resolver->resolve('qty'));
        $this->assertSame($default, $resolver->resolve('name'));
        $this->assertSame($default, $resolver->resolve(''));
    }
}
