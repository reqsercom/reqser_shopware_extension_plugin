<?php

namespace Reqser\Plugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Reqser\Plugin\Service\ReqserNotificationService;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;

class ReqserLanguageRedirectSubscriber implements EventSubscriberInterface
{
    private $requestStack;
    private $notificationService;
    private $salesChannelContextFactory;
    private $domainRepository;

    public function __construct(RequestStack $requestStack, ReqserNotificationService $notificationService, CachedSalesChannelContextFactory $salesChannelContextFactory, EntityRepository $domainRepository)
    {
        $this->requestStack = $requestStack;
        $this->notificationService = $notificationService;
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->domainRepository = $domainRepository; // Injecting the repository directly
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
        $request = $event->getRequest();
        $domainId = $request->attributes->get('sw-domain-id');
        $session = $request->getSession(); // Get the session from the request

        if ($session->get('reqser_redirect_done', false)) {
            return; // Skip redirect if it's already been done
        }
    
        // Retrieve sales channel domains for the current context
        $salesChannelDomains = $this->getSalesChannelDomains($event->getSalesChannelContext());
    
        if (count($salesChannelDomains) > 0) {
            $browserLanguages = explode(',', (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : ''));
            $region_code_exist = false;
    
            foreach ($browserLanguages as $browserLanguage) {
                $languageCode = explode(';', $browserLanguage)[0]; // Get the part before ';'
                if (!$region_code_exist && strpos($languageCode, '-') !== false) {
                    $region_code_exist = true;
                }
                $preferred_browser_language = strtolower(trim($languageCode)); // Normalize to lowercase and trim spaces
                $this->handleLanguageRedirect($preferred_browser_language, $salesChannelDomains, $domainId);

                if (isset($customFields['ReqserRedirect']['onlyRedirectDefaultLanguage']) && $customFields['ReqserRedirect']['onlyRedirectDefaultLanguage'] === true) {
                    break; // Break the loop if onlyRedirectDefaultLanguage is enabled
                }
            }
    
            // Second pass: If matchOnlyLanguage is enabled, check without region code
            if ($region_code_exist && isset($customFields['ReqserRedirect']['redirectWithoutCountryCode']) && $customFields['ReqserRedirect']['redirectWithoutCountryCode'] === true) {
                foreach ($browserLanguages as $browserLanguage) {
                    $languageCode = explode(';', $browserLanguage)[0]; // Get the part before ';'
                    $preferred_browser_language = strtolower(trim(explode('-', $languageCode)[0])); // Trim and get only the language code
                    $this->handleLanguageRedirect($preferred_browser_language, $salesChannelDomains, $domainId);
                    if (isset($customFields['ReqserRedirect']['onlyRedirectDefaultLanguage']) && $customFields['ReqserRedirect']['onlyRedirectDefaultLanguage'] === true) {
                        break; // Break the loop if onlyRedirectDefaultLanguage is enabled
                    }
                }
            }
        }
    }
    
    private function handleLanguageRedirect(string $preferred_browser_language, SalesChannelDomainCollection $salesChannelDomains, string $domainId): void
    {
        foreach ($salesChannelDomains as $salesChannelDomain) {
            $customFields = $salesChannelDomain->getCustomFields();
    
            // Check if ReqserRedirect exists and has a languageRedirect array
            if (isset($customFields['ReqserRedirect']['languageRedirect']) && is_array($customFields['ReqserRedirect']['languageRedirect'])) {
                if (in_array($preferred_browser_language, $customFields['ReqserRedirect']['languageRedirect'])) {
                    
                    if ($domainId != $salesChannelDomain->getId()) {
                        $this->requestStack->getSession()->set('reqser_redirect_done', true);
                        $response = new RedirectResponse($salesChannelDomain->getUrl());
                        $response->send();
                        exit; // Exit after redirecting
                    }
                }
            }
        }
    }

    private function getSalesChannelDomains(SalesChannelContext $context)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannelId', $context->getSalesChannel()->getId()));

        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('customFields.ReqserRedirect', null)
        ]));

        return $this->domainRepository->search($criteria, $context->getContext())->getEntities();
    }
}
