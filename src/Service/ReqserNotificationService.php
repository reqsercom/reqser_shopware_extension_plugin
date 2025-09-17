<?php
declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class ReqserNotificationService
{
    private $notificationRepository;

    public function __construct(EntityRepository $notificationRepository)
    {
        $this->notificationRepository = $notificationRepository;
    }

    public function sendAdminNotification(string $message, string $status = 'info', array $requiredPrivileges = [], bool $checkExisting = true): void
    {
        $context = Context::createDefaultContext();

        $shouldCreate = true;
        
        // Check if notification with same message already exists to prevent duplicates (if enabled)
        if ($checkExisting) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('message', $message));
            $criteria->addFilter(new EqualsFilter('status', $status));

            $existingNotifications = $this->notificationRepository->search($criteria, $context);
            $shouldCreate = $existingNotifications->getTotal() === 0;
        }
        
        // Create notification if conditions are met
        if ($shouldCreate) {
            $notificationData = [
                'id' => Uuid::randomHex(),
                'status' => $status, // 'info', 'success', 'error', etc.
                'message' => $message,
                'adminOnly' => true, // If user-specific, not admin-only
                'requiredPrivileges' => $requiredPrivileges, // Configurable privileges
                'createdAt' => new \DateTimeImmutable(),
            ];

            $this->notificationRepository->create([$notificationData], $context);
        } 
    }
}
