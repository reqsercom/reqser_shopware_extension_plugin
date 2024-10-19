<?php
declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

class ReqserNotificationService
{
    private $notificationRepository;

    public function __construct(EntityRepository $notificationRepository)
    {
        $this->notificationRepository = $notificationRepository;
    }

    public function sendAdminNotification(string $message): void
    {
        $context = Context::createDefaultContext();

        $this->notificationRepository->create([
            [
                'id' => Uuid::randomHex(),
                'status' => 'info',
                'message' => $message,
                'adminOnly' => true,
                'requiredPrivileges' => [],
            ]
        ], $context);
    }
}
