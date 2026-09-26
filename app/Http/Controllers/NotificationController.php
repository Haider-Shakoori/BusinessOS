<?php

namespace App\Http\Controllers;

use App\Models\BusinessNotification;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(NotificationService $notifications): View
    {
        $notifications->refreshOperationalAlerts();

        $items = BusinessNotification::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', auth()->id()))
            ->orderByRaw('read_at IS NOT NULL')
            ->latest('updated_at')
            ->paginate(30);

        return view('notifications.index', [
            'notifications' => $items,
            'unreadCount' => BusinessNotification::query()
                ->where('is_active', true)
                ->whereNull('read_at')
                ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', auth()->id()))
                ->count(),
        ]);
    }

    public function read(BusinessNotification $businessNotification): RedirectResponse
    {
        abort_unless($businessNotification->user_id === null || $businessNotification->user_id === auth()->id(), 404);

        $businessNotification->update(['read_at' => now()]);

        if ($businessNotification->action_url) {
            return redirect()->to($businessNotification->action_url);
        }

        return back();
    }

    public function readAll(): RedirectResponse
    {
        BusinessNotification::query()
            ->where('is_active', true)
            ->whereNull('read_at')
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', auth()->id()))
            ->update(['read_at' => now()]);

        return back()->with('status', __('productivity.notifications.all_read'));
    }
}
