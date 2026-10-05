<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model\Config;

use Panth\AdvancedProductGrid\Model\Config\Source\EditMode;
use Panth\AdvancedProductGrid\Model\Config\Source\MarkerColor;
use Panth\AdvancedProductGrid\Ui\Component\Listing\Column\AvailabilityOptions;
use Panth\AdvancedProductGrid\Ui\Component\Listing\Column\BackorderOptions;
use PHPUnit\Framework\TestCase;

class SourceModelsTest extends TestCase
{
    private function values(array $options): array
    {
        return array_map(static fn (array $o) => $o['value'], $options);
    }

    public function testEditModeOffersSingleAndMultiCell(): void
    {
        $this->assertSame(['single', 'multi'], $this->values((new EditMode())->toOptionArray()));
    }

    public function testMarkerColorsStartWithNoneAndMatchCssSafeNames(): void
    {
        $values = $this->values((new MarkerColor())->toOptionArray());

        $this->assertSame(['', 'red', 'yellow', 'green', 'blue', 'grey'], $values);
        foreach ($values as $value) {
            $this->assertMatchesRegularExpression('/^[a-z]*$/', $value);
        }
    }

    public function testAvailabilityOptionsUseTheConstantValues(): void
    {
        $options = (new AvailabilityOptions())->toOptionArray();

        $this->assertSame(
            [AvailabilityOptions::IN_STOCK, AvailabilityOptions::OUT_OF_STOCK, AvailabilityOptions::MANAGE_STOCK_DISABLED],
            $this->values($options)
        );
        $this->assertSame('In Stock', (string)$options[0]['label']);
    }

    public function testBackorderOptionsMatchCatalogInventoryCodes(): void
    {
        $this->assertSame(['0', '1', '2'], $this->values((new BackorderOptions())->toOptionArray()));
    }
}
