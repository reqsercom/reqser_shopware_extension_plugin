<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Reqser\Plugin\Service\ReqserWebhookService;
use Reqser\Plugin\Service\ReqserAppService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Shopware\Storefront\Page\Product\ProductPage;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\SalesChannel\Review\Event\ProductReviewsLoadedEvent;

class ReqserProductReviewSubscriber implements EventSubscriberInterface
{
    private $requestStack;
    private $webhookService;
    private $appService;
    private $domainRepository;
    private $logger;
    
    public function __construct(
        RequestStack $requestStack,
        ReqserWebhookService $webhookService,
        ReqserAppService $appService,
        EntityRepository $domainRepository,
        LoggerInterface $logger
    ) {
        $this->requestStack = $requestStack;
        $this->webhookService = $webhookService;
        $this->appService = $appService;
        $this->domainRepository = $domainRepository;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender',
            ProductReviewsLoadedEvent::class => 'onProductReviewsLoaded'
        ];
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        try {
            $context = $event->getSalesChannelContext();
            $request = $event->getRequest();
            
            // Validate if we should process this request
            if (!$this->shouldProcessRequest($context, $request)) {
                return;
            }

            $currentLanguage = $context->getLanguageId();
            $parameters = $event->getParameters();
            
            // Check if we're on a product page with reviews
            if (isset($parameters['page']) && $parameters['page'] instanceof ProductPage) {
                $this->processProductPageReviews($parameters['page'], $currentLanguage);
            }

        } catch (\Throwable $e) {
            $this->handleError('onStorefrontRender', $e);
        }
    }



    public function onProductReviewsLoaded(ProductReviewsLoadedEvent $event): void
    {
        try {
            $context = $event->getSalesChannelContext();
            $request = $event->request;
            
            // Validate if we should process this request
            if (!$this->shouldProcessRequest($context, $request)) {
                return;
            }

            $currentLanguage = $context->getLanguageId();
            $reviews = $event->reviews;
            
            $this->logger->info('Reqser Plugin caught ProductReviewsLoadedEvent', [
                'productId' => $reviews->getProductId(),
                'totalReviews' => $reviews->getTotal(),
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);
            
            // Process the reviews from the event
            $this->modifyProductReviews($reviews->getEntities(), $currentLanguage);

        } catch (\Throwable $e) {
            $this->handleError('onProductReviewsLoaded', $e);
        }
    }



    /**
     * Validates if the request should be processed based on app status and domain settings
     */
    private function shouldProcessRequest(SalesChannelContext $context, $request): bool
    {
        // Check if the app is active
        if (!$this->appService->isAppActive()) {
            return false;
        }

        $currentLanguage = $context->getLanguageId();
        if (!$currentLanguage) {
            return false;
        }

        // Check if review translation is active for this sales channel domain
        $domainId = $request->attributes->get('sw-domain-id');
        if (!$domainId) {
            return false;
        }
        
        // Retrieve sales channel domains for the current context
        $salesChannelDomains = $this->getSalesChannelDomains($context);
        $currentDomain = $salesChannelDomains->get($domainId);
        if (!$currentDomain) {
            return false;
        }
        
        $customFields = $currentDomain->getCustomFields();
        
        return isset($customFields['ReqserReviewTranslate']['active']) && 
               $customFields['ReqserReviewTranslate']['active'] === true;
    }

    /**
     * Processes reviews on a product page
     */
    private function processProductPageReviews(ProductPage $page, string $currentLanguage): void
    {
        // Access the reviewLoaderResult property using reflection
        $reflection = new \ReflectionClass($page);
        if ($reflection->hasProperty('reviewLoaderResult')) {
            $property = $reflection->getProperty('reviewLoaderResult');
            $property->setAccessible(true);
            $reviewLoaderResult = $property->getValue($page);
            
            if ($reviewLoaderResult) {
                // Access the entities property directly using reflection
                $reflection = new \ReflectionClass($reviewLoaderResult);
                if ($reflection->hasProperty('entities')) {
                    $property = $reflection->getProperty('entities');
                    $property->setAccessible(true);
                    $entities = $property->getValue($reviewLoaderResult);
                    if ($entities) {
                        $this->modifyProductReviews($entities, $currentLanguage);
                    }
                }
            }
        }
    }



    /**
     * Handles errors consistently across all methods
     */
    private function handleError(string $function, \Throwable $e): void
    {
        $this->logger->error("Reqser Plugin Error in {$function}", [
            'message' => $e->getMessage(),
            'file' => __FILE__, 
            'line' => __LINE__,
        ]);
        
        $this->webhookService->sendErrorToWebhook([
            'type' => 'error',
            'function' => $function,
            'message' => $e->getMessage() ?? 'unknown',
            'trace' => $e->getTraceAsString() ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s'),
            'file' => __FILE__, 
            'line' => __LINE__,
        ]);
    }

    /**
     * Modifies product review entities
     */
    private function modifyProductReviews($reviews, string $currentLanguageId): void
    {
        try {
            if (!$reviews) {
                return;
            }

            // Get the elements from the ProductReviewCollection
            $reviewElements = $reviews->getElements();
            foreach ($reviewElements as $reviewId => $review) { 
                $this->translateReviewEntity($review, $currentLanguageId);
            }

        } catch (\Exception $e) {
            $this->logger->error('Reqser Plugin Error modifying product reviews', [
                'message' => $e->getMessage(),
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);
        }
    }



    /**
     * Translates a single review entity
     */
    private function translateReviewEntity($review, string $currentLanguageId): void
    {
        // Compare the review language ID with current language ID
        if ($review->getLanguageId() !== $currentLanguageId) {
            // Get custom fields directly from the review entity
            $customFields = $review->getCustomFields();
            
            $translationData = $this->getTranslationData($customFields, $currentLanguageId);
            if ($translationData) {
                $this->applyTranslationToEntity($review, $translationData, $currentLanguageId);
            }
        }
    }



    /**
     * Gets translation data from custom fields
     */
    private function getTranslationData($customFields, string $currentLanguageId): ?array
    {
        if (isset($customFields['ReqserTranslation']) && 
            isset($customFields['ReqserTranslation'][$currentLanguageId])) {
            return $customFields['ReqserTranslation'][$currentLanguageId];
        }
        return null;
    }

    /**
     * Applies translation to a review entity
     */
    private function applyTranslationToEntity($review, array $translationData, string $currentLanguageId): void
    {
        if (isset($translationData['title'])) {
            $review->setTitle($translationData['title']);
        }
        if (isset($translationData['content'])) {
            $review->setContent($translationData['content']);
        }
        if (isset($translationData['comment'])) {
            $review->setComment($translationData['comment']);
        }
        
        // Update the language ID to match the current language
        $review->setLanguageId($currentLanguageId);
    }



    private function getSalesChannelDomains(SalesChannelContext $context)
    {
        // Retrieve the full collection without limiting to the current sales channel
        $criteria = new Criteria();
        
        // Ensure we're only retrieving domains that have the 'ReqserReviewTranslate' custom field set
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('customFields.ReqserReviewTranslate', null)
        ]));
    
        // Get the collection from the repository
        return $this->domainRepository->search($criteria, $context->getContext())->getEntities();
    }
} 