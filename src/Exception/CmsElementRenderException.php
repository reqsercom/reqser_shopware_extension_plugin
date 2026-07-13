<?php declare(strict_types=1);

namespace Reqser\Plugin\Exception;

class CmsElementRenderException extends \RuntimeException
{
    public function __construct(
        private readonly string $elementType,
        string $message,
        \Throwable|null $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function forType(string $elementType, \Throwable $previous): self
    {
        return new self(
            $elementType,
            "CMS element '{$elementType}' could not be rendered: " . $previous->getMessage(),
            $previous
        );
    }

    public function getElementType(): string
    {
        return $this->elementType;
    }
}
