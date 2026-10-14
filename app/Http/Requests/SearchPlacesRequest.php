<?php

namespace App\Http\Requests;

/**
 * بارامترات `GET /search` (02 F4): كلمة البحث `q` + نفس بارامترات `GET /places` (فلاتر F5 والترتيب والموقع
 * والـ pagination)، والفلاتر بتتطبّق كلها في نفس استعلام البحث.
 */
class SearchPlacesRequest extends IndexPlacesRequest
{
    /** من حرفين وطالع (02 F4: النتايج بتبدأ بعد حرفين). */
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 60;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:'.self::MIN_LENGTH, 'max:'.self::MAX_LENGTH],
            ...parent::rules(),
        ];
    }

    public function queryParameters(): array
    {
        return [
            'q' => [
                'description' => 'كلمة البحث، من حرفين لـ 60 حرف. بيتطبّع (أ إ آ = ا، ة = ه، ى = ي، من غير تشكيل) '
                    .'وبيتوسّع بالمرادفات (كوفي = كافيه = coffee)، وبيدوّر في الاسمين والـ aliases والتصنيف والخصائص '
                    .'والمنطقة والوصف.',
                'example' => 'كافيه هادي',
            ],
            ...parent::queryParameters(),
        ];
    }

    /**
     * كلمة البحث زي ما اتبعتت.
     */
    public function searchQuery(): string
    {
        return (string) $this->validated('q');
    }
}
