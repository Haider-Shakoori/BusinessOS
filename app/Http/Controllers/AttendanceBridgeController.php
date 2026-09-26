<?php

namespace App\Http\Controllers;

use App\Models\AttendanceBridge;
use App\Services\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AttendanceBridgeController extends Controller
{
    public function store(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        AttendanceBridge::create([
            'business_id' => $context->currentId(),
            'name' => $data['name'],
        ]);

        return back()->with('status', __('attendance.bridge.created'));
    }

    public function regenerateToken(AttendanceBridge $attendanceBridge): RedirectResponse
    {
        $attendanceBridge->forceFill([
            'token' => Str::random(64),
            'status' => 'offline',
        ])->save();

        return back()->with('status', __('attendance.bridge.token_regenerated'));
    }

    public function destroy(AttendanceBridge $attendanceBridge): RedirectResponse
    {
        $attendanceBridge->delete();

        return back()->with('status', __('attendance.bridge.deleted'));
    }
}
