<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Reqser\Plugin\Service\ReqserWebhookService;
use Reqser\Plugin\Service\ReqserAppService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Shopware\Storefront\Page\Product\ProductPage;
use Psr\Log\LoggerInterface;

class ReqserProductReviewSubscriber implements EventSubscriberInterface
{
    private $requestStack;
    private $webhookService;
    private $appService;
    private $logger;
    
    public function __construct(
        RequestStack $requestStack,
        ReqserWebhookService $webhookService,
        ReqserAppService $appService,
        LoggerInterface $logger
    ) {
        $this->requestStack = $requestStack;
        $this->webhookService = $webhookService;
        $this->appService = $appService;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender'
        ];
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        try {
            // Check if the app is active
            if (!$this->appService->isAppActive()) {
                return;
            }

            $context = $event->getSalesChannelContext();
            $currentLanguage = $context->getLanguageId();
            
            if (!$currentLanguage) {
                return;
            }

            // Check if review translation is active for this sales channel
            $salesChannel = $context->getSalesChannel();
            $customFields = $salesChannel->getCustomFields();
            
            if (!isset($customFields['ReqserReviewTranslate']['active']) || $customFields['ReqserReviewTranslate']['active'] !== true) {
                return;
            }

            // Get the template data
            $parameters = $event->getParameters();
            
            // Check if we're on a product page with reviews
            if (isset($parameters['page']) && 
                $parameters['page'] instanceof ProductPage) {
                
                // Access the reviewLoaderResult property using reflection
                $reflection = new \ReflectionClass($parameters['page']);
                if ($reflection->hasProperty('reviewLoaderResult')) {
                    $property = $reflection->getProperty('reviewLoaderResult');
                    $property->setAccessible(true);
                    $reviewLoaderResult = $property->getValue($parameters['page']);
                    //dd($reviewLoaderResult, method_exists($reviewLoaderResult, 'getReviews'));
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

        } catch (\Throwable $e) {
            $this->logger->error('Reqser Plugin Error in onStorefrontRender', [
                'message' => $e->getMessage(),
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);
            
            $this->webhookService->sendErrorToWebhook([
                'type' => 'error',
                'function' => 'onStorefrontRender',
                'message' => $e->getMessage() ?? 'unknown',
                'trace' => $e->getTraceAsString() ?? 'unknown',
                'timestamp' => date('Y-m-d H:i:s'),
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);
        }
    }

    private function modifyProductReviews($reviews, string $currentLanguageId): void
    {
        try {
            if (!$reviews) {
                return;
            }

            // Get the elements from the ProductReviewCollection
            $reviewElements = $reviews->getElements();
            foreach ($reviewElements as $reviewId => $review) { 
                // Compare the review language ID with current language ID
                if ($review->getLanguageId() !== $currentLanguageId) {
                    // Get custom fields directly from the review entity
                    $customFields = $review->getCustomFields();
                    
                    // Check if ReqserTranslation exists and has the current language
                    if (isset($customFields['ReqserTranslation']) && 
                        isset($customFields['ReqserTranslation'][$currentLanguageId])) {
                        
                        $translationData = $customFields['ReqserTranslation'][$currentLanguageId];
                        
                        // Update the review with translated content
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
                }
            }

        } catch (\Exception $e) {
            $this->logger->error('Reqser Plugin Error modifying product reviews', [
                'message' => $e->getMessage(),
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);
        }
    } 
} 