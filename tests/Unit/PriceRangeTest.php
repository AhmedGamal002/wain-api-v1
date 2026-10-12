<?php

namespace Tests\Unit;

use App\Support\PriceRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PriceRangeTest extends TestCase
{
    /**
     * @return array<string, array{0: ?int, 1: ?string}>
     */
    public static function boundaries(): array
    {
        return [
            'null' => [null, null],
            'zero' => [0, 'budget'],
            'just below 250' => [249, 'budget'],
            '250 is mid' => [250, 'mid'],
            '600 is still mid' => [600, 'mid'],
            '601 is premium' => [601, 'premium'],
            'very high' => [10000, 'premium'],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_default_thresholds(?int $max, ?string $expected): void
    {
        $this->assertSame($expected, PriceRange::forMaxPrice($max));
    }

    public function test_thresholds_can_come_from_settings(): void
    {
        // التضخم: الحدود بتتغيّر من جدول settings
        $this->assertSame('budget', PriceRange::forMaxPrice(299, budgetMax: 300, midMax: 700));
        $this->assertSame('mid', PriceRange::forMaxPrice(700, budgetMax: 300, midMax: 700));
        $this->assertSame('premium', PriceRange::forMaxPrice(701, budgetMax: 300, midMax: 700));
    }
}
