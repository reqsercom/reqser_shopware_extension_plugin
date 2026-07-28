<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Stores an image binary as a media entity under a caller-provided file name, next to a source media entity.
 */
class ReqserMediaUploadService
{
    /**
     * Value written to the author custom field on every media entity stored through this service.
     */
    public const AUTHOR = 'reqser';

    private const CUSTOM_FIELD_AUTHOR = 'reqser_media_author';
    private const CUSTOM_FIELD_SOURCE_MEDIA_ID = 'reqser_media_source_id';
    private const CUSTOM_FIELD_UPLOADED_AT = 'reqser_media_uploaded_at';

    /**
     * Raster image formats accepted on this write path, mapped to the image types their binary
     * must decode as. Vector and document formats are absent on purpose: SVG is XML and can
     * carry executable script, and no other container is verifiable by image signature.
     */
    private const ALLOWED_EXTENSIONS = [
        'jpg' => IMAGETYPE_JPEG,
        'jpeg' => IMAGETYPE_JPEG,
        'png' => IMAGETYPE_PNG,
        'webp' => IMAGETYPE_WEBP,
        'gif' => IMAGETYPE_GIF,
    ];

    private EntityRepository $mediaRepository;
    private FileSaver $fileSaver;

    /**
     * @param EntityRepository $mediaRepository
     * @param FileSaver $fileSaver
     */
    public function __construct(
        EntityRepository $mediaRepository,
        FileSaver $fileSaver
    ) {
        $this->mediaRepository = $mediaRepository;
        $this->fileSaver = $fileSaver;
    }

    /**
     * Return the file extensions accepted on this write path.
     *
     * @return array
     */
    public static function allowedExtensions(): array
    {
        return array_keys(self::ALLOWED_EXTENSIONS);
    }

    /**
     * Report whether the given media entity was stored through this service, based on its author
     * custom field.
     *
     * @param MediaEntity $media
     * @return bool
     */
    public function isOwnedByReqser(MediaEntity $media): bool
    {
        $customFields = $media->getCustomFields();

        if (!is_array($customFields)) {
            return false;
        }

        return ($customFields[self::CUSTOM_FIELD_AUTHOR] ?? null) === self::AUTHOR;
    }

    /**
     * Return the mime type to store for the given binary, or null when the binary does not decode
     * as an image of the given extension. The extension and any request content type are treated as
     * untrusted input: the format is taken from the binary signature, not from what the caller claims.
     *
     * @param string $binary
     * @param string $extension
     * @return string|null
     */
    public function resolveVerifiedMimeType(string $binary, string $extension): ?string
    {
        if (!isset(self::ALLOWED_EXTENSIONS[$extension])) {
            return null;
        }

        $imageInfo = @getimagesizefromstring($binary);

        if (!is_array($imageInfo) || !isset($imageInfo[2])) {
            return null;
        }

        $detectedType = (int) $imageInfo[2];

        if ($detectedType !== self::ALLOWED_EXTENSIONS[$extension]) {
            return null;
        }

        $mimeType = image_type_to_mime_type($detectedType);

        return $mimeType !== '' ? $mimeType : null;
    }

    /**
     * Return the media entity for the given id, or null when it does not exist.
     *
     * @param string $mediaId
     * @param Context $context
     * @return MediaEntity|null
     */
    public function findMediaById(string $mediaId, Context $context): ?MediaEntity
    {
        if (!Uuid::isValid($mediaId)) {
            return null;
        }

        $media = $this->mediaRepository->search(new Criteria([$mediaId]), $context)->first();

        return $media instanceof MediaEntity ? $media : null;
    }

