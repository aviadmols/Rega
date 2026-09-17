<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * A number with a unit found in product text, converted to the canonical unit of its dimension:
 * "2000mAh" is charge 2.0 Ah, '7-1/4"' is length 184.15 mm.
 */
final class Measurement
{
    public function __construct(
        public readonly string $id,
        public readonly string $dimension,
        public readonly float $value,
        public readonly string $unit,
        public readonly string $raw,
        public readonly string $quote,
        public readonly int $occurrences = 1,
    ) {}

    /** What the model sees: the raw text is enough, the line around it is already in the product text. */
    public function forAgent(): array
    {
        return [
            'id' => $this->id,
            'value' => self::compact($this->value),
            'unit' => $this->unit,
            'raw' => $this->raw,
        ];
    }

    /** @return array{id: string, dimension: string, value: float, unit: string, raw: string, quote: string, occurrences: int} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'dimension' => $this->dimension,
            'value' => $this->value,
            'unit' => $this->unit,
            'raw' => $this->raw,
            'quote' => $this->quote,
            'occurrences' => $this->occurrences,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['dimension'],
            (float) $data['value'],
            (string) $data['unit'],
            (string) ($data['raw'] ?? ''),
            (string) ($data['quote'] ?? ''),
            (int) ($data['occurrences'] ?? 1),
        );
    }

    /** 18.0 becomes 18, 184.15 stays; fewer tokens and no false precision. */
    public static function compact(float $value): float|int
    {
        $rounded = round($value, 2);

        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }
}
