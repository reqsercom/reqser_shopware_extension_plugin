<?php

namespace Reqser\Plugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Reqser\Plugin\Service\ReqserNotificationService;

class ReqserLanguageRedirectSubscriber implements EventSubscriberInterface
{
    private $requestStack;
    private $notificationService;

    public function __construct(RequestStack $requestStack, ReqserNotificationService $notificationService)
    {
        $this->requestStack = $requestStack;
        $this->notificationService = $notificationService;
    }

    public static function getSubscribedEvents(): array
    {
        // Define the event(s) this subscriber listens to
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender'
        ];
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        // Log the event for debugging
        $this->notificationService->sendAdminNotification('StorefrontRenderEvent triggered');

        /*// Retrieve the current request
        $request = $this->requestStack->getCurrentRequest();

        // Example language detection logic
        $preferredLanguage = $request->getPreferredLanguage(['en', 'de', 'fr']); // Add your supported languages

        // Example redirection based on language
        if ($preferredLanguage === 'de') {
            $response = new RedirectResponse('/de');  // Change this URL accordingly
            $response->send();
            exit;
        }*/
    }
}
