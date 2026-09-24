<?php

namespace App\Http\Controllers;

use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Category CRUD controller (Batch 11).
 *
 * All list queries are scoped to the current tenant by the BelongsToBusiness
 * global scope (Category::query()). Route-model binding resolves `{category}`
 * within the same scope — cross-business lookups return 404 automatically.
 *
 * Controller logic is intentionally thin: no forbidden-user checks here
 * (handled by the route middleware), no manual business_id assignment (handled
 * by the BelongsToBusiness trait on Category::create).
 */
class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        $searchTerm = trim((string) $request->string('search'));

        $categories = Category::query()
            ->when($searchTerm !== '', function ($query) use ($searchTerm) {
                $query->where('name', 'like', "%{$searchTerm}%");
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('categories.index', compact('categories', 'searchTerm'));
    }

    public function create(): View
    {
        return view('categories.create');
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $category = Category::create($request->validated());

        return redirect()
            ->route('categories.index')
            ->with('status', __('categories.created'));
    }

    public function edit(Category $category): View
    {
        return view('categories.edit', compact('category'));
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($request->validated());

        return redirect()
            ->route('categories.index')
            ->with('status', __('categories.updated'));
    }

    public function destroy(Category $category): RedirectResponse
    {
        $category->delete();

        return redirect()
            ->route('categories.index')
            ->with('status', __('categories.deleted'));
    }
}
