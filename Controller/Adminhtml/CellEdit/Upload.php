<?php
/**
 * Image upload endpoint for the popup image-cell editor.
 *
 * Wraps Magento's catalog product image uploader so the popup can
 * receive a `multipart/form-data` POST with a single `image` file,
 * push it through validation + media storage, and return the saved
 * relative path (e.g. `/w/b/wb02-green-0.jpg`) ready to be persisted
 * as the attribute value.
 *
 * Why a dedicated endpoint vs reusing catalog/product_gallery/upload?
 *   - That endpoint is tightly coupled to the product form's gallery
 *     UI and expects specific session state. This one is stateless
 *     and JSON-friendly for our popup flow.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Controller\Adminhtml\CellEdit;

use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Panth\AdvancedProductGrid\Controller\Adminhtml\AbstractAction;

class Upload extends AbstractAction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedProductGrid::manage_gallery';

    private const ALLOWED_EXT = ['jpg', 'jpeg', 'gif', 'png', 'webp'];

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly UploaderFactory $uploaderFactory,
        private readonly MediaConfig $mediaConfig,
        private readonly Filesystem $filesystem
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $uploader = $this->uploaderFactory->create(['fileId' => 'image']);
            $uploader->setAllowedExtensions(self::ALLOWED_EXT);
            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(true);

            $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            $destinationDir = $mediaDir->getAbsolutePath($this->mediaConfig->getBaseTmpMediaPath());

            $info = $uploader->save($destinationDir);
            if (empty($info['file'])) {
                throw new \RuntimeException((string)__('No file was uploaded.'));
            }

            // Move tmp → actual catalog/product folder so the attribute
            // can point at a stable path. The product save path normally
            // does this for the gallery; we replicate for direct attrs.
            $tmpFile = $this->mediaConfig->getTmpMediaPath($info['file']);
            $finalFile = $this->mediaConfig->getMediaPath(ltrim($info['file'], '/'));
            $mediaWrite = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            if ($mediaWrite->isFile($tmpFile)) {
                $mediaWrite->renameFile($tmpFile, $finalFile);
            }

            return $result->setData([
                'success' => true,
                'file' => $info['file'],
                'url'  => $this->mediaConfig->getMediaUrl($info['file']),
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
