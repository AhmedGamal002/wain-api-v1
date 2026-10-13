<?php

namespace App\Policies;

use App\Models\Place;
use App\Models\User;

/**
 * صلاحيات الأماكن في اللوحة (03 القسم 4.8). الـ Policy بتحكم بالدور بس: الحالة اللي بيظهر فيها الزرار
 * (مثلاً «نشر» لمسودة أو قيد المراجعة) في الـ resource، والأزرار الممنوعة ما بتظهرش أصلاً والـ Policy بترفض
 * أي محاولة مباشرة بـ 403.
 *
 * `update` للاتنين، لكن الـ Moderator ما يعدّلش حقول الباقة ولا صاحب المكان: الـ form بيقفلهم (`setTier`)،
 * والـ modal بتاع الباقة محمي بـ `setTier`.
 */
class PlacePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->team($user);
    }

    public function view(User $user, Place $place): bool
    {
        return $this->team($user);
    }

    public function create(User $user): bool
    {
        return $this->team($user);
    }

    public function update(User $user, Place $place): bool
    {
        return $this->team($user);
    }

    /** إرسال للمراجعة. */
    public function submit(User $user, Place $place): bool
    {
        return $this->team($user);
    }

    public function publish(User $user, Place $place): bool
    {
        return $this->admin($user);
    }

    public function hide(User $user, Place $place): bool
    {
        return $this->admin($user);
    }

    /** إظهار مكان مخفي. */
    public function show(User $user, Place $place): bool
    {
        return $this->admin($user);
    }

    public function reject(User $user, Place $place): bool
    {
        return $this->admin($user);
    }

    /** قفل نهائي. */
    public function close(User $user, Place $place): bool
    {
        return $this->admin($user);
    }

    public function setTier(User $user, Place $place): bool
    {
        return $this->admin($user);
    }

    public function delete(User $user, Place $place): bool
    {
        return $this->admin($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->admin($user);
    }

    public function restore(User $user, Place $place): bool
    {
        return $this->admin($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->admin($user);
    }

    /**
     * الحذف النهائي من الـ job بعد 30 يوم بس، مش من اللوحة.
     */
    public function forceDelete(User $user, Place $place): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function team(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'moderator']);
    }

    private function admin(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
