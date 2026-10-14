<?php

namespace App\Support;

use App\Models\Place;
use Illuminate\Database\Eloquent\Builder;

/**
 * ترتيب قوائم الأماكن (03 القسم 7.3): مشترك بين `GET /places` و`GET /search` عشان الترتيب المقترح
 * والأقرب والتقييم والأحدث يتصرّفوا بنفس الشكل في الرئيسية والبحث.
 */
final class PlaceListOrder
{
    /**
     * @param  Builder<Place>  $query
     * @param  string  $sort  recommended | nearest (محتاج `distance_m`) | rating | newest
     */
    public static function apply(Builder $query, string $sort): void
    {
        match ($sort) {
            'nearest' => $query->orderBy('distance_m'),
            'rating' => $query->orderByDesc('rating_avg')->orderByDesc('rating_count'),
            'newest' => $query->orderByDesc('published_at'),
            default => self::recommended($query),
        };

        // ترتيب ثابت يمنع تكرار أو ضياع عنصر بين صفحتين
        $query->orderByDesc('places.id');
    }

    /**
     * الترتيب المقترح (03 القسم 7.3): الباقة (premium ثم featured ثم free)، جوه المدفوعة ترتيب
     * بيتبدّل كل يوم بتوقيت القاهرة، وجوه المجاني اللي له 3 تقييمات أو أكتر الأول بالتقييم، والباقي
     * بالأحدث نشراً.
     *
     * @param  Builder<Place>  $query
     */
    private static function recommended(Builder $query): void
    {
        $today = now('Africa/Cairo')->toDateString();

        $query
            ->orderBy('tier_rank')
            ->orderByRaw("CASE WHEN tier_rank < 3 THEN MD5(CONCAT(places.id, '|', ?)) END", [$today])
            ->orderByRaw('(rating_count >= 3) DESC')
            ->orderByDesc('rating_avg')
            ->orderByDesc('published_at');
    }
}
