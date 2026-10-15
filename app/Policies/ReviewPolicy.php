<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

/**
 * التقييمات في اللوحة (02 «مصفوفة الصلاحيات»: مراجعة التقييمات والصور والبلاغات = Admin وModerator). على عكس
 * نشر الأماكن، المشرف بيوافق ويرفض هنا. الـ API (صاحب التقييم بيعدّل ويمسح بتاعه) بيتحقق من الملكية في
 * `ReviewService`، مش من الـ Policy دي.
 */
class ReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->team($user);
    }

    public function view(User $user, Review $review): bool
    {
        return $this->team($user);
    }

    /** موافقة أو رفض (تقييم جديد أو تعديل). */
    public function moderate(User $user, Review $review): bool
    {
        return $this->team($user);
    }

    private function team(User $user): bool
    {
        return $user->status === 'active' && $user->hasAnyRole(['admin', 'moderator']);
    }
}
