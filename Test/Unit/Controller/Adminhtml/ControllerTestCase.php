<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Controller\Adminhtml;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

/**
 * Shared doubles for the grid's JSON admin controllers.
 */
abstract class ControllerTestCase extends TestCase
{
    /** @var array|null last payload passed to the JSON result */
    protected ?array $json = null;

    /** @var string[] ACL resources the admin is NOT allowed to use */
    protected array $denied = [];

    protected array $checkedResources = [];

    protected ?int $adminUserId = 1;

    protected function context(array $params = []): Context
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn ($key, $default = null) => array_key_exists($key, $params) ? $params[$key] : $default
        );

        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(function ($resource) {
            $this->checkedResources[] = $resource;
            return !in_array($resource, $this->denied, true);
        });

        $auth = $this->createStub(Auth::class);
        $auth->method('getUser')->willReturnCallback(function () {
            if ($this->adminUserId === null) {
                return null;
            }
            $user = $this->createStub(User::class);
            $user->method('getId')->willReturn($this->adminUserId);
            return $user;
        });

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getAuthorization')->willReturn($authorization);
        $context->method('getAuth')->willReturn($auth);
        return $context;
    }

    protected function jsonFactory(): JsonFactory
    {
        $this->json = null;
        $result = $this->createStub(Json::class);
        $result->method('setData')->willReturnCallback(function ($data) use ($result) {
            $this->json = $data;
            return $result;
        });
        $factory = $this->createStub(JsonFactory::class);
        $factory->method('create')->willReturn($result);
        return $factory;
    }

    protected function callProtected(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        return $reflection->invokeArgs($object, $args);
    }
}
