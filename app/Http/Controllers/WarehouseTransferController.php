<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\BusinessContext;
use App\Services\WarehouseTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class WarehouseTransferController extends Controller
{
    public function index(): View
    {
        return view('inventory.transfers', [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
            'products' => Product::query()->orderBy('name')->get(),
            'transfers' => WarehouseTransfer::query()
                ->with(['sourceWarehouse', 'destinationWarehouse', 'items.product'])
                ->latest('id')
                ->limit(100)
                ->get(),
        ]);
    }

    public function store(
        Request $request,
        BusinessContext $context,
        WarehouseTransferService $service,
    ): RedirectResponse {
        $data = $request->validate([
            'source_warehouse_id' => [
                'required',
                'different:destination_warehouse_id',
                Rule::exists('warehouses', 'id')->where('business_id', $context->currentId()),
            ],
            'destination_warehouse_id' => [
                'required',
                'different:source_warehouse_id',
                Rule::exists('warehouses', 'id')->where('business_id', $context->currentId()),
            ],
            'product_id' => [
                'required',
                Rule::exists('products', 'id')->where('business_id', $context->currentId()),
            ],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $service->create(
                Warehouse::findOrFail($data['source_warehouse_id']),
                Warehouse::findOrFail($data['destination_warehouse_id']),
                Product::findOrFail($data['product_id']),
                (float) $data['quantity'],
                $data['note'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['transfer' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', __('operations.transfers.messages.created'));
    }

    public function dispatch(
        WarehouseTransfer $warehouseTransfer,
        WarehouseTransferService $service,
    ): RedirectResponse {
        try {
            $service->dispatch($warehouseTransfer);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['transfer' => $exception->getMessage()]);
        }

        return back()->with('status', __('operations.transfers.messages.dispatched'));
    }

    public function receive(
        WarehouseTransfer $warehouseTransfer,
        WarehouseTransferService $service,
    ): RedirectResponse {
        try {
            $service->receive($warehouseTransfer);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['transfer' => $exception->getMessage()]);
        }

        return back()->with('status', __('operations.transfers.messages.received'));
    }
}
