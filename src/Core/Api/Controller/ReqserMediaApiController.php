<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Controller;

use Psr\Log\LoggerInterface;
use Reqser\Plugin\Core\Api\Attribute\ReqserApiAuth;
use Reqser\Plugin\Service\ReqserMediaUploadService;
use Shopware\Core\Content\Media\MediaEntity;
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
     * only when this route uploaded it; any other entity is left untouched.
     *
     * The optional targetMediaId names the entity to write onto. Supplying it keeps the media id
     * stable when fileName differs from the name that entity currently carries, so references
     * held elsewhere by id — CMS slot configs, custom fields — keep resolving after a rename.
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
            $targetMediaId = trim((string) $request->query->get('targetMediaId', ''));

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

            $existingMedia = null;

            if ($targetMediaId !== '') {
                $existingMedia = $this->mediaUploadService->findMediaById($targetMediaId, $context);

                if ($existingMedia === null) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Target media not found: ' . $targetMediaId,
                    ], 404);
                }

                if (!$this->mediaUploadService->isOwnedByReqser($existingMedia)) {
                    return $this->conflictResponse(
                        $existingMedia,
                        'The target media entity was not uploaded by ' . ReqserMediaUploadService::AUTHOR
                    );
                }
            }

            $nameHolder = $this->mediaUploadService->findMediaByFileName($fileName, $context);
            $nameHeldByOther = $nameHolder !== null
                && ($existingMedia === null || $nameHolder->getId() !== $existingMedia->getId());

            if ($nameHeldByOther) {
                if (!$this->mediaUploadService->isOwnedByReqser($nameHolder)) {
                    return $this->conflictResponse(
                        $nameHolder,
                        'A media entity with this file name already exists and was not uploaded by '
                            . ReqserMediaUploadService::AUTHOR
                    );
                }

                if ($existingMedia !== null) {
                    return $this->conflictResponse(
                        $nameHolder,
                        'Another media entity already carries this file name, the target cannot be renamed to it'
                    );
                }

                $existingMedia = $nameHolder;
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

        } catch (\Throwable $e) {
            // MediaException exists only from Shopware 6.5+. On 6.4.x media domain
            // failures still live under Content\Media\Exception\* — match both shapes
            // by class-name prefix so this catch never autoloads a missing class.
            $exceptionClass = get_class($e);
            if (
                $exceptionClass === 'Shopware\\Core\\Content\\Media\\MediaException'
                || str_starts_with($exceptionClass, 'Shopware\\Core\\Content\\Media\\Exception\\')
            ) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Media could not be stored',
                    'message' => $e->getMessage(),
                    'exceptionType' => $exceptionClass,
                ], 400);
            }

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
                'exceptionType' => $exceptionClass,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    /**
     * @param MediaEntity $media
     * @param string $error
     * @return JsonResponse
     */
    private function conflictResponse(MediaEntity $media, string $error): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => $error,
            'data' => [
                'mediaId' => $media->getId(),
                'fileName' => $media->getFileName(),
                'extension' => $media->getFileExtension(),
                'url' => $media->getUrl(),
                'author' => null,
            ],
            'timestamp' => date('Y-m-d H:i:s'),
        ], 409);
    }
}
