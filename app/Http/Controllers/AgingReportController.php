<?php

namespace App\Http\Controllers;

use App\Services\AgingReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AgingReportController extends Controller
{
    public function index(Request $request, AgingReportService $aging): View
    {
        $data = $request->validate([
            'as_of' => ['nullable', 'date'],
        ]);
        $asOf = $data['as_of'] ?? now()->toDateString();

        return view('accounting.aging', [
            'asOf' => $asOf,
            'receivables' => $aging->receivables($asOf),
            'payables' => $aging->payables($asOf),
        ]);
    }
}
