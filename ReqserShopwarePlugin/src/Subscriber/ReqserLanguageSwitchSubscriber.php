<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Reqser\Plugin\Service\ReqserSessionService;
use Reqser\Plugin\Service\ReqserWebhookService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

class ReqserLanguageSwitchSubscriber implements EventSubscriberInterface
{
    private $requestStack;
    private $webhookService;
    private bool $debugMode;
    
    public function __construct(
        RequestStack $requestStack,
        ReqserWebhookService $webhookService
        )
    {
        $this->requestStack = $requestStack;
        $this->webhookService = $webhookService;
        $this->debugMode = false;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'frontend.checkout.switch-language.response' => 'onLanguageSwitchResponse'
        ];
    }

    public function onLanguageSwitchResponse(ResponseEvent $event): void
    {
        try {
            $request = $event->getRequest();
            $response = $event->getResponse();
            
            $session = ReqserSessionService::getSessionWithFallback($request, $this->requestStack);
            if ($session) {
                $session->set('reqser_redirect_user_override_timestamp', time());
                $session->set('reqser_user_override_language_id', $request->request->get('languageId'));
            }            
        } catch (\Throwable $e) {
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook([
                    'type' => 'error',
                    'function' => 'onLanguageSwitchResponse',
                    'message' => $e->getMessage() ?? 'unknown',
                    'trace' => $e->getTraceAsString() ?? 'unknown',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'file' => __FILE__, 
                    'line' => __LINE__,
                ]);
            }
            return;
        }
    }
}
