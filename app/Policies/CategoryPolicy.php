<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * التصنيفات في اللوحة (02 «مصفوفة الصلاحيات»): Admin يعدّل، والـ Moderator عرض فقط.
 * الحذف ممنوع لتصنيف فيه أماكن (A2): بيتخفى بدل ما يتحذف.
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'moderator']);
    }

    public function view(User $user, Category $category): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Category $category): bool
    {
        return $user->hasRole('admin');
    }

    public function reorder(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->hasRole('admin') && ! $category->hasPlaces();
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
