<?php

namespace App\Http\Controllers;

use App\Models\BusinessNotification;
use App\Services\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class NotificationCenterController extends Controller
{
    public function index(BusinessContext $context): View
    {
        $userId = auth()->id();

        return view('system.notifications', [
            'notifications' => BusinessNotification::query()
                ->where(function ($query) use ($userId): void {
                    $query->whereNull('user_id')->orWhere('user_id', $userId);
                })
                ->latest('created_at')
                ->paginate(30),
            'unreadCount' => BusinessNotification::query()
                ->where(function ($query) use ($userId): void {
                    $query->whereNull('user_id')->orWhere('user_id', $userId);
                })
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function markRead(BusinessNotification $notification, BusinessContext $context): RedirectResponse
    {
        abort_unless($notification->business_id === $context->currentId(), 404);
        abort_unless($notification->user_id === null || $notification->user_id === auth()->id(), 404);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return back();
    }

    public function markAllRead(BusinessContext $context): RedirectResponse
    {
        $userId = auth()->id();

        BusinessNotification::query()
            ->where(function ($query) use ($userId): void {
                $query->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with('status', __('system.notifications.all_read'));
    }
}
