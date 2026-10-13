<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->input('email')) ? Str::lower(trim($this->input('email'))) : $this->input('email'),
        ]);
    }

    /**
     * نفس قواعد الباسورد في التسجيل (8 حروف على الأقل، فيها حرف ورقم، ومعاه تأكيد).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'reset_token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'email' => ['description' => 'الإيميل.', 'example' => 'user@example.com'],
            'reset_token' => ['description' => 'الـ `reset_token` اللي رجع من `POST /auth/password/verify` (لمرة واحدة، 10 دقايق).', 'example' => 'k3Jx...'],
            'password' => ['description' => 'الباسورد الجديد: 8 حروف على الأقل وفيه حرف ورقم.', 'example' => 'newpass123'],
            'password_confirmation' => ['description' => 'تأكيد الباسورد.', 'example' => 'newpass123'],
        ];
    }
}
