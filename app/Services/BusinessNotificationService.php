<?php

namespace App\Services;

use App\Models\BusinessNotification;

class BusinessNotificationService
{
    public function create(
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?int $userId = null,
        string $type = 'system',
        array $data = [],
    ): BusinessNotification {
        return BusinessNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
            'data' => $data === [] ? null : $data,
        ]);
    }
}
