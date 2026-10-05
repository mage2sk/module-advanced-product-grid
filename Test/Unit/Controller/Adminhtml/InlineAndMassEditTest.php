<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Controller\Adminhtml;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Ui\Component\MassAction\Filter;
use Panth\AdvancedProductGrid\Controller\Adminhtml\Index\InlineEdit;
use Panth\AdvancedProductGrid\Controller\Adminhtml\MassEdit\Form as MassEditForm;
use Panth\AdvancedProductGrid\Controller\Adminhtml\MassEdit\Save as MassEditSave;
use Panth\AdvancedProductGrid\Model\InlineEdit\Processor;
use Panth\AdvancedProductGrid\Model\MassEdit\AttributeCatalog;

class InlineAndMassEditTest extends ControllerTestCase
{
    private array $processed = [];
    private array $outcome = ['success' => [], 'errors' => []];
    private array $filterIds = [];
    private bool $filterThrows = false;

    private function processor(): Processor
    {
        $processor = $this->createStub(Processor::class);
        $processor->method('process')->willReturnCallback(function ($items, $storeId) {
            $this->processed[] = [$items, $storeId];
            return $this->outcome;
        });
        return $processor;
    }

    private function inline(array $params): InlineEdit
    {
        return new InlineEdit($this->context($params), $this->jsonFactory(), $this->processor());
    }

    public function testAclRequiresBothModuleAndCatalogPermissions(): void
    {
        $controller = $this->inline([]);
        $this->assertTrue($this->callProtected($controller, '_isAllowed'));
        $this->assertSame(['Panth_AdvancedProductGrid::inline_edit', 'Magento_Catalog::products'], $this->checkedResources);

        $this->denied = ['Magento_Catalog::products'];
        $this->assertFalse($this->callProtected($controller, '_isAllowed'));
    }

    public function testDeniedCodesOnlyListRestrictedAttributesOnce(): void
    {
        $this->denied = ['Panth_AdvancedProductGrid::manage_gallery'];
        $controller = $this->inline([]);

        $denied = $this->callProtected($controller, 'findDeniedCodes', [['name', 'image', 'tier_price', 'image', 'thumbnail']]);

        $this->assertSame(['image', 'thumbnail'], $denied);
    }

    public function testInlineEditRejectsNonAjaxOrEmptyRequests(): void
    {
        foreach ([['items' => [1 => ['name' => 'x']]], ['items' => [], 'isAjax' => 1], ['items' => 'x', 'isAjax' => 1]] as $params) {
            $this->inline($params)->execute();
            $this->assertTrue($this->json['error']);
            $this->assertSame(['Please correct the data sent.'], $this->json['messages']);
        }
        $this->assertSame([], $this->processed);
    }

    public function testInlineEditBlocksRestrictedAttributes(): void
    {
        $this->denied = ['Panth_AdvancedProductGrid::tier_price'];

        $this->inline(['isAjax' => 1, 'items' => [5 => ['name' => 'A'], 6 => ['panth_tier_price_label' => '[]'], 7 => 'x']])->execute();

        $this->assertTrue($this->json['error']);
        $this->assertSame(['You are not allowed to edit: panth_tier_price_label'], $this->json['messages']);
        $this->assertSame([], $this->processed);
    }

    public function testInlineEditReportsSuccessAndErrors(): void
    {
        $this->outcome = ['success' => [5, 6], 'errors' => [7 => 'Bad price']];

        $this->inline(['isAjax' => 1, 'store_id' => '2', 'items' => [5 => ['name' => 'A']]])->execute();

        $this->assertSame([[[5 => ['name' => 'A']], 2]], $this->processed);
        $this->assertSame([
            'messages' => ['2 products updated successfully.', 'Product #7: Bad price'],
            'error' => true,
            'success' => 2,
            'failed' => 1,
        ], $this->json);
    }

    public function testInlineEditUsesSingularMessageAndWarnsWhenNothingSaved(): void
    {
        $this->outcome = ['success' => [5], 'errors' => []];
        $this->inline(['isAjax' => 1, 'items' => [5 => ['name' => 'A']]])->execute();
        $this->assertSame(['1 product updated successfully.'], $this->json['messages']);
        $this->assertFalse($this->json['error']);

        $this->outcome = ['success' => [], 'errors' => []];
        $this->inline(['isAjax' => 1, 'items' => [5 => ['name' => 'A']]])->execute();
        $this->assertStringStartsWith('No changes were persisted.', $this->json['messages'][0]);
    }

