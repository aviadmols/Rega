<?php

namespace App\Modules\Analytics\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates widget beacons against packages/event-spec, the same files the widget's tests use.
 * In the Docker image the schema files are copied to resources/event-spec.
 */
final class BeaconSchema
{
    public const BEACON_ID = 'https://spec.upsell.internal/event-spec/beacon.v1.schema.json';

    public const EVENT_ID = 'https://spec.upsell.internal/event-spec/event.v1.schema.json';

    private ?Validator $validator = null;

    /** @return list<string> problems; empty when the beacon is valid */
    public function problems(mixed $decodedAsObjects): array
    {
        $result = $this->validator()->validate($decodedAsObjects, self::BEACON_ID);

        if ($result->isValid()) {
            return [];
        }

        $errors = (new ErrorFormatter)->format($result->error(), false);

        return array_values(array_map(fn ($message, $path): string => $path.': '.$message, $errors, array_keys($errors)));
    }

    public static function directory(): string
    {
        $inRepository = base_path('../../packages/event-spec/schema');

        return is_dir($inRepository) ? $inRepository : resource_path('event-spec');
    }

    private function validator(): Validator
    {
        if ($this->validator === null) {
            $this->validator = new Validator;
            $this->validator->setMaxErrors(3);
            $this->validator->resolver()->registerFile(self::EVENT_ID, self::directory().'/event.v1.schema.json');
            $this->validator->resolver()->registerFile(self::BEACON_ID, self::directory().'/beacon.v1.schema.json');
        }

        return $this->validator;
    }
}
