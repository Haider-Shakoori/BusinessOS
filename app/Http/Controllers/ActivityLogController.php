<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request, BusinessContext $context): View
    {
        $businessId = $context->currentId();

        abort_unless($businessId, 404);

        $search = trim((string) $request->string('search'));
        $event = trim((string) $request->string('event'));
        $userId = $request->integer('user_id');

        $logs = ActivityLog::query()
            ->where('business_id', $businessId)
            ->with('user')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('route_name', 'like', "%{$search}%")
                        ->orWhere('path', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%");
                });
            })
            ->when($event !== '', fn ($query) => $query->where('event', $event))
            ->when($userId > 0, fn ($query) => $query->where('user_id', $userId))
            ->latest('occurred_at')
            ->paginate(30)
            ->withQueryString();

        $userIds = ActivityLog::query()
            ->where('business_id', $businessId)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        return view('system.activity-log', [
            'logs' => $logs,
            'users' => User::query()->whereIn('id', $userIds)->orderBy('name')->get(),
            'events' => ActivityLog::query()
                ->where('business_id', $businessId)
                ->distinct()
                ->orderBy('event')
                ->pluck('event'),
            'searchTerm' => $search,
            'eventFilter' => $event,
            'userFilter' => $userId,
        ]);
    }
}
