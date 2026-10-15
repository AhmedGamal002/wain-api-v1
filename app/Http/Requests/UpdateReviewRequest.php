<?php

namespace App\Http\Requests;

/**
 * `PUT /reviews/{id}`: نفس حقول الإرسال (استبدال كامل للحقول القابلة للتعديل)، والصور المربوطة بنفس
 * التقييم فضلت مسموحة.
 */
class UpdateReviewRequest extends ReviewRequest
{
    protected function reviewId(): ?int
    {
        return (int) $this->route('id');
    }
}