    /**
     * Return the first media entity carrying the given file name, or null when none exists.
     *
     * @param string $fileName
     * @param Context $context
     * @return MediaEntity|null
     */
    public function findMediaByFileName(string $fileName, Context $context): ?MediaEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('fileName', $fileName));
        $criteria->setLimit(1);

        $media = $this->mediaRepository->search($criteria, $context)->first();

        return $media instanceof MediaEntity ? $media : null;
    }

    /**
     * Write the given binary into a media entity named $fileName, placed in the media folder of
     * $sourceMedia and inheriting its visibility. Reuses $existingMedia when supplied, otherwise
     * creates a new entity.
     *
     * @param MediaEntity $sourceMedia
     * @param MediaEntity|null $existingMedia
     * @param string $fileName
     * @param string $extension
     * @param string $mimeType
     * @param string $binary
     * @param Context $context
     * @return array
     * @throws \RuntimeException When the binary cannot be buffered to a temporary file.
     */
    public function storeVariant(
        MediaEntity $sourceMedia,
        ?MediaEntity $existingMedia,
        string $fileName,
        string $extension,
        string $mimeType,
        string $binary,
        Context $context
    ): array {
        $mediaId = $existingMedia !== null ? $existingMedia->getId() : Uuid::randomHex();
        $temporaryPath = $this->writeTemporaryFile($binary);

        try {
            $context->scope(Context::SYSTEM_SCOPE, function (Context $systemContext) use (
                $sourceMedia,
                $existingMedia,
                $mediaId,
                $fileName,
                $extension,
                $mimeType,
                $temporaryPath
            ): void {
                if ($existingMedia === null) {
                    $this->mediaRepository->create([[
                        'id' => $mediaId,
                        'mediaFolderId' => $sourceMedia->getMediaFolderId(),
                        'private' => $sourceMedia->isPrivate(),
                    ]], $systemContext);
                }

                $mediaFile = new MediaFile(
                    $temporaryPath,
                    $mimeType,
                    $extension,
                    (int) filesize($temporaryPath)
                );

                $this->fileSaver->persistFileToMedia($mediaFile, $fileName, $mediaId, $systemContext);

                $this->stampAuthor($mediaId, $sourceMedia->getId(), $existingMedia, $systemContext);
            });
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }

        $storedMedia = $this->findMediaById($mediaId, $context);

        return [
            'mediaId' => $mediaId,
            'fileName' => $storedMedia !== null ? $storedMedia->getFileName() : $fileName,
            'extension' => $storedMedia !== null ? $storedMedia->getFileExtension() : $extension,
            'mimeType' => $storedMedia !== null ? $storedMedia->getMimeType() : $mimeType,
            'fileSize' => $storedMedia !== null ? $storedMedia->getFileSize() : null,
            'url' => $storedMedia !== null ? $storedMedia->getUrl() : null,
            'mediaFolderId' => $sourceMedia->getMediaFolderId(),
            'private' => $sourceMedia->isPrivate(),
            'author' => self::AUTHOR,
            'action' => $existingMedia !== null ? 'replaced' : 'created',
        ];
    }

    /**
     * Write the author custom fields onto the stored media entity, preserving any custom fields the
     * entity already carried.
     *
     * @param string $mediaId
     * @param string $sourceMediaId
     * @param MediaEntity|null $existingMedia
     * @param Context $context
     * @return void
     */
    private function stampAuthor(
        string $mediaId,
        string $sourceMediaId,
        ?MediaEntity $existingMedia,
        Context $context
    ): void {
        $customFields = $existingMedia !== null && is_array($existingMedia->getCustomFields())
            ? $existingMedia->getCustomFields()
            : [];

        $customFields[self::CUSTOM_FIELD_AUTHOR] = self::AUTHOR;
        $customFields[self::CUSTOM_FIELD_SOURCE_MEDIA_ID] = $sourceMediaId;
        $customFields[self::CUSTOM_FIELD_UPLOADED_AT] = (new \DateTimeImmutable())->format(\DATE_ATOM);

        $this->mediaRepository->update([[
            'id' => $mediaId,
            'customFields' => $customFields,
        ]], $context);
    }

    /**
     * Buffer the given binary into a temporary file and return its path.
     *
     * @param string $binary
     * @return string
     * @throws \RuntimeException When no temporary file can be created or written.
     */
    private function writeTemporaryFile(string $binary): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'reqser_media_');

        if ($temporaryPath === false) {
            throw new \RuntimeException('Could not create a temporary file for the uploaded binary.');
        }

        if (file_put_contents($temporaryPath, $binary) === false) {
            unlink($temporaryPath);

            throw new \RuntimeException('Could not write the uploaded binary to a temporary file.');
        }

        return $temporaryPath;
    }
}
