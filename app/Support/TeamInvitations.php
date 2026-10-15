<?php

namespace App\Support;

use App\Mail\TeamInvitationMail;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * دعوة عضو فريق بالإيميل (A7، 02 «المستخدمين والأدوار»): الأدمن بيدعو بإيميل ودور، والمدعو بيفتح الرابط
 * (صالح 7 أيام، لمرة واحدة) ويكمّل الحساب بالدور المحدد.
 *
 * الـ token بيتبعت في الإيميل بس والمخزّن hash (sha256) منه، زي كود الباسورد. دعوة جديدة لنفس الإيميل بتلغي
 * القديمة (فـ «ابعت تاني» دعوة جديدة برابط جديد ومهلة 7 أيام تانية).
 *
 * بيرمي `UserModerationException` برسالة عربي جاهزة للعرض، وأي رفض صلاحية `AuthorizationException`.
 */
final class TeamInvitations
{
    public const VALID_DAYS = 7;

    /** الاسم زي التسجيل من التطبيق (02 F2): 2–50 حرف. */
    public const NAME_MIN = 2;

    public const NAME_MAX = 50;

    /**
     * بيعمل الدعوة ويبعت الإيميل. لو الإيميل لمستخدم عادي موجود، القبول بيدّيه الدور على حسابه.
     */
    public function invite(string $email, string $role, User $actor): TeamInvitation
    {
        Gate::forUser($actor)->authorize('invite', User::class);

        $email = Str::lower(trim($email));

        if (! array_key_exists($role, UserModeration::TEAM_ROLES)) {
            throw new UserModerationException('الدور ده مش متاح');
        }

        $this->requireInvitable($email);

        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($email, $role, $actor, $token): TeamInvitation {
            // دعوة واحدة معلّقة للإيميل: الجديدة بتلغي القديمة
            TeamInvitation::query()->where('email', $email)->whereNull('accepted_at')->delete();

            $invitation = TeamInvitation::query()->create([
                'email' => $email,
                'role' => $role,
                'token_hash' => self::hash($token),
                'invited_by' => $actor->id,
                'expires_at' => now()->addDays(self::VALID_DAYS),
            ]);

            $this->log($invitation, $actor, 'team_invited', ['email' => $email, 'role' => $role]);

            return $invitation;
        });

        try {
            Mail::to($email)->send(new TeamInvitationMail(
                url: route('team.invitation.show', ['token' => $token]),
                role: UserModeration::TEAM_ROLES[$role],
                inviter: (string) ($actor->name ?? ''),
                days: self::VALID_DAYS,
            ));
        } catch (Throwable $e) {
            // دعوة من غير إيميل واصل ملهاش لازمة (الـ token ما اتعرفش لحد): بنشيلها ونقول للأدمن
            $invitation->delete();
            report($e);

            throw new UserModerationException('الإيميل ما اتبعتش، جرّب تاني بعد شوية');
        }

        return $invitation;
    }

    /**
     * «ابعت تاني»: دعوة جديدة لنفس الإيميل والدور برابط جديد (القديم بيتلغي).
     */
    public function resend(TeamInvitation $invitation, User $actor): TeamInvitation
    {
        if ($invitation->accepted_at !== null) {
            throw new UserModerationException('الدعوة دي اتقبلت بالفعل');
        }

        return $this->invite($invitation->email, $invitation->role, $actor);
    }

    public function revoke(TeamInvitation $invitation, User $actor): void
    {
        Gate::forUser($actor)->authorize('invite', User::class);

        if ($invitation->accepted_at !== null) {
            throw new UserModerationException('الدعوة دي اتقبلت بالفعل');
        }

        DB::transaction(function () use ($invitation, $actor): void {
            $this->log($invitation, $actor, 'team_invitation_revoked', ['email' => $invitation->email, 'role' => $invitation->role]);

            $invitation->delete();
        });
    }

    /**
     * الدعوة اللي لسه شغّالة لرابط معيّن، أو null (متغيّر، متقبلة، أو منتهية: نفس الرد لكل الحالات).
     */
    public function find(string $token): ?TeamInvitation
    {
        return TeamInvitation::query()->pending()->where('token_hash', self::hash($token))->first();
    }

    /**
     * بيقبل الدعوة: حساب جديد بالاسم والباسورد، أو الدور على حساب موجود بنفس الإيميل. الرابط بيتحرق.
     *
     * @throws UserModerationException لو الدعوة مش شغّالة أو الحساب موقوف أو محذوف
     */
    public function accept(string $token, ?string $name = null, ?string $password = null): User
    {
        return DB::transaction(function () use ($token, $name, $password): User {
            // lock: ضغطتين مع بعض ما يعملوش حسابين
            $invitation = TeamInvitation::query()
                ->pending()
                ->where('token_hash', self::hash($token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null) {
                throw new UserModerationException('الدعوة دي انتهت أو اتستخدمت');
            }

            $existing = User::query()->withTrashed()->where('email', $invitation->email)->first();

            if ($existing !== null) {
                if ($existing->trashed() || $existing->isSuspended()) {
                    throw new UserModerationException('الحساب المرتبط بالإيميل ده مش متاح');
                }

                $user = $existing;
                $kept = $user->getRoleNames()->reject(fn (string $role) => array_key_exists($role, UserModeration::TEAM_ROLES));
                $user->syncRoles([...$kept->all(), $invitation->role]);

                if ($user->email_verified_at === null) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }
            } else {
                $user = new User(['name' => $name, 'email' => $invitation->email, 'password' => $password]);
                $user->forceFill([
                    'locale' => 'ar',
                    // الإيميل اتأكد بإنه فتح الرابط اللي اتبعتله
                    'email_verified_at' => now(),
                ])->save();
                $user->assignRole($invitation->role);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->log($invitation, $user, 'team_invitation_accepted', ['email' => $invitation->email, 'role' => $invitation->role]);

            return $user;
        });
    }

    /**
     * هل الدعوة هتعمل حساب جديد (محتاج اسم وباسورد) ولا هتدّي دور لحساب موجود؟
     */
    public function needsAccount(TeamInvitation $invitation): bool
    {
        return ! User::query()->withTrashed()->where('email', $invitation->email)->exists();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function requireInvitable(string $email): void
    {
        $existing = User::query()->withTrashed()->where('email', $email)->first();

        if ($existing === null) {
            return;
        }

        if ($existing->trashed()) {
            throw new UserModerationException('الإيميل ده لحساب محذوف');
        }

        if ($existing->isSuspended()) {
            throw new UserModerationException('الحساب ده موقوف. ارفع الإيقاف الأول');
        }

        if ($existing->hasAnyRole(array_keys(UserModeration::TEAM_ROLES))) {
            throw new UserModerationException('ده عضو في الفريق بالفعل');
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(TeamInvitation $invitation, User $causer, string $event, array $properties): void
    {
        activity(UserModeration::LOG_NAME)
            ->performedOn($invitation)
            ->causedBy($causer)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }
}
