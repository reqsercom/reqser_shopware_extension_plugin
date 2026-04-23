<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Attribute;

/**
 * Marks an API controller (or single controller method) as requiring
 * Reqser App integration authentication.
 *
 * When present, ReqserApiAuthSubscriber runs the shared validation
 * (ReqserApiAuthService::validateAuthentication) before the controller
 * executes and short-circuits the response on failure.
 *
 * Usage on a whole controller (protects every action):
 *     #[ReqserApiAuth]
 *     class MyApiController extends AbstractController { ... }
 *
 * Usage on a single method (for mixed controllers):
 *     #[ReqserApiAuth]
 *     public function someAction(Request $request, Context $context): JsonResponse { ... }
 *
 * Not inherited by subclasses on purpose: attribute resolution uses the
 * declaring class, so putting this on an abstract base would not leak
 * into foreign plugins that extend it.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class ReqserApiAuth
{
}
