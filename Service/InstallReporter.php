<?php
/**
 * Standalone install + heartbeat reporter for Panth_AdvancedProductGrid.
 *
 * Self-contained — no dependency on any sibling Panth_* class — so that
 * install detection works even when sibling modules are disabled or absent.
 *
 * Sends a single fire-and-forget POST per (module, version) the first time
 * setup:upgrade observes the new version. Failures are swallowed and logged;
 * they MUST NOT block setup:upgrade.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\PackageInfo;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class InstallReporter
{
    public const MAGENTO_MODULE = 'Panth_AdvancedProductGrid';
    public const COMPOSER_PACKAGE = 'mage2kishan/module-advanced-product-grid';

    private const ENDPOINT_INSTALL = 'https://kishansavaliya.com/panth/notifications/install';
    private const AUTH_USER = 'Kishan';
    private const AUTH_PASS = 'kishan123#';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly ModuleListInterface $moduleList,
        private readonly PackageInfo $packageInfo,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly FlagManager $flagManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function reportInstall(): void
    {
        try {
            if (!function_exists('curl_init')) {
                return;
            }
            $version = (string)$this->packageInfo->getVersion(self::MAGENTO_MODULE);
            if ($version === '') {
                return;
            }

            $flagCode = 'panth_' . strtolower(self::MAGENTO_MODULE) . '_reported_' . $version;
            if ($this->flagManager->getFlagData($flagCode)) {
                return;
            }

            $previous = $this->flagManager->getFlagData(
                'panth_' . strtolower(self::MAGENTO_MODULE) . '_last_version'
            );

            $store = $this->storeManager->getDefaultStoreView();
            $baseUrl = $store ? $store->getBaseUrl(UrlInterface::URL_TYPE_WEB) : '';
            $siteName = $store ? (string)$store->getName() : '';

            $coreModule = $this->moduleList->getOne('Panth_Core');
            $coreVersion = $coreModule ? (string)$this->packageInfo->getVersion('Panth_Core') : '';

            $this->post(self::ENDPOINT_INSTALL, [
                'event_type' => $previous ? 'upgrade' : 'install',
                'composer_package' => self::COMPOSER_PACKAGE,
                'magento_module' => self::MAGENTO_MODULE,
                'module_version' => $version,
                'previous_version' => $previous ?: null,
                'site_url' => $baseUrl,
                'site_name' => $siteName,
                'magento_version' => (string)$this->productMetadata->getVersion(),
                'magento_edition' => (string)$this->productMetadata->getEdition(),
                'php_version' => PHP_VERSION,
                'panth_core_present' => (bool)$coreModule,
                'panth_core_version' => $coreVersion ?: null,
                'reported_at' => gmdate('c'),
            ]);

            $this->flagManager->saveFlag($flagCode, 1);
            $this->flagManager->saveFlag(
                'panth_' . strtolower(self::MAGENTO_MODULE) . '_last_version',
                $version
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth InstallReporter] ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $url, array $payload): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('payload encode failed');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode(self::AUTH_USER . ':' . self::AUTH_PASS),
                'User-Agent: Panth-Module-Reporter/1.0',
            ],
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => 1,
        ]);
        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('curl error: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('non-2xx response: ' . $code);
        }
    }
}
