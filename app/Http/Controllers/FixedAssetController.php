<?php

namespace App\Http\Controllers;

use App\Models\AssetCategory;
use App\Models\CostCenter;
use App\Models\FixedAsset;
use App\Services\BusinessContext;
use App\Services\FixedAssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FixedAssetController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->string('status')->toString();

        $assets = FixedAsset::query()
            ->with(['category', 'costCenter'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderBy('asset_number')
            ->paginate(50)
            ->withQueryString();

        return view('fixed-assets.index', [
            'assets' => $assets,
            'categories' => AssetCategory::query()->orderBy('code')->get(),
            'costCenters' => CostCenter::query()->where('is_active', true)->orderBy('code')->get(),
            'status' => $status,
            'summary' => [
                'count' => FixedAsset::query()->where('status', 'active')->count(),
                'cost' => FixedAsset::query()->where('status', 'active')->sum('acquisition_cost'),
                'accumulated' => FixedAsset::query()->where('status', 'active')->sum('accumulated_depreciation'),
                'book_value' => FixedAsset::query()->where('status', 'active')->sum('book_value'),
            ],
        ]);
    }

    public function show(FixedAsset $fixedAsset): View
    {
        return view('fixed-assets.show', [
            'asset' => $fixedAsset->load([
                'category',
                'costCenter',
                'depreciationEntries' => fn ($query) => $query->with('journalEntry')->latest('period_end'),
            ]),
        ]);
    }

    public function storeCategory(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('asset_categories', 'code')->where('business_id', $context->currentId()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'depreciation_method' => ['required', Rule::in(['straight_line', 'declining_balance'])],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:1200'],
            'salvage_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'asset_account_code' => ['required', 'string', 'max:40'],
            'accumulated_depreciation_account_code' => ['required', 'string', 'max:40'],
            'depreciation_expense_account_code' => ['required', 'string', 'max:40'],
        ]);

        AssetCategory::create($data);

        return back()->with('status', __('fixed_assets.messages.category_created'));
    }

    public function store(
        Request $request,
        BusinessContext $context,
        FixedAssetService $assets,
    ): RedirectResponse {
        $data = $request->validate([
            'asset_category_id' => [
                'required',
                Rule::exists('asset_categories', 'id')->where('business_id', $context->currentId()),
            ],
            'cost_center_id' => [
                'nullable',
                Rule::exists('cost_centers', 'id')->where('business_id', $context->currentId()),
            ],
            'asset_number' => [
                'required',
                'string',
                'max:80',
                Rule::unique('fixed_assets', 'asset_number')->where('business_id', $context->currentId()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'acquisition_date' => ['required', 'date'],
            'in_service_date' => ['required', 'date', 'after_or_equal:acquisition_date'],
            'acquisition_cost' => ['required', 'numeric', 'gt:0'],
            'salvage_value' => ['nullable', 'numeric', 'gte:0', 'lte:acquisition_cost'],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:1200'],
            'depreciation_method' => ['required', Rule::in(['straight_line', 'declining_balance'])],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'other'])],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $asset = $assets->acquire($data);

        return redirect()
            ->route('assets.show', $asset)
            ->with('status', __('fixed_assets.messages.asset_created'));
    }

    public function depreciate(
        Request $request,
        FixedAsset $fixedAsset,
        FixedAssetService $assets,
    ): RedirectResponse {
        $data = $request->validate([
            'through_date' => ['required', 'date'],
        ]);

        $assets->depreciateThrough($fixedAsset, $data['through_date']);

        return back()->with('status', __('fixed_assets.messages.depreciated'));
    }

    public function depreciateAll(Request $request, FixedAssetService $assets): RedirectResponse
    {
        $data = $request->validate([
            'through_date' => ['required', 'date'],
        ]);

        $count = 0;

        FixedAsset::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($assets, $data, &$count): void {
                foreach ($rows as $asset) {
                    $before = (string) $asset->accumulated_depreciation;
                    $updated = $assets->depreciateThrough($asset, $data['through_date']);

                    if ((string) $updated->accumulated_depreciation !== $before) {
                        $count++;
                    }
                }
            });

        return back()->with('status', __('fixed_assets.messages.bulk_depreciated', ['count' => $count]));
    }

    public function dispose(
        Request $request,
        FixedAsset $fixedAsset,
        FixedAssetService $assets,
    ): RedirectResponse {
        $data = $request->validate([
            'disposal_date' => ['required', 'date', 'after_or_equal:'.$fixedAsset->in_service_date->toDateString()],
            'proceeds' => ['required', 'numeric', 'gte:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $assets->dispose(
            $fixedAsset,
            $data['disposal_date'],
            (string) $data['proceeds'],
            $data['note'] ?? null,
        );

        return back()->with('status', __('fixed_assets.messages.disposed'));
    }
}
