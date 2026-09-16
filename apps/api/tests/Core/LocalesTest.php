<?php

namespace Tests\Core;

use App\Core\Localization\Locales;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LocalesTest extends TestCase
{
    /** @return array<string, array{0: string|null, 1: string|null}> */
    public static function headers(): array
    {
        return [
            'hebrew browser' => ['he-IL,he;q=0.9,en-US;q=0.8', 'he'],
            'english browser' => ['en-US,en;q=0.9', 'en'],
            'quality decides' => ['en;q=0.4,he;q=0.8', 'he'],
            'first wins on a tie' => ['en,he', 'en'],
            'legacy hebrew code' => ['iw-IL', 'he'],
            'unsupported only' => ['fr-FR,de;q=0.5', null],
            'unsupported first, supported later' => ['fr,en;q=0.2', 'en'],
            'explicitly refused' => ['he;q=0', null],
            'empty' => ['', null],
            'missing' => [null, null],
        ];
    }

    #[DataProvider('headers')]
    public function test_it_negotiates_a_supported_locale(?string $header, ?string $expected): void
    {
        $this->assertSame($expected, Locales::negotiate($header));
    }

    public function test_it_knows_the_text_direction(): void
    {
        $this->assertSame('rtl', Locales::direction('he'));
        $this->assertSame('rtl', Locales::direction('he_IL'));
        $this->assertSame('ltr', Locales::direction('en'));
        $this->assertSame('ltr', Locales::direction('en-US'));
    }

    public function test_hebrew_is_the_fallback(): void
    {
        $this->assertSame(['he', 'en'], Locales::supported());
        $this->assertSame('he', Locales::fallback());
    }
}
