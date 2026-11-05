<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidGtin;
use App\Rules\ValidNcm;
use App\Rules\ValidStateRegistration;
use Illuminate\Contracts\Validation\ValidationRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FiscalRulesTest extends TestCase
{
    private function fails(ValidationRule $rule, mixed $value): bool
    {
        $failed = false;
        $rule->validate('campo', $value, function () use (&$failed): void {
            $failed = true;
        });

        return $failed;
    }

    /**
     * @return array<string, array{ValidationRule}>
     */
    public static function rules(): array
    {
        return [
            'ncm' => [new ValidNcm],
            'gtin' => [new ValidGtin],
            'ie' => [new ValidStateRegistration],
        ];
    }

    #[DataProvider('rules')]
    public function test_empty_values_pass_because_the_fields_are_optional(ValidationRule $rule): void
    {
        $this->assertFalse($this->fails($rule, null));
        $this->assertFalse($this->fails($rule, ''));
    }

    public function test_ncm_needs_exactly_eight_digits_with_or_without_mask(): void
    {
        $rule = new ValidNcm;

        $this->assertFalse($this->fails($rule, '19059090'));
        $this->assertFalse($this->fails($rule, '1905.90.90'));
        $this->assertTrue($this->fails($rule, '1905909'));
        $this->assertTrue($this->fails($rule, '190590901'));
        $this->assertTrue($this->fails($rule, 'abcdefgh'));
        $this->assertTrue($this->fails($rule, '1234-5678'), 'Só o ponto da máscara é aceito.');
        $this->assertTrue($this->fails($rule, '12ab345678'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function gtins(): array
    {
        return [
            'EAN-13 válido' => ['7891000100103', false],
            'EAN-13 com dígito errado' => ['7891000100104', true],
            'EAN-8 válido' => ['96385074', false],
            'EAN-8 com dígito errado' => ['96385075', true],
            'UPC-A válido' => ['036000291452', false],
            'GTIN-14 válido' => ['10012345678902', false],
            'tamanho inválido' => ['123456789', true],
            'com máscara/espaços' => ['7891000 100103', false],
            'com traços de formatação' => ['7891-000-100103', false],
            'com letras' => ['78910001001A3', true],
            'com símbolos' => ['7891000100103!', true],
        ];
    }

    #[DataProvider('gtins')]
    public function test_gtin_checks_length_and_the_gs1_check_digit(string $gtin, bool $shouldFail): void
    {
        $this->assertSame($shouldFail, $this->fails(new ValidGtin, $gtin));
    }

    public function test_state_registration_accepts_digits_or_isento(): void
    {
        $rule = new ValidStateRegistration;

        $this->assertFalse($this->fails($rule, '123456789'));
        $this->assertFalse($this->fails($rule, '110.042.490.114'));
        $this->assertFalse($this->fails($rule, 'ISENTO'));
        $this->assertFalse($this->fails($rule, 'isento'));
        $this->assertTrue($this->fails($rule, 'ABC123'));
        $this->assertTrue($this->fails($rule, '1'));
        $this->assertTrue($this->fails($rule, '123456789012345'));
    }
}
