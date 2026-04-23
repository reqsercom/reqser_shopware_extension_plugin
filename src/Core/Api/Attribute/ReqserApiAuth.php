<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Attribute;

/** Marks an API controller or method as requiring Reqser App authentication. */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class ReqserApiAuth
{
}
