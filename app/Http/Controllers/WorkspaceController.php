<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function placeholder(Request $request, string $section): View
    {
        $definition = collect(config('navigation.items', []))
            ->first(fn (array $item): bool => ($item['placeholder'] ?? null) === $section);

        abort_if($definition === null, 404);

        return view('workspace.placeholder', [
            'section' => $section,
            'title' => __((string) ($definition['label'] ?? 'navigation.workspace')),
            'icon' => (string) ($definition['icon'] ?? 'cube'),
        ]);
    }
}
