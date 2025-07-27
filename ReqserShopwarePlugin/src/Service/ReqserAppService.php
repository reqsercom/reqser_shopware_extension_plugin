<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

class ReqserAppService
{
    private Connection $connection;
    private RequestStack $requestStack;

    public function __construct(
        Connection $connection,
        RequestStack $requestStack
    ) {
        $this->connection = $connection;
        $this->requestStack = $requestStack;
    }

    public function isAppActive(): bool
    {
        try {
            $request = $this->requestStack->getCurrentRequest();
            
            if (!$request) {
                // No request available (e.g., CLI context), query database directly
                return $this->queryDatabaseForAppStatus();
            }
            
            $session = $request->getSession();
            
            if (!$session) {
                // No session available, query database directly
                return $this->queryDatabaseForAppStatus();
            }
            
            // Check if app status is already stored in session
            if ($session->has('reqser_app_active')) {
                return $session->get('reqser_app_active');
            }
            
            // Session miss - check database
            $is_app_active = $this->queryDatabaseForAppStatus();
            
            // Store in session for future requests
            $session->set('reqser_app_active', $is_app_active);
            
            return $is_app_active;
            
        } catch (\Throwable $e) {
            // If anything goes wrong with session handling, fall back to database query
            return $this->queryDatabaseForAppStatus();
        }
    }
    
    private function queryDatabaseForAppStatus(): bool
    {
        try {
            $app_name = "ReqserApp";
            $is_app_active = $this->connection->fetchOne(
                "SELECT active FROM `app` WHERE name = :app_name",
                ['app_name' => $app_name]
            );
            
            return (bool)$is_app_active;
        } catch (\Throwable $e) {
            return false;
        }
    }
} 