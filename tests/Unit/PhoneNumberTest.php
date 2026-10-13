<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * أرقام التليفون المصرية بتتخزّن دولية `+20…` (03 القسم 2 و4.3).
 */
class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validNumbers(): array
    {
        return [
            'mobile with zero' => ['01001234567', '+201001234567'],
            'mobile spaced' => ['010 0123 4567', '+201001234567'],
            'mobile dashed' => ['0100-123-4567', '+201001234567'],
            'mobile international' => ['+201001234567', '+201001234567'],
            'mobile 0020' => ['00201001234567', '+201001234567'],
            'mobile 20 prefix' => ['201001234567', '+201001234567'],
            'mobile without zero' => ['1001234567', '+201001234567'],
            'mobile arabic digits' => ['٠١٠٠١٢٣٤٥٦٧', '+201001234567'],
            'etisalat 011' => ['01112345678', '+201112345678'],
            'orange 012' => ['01212345678', '+201212345678'],
            'we 015' => ['01512345678', '+201512345678'],
            'cairo landline' => ['0225123456', '+20225123456'],
            'cairo landline 8 digits' => ['0227654321', '+20227654321'],
            'alexandria landline' => ['034876543', '+2034876543'],
            'qalyubia landline' => ['0132123456', '+20132123456'],
            'landline international' => ['+20225123456', '+20225123456'],
            'hotline 16' => ['16000', '16000'],
            'hotline 19' => ['19123', '19123'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_valid_numbers_are_stored_in_international_form(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function invalidNumbers(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'letters' => ['abc'],
            'too short' => ['0100123'],
            'too long' => ['010012345678901'],
            'wrong mobile prefix' => ['01301234567'],
            'foreign number' => ['+14155552671'],
            'hotline not starting with 1' => ['26000'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_invalid_numbers_are_rejected(?string $input): void
    {
        $this->assertNull(PhoneNumber::normalize($input));
    }

    public function test_only_egyptian_mobiles_are_whatsapp_capable(): void
    {
        $this->assertTrue(PhoneNumber::isMobile('+201001234567'));
        $this->assertFalse(PhoneNumber::isMobile('+20225123456'));
        $this->assertFalse(PhoneNumber::isMobile('16000'));
        $this->assertFalse(PhoneNumber::isMobile(null));
    }

    public function test_normalized_numbers_fit_the_column(): void
    {
        foreach (self::validNumbers() as [$input]) {
            $this->assertLessThanOrEqual(20, strlen((string) PhoneNumber::normalize($input)));
        }
    }
}
