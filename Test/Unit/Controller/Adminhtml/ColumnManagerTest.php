<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Controller\Adminhtml;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Eav\Api\Data\AttributeSearchResultsInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\User\Model\User;
use Panth\AdvancedProductGrid\Controller\Adminhtml\ColumnManager\InlineEdit;
use Panth\AdvancedProductGrid\Controller\Adminhtml\ColumnManager\ListColumns;
use Panth\AdvancedProductGrid\Controller\Adminhtml\ColumnManager\MassAction;
use Panth\AdvancedProductGrid\Controller\Adminhtml\ColumnManager\RenameFromGrid;
use Panth\AdvancedProductGrid\Controller\Adminhtml\ColumnManager\Reset;
use Panth\AdvancedProductGrid\Model\BookmarkVisibilityUpdater;
use Panth\AdvancedProductGrid\Model\ColumnConfigRepository;
use Panth\AdvancedProductGrid\Test\Unit\Fixture\DbDoublesTrait;

class ColumnManagerTest extends ControllerTestCase
{
    use DbDoublesTrait;

    private array $saved = [];
    private array $failingCodes = [];
    private array $bookmarkApplied = [];
    private bool $bookmarkThrows = false;
    private array $overrides = [];
    private int $resets = 0;
    private bool $resetThrows = false;
    private array $tableDeletes = [];
    private bool $tableExists = true;
    private mixed $bookmarkConfig = false;
    private bool $attributesThrow = false;

    private function repository(): ColumnConfigRepository
    {
        $repository = $this->createStub(ColumnConfigRepository::class);
        $repository->method('save')->willReturnCallback(function ($code, $payload) {
            if (in_array($code, $this->failingCodes, true)) {
                throw new \RuntimeException('cannot save ' . $code);
            }
            $this->saved[] = [$code, $payload];
        });
        $repository->method('getAll')->willReturnCallback(fn () => $this->overrides);
        $repository->method('resetAll')->willReturnCallback(function () {
            if ($this->resetThrows) {
                throw new \RuntimeException('locked table');
            }
            $this->resets++;
        });
        return $repository;
    }

    private function bookmarkUpdater(): BookmarkVisibilityUpdater
    {
        $updater = $this->createStub(BookmarkVisibilityUpdater::class);
        $updater->method('apply')->willReturnCallback(function ($visibility, $positions) {
            if ($this->bookmarkThrows) {
                throw new \RuntimeException('bookmark write failed');
            }
            $this->bookmarkApplied[] = [$visibility, $positions];
        });
        return $updater;
    }

    private function inlineEdit(array $params): InlineEdit
    {
        return new InlineEdit($this->context($params), $this->jsonFactory(), $this->repository(), $this->bookmarkUpdater());
    }

    public function testInlineEditRejectsBadRequests(): void
    {
        $this->inlineEdit(['items' => ['name' => ['is_visible' => 1]]])->execute();
        $this->assertTrue($this->json['error']);
        $this->inlineEdit(['isAjax' => 1, 'items' => []])->execute();
        $this->assertTrue($this->json['error']);
        $this->assertSame([], $this->saved);
    }

    public function testInlineEditNormalisesAllowedFieldsAndSyncsBookmarks(): void
    {
        $this->inlineEdit(['isAjax' => 1, 'items' => [
            'name' => [
                'is_visible' => 'true', 'is_editable' => '', 'sort_order' => '5', 'default_width' => '',
                'custom_label' => 12, 'hack' => 'x', 'attribute_code' => 'evil',
            ],
            'sku' => ['is_visible' => '0', 'sort_order' => ''],
            'color' => ['unknown' => 1],
            'size' => 'scalar',
        ]])->execute();

        $this->assertSame([
            ['name', ['is_visible' => 1, 'is_editable' => 0, 'sort_order' => 5, 'default_width' => null, 'custom_label' => '12']],
            ['sku', ['is_visible' => 0, 'sort_order' => null]],
        ], $this->saved);
        $this->assertSame([[['name' => true, 'sku' => false], ['name' => 5]]], $this->bookmarkApplied);
        $this->assertSame(['messages' => ['Column configuration saved.'], 'error' => false], $this->json);
    }

