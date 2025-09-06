<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Reqser\Plugin\Service\ReqserSessionService;
use Reqser\Plugin\Service\ReqserWebhookService;

class ReqserRedirectService
{
    private $sessionService;
    private $webhookService;
    private bool $debugMode = false;
    private bool $debugEchoMode = false;
    
    public function __construct(
        ReqserSessionService $sessionService,
        ReqserWebhookService $webhookService
    ) {
        $this->sessionService = $sessionService;
        $this->webhookService = $webhookService;
    }

    /**
     * Initialize debug modes
     */
    public function setDebugModes(bool $debugMode, bool $debugEchoMode): void
    {
        $this->debugMode = $debugMode;
        $this->debugEchoMode = $debugEchoMode;
    }

    /**
     * Handle direct redirect to a specific domain (bypassing language checks)
     * 
     * @param string $redirectUrl The URL to redirect to
     * @param bool $javaScriptRedirect Whether JavaScript redirect is enabled
     * @param StorefrontRenderEvent|null $event The current event for JavaScript injection
     */
    public function handleDirectDomainRedirect(string $redirectUrl, bool $javaScriptRedirect = false, ?StorefrontRenderEvent $event = null): void
    {
        // Check if headers are already sent
        if (headers_sent()) {
            if ($javaScriptRedirect && $event) {
                if ($this->debugMode) {
                    $this->webhookService->sendErrorToWebhook([
                        'type' => 'debug', 
                        'info' => 'Headers already sent - using JavaScript redirect fallback', 
                        'url' => $redirectUrl, 
                        'file' => __FILE__, 
                        'line' => __LINE__
                    ], $this->debugEchoMode);
                }
                
                try {
                    // Prevent JavaScript redirect if echo mode is active
                    if ($this->debugMode && $this->debugEchoMode) {
                        $this->webhookService->sendErrorToWebhook([
                            'type' => 'debug', 
                            'info' => 'JAVASCRIPT REDIRECT PREVENTED - Echo mode active', 
                            'would_redirect_to' => $redirectUrl, 
                            'file' => __FILE__, 
                            'line' => __LINE__
                        ], $this->debugEchoMode);
                        exit;
                    } else {
                        // Prepare session variables before redirect
                        $this->sessionService->prepareRedirectSession();
                    }
                    
                    $this->injectJavaScriptRedirect($event, $redirectUrl);
                    exit;
                } catch (\Throwable $e) {
                    if ($this->debugMode) {
                        $this->webhookService->sendErrorToWebhook([
                            'type' => 'debug', 
                            'info' => 'Error injecting JavaScript redirect', 
                            'message' => $e->getMessage(), 
                            'file' => __FILE__, 
                            'line' => __LINE__
                        ], $this->debugEchoMode);
                    }
                }
            }
            return;
        }

        // Prevent redirect if echo mode is active so we can see the debug output
        if ($this->debugMode && $this->debugEchoMode) {
            $this->webhookService->sendErrorToWebhook([
                'type' => 'debug', 
                'info' => 'REDIRECT PREVENTED - Echo mode active', 
                'would_redirect_to' => $redirectUrl, 
                'file' => __FILE__, 
                'line' => __LINE__
            ], $this->debugEchoMode);
            exit;
        } else {
            // Prepare session variables before redirect
            $this->sessionService->prepareRedirectSession();
        }
        
        // Execute the redirect
        $response = new RedirectResponse($redirectUrl);
        $response->send();
        exit;
    }

    /**
     * Inject JavaScript redirect when headers are already sent
     * 
     * @param StorefrontRenderEvent $event The storefront render event
     * @param string $redirectUrl The URL to redirect to
     */
    public function injectJavaScriptRedirect(StorefrontRenderEvent $event, string $redirectUrl): void
    {
        $escapedUrl = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
        
        // Since we can't use setParameters, we'll output JavaScript directly
        echo "
        <script type='text/javascript'>
            // Reqser Language Redirect - JavaScript fallback
            (function() {
                var redirectUrl = '{$escapedUrl}';
                console.log('Reqser: Headers already sent, using JavaScript redirect to:', redirectUrl);
                
                // Small delay to ensure page elements are loaded
                setTimeout(function() {
                    window.location.href = redirectUrl;
                }, 100);
                
                // Backup: If setTimeout doesn't work, try immediate redirect
                if (document.readyState === 'complete') {
                    window.location.href = redirectUrl;
                }
            })();
        </script>";
    }

    /**
     * Sanitize URL by removing query parameters and applying rtrim
     * 
     * @param string $url The URL to sanitize
     * @return string The sanitized URL
     */
    public function sanitizeUrl(string $url): string
    {
        // Remove query parameters (everything after ?)
        $urlWithoutParams = strpos($url, '?') !== false ? substr($url, 0, strpos($url, '?')) : $url;
        
        // Apply rtrim to remove trailing slashes
        return rtrim($urlWithoutParams, '/');
    }

    /**
     * Check if domain is valid for redirect operations
     * 
     * @param array $redirectConfig Pre-parsed redirect configuration
     * @param object $currentDomain Current domain object
     * @return bool Returns true if domain is valid for redirect, false otherwise
     */
    public function isDomainValidForRedirect(array $redirectConfig, $currentDomain): bool
    {
        // Check if domain is active
        if (!($redirectConfig['active'] ?? false)) {
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook([
                    'type' => 'debug', 
                    'info' => 'Domain is not active - stopping redirect', 
                    'domain_id' => $currentDomain->getId(), 
                    'file' => __FILE__, 
                    'line' => __LINE__
                ], $this->debugEchoMode);
            }
            return false;
        }

        // Check if redirectFrom is enabled
        if (!($redirectConfig['redirectFrom'] ?? false)) {
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook([
                    'type' => 'debug', 
                    'info' => 'Domain redirectFrom disabled - stopping redirect', 
                    'domain_id' => $currentDomain->getId(), 
                    'file' => __FILE__, 
                    'line' => __LINE__
                ], $this->debugEchoMode);
            }
            return false;
        }

        return true;
    }
}
