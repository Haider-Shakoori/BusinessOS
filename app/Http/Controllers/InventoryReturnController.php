<?php

namespace App\Http\Controllers;

use App\Models\InventoryReturn;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\InventoryReturnService;
use App\Services\ModuleManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class InventoryReturnController extends Controller
{
    public function index(ModuleManager $modules): View
    {
        $sales = collect();
        $purchases = collect();

        if ($modules->isEnabled('pos') && Gate::allows('pos.view')) {
            $sales = PosSale::query()
                ->where('status', 'completed')
                ->with(['items.product', 'items.variant', 'register'])
                ->latest('completed_at')
                ->limit(50)
                ->get();
        }

        if ($modules->isEnabled('purchasing') && Gate::allows('purchasing.view')) {
            $purchases = PurchaseOrder::query()
                ->where('status', 'received')
                ->with(['items.product', 'items.variant', 'supplier'])
                ->latest('id')
                ->limit(50)
                ->get();
        }

        return view('inventory.returns', [
            'sales' => $sales,
            'purchases' => $purchases,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
            'returns' => InventoryReturn::query()
                ->with(['warehouse', 'items.product', 'items.variant', 'processor'])
                ->latest('processed_at')
                ->limit(100)
                ->get(),
        ]);
    }

    public function storeSales(Request $request, InventoryReturnService $service): RedirectResponse
    {
        Gate::authorize('pos.manage');

        $data = $request->validate([
            'pos_sale_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $item = PosSaleItem::query()
            ->with('sale')
            ->whereHas('sale', fn ($query) => $query->where('status', 'completed'))
            ->findOrFail($data['pos_sale_item_id']);

        try {
            $service->salesReturn(
                $item->sale,
                $item,
                (float) $data['quantity'],
                $data['reason'],
                (int) Auth::id(),
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['return' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', __('operations.returns.messages.sales_created'));
    }

    public function storePurchase(
        Request $request,
        BusinessContext $context,
        InventoryReturnService $service,
    ): RedirectResponse {
        Gate::authorize('purchasing.manage');

        $data = $request->validate([
            'purchase_order_item_id' => ['required', 'integer'],
            'warehouse_id' => [
                'required',
                Rule::exists('warehouses', 'id')->where('business_id', $context->currentId()),
            ],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $item = PurchaseOrderItem::query()
            ->with('purchaseOrder')
            ->whereHas('purchaseOrder', fn ($query) => $query->where('status', 'received'))
            ->findOrFail($data['purchase_order_item_id']);

        try {
            $service->purchaseReturn(
                $item->purchaseOrder,
                $item,
                Warehouse::findOrFail($data['warehouse_id']),
                (float) $data['quantity'],
                $data['reason'],
                (int) Auth::id(),
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['return' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', __('operations.returns.messages.purchase_created'));
    }
}
