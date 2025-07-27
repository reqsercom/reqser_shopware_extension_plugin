<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Reqser\Plugin\Service\ReqserWebhookService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ReqserLanguageSwitchSubscriber implements EventSubscriberInterface
{
    private $requestStack;
    private $webhookService;
    
    public function __construct(
        RequestStack $requestStack,
        ReqserWebhookService $webhookService
        )
    {
        $this->requestStack = $requestStack;
        $this->webhookService = $webhookService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse'
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        try {
            $request = $event->getRequest();
            $domainId = $request->attributes->get('sw-domain-id');
            $currentRoute = $request->attributes->get('_route');

            if ($currentRoute !== 'frontend.checkout.switch-language' || $domainId === null) {
                return;
            }

            $this->requestStack->getSession()->set('reqser_redirect_domain_user_override', $domainId);
        } catch (\Throwable $e) {
            $this->webhookService->sendErrorToWebhook([
                'type' => 'error',
                'function' => 'onKernelResponse',
                'message' => $e->getMessage() ?? 'unknown',
                'trace' => $e->getTraceAsString() ?? 'unknown',
                'timestamp' => date('Y-m-d H:i:s'),
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);
            return;
        }
    }
}
