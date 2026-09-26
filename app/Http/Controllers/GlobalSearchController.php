<?php

namespace App\Http\Controllers;

use App\Services\GlobalSearchService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GlobalSearchController extends Controller
{
    public function index(Request $request, GlobalSearchService $search): View
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = trim((string) ($data['q'] ?? ''));
        $results = mb_strlen($query) >= 2 ? $search->search($query) : [];

        return view('global-search.index', compact('query', 'results'));
    }
}
