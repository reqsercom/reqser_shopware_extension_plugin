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
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Shopware\Core\Content\Product\SalesChannel\Review\Event\ProductReviewsLoadedEvent;
use Shopware\Core\Content\Product\SalesChannel\Review\ProductReviewsWidgetLoadedHook;

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
            KernelEvents::RESPONSE => 'onKernelResponse',
            ProductReviewsLoadedEvent::class => 'onProductReviewsLoaded',
            ProductReviewsWidgetLoadedHook::class => 'onProductReviewsWidgetLoaded'
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

    public function onKernelResponse(ResponseEvent $event): void
    {
        try {
            $request = $event->getRequest();
            $response = $event->getResponse();
            
            // Only handle AJAX requests
            if (!$request->isXmlHttpRequest()) {
                return;
            }
            
            // Check if this is a review-related route (more comprehensive check)
            $route = $request->attributes->get('_route');
            $path = $request->getPathInfo();
            
            // Log all AJAX requests for debugging
            $this->logger->info('Reqser Plugin caught AJAX request', [
                'route' => $route,
                'path' => $path,
                'method' => $request->getMethod(),
                'contentType' => $response->headers->get('Content-Type'),
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);
            
            // Check various possible review routes and paths
            $isReviewRoute = $route && (
                str_contains($route, 'review') ||
                str_contains($route, 'product') ||
                str_contains($route, 'frontend.product')
            );
            
            $isReviewPath = str_contains($path, 'review') || 
                           str_contains($path, 'product') ||
                           str_contains($path, 'ajax');
            
            if (!$isReviewRoute && !$isReviewPath) {
                return;
            }
            
            // Get the sales channel context from the request
            $context = $request->attributes->get('sw-sales-channel-context');
            if (!$context) {
                return;
            }
            
            // Validate if we should process this request
            if (!$this->shouldProcessRequest($context, $request)) {
                return;
            }

            $currentLanguage = $context->getLanguageId();
            
            // Process AJAX review response with debugging
            $this->processAjaxReviewResponse($response, $currentLanguage, $route, $path);

        } catch (\Throwable $e) {
            $this->handleError('onKernelResponse', $e);
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

    public function onProductReviewsWidgetLoaded(ProductReviewsWidgetLoadedHook $event): void
    {
        try {
            $context = $event->getSalesChannelContext();
            $reviews = $event->getReviews();
            
            $this->logger->info('Reqser Plugin caught ProductReviewsWidgetLoadedHook', [
                'productId' => $reviews->getProductId(),
                'totalReviews' => $reviews->getTotal(),
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);
            
            // Process the reviews from the hook
            $this->modifyProductReviews($reviews->getEntities(), $context->getLanguageId());

        } catch (\Throwable $e) {
            $this->handleError('onProductReviewsWidgetLoaded', $e);
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
     * Processes AJAX review response
     */
    private function processAjaxReviewResponse($response, string $currentLanguage, string $route = '', string $path = ''): void
    {
        if (!$response->getContent()) {
            return;
        }
        
        $content = $response->getContent();
        $contentType = $response->headers->get('Content-Type', '');
        
        // Log for debugging
        $this->logger->info('Reqser Plugin processing AJAX response', [
            'route' => $route,
            'path' => $path,
            'contentType' => $contentType,
            'contentLength' => strlen($content),
            'file' => __FILE__, 
            'line' => __LINE__,
        ]);
        
        // Handle JSON responses
        if (str_contains($contentType, 'application/json') || $this->isJson($content)) {
            $data = json_decode($content, true);
            if ($data) {
                $modified = false;
                
                // Check for different possible review data structures
                if (isset($data['reviews'])) {
                    $this->modifyReviewsInResponse($data['reviews'], $currentLanguage);
                    $modified = true;
                } elseif (isset($data['data']['reviews'])) {
                    $this->modifyReviewsInResponse($data['data']['reviews'], $currentLanguage);
                    $modified = true;
                } elseif (isset($data['elements'])) {
                    $this->modifyReviewsInResponse($data['elements'], $currentLanguage);
                    $modified = true;
                } elseif (isset($data['data']['elements'])) {
                    $this->modifyReviewsInResponse($data['data']['elements'], $currentLanguage);
                    $modified = true;
                }
                
                if ($modified) {
                    $response->setContent(json_encode($data));
                    $this->logger->info('Reqser Plugin modified review response', [
                        'route' => $route,
                        'file' => __FILE__, 
                        'line' => __LINE__,
                    ]);
                }
            }
        }
        // Handle HTML responses (some pagination might return HTML)
        elseif (str_contains($contentType, 'text/html') || str_contains($content, '<div')) {
            // For HTML responses, we might need to parse and modify HTML content
            // This is more complex and would require DOM manipulation
            $this->logger->info('Reqser Plugin found HTML response for reviews', [
                'route' => $route,
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);
        }
    }
    
    /**
     * Check if a string is valid JSON
     */
    private function isJson($string): bool
    {
        if (!is_string($string)) {
            return false;
        }
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
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
     * Modifies reviews in AJAX response
     */
    private function modifyReviewsInResponse(array &$reviews, string $currentLanguageId): void
    {
        try {
            foreach ($reviews as &$review) {
                $this->translateReviewArray($review, $currentLanguageId);
            }

        } catch (\Exception $e) {
            $this->logger->error('Reqser Plugin Error modifying reviews in response', [
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
     * Translates a single review array
     */
    private function translateReviewArray(array &$review, string $currentLanguageId): void
    {
        // Check if the review has a different language ID
        if (isset($review['languageId']) && $review['languageId'] !== $currentLanguageId) {
            $translationData = $this->getTranslationData($review['customFields'] ?? [], $currentLanguageId);
            if ($translationData) {
                $this->applyTranslationToArray($review, $translationData, $currentLanguageId);
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

    /**
     * Applies translation to a review array
     */
    private function applyTranslationToArray(array &$review, array $translationData, string $currentLanguageId): void
    {
        if (isset($translationData['title'])) {
            $review['title'] = $translationData['title'];
        }
        if (isset($translationData['content'])) {
            $review['content'] = $translationData['content'];
        }
        if (isset($translationData['comment'])) {
            $review['comment'] = $translationData['comment'];
        }
        
        // Update the language ID to match the current language
        $review['languageId'] = $currentLanguageId;
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