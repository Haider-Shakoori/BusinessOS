<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\PosRegister;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\PosService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class PosController extends Controller
{
    public function index(Request $request): View
    {
        $registers = PosRegister::query()
            ->with('warehouse')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $selectedRegister = null;

        if ($request->filled('register')) {
            $selectedRegister = PosRegister::query()
                ->with('warehouse')
                ->where('is_active', true)
                ->find($request->integer('register'));
        }

        $selectedRegister ??= $registers->first();

        $openShift = null;
        $registerOpenShift = null;
        $products = collect();
        $stock = collect();

        if ($selectedRegister !== null) {
            $registerOpenShift = PosShift::query()
                ->with('user')
                ->where('pos_register_id', $selectedRegister->id)
                ->where('status', 'open')
                ->first();

            if ($registerOpenShift?->user_id === Auth::id()) {
                $openShift = $registerOpenShift;
            }

            $products = Product::query()
                ->with(['category', 'tax'])
                ->orderBy('name')
                ->get();

            $stock = StockMovement::query()
                ->where('warehouse_id', $selectedRegister->warehouse_id)
                ->selectRaw('product_id, SUM(quantity) as quantity')
                ->groupBy('product_id')
                ->pluck('quantity', 'product_id');
        }

        return view('pos.index', [
            'registers' => $registers,
            'register' => $selectedRegister,
            'openShift' => $openShift,
            'registerOpenShift' => $registerOpenShift,
            'products' => $products,
            'stock' => $stock,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
            'customers' => Customer::query()->orderBy('name')->get(),
            'recentSales' => PosSale::query()
                ->with(['cashier', 'register'])
                ->latest('completed_at')
                ->limit(15)
                ->get(),
        ]);
    }

    public function storeRegister(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('business_id', $context->currentId())],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('pos_registers', 'code')->where('business_id', $context->currentId()),
            ],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $register = PosRegister::create($data + ['is_active' => true]);

        return redirect()
            ->route('pos.index', ['register' => $register->id])
            ->with('status', __('pos.messages.register_created'));
    }

    public function openShift(Request $request, PosRegister $posRegister, PosService $pos): RedirectResponse
    {
        $data = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $pos->openShift($posRegister, (int) Auth::id(), (float) $data['opening_cash']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['shift' => $exception->getMessage()]);
        }

        return redirect()
            ->route('pos.index', ['register' => $posRegister->id])
            ->with('status', __('pos.messages.shift_opened'));
    }

    public function closeShift(Request $request, PosShift $posShift, PosService $pos): RedirectResponse
    {
        $data = $request->validate([
            'closing_cash' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $closed = $pos->closeShift(
                $posShift,
                (int) Auth::id(),
                (float) $data['closing_cash'],
                $data['note'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['shift' => $exception->getMessage()]);
        }

        return redirect()
            ->route('pos.index', ['register' => $closed->pos_register_id])
            ->with('status', __('pos.messages.shift_closed', [
                'variance' => number_format((float) $closed->cash_variance, 2),
            ]));
    }

    public function checkout(Request $request, PosShift $posShift, PosService $pos, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'string'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'mobile', 'credit'])],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('business_id', $context->currentId())],
        ]);

        $items = json_decode($data['items'], true);

        if (! is_array($items)) {
            return back()->withErrors(['cart' => __('pos.errors.invalid_cart')]);
        }

        try {
            $sale = $pos->checkout(
                $posShift,
                (int) Auth::id(),
                array_values($items),
                $data['payment_method'],
                (float) ($data['discount_amount'] ?? 0),
                (float) ($data['amount_tendered'] ?? 0),
                isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['cart' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('pos.receipt', $sale)
            ->with('status', __('pos.messages.sale_completed'));
    }

    public function receipt(PosSale $posSale): View
    {
        return view('pos.receipt', [
            'sale' => $posSale->load(['items', 'register.warehouse', 'customer', 'cashier']),
        ]);
    }

    public function void(Request $request, PosSale $posSale, PosService $pos): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        try {
            $sale = $pos->void($posSale, (int) Auth::id(), $data['reason']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['sale' => $exception->getMessage()]);
        }

        return redirect()
            ->route('pos.receipt', $sale)
            ->with('status', __('pos.messages.sale_voided'));
    }
}