    public function testInlineEditDisablesCustomCsrfHandling(): void
    {
        $controller = $this->inline([]);
        $request = $this->createStub(RequestInterface::class);

        $this->assertNull($controller->validateForCsrf($request));
        $this->assertNull($controller->createCsrfValidationException($request));
    }

    public function testMassEditFormReturnsGroupsOrError(): void
    {
        $catalog = $this->createStub(AttributeCatalog::class);
        $catalog->method('getGroupedAttributes')->willReturn(['Pricing' => [['code' => 'price']]]);
        (new MassEditForm($this->context(), $this->jsonFactory(), $catalog))->execute();
        $this->assertSame(['success' => true, 'groups' => ['Pricing' => [['code' => 'price']]]], $this->json);

        $broken = $this->createStub(AttributeCatalog::class);
        $broken->method('getGroupedAttributes')->willThrowException(new \RuntimeException('eav offline'));
        (new MassEditForm($this->context(), $this->jsonFactory(), $broken))->execute();
        $this->assertSame(['success' => false, 'error' => 'eav offline'], $this->json);
    }

    private function massSave(array $params): MassEditSave
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getAllIds')->willReturnCallback(fn () => $this->filterIds);
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);
        $filter = $this->createStub(Filter::class);
        $filter->method('getCollection')->willReturnCallback(function ($c) {
            if ($this->filterThrows) {
                throw new \RuntimeException('Invalid filter');
            }
            return $c;
        });
        return new MassEditSave($this->context($params), $this->jsonFactory(), $this->processor(), $collectionFactory, $filter);
    }

    public function testMassSaveRequiresChangesAndPermission(): void
    {
        $this->massSave(['changes' => []])->execute();
        $this->assertSame('No attribute changes were provided.', $this->json['error']);

        $this->denied = ['Panth_AdvancedProductGrid::manage_gallery'];
        $this->massSave(['changes' => ['small_image' => '/a.jpg'], 'selected' => [1]])->execute();
        $this->assertSame('You are not allowed to edit: small_image', $this->json['error']);
        $this->assertSame([], $this->processed);
    }

    public function testMassSaveAppliesChangesToExplicitSelection(): void
    {
        $this->outcome = ['success' => [3, 4], 'errors' => [9 => 'locked']];

        $this->massSave(['changes' => ['status' => '2'], 'selected' => ['3', '4', '9'], 'store_id' => '1'])->execute();

        $this->assertSame([[[3 => ['status' => '2'], 4 => ['status' => '2'], 9 => ['status' => '2']], 1]], $this->processed);
        $this->assertSame([
            'success' => false,
            'updated' => 2,
            'errors' => 1,
            'messages' => ['2 product(s) updated.', 'Product 9: locked'],
        ], $this->json);
    }

    public function testMassSaveAcceptsCommaSeparatedSelection(): void
    {
        $this->outcome = ['success' => [3, 4], 'errors' => []];

        $this->massSave(['changes' => ['status' => '1'], 'selected' => '3,4'])->execute();

        $this->assertSame([3, 4], array_keys($this->processed[0][0]));
        $this->assertTrue($this->json['success']);
    }

    public function testMassSaveFallsBackToGridFilterSelection(): void
    {
        $this->filterIds = ['11', '12'];
        $this->outcome = ['success' => [11, 12], 'errors' => []];

        $this->massSave(['changes' => ['status' => '1']])->execute();

        $this->assertSame([11, 12], array_keys($this->processed[0][0]));
    }

    public function testMassSaveReportsEmptyOrBrokenSelection(): void
    {
        $this->massSave(['changes' => ['status' => '1']])->execute();
        $this->assertSame('No products were selected.', $this->json['error']);

        $this->filterThrows = true;
        $this->massSave(['changes' => ['status' => '1']])->execute();
        $this->assertSame(['success' => false, 'error' => 'Invalid filter'], $this->json);
        $this->assertSame([], $this->processed);
    }
}
