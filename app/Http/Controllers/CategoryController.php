<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index()
    {
        $this->authorize('manage-categories');
        $categories = Category::withCount('tasks')->orderBy('name')->get();

        return view('categories.index', compact('categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-categories');
        Category::create($this->rules($request) + ['created_by' => $request->user()->id]);

        return back()->with('success', 'Category created.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $this->authorize('manage-categories');
        $category->update($this->rules($request, $category));

        return back()->with('success', 'Category updated.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $this->authorize('manage-categories');
        $category->delete(); // tasks keep existing: category_id is set to NULL by the foreign key

        return back()->with('success', 'Category deleted. Its tasks are now uncategorised.');
    }

    private function rules(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('categories', 'name')->ignore($category?->id)],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:255'],
        ], ['color.regex' => 'Pick a valid colour.']);
    }
}
