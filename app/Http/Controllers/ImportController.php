<?php

namespace App\Http\Controllers;

use App\Services\BusinessContext;
use App\Services\ImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSV import controller for customers and products (Batch 20).
 *
 * One controller serves both imports; the module + permission are enforced at
 * the route (module:customers + customers.manage, module:products +
 * products.manage), so this controller never re-checks authorization. The
 * `type` route parameter identifies which import a request belongs to and is
 * hard-wired into every route via ->defaults() — it can never be supplied by
 * the client.
 *
 * Thin by design: upload validation, the two-phase preview/execute flow, the
 * opaque token binding and the all-or-nothing transaction all live in
 * ImportService. The controller only moves requests between pages.
 */
class ImportController extends Controller
{
    public function __construct(
        private readonly ImportService $imports,
        private readonly BusinessContext $context,
    ) {
        //
    }

    public function show(string $type): View
    {
        $labels = $this->labels($type);

        return view('imports.upload', [
            'type' => $type,
            'title' => $labels['title'],
            'subtitle' => $labels['subtitle'],
            'maxFileKb' => (int) config('business.import.max_file_kb'),
            'maxRows' => (int) config('business.import.max_rows'),
            'routes' => $this->routeNames($type),
        ]);
    }

    public function preview(Request $request, string $type): RedirectResponse
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'extensions:csv',
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel',
                'max:'.(int) config('business.import.max_file_kb'),
            ],
        ], $this->uploadMessages());

        $businessId = (int) $this->context->currentId();
        $relativePath = $this->imports->storeUpload($validated['file'], $businessId);

        $token = $this->imports->createToken($relativePath, $businessId, (int) auth()->id(), $type);

        return redirect()->route($this->routeNames($type)['confirm'], ['token' => $token]);
    }

    public function confirm(Request $request, string $token): View|RedirectResponse
    {
        $type = (string) $request->route('type');

        $preview = $this->imports->preview(
            $token,
            (int) $this->context->currentId(),
            (int) auth()->id(),
            $type,
        );

        if ($preview === null) {
            return redirect()
                ->route($this->routeNames($type)['import'])
                ->with('status', __('imports.session_expired'));
        }

        $mapper = $this->imports->mapper($type, (int) $this->context->currentId());

        return view('imports.preview', [
            'type' => $type,
            'token' => $token,
            'preview' => $preview,
            'columnLabels' => $mapper->headerLabels(),
            'previewRows' => (int) config('business.import.preview_rows'),
            'routes' => $this->routeNames($type),
        ]);
    }

    public function execute(Request $request, string $token): RedirectResponse
    {
        $type = (string) $request->route('type');

        $result = $this->imports->execute(
            $token,
            (int) $this->context->currentId(),
            (int) auth()->id(),
            $type,
        );

        $routes = $this->routeNames($type);

        if ($result->isQueued()) {
            return redirect()
                ->route($routes['index'])
                ->with('status', __('imports.queued', ['count' => $result->count]));
        }

        if ($result->isSuccess()) {
            return redirect()
                ->route($routes['index'])
                ->with('status', __('imports.completed', ['count' => $result->count]));
        }

        // Validation failed at execution time: send the user back to the
        // preview, which re-scans the (still staged) file and shows the errors.
        return redirect()
            ->route($routes['confirm'], ['token' => $token])
            ->with('error', __('imports.failed'));
    }

    public function cancel(Request $request, string $token): RedirectResponse
    {
        $type = (string) $request->route('type');

        $this->imports->cancel($token, (int) $this->context->currentId(), (int) auth()->id(), $type);

        return redirect()
            ->route($this->routeNames($type)['import'])
            ->with('status', __('imports.cancelled'));
    }

    public function template(string $type): Response
    {
        $csv = $this->imports->template($type);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$type.'-import-template.csv"',
            'Content-Length' => (string) strlen($csv),
        ]);
    }

    /**
     * @return array<string, string> route name => translated title
     */
    private function labels(string $type): array
    {
        return match ($type) {
            'customers' => [
                'title' => __('imports.import_customers'),
                'subtitle' => __('imports.import_customers_subtitle'),
            ],
            'products' => [
                'title' => __('imports.import_products'),
                'subtitle' => __('imports.import_products_subtitle'),
            ],
            default => abort(404),
        };
    }

    /**
     * @return array<string, string>
     */
    private function routeNames(string $type): array
    {
        return [
            'index' => $type.'.index',
            'import' => $type.'.import',
            'preview' => $type.'.import.preview',
            'confirm' => $type.'.import.confirm',
            'execute' => $type.'.import.execute',
            'cancel' => $type.'.import.cancel',
            'template' => $type.'.import.template',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function uploadMessages(): array
    {
        return [
            'file.required' => __('imports.validation.file_required'),
            'file.mimes' => __('imports.validation.file_mimes'),
            'file.mimetypes' => __('imports.validation.file_mimes'),
            'file.extensions' => __('imports.validation.file_extensions'),
            'file.max' => __('imports.validation.file_max'),
        ];
    }
}
