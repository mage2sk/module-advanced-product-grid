<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\User\Model\User;
use Panth\AdvancedProductGrid\Model\BookmarkVisibilityUpdater;
use Panth\AdvancedProductGrid\Test\Unit\Fixture\DbDoublesTrait;
use PHPUnit\Framework\TestCase;

class BookmarkVisibilityUpdaterTest extends TestCase
{
    use DbDoublesTrait;

    private array $bookmarks = [];
    private array $updates = [];
    private bool $tableExists = true;
    private int $fetches = 0;

    private function updater(?int $userId = 3): BookmarkVisibilityUpdater
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturnCallback(fn () => $this->tableExists);
        $connection->method('select')->willReturnCallback(fn () => $this->select());
        $connection->method('fetchAll')->willReturnCallback(function () {
            $this->fetches++;
            return $this->bookmarks;
        });
        $connection->method('update')->willReturnCallback(function ($table, $data, $where) {
            $this->updates[$where['bookmark_id = ?']] = json_decode($data['config'], true);
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $user = null;
        if ($userId !== null) {
            $user = $this->createStub(User::class);
            $user->method('getId')->willReturn($userId);
        }
        $session = self::getStubBuilder(AuthSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getStub();
        $session->method('__call')->willReturnCallback(
            static fn ($name) => $name === 'getUser' ? $user : null
        );

        return new BookmarkVisibilityUpdater($resource, $session);
    }

    public function testNothingHappensWithoutChanges(): void
    {
        $this->updater()->apply([], []);

        $this->assertSame(0, $this->fetches);
    }

    public function testNothingHappensWithoutAdminUserOrTable(): void
    {
        $this->updater(null)->apply(['name' => true]);
        $this->tableExists = false;
        $this->updater()->apply(['name' => true]);

        $this->assertSame(0, $this->fetches);
        $this->assertSame([], $this->updates);
    }

    public function testBookmarksAreFilteredByNamespaceAndUser(): void
    {
        $this->updater(42)->apply(['name' => true]);

        $this->assertContains(['namespace = ?', 'product_listing'], $this->wheres);
        $this->assertContains(['user_id = ?', 42], $this->wheres);
    }

    public function testExistingColumnsNodesAreUpdatedEverywhere(): void
    {
        $this->bookmarks = [[
            'bookmark_id' => '10',
            'config' => json_encode([
                'views' => ['current' => ['data' => ['columns' => ['name' => ['visible' => false, 'sorting' => 'asc']]]]],
                'current' => ['columns' => ['sku' => ['visible' => true]]],
            ]),
        ]];

        $this->updater()->apply(['name' => 1, 'sku' => 0]);

        $config = $this->updates[10];
        $this->assertSame(
            ['visible' => true, 'sorting' => 'asc'],
            $config['views']['current']['data']['columns']['name']
        );
        $this->assertFalse($config['views']['current']['data']['columns']['sku']['visible']);
        $this->assertFalse($config['current']['columns']['sku']['visible']);
        $this->assertTrue($config['current']['columns']['name']['visible']);
    }

    public function testMissingColumnsNodeIsCreatedUnderCurrentView(): void
    {
        $this->bookmarks = [['bookmark_id' => 11, 'config' => 'not json']];

        $this->updater()->apply(['color' => true]);

        $this->assertSame(
            ['views' => ['current' => ['data' => ['columns' => ['color' => ['visible' => true]]]]]],
            $this->updates[11]
        );
    }

    public function testPositionsAreMergedAndRenumberedSequentially(): void
    {
        $this->bookmarks = [[
            'bookmark_id' => 12,
            'config' => json_encode(['current' => ['positions' => ['ids' => 0, 'name' => 1, 'sku' => 2, 'price' => 3]]]),
        ]];

        $this->updater()->apply([], ['price' => 1, 'color' => 9]);

        $this->assertSame(
            ['ids' => 0, 'name' => 1, 'price' => 2, 'sku' => 3, 'color' => 4],
            $this->updates[12]['current']['positions']
        );
    }

    public function testMissingPositionsNodeIsCreated(): void
    {
        $this->bookmarks = [['bookmark_id' => 13, 'config' => '{}']];

        $this->updater()->apply([], ['name' => '5']);

        $this->assertSame(['name' => 5], $this->updates[13]['views']['current']['data']['positions']);
        $this->assertArrayNotHasKey('columns', $this->updates[13]['views']['current']['data']);
    }
}
