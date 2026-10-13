<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Mail\AuthCodeMail;
use App\Models\AuthCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * أكواد نسيت الباسورد وتأكيد الإيميل (02 F2، 06 القسم 2.1).
 *
 *  - الكود 6 أرقام بيتخزّن hash، صالح 15 دقيقة، و5 محاولات غلط بس.
 *  - «ابعت تاني» بعد 60 ثانية من آخر إرسال.
 *  - بعد الكود الصح لنسيت الباسورد بيتعمل `reset_token` عشوائي (بيتخزّن SHA-256) لمرة واحدة صالح 10 دقايق.
 *  - الكود الجديد بيلغي القديم، فمفيش غير كود واحد شغّال لكل (نوع، إيميل).
 */
class AuthCodeService
{
    public const CODE_TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_AFTER_SECONDS = 60;

    public const RESET_TOKEN_TTL_MINUTES = 10;

    /**
     * كام ثانية لسه لحد ما يتسمح بإرسال كود جديد (0 = دلوقتي).
     */
    public function resendIn(string $type, string $email): int
    {
        $lastSent = AuthCode::query()
            ->where('type', $type)
            ->where('email', $email)
            ->latest('id')
            ->value('last_sent_at');

        if ($lastSent === null) {
            return 0;
        }

        $elapsed = (int) abs(now()->diffInSeconds($lastSent, false));

        return max(0, self::RESEND_AFTER_SECONDS - $elapsed);
    }

    /**
     * بيعمل كود جديد، بيلغي اللي قبله، وبيبعته بالإيميل (على الـ log driver في التطوير).
     * بيرجع الكود الصريح للتست بس: التطبيق ما بيشوفوش.
     */
    public function issueAndSend(string $type, string $email, ?User $user, string $locale): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($type, $email, $user, $code) {
            AuthCode::query()->where('type', $type)->where('email', $email)->whereNull('consumed_at')->delete();

            AuthCode::query()->create([
                'type' => $type,
                'email' => $email,
                'user_id' => $user?->id,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'last_sent_at' => now(),
            ]);
        });

        // مباشرة مش في queue: المستخدم مستنّي الكود، والـ queue بيشتغل من cron كل دقيقة (06 القسم 4)
        Mail::to($email)->locale($locale)->send(new AuthCodeMail($type, $code, self::CODE_TTL_MINUTES));

        return $code;
    }

    /**
     * بيتحقق من الكود. بيرمي INVALID_CODE (غلط أو مفيش كود) أو CODE_EXPIRED أو CODE_ATTEMPTS_EXCEEDED.
     * المحاولات بتتحسب تحت lock عشان طلبات متوازية ما تتخطّاش الحد.
     *
     * الغلط بيتحوّل لـ exception **بعد** ما الـ transaction تتأكّد: لو اترمى جواها هيتعمل rollback
     * وعدّاد المحاولات مايزيدش أبداً.
     */
    public function verify(string $type, string $email, string $code): AuthCode
    {
        $outcome = DB::transaction(function () use ($type, $email, $code): AuthCode|string {
            $record = AuthCode::query()
                ->where('type', $type)
                ->where('email', $email)
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return 'INVALID_CODE';
            }

            if ($record->expires_at->isPast()) {
                return 'CODE_EXPIRED';
            }

            if ($record->attempts >= self::MAX_ATTEMPTS) {
                return 'CODE_ATTEMPTS_EXCEEDED';
            }

            if (! Hash::check($code, $record->code_hash)) {
                $record->increment('attempts');

                return $record->attempts >= self::MAX_ATTEMPTS ? 'CODE_ATTEMPTS_EXCEEDED' : 'INVALID_CODE';
            }

            return $record;
        });

        if (is_string($outcome)) {
            throw $this->codeError($outcome);
        }

        return $outcome;
    }

    /**
     * بعد الكود الصح لنسيت الباسورد: الكود بيتستهلك وبيتولّد reset_token (الصريح بيرجع مرة واحدة هنا).
     */
    public function exchangeForResetToken(AuthCode $record): string
    {
        $token = Str::random(64);

        $record->forceFill([
            'consumed_at' => now(),
            'reset_token_hash' => hash('sha256', $token),
            'reset_token_expires_at' => now()->addMinutes(self::RESET_TOKEN_TTL_MINUTES),
        ])->save();

        return $token;
    }

    /**
     * بيتحقق من الـ reset_token ويستهلكه (مرة واحدة). بيرمي RESET_TOKEN_INVALID لو غلط أو منتهي أو مستخدم.
     */
    public function consumeResetToken(string $email, string $token): AuthCode
    {
        return DB::transaction(function () use ($email, $token): AuthCode {
            $record = AuthCode::query()
                ->where('type', AuthCode::TYPE_PASSWORD_RESET)
                ->where('email', $email)
                ->whereNotNull('reset_token_hash')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $valid = $record !== null
                && $record->reset_token_expires_at?->isFuture()
                && hash_equals($record->reset_token_hash, hash('sha256', $token));

            if (! $valid) {
                throw new ApiException(422, 'RESET_TOKEN_INVALID', '', ['reset_token' => [__('api.RESET_TOKEN_INVALID')]]);
            }

            $record->forceFill(['reset_token_hash' => null, 'reset_token_expires_at' => null])->save();

            return $record;
        });
    }

    private function codeError(string $code): ApiException
    {
        return new ApiException(422, $code, '', ['code' => [__("api.{$code}")]]);
    }
}
