<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * إجراءات الأدمن على المستخدمين (A7، 02 «المستخدمين والأدوار»): إيقاف وإلغاء إيقاف بسبب، وتغيير دور الفريق.
 * مكان واحد للقواعد، واللوحة بتنادي عليه بس (زي `PlaceTransitions`). كل إجراء بيتحقق من الصلاحية (`UserPolicy`)
 * والحالة والسبب، وبيتسجّل في سجل التغييرات (`activity_log`، اسم السجل `user`) بمين عمله وإيه السبب.
 *
 * الأدمن ما يوقفش نفسه ولا يغيّر دوره (`UserPolicy`)، فاللوحة مش ممكن تفضل من غير أدمن شغّال بالإجراءات دي.
 *
 * الإيقاف بيمسح كل tokens المستخدم (فالتطبيق بياخد 401 في أول request ويخرج) وجلسات اللوحة بتاعته لو فريق،
 * و`ACCOUNT_SUSPENDED` من `EnsureAccountIsActive` شبكة أمان لأي token اتعمل في نفس اللحظة.
 *
 * بيرمي `UserModerationException` برسالة عربي جاهزة للعرض، وأي رفض صلاحية `AuthorizationException`.
 */
final class UserModeration
{
    public const LOG_NAME = 'user';

    /** أدوار الفريق اللي بتتغيّر من الشاشة. دور `owner` بيتدار من طلبات الملكية (F13) فمش بيتلمس هنا. */
    public const TEAM_ROLES = [
        'admin' => 'أدمن',
        'moderator' => 'مشرف',
    ];

    /** `users.suspended_reason` عمود VARCHAR(255). */
    private const REASON_MAX = 255;

    public function suspend(User $user, User $actor, string $reason): User
    {
        $this->authorize($actor, 'suspend', $user);
        $this->requireNotDeleted($user);

        if ($user->isSuspended()) {
            throw new UserModerationException('الحساب ده موقوف بالفعل');
        }

        $reason = $this->reason($reason);

        DB::transaction(function () use ($user, $actor, $reason): void {
            $user->forceFill([
                'status' => 'suspended',
                'suspended_reason' => $reason,
                'suspended_at' => now(),
            ])->save();

            // كل الأجهزة تخرج، وفريق اللوحة تتقفل جلساته (canAccessPanel بيرفض أصلاً لأن الحالة مش active)
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $this->log($user, $actor, 'suspended', ['reason' => $reason]);
        });

        return $user->refresh();
    }

    public function unsuspend(User $user, User $actor, string $reason): User
    {
        $this->authorize($actor, 'unsuspend', $user);
        $this->requireNotDeleted($user);

        if (! $user->isSuspended()) {
            throw new UserModerationException('الحساب ده مش موقوف');
        }

        $reason = $this->reason($reason);

        DB::transaction(function () use ($user, $actor, $reason): void {
            $previous = $user->suspended_reason;

            $user->forceFill([
                'status' => 'active',
                'suspended_reason' => null,
                'suspended_at' => null,
            ])->save();

            $this->log($user, $actor, 'unsuspended', ['reason' => $reason, 'suspended_reason' => $previous]);
        });

        return $user->refresh();
    }

    /**
     * تغيير دور الفريق: `admin` أو `moderator` أو null (مستخدم عادي). باقي أدوار المستخدم (owner) بتفضل.
     */
    public function changeRole(User $user, User $actor, ?string $role): User
    {
        $this->authorize($actor, 'changeRole', $user);
        $this->requireNotDeleted($user);

        if ($role !== null && ! array_key_exists($role, self::TEAM_ROLES)) {
            throw new UserModerationException('الدور ده مش متاح');
        }

        $current = self::teamRole($user);

        if ($current === $role) {
            throw new UserModerationException('ده هو الدور الحالي للمستخدم');
        }

        DB::transaction(function () use ($user, $actor, $role, $current): void {
            $kept = $user->getRoleNames()->reject(fn (string $name) => array_key_exists($name, self::TEAM_ROLES));

            $user->syncRoles($role === null ? $kept->all() : [...$kept->all(), $role]);

            $this->log($user, $actor, 'role_changed', ['old' => $current, 'new' => $role]);
        });

        return $user->refresh();
    }

    /**
     * دور الفريق الحالي (`admin` ثم `moderator`)، أو null لو مستخدم عادي أو صاحب مكان.
     */
    public static function teamRole(User $user): ?string
    {
        foreach (array_keys(self::TEAM_ROLES) as $role) {
            if ($user->hasRole($role)) {
                return $role;
            }
        }

        return null;
    }

    // ───────────────────────── القواعد ─────────────────────────

    private function authorize(User $actor, string $ability, User $target): void
    {
        Gate::forUser($actor)->authorize($ability, $target);
    }

    private function requireNotDeleted(User $user): void
    {
        if ($user->trashed()) {
            throw new UserModerationException('الحساب ده اتحذف');
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new UserModerationException('السبب مطلوب');
        }

        return Str::limit($reason, self::REASON_MAX, '');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(User $user, User $actor, string $event, array $properties): void
    {
        activity(self::LOG_NAME)
            ->performedOn($user)
            ->causedBy($actor)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }
}
