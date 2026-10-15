<?php

namespace App\Http\Controllers;

use App\Models\TeamInvitation;
use App\Support\TeamInvitations;
use App\Support\UserModeration;
use App\Support\UserModerationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rules\Password;

/**
 * صفحة قبول دعوة الفريق (A7): الرابط اللي اتبعت في الإيميل بيفتح فورم الاسم والباسورد (أو تأكيد بس لو الإيميل
 * ليه حساب)، والقبول بيعمل الحساب بالدور المحدد. أي رابط مش شغّال (غلط أو اتقبل أو انتهى) بيرد بنفس الصفحة.
 */
class TeamInvitationController extends Controller
{
    public function __construct(private readonly TeamInvitations $invitations) {}

    public function show(string $token): View|Response
    {
        $invitation = $this->invitations->find($token);

        if ($invitation === null) {
            return $this->invalid();
        }

        return view('team.invitation', $this->viewData($invitation, $token));
    }

    public function accept(Request $request, string $token): View|Response
    {
        $invitation = $this->invitations->find($token);

        if ($invitation === null) {
            return $this->invalid();
        }

        $needsAccount = $this->invitations->needsAccount($invitation);

        $data = $needsAccount
            ? $request->merge(['name' => trim((string) $request->input('name'))])->validate([
                'name' => ['required', 'string', 'min:'.TeamInvitations::NAME_MIN, 'max:'.TeamInvitations::NAME_MAX],
                'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
            ], [], ['name' => 'الاسم', 'password' => 'الباسورد'])
            : [];

        try {
            $this->invitations->accept($token, $data['name'] ?? null, $data['password'] ?? null);
        } catch (UserModerationException $e) {
            return $this->invalid($e->getMessage());
        }

        return view('team.invitation-accepted', [
            'role' => UserModeration::TEAM_ROLES[$invitation->role] ?? $invitation->role,
            'panelUrl' => url('/admin'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(TeamInvitation $invitation, string $token): array
    {
        return [
            'token' => $token,
            'email' => $invitation->email,
            'role' => UserModeration::TEAM_ROLES[$invitation->role] ?? $invitation->role,
            'needsAccount' => $this->invitations->needsAccount($invitation),
            'expiresAt' => $invitation->expires_at->copy()->timezone('Africa/Cairo'),
        ];
    }

    private function invalid(?string $message = null): Response
    {
        return response()->view('team.invitation-invalid', [
            'message' => $message ?? 'الدعوة دي انتهت أو اتستخدمت. اطلب من الأدمن يبعتلك دعوة جديدة.',
        ], 410);
    }
}
