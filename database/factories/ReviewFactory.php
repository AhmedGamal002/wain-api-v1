<?php

namespace Database\Factories;

use App\Models\Place;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'place_id' => Place::factory()->published(),
            'user_id' => User::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'body' => 'تجربة حلوة والخدمة سريعة والمكان نضيف.',
            'status' => Review::STATUS_PENDING,
            'edit_status' => Review::EDIT_NONE,
            'submitted_at' => now(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => Review::STATUS_PUBLISHED,
            'published_at' => now(),
            'moderated_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'ألفاظ غير لائقة'): static
    {
        return $this->state(fn () => [
            'status' => Review::STATUS_REJECTED,
            'status_reason' => $reason,
            'moderated_at' => now(),
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => [
            'status' => Review::STATUS_HIDDEN,
            'status_reason' => 'reports',
            'published_at' => now(),
        ]);
    }

    /**
     * تعديل منتظر موافقة على تقييم منشور.
     *
     * @param  array<string, mixed>  $payload
     */
    public function withPendingEdit(array $payload): static
    {
        return $this->state(fn () => [
            'status' => Review::STATUS_PUBLISHED,
            'published_at' => now(),
            'edit_status' => Review::EDIT_PENDING,
            'pending_payload' => $payload,
        ]);
    }

    public function rating(int $rating): static
    {
        return $this->state(fn () => ['rating' => $rating]);
    }
}
