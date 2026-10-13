<?php

namespace App\Policies;

use App\Models\Concerns\IsCatalogItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * التصنيفات والخصائص والمدن والمناطق في اللوحة (02 «مصفوفة الصلاحيات»): Admin يعدّل، والـ Moderator
 * عرض فقط. الحذف ممنوع لسجل مستخدم (A2/A3/A4): بيتخفى بدل ما يتحذف.
 *
 * بتتسجّل لكل الموديلز دي في AppServiceProvider.
 */
class CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'moderator']);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Model $record): bool
    {
        return $user->hasRole('admin');
    }

    public function reorder(User $user): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * @param  Model&IsCatalogItem  $record
     */
    public function delete(User $user, Model $record): bool
    {
        return $user->hasRole('admin') && ! $record->isInUse();
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
