<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Symfony\Component\HttpFoundation\RequestStack;

class ReqserSessionService
{
    private $session = null;
    private ?int $redirectCount = null;

    public function __construct()
    {
    }

    /**
     * Initialize the service with a session
     * Returns false if session is null to indicate initialization failed
     */
    public function initialize($session): bool
    {
        if ($session === null) {
            return false; // Initialization failed - no session available
        }
        
        $this->session = $session;
        $this->redirectCount = null; // Reset cache when session changes
        return true; // Initialization successful
    }

    /**
     * Get session with fallback strategy
     * First tries to get session from request, then falls back to requestStack
     */
    public static function getSessionWithFallback($request, RequestStack $requestStack)
    {
        // Try to get session from request first
        $session = $request->getSession();
        
        // Try fallback to requestStack if request session is null
        if (!$session) {
            try {
                $session = $requestStack->getSession();
            } catch (\Throwable $e) {
                // If both methods fail, session is truly unavailable
                $session = null;
            }
        }
        
        return $session;
    }

    /**
     * Check if redirect was already called
     */
    public function isRedirectCalled(): bool
    {
        return $this->session->get('reqser_redirect_called', false);
    }


    /**
     * Get last redirect timestamp
     */
    public function getLastRedirectTime(): ?float
    {
        return $this->session->get('reqser_last_redirect_at', null);
    }

    /**
     * Set last redirect timestamp
     */
    public function setLastRedirectTime(?float $timestamp = null): void
    {
        if ($timestamp === null) {
            $timestamp = microtime(true) * 1000;
        }
        $this->session->set('reqser_last_redirect_at', $timestamp);
    }

    /**
     * Get last redirect timestamp
     */
    public function getLastRedirectAt(): ?float
    {
        return $this->session->get('reqser_last_redirect_at', null);
    }

    /**
     * Get redirect count from session and cache it
     */
    public function getRedirectCount(): int
    {
        if ($this->redirectCount === null) {
            $this->redirectCount = $this->session->get('reqser_redirect_count', 0);
        }
        return $this->redirectCount;
    }

    /**
     * Increment and store redirect count
     */
    public function incrementRedirectCount(): int
    {
        $redirectCount = $this->getRedirectCount() + 1;
        $this->redirectCount = $redirectCount;
        $this->session->set('reqser_redirect_count', $redirectCount);
        return $redirectCount;
    }

    /**
     * Get script call count
     */
    public function getScriptCallCount(): int
    {
        return $this->session->get('reqser_script_call_count', 0);
    }

    /**
     * Increment script call count
     */
    public function incrementScriptCallCount(?int $currentCount = null): int
    {
        if ($currentCount === null) {
            $currentCount = $this->getScriptCallCount();
        }
        $scriptCallCount = $currentCount + 1;
        $this->session->set('reqser_script_call_count', $scriptCallCount);
        return $scriptCallCount;
    }

    /**
     * Get user override timestamp
     */
    public function getUserlanguageSwitchTimestamp(): ?int
    {
        return $this->session->get('reqser_redirect_user_override_timestamp', null);
    }



    /**
     * Get user override language ID from session
     */
    public function getUserOverrideLanguageId(): ?string
    {
        return $this->session?->get('reqser_user_override_language_id', null);
    }


    /**
     * Prepare session variables before redirecting
     */
    public function prepareRedirectSession(): void
    {
        $this->setLastRedirectTime();
        $this->incrementRedirectCount();
    }

    /**
     * Get all session data (for debugging)
     */
    public function getAllSessionData(): array
    {
        return $this->session?->all() ?? [];
    }
    


}
