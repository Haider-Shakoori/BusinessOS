<?php

namespace App\Http\Controllers;

use App\Models\BusinessMembership;
use App\Services\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BusinessWorkspaceController extends Controller
{
    public function index(Request $request, BusinessContext $context): View
    {
        $memberships = BusinessMembership::query()
            ->withoutGlobalScopes()
            ->where('user_id', $request->user()->id)
            ->with([
                'business.modules',
                'roles',
            ])
            ->orderBy('business_id')
            ->get();

        return view('system.businesses', [
            'memberships' => $memberships,
            'currentBusinessId' => $context->currentId(),
        ]);
    }
}
