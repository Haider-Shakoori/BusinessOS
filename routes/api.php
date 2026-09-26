<?php

use App\Http\Controllers\AttendancePushController;
use Illuminate\Support\Facades\Route;

Route::post('/attendance/push/{attendanceDevice}', [AttendancePushController::class, 'store'])
    ->name('attendance.push')
    ->middleware('throttle:120,1');
