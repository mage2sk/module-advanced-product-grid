<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model\InlineEdit;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\AdvancedProductGrid\Model\InlineEdit\AttributeSetAssigner;
use Panth\AdvancedProductGrid\Model\InlineEdit\ChangeValidator;
use Panth\AdvancedProductGrid\Model\InlineEdit\Processor;
use Panth\AdvancedProductGrid\Model\InlineEdit\Strategy\StrategyInterface;
use Panth\AdvancedProductGrid\Model\InlineEdit\StrategyResolver;
use Panth\AdvancedProductGrid\Test\Unit\Fixture\DbDoublesTrait;
use PHPUnit\Framework\TestCase;

class ProcessorTest extends TestCase
{
    use DbDoublesTrait;

    private array $products = [];
    private array $loaded = [];
    private array $saved = [];
    private array $applied = [];
    private array $ensured = [];
    private array $events = [];
    private array $validated = [];
    private ?string $validationError = null;
    private ?\Throwable $saveException = null;

    private function processor(): Processor
    {
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(
            function ($id, $edit = false, $storeId = null, $force = false) {
                $this->loaded[] = [$id, $edit, $storeId, $force];
                if (!isset($this->products[$id])) {
                    throw new NoSuchEntityException(__('nope'));
                }
                return $this->products[$id];
            }
        );
        $repository->method('save')->willReturnCallback(function (ProductInterface $product) {
            if ($this->saveException !== null) {
                throw $this->saveException;
            }
            $this->saved[] = $product->getId();
            return $product;
        });

        $strategy = $this->createStub(StrategyInterface::class);
        $strategy->method('apply')->willReturnCallback(function ($product, $code, $value) {
            $this->applied[] = [$product->getId(), $code, $value];
            if ($code === 'url_key') {
                $product->setData('panth_request_url_rewrite_refresh', true);
            }
        });
        $resolver = new StrategyResolver([], $strategy);

        $events = $this->createStub(ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function ($name, $data) {
            $this->events[] = [$name, $data['product']->getId()];
        });

        $assigner = $this->createStub(AttributeSetAssigner::class);
        $assigner->method('ensure')->willReturnCallback(function ($setId, $code) {
            $this->ensured[] = [$setId, $code];
        });

        $validator = $this->createStub(ChangeValidator::class);
        $validator->method('normalize')->willReturnArgument(0);
        $validator->method('validate')->willReturnCallback(function (array $changes) {
            $this->validated[] = $changes;
            return $this->validationError;
        });

        return new Processor($repository, $resolver, $events, $assigner, $validator);
    }

    public function testInvalidRowsAreSkippedSilently(): void
    {
        $result = $this->processor()->process([0 => ['name' => 'x'], -3 => ['name' => 'y'], 5 => 'scalar'], 1);

        $this->assertSame(['success' => [], 'errors' => []], $result);
        $this->assertSame([], $this->loaded);
    }

    public function testValidationErrorStopsTheRowBeforeLoading(): void
    {
        $this->validationError = 'bad value';

        $result = $this->processor()->process([7 => ['name' => '']], 1);

        $this->assertSame(['success' => [], 'errors' => [7 => 'bad value']], $result);
        $this->assertSame([], $this->loaded);
    }

    public function testMatchingEntityIdEchoIsDroppedBeforeValidation(): void
    {
        $this->products[7] = $this->product(['entity_id' => 7, 'attribute_set_id' => 4]);

        $this->processor()->process([7 => ['entity_id' => '7', 'name' => 'A']], 1);

        $this->assertSame([['name' => 'A']], $this->validated);
    }

    public function testDifferentEntityIdIsKeptForTheValidatorToReject(): void
    {
        $this->validationError = 'not allowed';

        $this->processor()->process([7 => ['entity_id' => '8', 'name' => 'A']], 1);

        $this->assertSame([['entity_id' => '8', 'name' => 'A']], $this->validated);
    }

    public function testSuccessfulRowAppliesEveryChangeAndSaves(): void
    {
        $this->products[7] = $this->product(['entity_id' => 7, 'attribute_set_id' => 4]);

        $result = $this->processor()->process([7 => ['name' => 'A', 'panth_availability' => '1']], 3);

        $this->assertSame(['success' => [7], 'errors' => []], $result);
        $this->assertSame([[7, true, 3, true]], $this->loaded);
        $this->assertSame(3, $this->products[7]->getData('store_id'));
        $this->assertSame([[4, 'name']], $this->ensured, 'virtual panth_ codes are never assigned to sets');
        $this->assertSame([[7, 'name', 'A'], [7, 'panth_availability', '1']], $this->applied);
        $this->assertSame([7], $this->saved);
        $this->assertSame([], $this->events);
    }

    public function testAdminStoreIsUsedForStoreZero(): void
    {
        $this->products[7] = $this->product(['entity_id' => 7]);

        $this->processor()->process([7 => ['name' => 'A']], 0);

        $this->assertSame(0, $this->products[7]->getData('store_id'));
    }

    public function testUrlRewriteRefreshEventIsDispatchedWhenRequested(): void
    {
        $this->products[7] = $this->product(['entity_id' => 7]);

        $this->processor()->process([7 => ['url_key' => 'new-key']], 1);

        $this->assertSame([['panth_product_grid_url_rewrite_refresh', 7]], $this->events);
    }

    public function testNumericKeysAreNotTreatedAsAttributes(): void
    {
        $this->products[7] = $this->product(['entity_id' => 7, 'attribute_set_id' => 4]);

        $this->processor()->process([7 => [0 => 'x', 'name' => 'A']], 1);

        $this->assertSame([[7, 'name', 'A']], $this->applied);
        $this->assertSame([[4, 'name']], $this->ensured);
    }

    public function testMissingProductIsReportedPerRow(): void
    {
        $this->products[8] = $this->product(['entity_id' => 8]);

        $result = $this->processor()->process([7 => ['name' => 'A'], 8 => ['name' => 'B']], 1);

        $this->assertSame([8], $result['success']);
        $this->assertSame('Product 7 does not exist.', $result['errors'][7]);
    }

    public function testLocalizedAndGenericSaveErrorsAreReported(): void
    {
        $this->products[7] = $this->product(['entity_id' => 7]);
        $this->saveException = new LocalizedException(__('URL key already exists'));
        $first = $this->processor()->process([7 => ['name' => 'A']], 1);

        $this->saveException = new \RuntimeException('deadlock');
        $second = $this->processor()->process([7 => ['name' => 'A']], 1);

        $this->assertSame([7 => 'URL key already exists'], $first['errors']);
        $this->assertSame([7 => 'deadlock'], $second['errors']);
        $this->assertSame([], $second['success']);
    }
}