    public function testInlineEditSkipsBookmarkSyncWhenNothingRelevantChanged(): void
    {
        $this->inlineEdit(['isAjax' => 1, 'items' => ['name' => ['custom_label' => 'Title']]])->execute();

        $this->assertSame([], $this->bookmarkApplied);
        $this->assertFalse($this->json['error']);
    }

    public function testInlineEditCollectsSaveAndBookmarkErrors(): void
    {
        $this->failingCodes = ['sku'];
        $this->bookmarkThrows = true;

        $this->inlineEdit(['isAjax' => 1, 'items' => [
            'sku' => ['is_visible' => 1],
            'name' => ['is_visible' => 1],
        ]])->execute();

        $this->assertTrue($this->json['error']);
        $this->assertSame(['sku: cannot save sku', '__bookmark: bookmark write failed'], $this->json['messages']);
        $this->assertSame([['name', ['is_visible' => 1]]], $this->saved);
    }

    public function testMassActionValidatesOperationAndSelection(): void
    {
        (new MassAction($this->context(['op' => 'explode', 'selected' => ['a']]), $this->jsonFactory(), $this->repository()))->execute();
        $this->assertSame(['success' => false, 'error' => 'Unknown operation: explode'], $this->json);

        (new MassAction($this->context(['op' => 'enable_visible', 'selected' => 'a']), $this->jsonFactory(), $this->repository()))->execute();
        $this->assertSame(['success' => false, 'error' => 'No attributes were selected.'], $this->json);
        $this->assertSame([], $this->saved);
    }

    public function testMassActionAppliesOperationAndCountsFailures(): void
    {
        $this->failingCodes = ['sku'];

        (new MassAction(
            $this->context(['op' => 'disable_export', 'selected' => ['name', 'sku', 'price']]),
            $this->jsonFactory(),
            $this->repository()
        ))->execute();

        $this->assertSame([['name', ['in_export' => 0]], ['price', ['in_export' => 0]]], $this->saved);
        $this->assertSame(['success' => false, 'updated' => 2, 'errors' => ['sku' => 'cannot save sku']], $this->json);
    }

    public function testRenameRequiresCodeAndLabelAndReportsErrors(): void
    {
        (new RenameFromGrid($this->context(['attribute_code' => ' name ', 'custom_label' => '  ']), $this->jsonFactory(), $this->repository()))->execute();
        $this->assertFalse($this->json['success']);

        (new RenameFromGrid($this->context(['attribute_code' => ' name ', 'custom_label' => ' Title ']), $this->jsonFactory(), $this->repository()))->execute();
        $this->assertSame(['success' => true], $this->json);
        $this->assertSame([['name', ['custom_label' => 'Title']]], $this->saved);

        $this->failingCodes = ['sku'];
        (new RenameFromGrid($this->context(['attribute_code' => 'sku', 'custom_label' => 'Code']), $this->jsonFactory(), $this->repository()))->execute();
        $this->assertSame(['success' => false, 'error' => 'cannot save sku'], $this->json);
    }

