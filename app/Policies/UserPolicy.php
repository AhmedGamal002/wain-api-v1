<?php

namespace App\Policies;

use App\Models\User;

/**
 * المستخدمين والأدوار في اللوحة (02 «مصفوفة الصلاحيات»: المستخدمين والأدوار والحملات = Admin بس، والـ Moderator
 * مالوش دخول للشاشة). مفيش تعديل لبيانات المستخدم الشخصية (A7)، فمفيش `update`.
 *
 * الأدمن ما يوقفش نفسه ولا يغيّر دوره بنفسه (عشان ما يقفلش اللوحة على نفسه بالغلط). قاعدة «آخر أدمن» جوه
 * `UserModeration` لإنها محتاجة تعدّ الأدمنز.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->admin($actor);
    }

    public function view(User $actor, User $target): bool
    {
        return $this->admin($actor);
    }

    /** دعوة عضو فريق. */
    public function invite(User $actor): bool
    {
        return $this->admin($actor);
    }

    public function suspend(User $actor, User $target): bool
    {
        return $this->admin($actor) && $actor->isNot($target);
    }

    public function unsuspend(User $actor, User $target): bool
    {
        return $this->admin($actor);
    }

    public function changeRole(User $actor, User $target): bool
    {
        return $this->admin($actor) && $actor->isNot($target);
    }

    private function admin(User $actor): bool
    {
        return $actor->status === 'active' && $actor->hasRole('admin');
    }
}
