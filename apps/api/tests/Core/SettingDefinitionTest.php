<?php

namespace Tests\Core;

use App\Core\Settings\InvalidSettingValue;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingScope;
use App\Core\Settings\SettingType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingDefinitionTest extends TestCase
{
    /** @return array<string, array{0: SettingDefinition, 1: mixed, 2: int|float|bool|string}> */
    public static function acceptedValues(): array
    {
        return [
            'int from int' => [self::int(), 150, 150],
            'int from form string' => [self::int(), ' 150 ', 150],
            'int from whole float' => [self::int(), 150.0, 150],
            'int at the minimum' => [self::int(), 20, 20],
            'float from string' => [self::float(), '0.5', 0.5],
            'bool from "true"' => [self::bool(), 'true', true],
            'bool from 0' => [self::bool(), 0, false],
            'string within length' => [self::string(), 'friendly', 'friendly'],
            'enum option' => [self::enum(), 'neutral', 'neutral'],
        ];
    }

    #[DataProvider('acceptedValues')]
    public function test_it_normalizes_accepted_values(SettingDefinition $definition, mixed $input, int|float|bool|string $expected): void
    {
        $this->assertSame($expected, $definition->normalize($input));
    }

    /** @return array<string, array{0: SettingDefinition, 1: mixed, 2: string}> */
    public static function rejectedValues(): array
    {
        return [
            'int below min' => [self::int(), 19, 'below_min'],
            'int above max' => [self::int(), 5001, 'above_max'],
            'int from fraction' => [self::int(), '1.5', 'not_integer'],
            'int from words' => [self::int(), 'many', 'not_integer'],
            'float above max' => [self::float(), 1.5, 'above_max'],
            'bool from "yes"' => [self::bool(), 'yes', 'not_boolean'],
            'string too long' => [self::string(), str_repeat('x', 41), 'too_long'],
            'string from number' => [self::string(), 5, 'not_string'],
            'enum not an option' => [self::enum(), 'pushy', 'not_option'],
        ];
    }

    #[DataProvider('rejectedValues')]
    public function test_it_rejects_values_outside_the_declaration(SettingDefinition $definition, mixed $input, string $reason): void
    {
        try {
            $definition->normalize($input);
            $this->fail('Expected InvalidSettingValue.');
        } catch (InvalidSettingValue $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($definition->key(), $e->key);
        }
    }

    private static function int(): SettingDefinition
    {
        return new SettingDefinition('bank', 'retire_after_exposures', SettingType::Int, 150, SettingScope::Shop, 20, 5000);
    }

    private static function float(): SettingDefinition
    {
        return new SettingDefinition('learning', 'holdout_share', SettingType::Float, 0.1, SettingScope::Shop, 0.0, 0.5);
    }

    private static function bool(): SettingDefinition
    {
        return new SettingDefinition('chat', 'enabled', SettingType::Bool, true, SettingScope::Shop);
    }

    private static function string(): SettingDefinition
    {
        return new SettingDefinition('factory', 'style_note', SettingType::String, '', SettingScope::Shop, null, 40);
    }

    private static function enum(): SettingDefinition
    {
        return new SettingDefinition('widget', 'tone', SettingType::Enum, 'friendly', SettingScope::Shop, options: ['friendly', 'neutral']);
    }
}
