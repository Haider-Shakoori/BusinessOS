<?php

namespace App\Http\Controllers;

use App\Models\CrmLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CrmController extends Controller
{
    public function index(): View
    {
        return view('crm.index', [
            'leads' => CrmLead::query()->with('activities')->latest('id')->limit(100)->get(),
        ]);
    }

    public function storeLead(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(['new', 'contacted', 'qualified', 'won', 'lost'])],
            'source' => ['nullable', 'string', 'max:80'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'next_follow_up_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        CrmLead::create($data);

        return back()->with('status', __('operations.crm.lead_created'));
    }

    public function storeActivity(Request $request, CrmLead $crmLead): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['call', 'meeting', 'email', 'note', 'follow_up'])],
            'note' => ['required', 'string', 'max:4000'],
        ]);

        $crmLead->activities()->create($data + ['occurred_at' => now()]);

        return back()->with('status', __('operations.crm.activity_created'));
    }
}
