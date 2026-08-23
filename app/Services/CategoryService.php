<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class CategoryService
{
    public function getUserCategories(User $user, ?string $type = null): Collection
    {
        return Category::where('user_id', $user->id)
            ->when($type, fn($query) => $query->where('type', $type))
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('name', 'asc')
            ->get();
    }

    public function createCategory(User $user, array $data): Category
    {
        return $user->categories()->create([
            'name' => $data['name'],
            'type' => $data['type'],
            'icon' => $data['icon'] ?? null,
            'color' => $data['color'] ?? '#6C4CF1',
            'parent_id' => $data['parent_id'] ?? null,
        ]);
    }

    public function updateCategory(Category $category, array $data): Category
    {
        $category->update($data);
        return $category->fresh();
    }

    public function deleteCategory(Category $category): bool
    {
        return $category->delete();
    }
}
