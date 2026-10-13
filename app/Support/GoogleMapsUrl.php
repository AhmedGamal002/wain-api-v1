<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * استخراج الإحداثيات من رابط Google Maps (03 القسم 4.3 تاب «الموقع»): الفريق بيلصق الرابط والإحداثيات
 * تتملى، والـ picker بيضبط الـ pin بس. مفيش API ولا مفتاح: قراية للرابط نفسه.
 *
 * `coordinates()` بتقرا من رابط طويل من غير أي طلب شبكة. `resolve()` بتتبع الروابط المختصرة
 * (`maps.app.goo.gl`) بطلبات `Location` يدوية على دومينات Google بس (مش أي redirect)، فالأداة دي ما تنفعش
 * تتحوّل لـ proxy لأي عنوان.
 */
final class GoogleMapsUrl
{
    /** أقصى عدد redirects بنتبعه للرابط المختصر. */
    private const MAX_HOPS = 5;

    /** دومينات الروابط المختصرة اللي بنتبعها. */
    private const SHORT_HOSTS = ['maps.app.goo.gl', 'goo.gl', 'g.co'];

    /**
     * إحداثيات من رابط طويل، بالأولوية: `!3d..!4d..` (نقطة المكان نفسه) ثم `@lat,lng` (مركز العرض) ثم
     * بارامتر `q` أو `ll` أو `query` أو `destination` أو `center` بقيمة `lat,lng`.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function coordinates(string $url): ?array
    {
        $url = rawurldecode(trim($url));

        $patterns = [
            '/!3d(-?\d{1,3}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/',
            '/@(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/',
            '/[?&](?:q|ll|query|destination|center|daddr)=(-?\d{1,3}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $match) === 1) {
                $lat = (float) $match[1];
                $lng = (float) $match[2];

                if (abs($lat) <= 90 && abs($lng) <= 180) {
                    return ['lat' => $lat, 'lng' => $lng];
                }
            }
        }

        return null;
    }

    /**
     * رابط Google Maps (طويل أو مختصر) من غير حقل خريطة فاضي؟ بيرفض أي دومين تاني قبل أي طلب.
     */
    public static function isGoogleMapsUrl(string $url): bool
    {
        $host = self::host($url);

        return $host !== null && (self::isShortHost($host) || self::isGoogleHost($host));
    }

    /**
     * إحداثيات من أي رابط Google Maps. الطويل بيتقرا مباشرةً، والمختصر بيتتبع لحد الرابط الطويل.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function resolve(string $url): ?array
    {
        $url = trim($url);

        if (! self::isGoogleMapsUrl($url)) {
            return null;
        }

        if (($direct = self::coordinates($url)) !== null) {
            return $direct;
        }

        $current = $url;
        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $next = self::nextLocation($current);
            if ($next === null || ! self::isGoogleMapsUrl($next)) {
                return null;
            }

            if (($found = self::coordinates($next)) !== null) {
                return $found;
            }

            $current = $next;
        }

        return null;
    }

    /**
     * الـ `Location` اللي الرابط بيحوّل عليه (من غير ما نتبعه تلقائياً)، أو null.
     * صفحة الموافقة (consent) بتحط الوجهة الحقيقية في بارامتر `continue`.
     */
    private static function nextLocation(string $url): ?string
    {
        try {
            $response = Http::timeout(5)->withoutRedirecting()->withUserAgent('Mozilla/5.0 (compatible; WainDashboard/1.0)')->get($url);
        } catch (Throwable) {
            return null;
        }

        $location = $response->header('Location');
        if ($location === '') {
            return null;
        }

        $parts = parse_url($location);
        if (is_array($parts) && isset($parts['host']) && str_starts_with($parts['host'], 'consent.') && isset($parts['query'])) {
            parse_str($parts['query'], $query);
            if (is_string($query['continue'] ?? null)) {
                return $query['continue'];
            }
        }

        return $location;
    }

    private static function host(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) && $host !== '' && str_starts_with(strtolower(trim($url)), 'http') ? strtolower($host) : null;
    }

    private static function isShortHost(string $host): bool
    {
        return in_array($host, self::SHORT_HOSTS, true);
    }

    /** google.com و google.com.eg وأي subdomain فيهم (www وmaps وconsent). */
    private static function isGoogleHost(string $host): bool
    {
        return (bool) preg_match('/(^|\.)google\.(com|com\.eg)$/', $host);
    }
}
