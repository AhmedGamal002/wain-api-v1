<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class VerifyResetCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->input('email')) ? Str::lower(trim($this->input('email'))) : $this->input('email'),
            // مسافات من اللصق من الإيميل
            'code' => is_string($this->input('code')) ? preg_replace('/\s+/', '', $this->input('code')) : $this->input('code'),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'email' => ['description' => 'نفس الإيميل اللي اتبعت له الكود.', 'example' => 'user@example.com'],
            'code' => ['description' => 'الكود 6 أرقام.', 'example' => '482915'],
        ];
    }
}
