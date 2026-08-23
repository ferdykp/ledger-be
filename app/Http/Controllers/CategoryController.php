<?php

namespace App\Http\Controllers;

use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function __construct(
        protected CategoryService $categoryService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $type = $request->query('type'); // Filter opsional: ?type=expense / ?type=income
        $categories = $this->categoryService->getUserCategories($request->user(), $type);

        return CategoryResource::collection($categories);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->createCategory($request->user(), $request->validated());

        return response()->json([
            'message' => 'Kategori berhasil dibuat.',
            'data' => new CategoryResource($category),
        ], 201);
    }

    public function show(Request $request, Category $category): JsonResponse
    {
        $this->authorizeOwner($request->user()->id, $category);

        return response()->json([
            'data' => new CategoryResource($category->load('children')),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $updatedCategory = $this->categoryService->updateCategory($category, $request->validated());

        return response()->json([
            'message' => 'Kategori berhasil diperbarui.',
            'data' => new CategoryResource($updatedCategory),
        ]);
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorizeOwner($request->user()->id, $category);

        $this->categoryService->deleteCategory($category);

        return response()->json([
            'message' => 'Kategori berhasil dihapus.',
        ]);
    }

    private function authorizeOwner(int $userId, Category $category): void
    {
        if ($category->user_id !== $userId) {
            abort(403, 'Anda tidak memiliki akses ke kategori ini.');
        }
    }
}
