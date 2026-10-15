<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /uploads/photos` (06 القسم 3): صورة من التطبيق (بعد ضغطها على الموبايل لأقل من 1MB)، و`context` = `review`
 * (الاقتراحات `suggestion` مع F10، أسبوع 13). الحد في السيرفر 8MB ومن غير مسافة للتطبيق القديم.
 */
class UploadPhotoRequest extends FormRequest
{
    public const MAX_KB = 8192;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'context' => ['required', 'string', 'in:review'],
            'photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.self::MAX_KB],
        ];
    }
}
