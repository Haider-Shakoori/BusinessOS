<?php

namespace App\Services;

use App\Models\BusinessMembership;
use App\Models\BusinessNotification;

class BusinessNotificationService
{
    public function __construct(
        private readonly BusinessContext $context,
    ) {
        //
    }

    public function create(
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?int $userId = null,
        string $type = 'system',
        array $data = [],
    ): void {
        $businessId = $this->context->currentId();

        if ($businessId === null) {
            return;
        }

        $userIds = $userId !== null
            ? collect([$userId])
            : BusinessMembership::query()
                ->where('business_id', $businessId)
                ->pluck('user_id');

        foreach ($userIds->unique()->values() as $recipientId) {
            BusinessNotification::create([
                'user_id' => $recipientId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
                'data' => $data === [] ? null : $data,
            ]);
        }
    }
}
