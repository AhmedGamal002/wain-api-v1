<?php

namespace Tests\Unit;

use App\Support\GoogleMapsUrl;
use PHPUnit\Framework\TestCase;

/**
 * قراية الإحداثيات من رابط Google Maps الطويل (من غير شبكة). تتبّع الروابط المختصرة في
 * `Tests\Feature\GoogleMapsUrlResolveTest`.
 */
class GoogleMapsUrlTest extends TestCase
{
    public function test_place_pin_wins_over_the_viewport_center(): void
    {
        $url = 'https://www.google.com/maps/place/Cilantro/@30.0300,31.4700,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x1!8m2!3d30.0311234!4d31.4722345!16s%2Fg%2F1';

        $this->assertSame(['lat' => 30.0311234, 'lng' => 31.4722345], GoogleMapsUrl::coordinates($url));
    }

    public function test_viewport_center_is_the_fallback(): void
    {
        $this->assertSame(
            ['lat' => 30.0444, 'lng' => 31.2357],
            GoogleMapsUrl::coordinates('https://www.google.com/maps/@30.0444,31.2357,15z'),
        );
    }

    public function test_query_parameters(): void
    {
        $this->assertSame(
            ['lat' => 29.9792, 'lng' => 31.1342],
            GoogleMapsUrl::coordinates('https://www.google.com/maps?q=29.9792,31.1342'),
        );
        $this->assertSame(
            ['lat' => 29.9792, 'lng' => 31.1342],
            GoogleMapsUrl::coordinates('https://maps.google.com/?ll=29.9792%2C31.1342&z=14'),
        );
        $this->assertSame(
            ['lat' => 31.2001, 'lng' => 29.9187],
            GoogleMapsUrl::coordinates('https://www.google.com/maps/search/?api=1&query=31.2001%2C29.9187'),
        );
    }

    public function test_negative_coordinates(): void
    {
        $this->assertSame(
            ['lat' => -33.8688, 'lng' => 151.2093],
            GoogleMapsUrl::coordinates('https://www.google.com/maps/@-33.8688,151.2093,12z'),
        );
    }

    public function test_links_without_coordinates_give_null(): void
    {
        $this->assertNull(GoogleMapsUrl::coordinates('https://www.google.com/maps/place/Cilantro+New+Cairo'));
        $this->assertNull(GoogleMapsUrl::coordinates('https://maps.app.goo.gl/AbCdEf123'));
        $this->assertNull(GoogleMapsUrl::coordinates(''));
    }

    public function test_out_of_range_numbers_are_not_coordinates(): void
    {
        $this->assertNull(GoogleMapsUrl::coordinates('https://www.google.com/maps/@130.0,31.2,15z'));
    }

    public function test_only_google_maps_hosts_are_recognised(): void
    {
        $this->assertTrue(GoogleMapsUrl::isGoogleMapsUrl('https://www.google.com/maps/place/x'));
        $this->assertTrue(GoogleMapsUrl::isGoogleMapsUrl('https://google.com.eg/maps/place/x'));
        $this->assertTrue(GoogleMapsUrl::isGoogleMapsUrl('https://maps.app.goo.gl/AbCdEf123'));
        $this->assertTrue(GoogleMapsUrl::isGoogleMapsUrl('https://goo.gl/maps/AbCdEf123'));

        $this->assertFalse(GoogleMapsUrl::isGoogleMapsUrl('https://evil.example/maps/@30.0,31.0,15z'));
        $this->assertFalse(GoogleMapsUrl::isGoogleMapsUrl('https://google.com.evil.example/maps'));
        $this->assertFalse(GoogleMapsUrl::isGoogleMapsUrl('https://notgoogle.com/maps'));
        $this->assertFalse(GoogleMapsUrl::isGoogleMapsUrl('ftp://www.google.com/maps'));
        $this->assertFalse(GoogleMapsUrl::isGoogleMapsUrl('www.google.com/maps/@30.0,31.0,15z'));
        $this->assertFalse(GoogleMapsUrl::isGoogleMapsUrl('not a url'));
    }
}
