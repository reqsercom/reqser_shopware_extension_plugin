<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Controller;

use Psr\Log\LoggerInterface;
use Reqser\Plugin\Core\Api\Attribute\ReqserApiAuth;
use Reqser\Plugin\Service\ReqserMediaUploadService;
use Shopware\Core\Content\Media\MediaException;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Admin API controller for storing an image binary as a media entity alongside an existing one.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
#[ReqserApiAuth]
class ReqserMediaApiController extends AbstractController
{
    private ReqserMediaUploadService $mediaUploadService;
    private LoggerInterface $logger;

    /**
     * @param ReqserMediaUploadService $mediaUploadService
     * @param LoggerInterface $logger
     */
    public function __construct(
        ReqserMediaUploadService $mediaUploadService,
        LoggerInterface $logger
    ) {
        $this->mediaUploadService = $mediaUploadService;
        $this->logger = $logger;
    }

    /**
     * Store the raw request body as a media entity named by the fileName parameter, in the media
     * folder of the referenced source media. An existing entity with that file name is replaced
     * when it was uploaded by this route, and otherwise only when overwrite is requested.
     *
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/media/upload',
        name: 'api.action.reqser.media.upload',
        methods: ['POST']
    )]
    public function upload(Request $request, Context $context): JsonResponse
    {
        try {
            $sourceMediaId = (string) $request->query->get('sourceMediaId', '');
            $fileName = trim((string) $request->query->get('fileName', ''));
            $extension = strtolower(trim((string) $request->query->get('extension', '')));
            $overwrite = $request->query->getBoolean('overwrite');

            if ($sourceMediaId === '' || $fileName === '' || $extension === '') {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing required parameters: sourceMediaId, fileName, extension',
                ], 400);
            }

            $allowedExtensions = ReqserMediaUploadService::allowedExtensions();

            if (!in_array($extension, $allowedExtensions, true)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Unsupported extension, allowed: ' . implode(', ', $allowedExtensions),
                ], 400);
            }

            $binary = $request->getContent();

            if ($binary === '') {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Request body is empty, expected the raw image binary',
                ], 400);
            }

            $mimeType = $this->mediaUploadService->resolveVerifiedMimeType($binary, $extension);

            if ($mimeType === null) {
                $this->logger->warning('Reqser API: media upload rejected, body is not a valid image', [
                    'endpoint' => $request->getPathInfo(),
                    'method' => $request->getMethod(),
                    'extension' => $extension,
                    'file' => __FILE__,
                    'line' => __LINE__,
                ]);

                return new JsonResponse([
                    'success' => false,
                    'error' => 'Request body is not a valid ' . $extension . ' image',
                ], 400);
            }

            $sourceMedia = $this->mediaUploadService->findMediaById($sourceMediaId, $context);

            if ($sourceMedia === null) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Source media not found: ' . $sourceMediaId,
                ], 404);
            }

            $existingMedia = $this->mediaUploadService->findMediaByFileName($fileName, $context);

            if ($existingMedia !== null
                && !$overwrite
                && !$this->mediaUploadService->isOwnedByReqser($existingMedia)
            ) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'A media entity with this file name already exists and was not uploaded by '
                        . ReqserMediaUploadService::AUTHOR,
                    'data' => [
                        'mediaId' => $existingMedia->getId(),
                        'fileName' => $existingMedia->getFileName(),
                        'extension' => $existingMedia->getFileExtension(),
                        'url' => $existingMedia->getUrl(),
                        'author' => null,
                    ],
                    'timestamp' => date('Y-m-d H:i:s'),
                ], 409);
            }

            $result = $this->mediaUploadService->storeVariant(
                $sourceMedia,
                $existingMedia,
                $fileName,
                $extension,
                $mimeType,
                $binary,
                $context
            );

            return new JsonResponse([
                'success' => true,
                'data' => $result,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

        } catch (MediaException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Media could not be stored',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
            ], 400);

        } catch (\Throwable $e) {
            $this->logger->error('Reqser API: media upload failed', [
                'endpoint' => $request->getPathInfo(),
                'method' => $request->getMethod(),
                'file' => __FILE__,
                'line' => __LINE__,
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Error storing media',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }
}
