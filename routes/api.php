<?php

use App\Http\Controllers\AttendanceBridgeApiController;
use App\Http\Controllers\AttendancePushController;
use Illuminate\Support\Facades\Route;

Route::post('/attendance/push/{attendanceDevice}', [AttendancePushController::class, 'store'])
    ->name('attendance.push')
    ->middleware('throttle:120,1');

Route::prefix('/attendance/bridge/{bridge}')
    ->middleware('throttle:240,1')
    ->group(function () {
        Route::post('/heartbeat', [AttendanceBridgeApiController::class, 'heartbeat'])->name('attendance.bridge.heartbeat');
        Route::get('/jobs', [AttendanceBridgeApiController::class, 'jobs'])->name('attendance.bridge.jobs');
        Route::post('/jobs/{job}/result', [AttendanceBridgeApiController::class, 'jobResult'])->name('attendance.bridge.jobs.result');
        Route::get('/devices', [AttendanceBridgeApiController::class, 'devices'])->name('attendance.bridge.devices');
        Route::post('/devices/{device}/records', [AttendanceBridgeApiController::class, 'records'])->name('attendance.bridge.records');
    });
