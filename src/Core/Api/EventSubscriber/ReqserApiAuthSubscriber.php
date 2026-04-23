<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\EventSubscriber;

use Psr\Log\LoggerInterface;
use Reqser\Plugin\Core\Api\Attribute\ReqserApiAuth;
use Reqser\Plugin\Service\ReqserApiAuthService;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runs Reqser App auth for every controller tagged with #[ReqserApiAuth].
 *
 * @see docs/integrations/shopware_plugin/ReqserApiAuth.md
 */
class ReqserApiAuthSubscriber implements EventSubscriberInterface
{
    private ReqserApiAuthService $authService;
    private LoggerInterface $logger;

    public function __construct(
        ReqserApiAuthService $authService,
        LoggerInterface $logger
    ) {
        $this->authService = $authService;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        // Priority -12 runs right after Shopware's ContextResolverListener
        // (priority -10) and before the scope-validation listeners (-19+),
        // so $request->attributes[ATTRIBUTE_CONTEXT_OBJECT] is populated.
        return [
            KernelEvents::CONTROLLER => ['onController', -12],
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        $controller = $event->getController();

        if (!\is_array($controller) || \count($controller) !== 2) {
            return;
        }

        [$instance, $methodName] = $controller;

        try {
            $reflectionMethod = new \ReflectionMethod($instance, $methodName);
        } catch (\ReflectionException $e) {
            return;
        }

        $hasMethodAttr = $reflectionMethod->getAttributes(ReqserApiAuth::class) !== [];
        $hasClassAttr  = $reflectionMethod->getDeclaringClass()->getAttributes(ReqserApiAuth::class) !== [];

        if (!$hasMethodAttr && !$hasClassAttr) {
            return;
        }

        $request = $event->getRequest();
        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT);

        if (!$context instanceof Context) {
            $this->logger->warning('Reqser API auth subscriber: Shopware Context not available on request', [
                'route'    => $request->attributes->get('_route'),
                'endpoint' => $request->getPathInfo(),
                'method'   => $request->getMethod(),
            ]);

            $event->setController(static fn() => new JsonResponse([
                'success' => false,
                'error'   => 'Context unavailable',
                'message' => 'Shopware Context could not be resolved for this request',
            ], 500));
            return;
        }

        $result = $this->authService->validateAuthentication($request, $context);

        if ($result !== true) {
            $event->setController(static fn() => $result);
        }
    }
}
