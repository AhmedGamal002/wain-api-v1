<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * المستخدم الحالي (GET /auth/me وداخل ردّ التسجيل والدخول). مفيش حقول داخلية (status وterms وغيرهم).
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'avatar_url' => $this->avatar_path ? Storage::url($this->avatar_path) : null,
            'locale' => $this->locale,
            'default_area_id' => $this->default_area_id,
        ];
    }
}
