<?php

namespace Tests\Feature;

use App\Support\GoogleMapsUrl;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تتبّع الرابط المختصر `maps.app.goo.gl` لحد الرابط الطويل. بنحاكي ردود Google بـ `Http::fake`:
 * مفيش اتصال حقيقي، وبنتأكد إن الأداة ما بتتبعش أي redirect برّه دومينات Google.
 */
class GoogleMapsUrlResolveTest extends TestCase
{
    public function test_long_links_resolve_without_any_request(): void
    {
        Http::fake();

        $this->assertSame(
            ['lat' => 30.03, 'lng' => 31.47],
            GoogleMapsUrl::resolve('https://www.google.com/maps/@30.03,31.47,17z'),
        );

        Http::assertNothingSent();
    }

    public function test_short_link_follows_the_redirect_to_the_long_url(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, [
                'Location' => 'https://www.google.com/maps/place/Cilantro/@30.0300,31.4700,17z/data=!3d30.0311!4d31.4722',
            ]),
        ]);

        $this->assertSame(['lat' => 30.0311, 'lng' => 31.4722], GoogleMapsUrl::resolve('https://maps.app.goo.gl/AbCdEf123'));
        Http::assertSentCount(1);
    }

    public function test_short_link_that_hops_through_google_twice(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://goo.gl/maps/second']),
            'goo.gl/*' => Http::response('', 302, ['Location' => 'https://www.google.com/maps?q=30.05,31.25']),
        ]);

        $this->assertSame(['lat' => 30.05, 'lng' => 31.25], GoogleMapsUrl::resolve('https://maps.app.goo.gl/AbCdEf123'));
        Http::assertSentCount(2);
    }

    public function test_consent_page_continue_parameter_is_followed(): void
    {
        $continue = rawurlencode('https://www.google.com/maps/place/x/@30.1,31.2,17z');

        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, [
                'Location' => "https://consent.google.com/m?continue={$continue}&gl=DE",
            ]),
        ]);

        $this->assertSame(['lat' => 30.1, 'lng' => 31.2], GoogleMapsUrl::resolve('https://maps.app.goo.gl/AbCdEf123'));
    }

    public function test_redirect_to_a_foreign_host_is_not_followed(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://evil.example/@30.0,31.0,15z']),
            'evil.example/*' => Http::response('', 200),
        ]);

        $this->assertNull(GoogleMapsUrl::resolve('https://maps.app.goo.gl/AbCdEf123'));
        Http::assertSentCount(1);
    }

    public function test_foreign_urls_are_rejected_before_any_request(): void
    {
        Http::fake();

        $this->assertNull(GoogleMapsUrl::resolve('https://evil.example/@30.0,31.0,15z'));
        Http::assertNothingSent();
    }

    public function test_server_error_gives_null(): void
    {
        // مفيش exception هنا عن قصد: Xdebug بوضع develop على PHP 8.3.6 (Windows) بيعمل segfault لما exception
        // يعدّي في Guzzle. الـ catch (Throwable) في الأداة بيغطي الاتصال المقطوع.
        Http::fake(['*' => Http::response('', 503)]);

        $this->assertNull(GoogleMapsUrl::resolve('https://maps.app.goo.gl/AbCdEf123'));
    }

    public function test_redirect_loop_gives_up(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://maps.app.goo.gl/again']),
        ]);

        $this->assertNull(GoogleMapsUrl::resolve('https://maps.app.goo.gl/AbCdEf123'));
        Http::assertSentCount(5);
    }
}
