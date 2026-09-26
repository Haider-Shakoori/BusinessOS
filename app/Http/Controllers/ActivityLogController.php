<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
            'method' => ['nullable', 'in:POST,PUT,PATCH,DELETE'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $logs = ActivityLog::query()
            ->with('user')
            ->when($data['search'] ?? null, function ($query, $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('action', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('path', 'like', "%{$search}%");
                });
            })
            ->when($data['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($data['method'] ?? null, fn ($query, $method) => $query->where('method', $method))
            ->when($data['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($data['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('activity-log.index', [
            'logs' => $logs,
            'users' => User::query()
                ->whereHas('memberships', fn ($query) => $query->where('business_id', app(\App\Services\BusinessContext::class)->currentId()))
                ->orderBy('name')
                ->get(),
        ]);
    }
}