    private function resource(): ResourceConnection
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturnCallback(fn () => $this->tableExists);
        $connection->method('delete')->willReturnCallback(function ($table, $where) {
            $this->tableDeletes[] = [$table, $where];
            return 1;
        });
        $connection->method('select')->willReturnCallback(fn () => $this->select());
        $connection->method('fetchOne')->willReturnCallback(fn () => $this->bookmarkConfig);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function session(?int $userId): AuthSession
    {
        $user = null;
        if ($userId !== null) {
            $user = $this->createStub(User::class);
            $user->method('getId')->willReturn($userId);
        }
        $session = self::getStubBuilder(AuthSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getStub();
        $session->method('__call')->willReturnCallback(static fn ($name) => $name === 'getUser' ? $user : null);
        return $session;
    }

    public function testResetClearsOverridesAndOwnBookmarks(): void
    {
        (new Reset($this->context(), $this->jsonFactory(), $this->repository(), $this->resource(), $this->session(8)))->execute();

        $this->assertSame(1, $this->resets);
        $this->assertSame([['ui_bookmark', ['namespace = ?' => 'product_listing', 'user_id = ?' => 8]]], $this->tableDeletes);
        $this->assertSame(['success' => true, 'message' => 'All column overrides cleared.'], $this->json);
    }

    public function testResetSkipsBookmarksWithoutUserOrTable(): void
    {
        (new Reset($this->context(), $this->jsonFactory(), $this->repository(), $this->resource(), $this->session(null)))->execute();
        $this->tableExists = false;
        (new Reset($this->context(), $this->jsonFactory(), $this->repository(), $this->resource(), $this->session(8)))->execute();

        $this->assertSame(2, $this->resets);
        $this->assertSame([], $this->tableDeletes);
        $this->assertTrue($this->json['success']);
    }

    public function testResetReportsFailure(): void
    {
        $this->resetThrows = true;

        (new Reset($this->context(), $this->jsonFactory(), $this->repository(), $this->resource(), $this->session(8)))->execute();

        $this->assertSame(['success' => false, 'error' => 'locked table'], $this->json);
        $this->assertSame([], $this->tableDeletes);
    }

    private function attribute(string $code, string $input, string $label, int $required = 0): AttributeInterface
    {
        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getDefaultFrontendLabel')->willReturn($label);
        $attribute->method('getIsRequired')->willReturn($required);
        return $attribute;
    }

    private function listColumns(array $attributes): ListColumns
    {
        $results = $this->createStub(AttributeSearchResultsInterface::class);
        $results->method('getItems')->willReturn($attributes);
        $repo = $this->createStub(AttributeRepositoryInterface::class);
        $repo->method('getList')->willReturnCallback(function () use ($results) {
            if ($this->attributesThrow) {
                throw new \RuntimeException('eav');
            }
            return $results;
        });
        $builder = $this->createStub(SearchCriteriaBuilder::class);
        $builder->method('create')->willReturn(new SearchCriteria());
        return new ListColumns($this->context(), $this->jsonFactory(), $repo, $builder, $this->repository(), $this->resource());
    }

    private function rowsByCode(): array
    {
        return array_column($this->json['columns'], null, 'code');
    }

    public function testListColumnsMergesVirtualAndAttributeColumnsWithDefaults(): void
    {
        $this->listColumns([
            $this->attribute('sku', 'text', 'SKU', 1),
            $this->attribute('price', 'price', 'Price'),
            $this->attribute('color', 'swatch_visual', ''),
            $this->attribute('image', 'media_image', 'Base Image'),
        ])->execute();

        $this->assertTrue($this->json['success']);
        $rows = $this->rowsByCode();
        $this->assertSame(1, $rows['sku']['is_visible']);
        $this->assertSame(1, $rows['sku']['is_required']);
        $this->assertSame('standard', $rows['sku']['group']);
        $this->assertSame('price', $rows['price']['type']);
        $this->assertSame('pricing', $rows['price']['group']);
        $this->assertSame('text', $rows['color']['type']);
        $this->assertSame('Color', $rows['color']['label']);
        $this->assertSame(0, $rows['color']['is_visible']);
        $this->assertSame('attributes', $rows['color']['group']);
        $this->assertSame('image', $rows['image']['type']);
        $this->assertSame(1, $rows['panth_qty_sold']['is_virtual']);
        $this->assertSame(1, $rows['panth_qty_sold']['is_filterable']);
        $this->assertSame(1, $rows['panth_qty_sold']['in_export']);
        $this->assertSame(0, $rows['panth_qty_sold']['is_editable']);
        $this->assertSame(100, $rows['panth_qty_sold']['sort_order']);

        $virtualFlags = array_column($this->json['columns'], 'is_virtual');
        $this->assertSame(array_fill(0, 8, 1), array_slice($virtualFlags, 0, 8), 'virtual columns come first');
        $attributeLabels = array_column(array_slice($this->json['columns'], 8), 'label');
        $this->assertSame(['Base Image', 'Color', 'Price', 'SKU'], $attributeLabels, 'case-insensitive label order');
    }

    public function testListColumnsAppliesOverridesThenBookmarksThenDefaults(): void
    {
        $this->overrides = ['price' => [
            'is_visible' => '0', 'is_editable' => '1', 'is_filterable' => '0', 'in_export' => '0',
            'custom_label' => 'Cost', 'sort_order' => '3', 'marker_color' => 'red',
        ]];
        $this->bookmarkConfig = json_encode(['views' => ['current' => ['data' => ['columns' => [
            'price' => ['visible' => true],
            'color' => ['visible' => true],
            'sku' => ['visible' => false],
            'noflag' => ['sorting' => 'asc'],
        ]]]]]);

        $this->listColumns([
            $this->attribute('price', 'price', 'Price'),
            $this->attribute('color', 'select', 'Color'),
            $this->attribute('sku', 'text', 'SKU'),
        ])->execute();

        $rows = $this->rowsByCode();
        $this->assertSame(0, $rows['price']['is_visible'], 'stored override beats bookmark');
        $this->assertSame(1, $rows['price']['is_editable']);
        $this->assertSame(0, $rows['price']['is_filterable']);
        $this->assertSame(0, $rows['price']['in_export']);
        $this->assertSame('Cost', $rows['price']['custom_label']);
        $this->assertSame('red', $rows['price']['marker_color']);
        $this->assertSame(1, $rows['color']['is_visible']);
        $this->assertSame(0, $rows['sku']['is_visible']);
        $this->assertSame('price', $this->json['columns'][8]['code'], 'lower sort order sorts first');
        $this->assertContains(['user_id = ?', 1], $this->wheres);
        $this->assertContains(['current = ?', 1], $this->wheres);
    }

    public function testListColumnsIgnoresBookmarksWithoutUserTableOrValidJson(): void
    {
        foreach ([
            ['user' => null, 'table' => true, 'config' => '{"columns":{"color":{"visible":true}}}'],
            ['user' => 1, 'table' => false, 'config' => '{"columns":{"color":{"visible":true}}}'],
            ['user' => 1, 'table' => true, 'config' => 'not json'],
            ['user' => 1, 'table' => true, 'config' => false],
        ] as $case) {
            $this->adminUserId = $case['user'];
            $this->tableExists = $case['table'];
            $this->bookmarkConfig = $case['config'];

            $this->listColumns([$this->attribute('color', 'select', 'Color')])->execute();

            $this->assertSame(0, $this->rowsByCode()['color']['is_visible']);
        }
    }

    public function testListColumnsUsesGridLabelsForUnlabelledStaticAttributes(): void
    {
        $this->listColumns([
            $this->attribute('created_at', 'date', ''),
            $this->attribute('updated_at', 'date', '  '),
            $this->attribute('news_from_date', 'date', ''),
            $this->attribute('name', 'text', 'Product Name'),
        ])->execute();

        $rows = $this->rowsByCode();
        $this->assertSame('Created At', $rows['created_at']['label']);
        $this->assertSame('Last Updated At', $rows['updated_at']['label']);
        $this->assertSame('News From Date', $rows['news_from_date']['label']);
        $this->assertSame('Product Name', $rows['name']['label']);
    }

    public function testListColumnsSurvivesAttributeFailureAndReportsHardErrors(): void
    {
        $this->attributesThrow = true;
        $this->listColumns([])->execute();
        $this->assertTrue($this->json['success']);
        $this->assertCount(8, $this->json['columns']);

        $broken = $this->createStub(ColumnConfigRepository::class);
        $broken->method('getAll')->willThrowException(new \RuntimeException('db gone'));
        $builder = $this->createStub(SearchCriteriaBuilder::class);
        (new ListColumns(
            $this->context(),
            $this->jsonFactory(),
            $this->createStub(AttributeRepositoryInterface::class),
            $builder,
            $broken,
            $this->resource()
        ))->execute();
        $this->assertSame(['success' => false, 'error' => 'db gone'], $this->json);
    }
}
