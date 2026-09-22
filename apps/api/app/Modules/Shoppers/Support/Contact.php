<?php

namespace App\Modules\Shoppers\Support;

use App\Core\Facades\Settings;

/**
 * A phone or an email as the shopper typed it, turned into one form so the same person is
 * recognised however they wrote it, plus a masked version for lists and logs.
 */
final readonly class Contact
{
    public const PHONE = 'phone';

    public const EMAIL = 'email';

    private function __construct(
        public string $channel,
        public string $value,
        public string $masked,
    ) {}

    /** The typed text, or null when it is neither a phone nor an email we can use. */
    public static function parse(string $raw, string $shopId): ?self
    {
        $raw = trim($raw);

        if ($raw === '' || mb_strlen($raw) > 190) {
            return null;
        }

        return str_contains($raw, '@') ? self::email($raw) : self::phone($raw, $shopId);
    }

    private static function email(string $raw): ?self
    {
        $value = mb_strtolower($raw);

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        [$name, $domain] = explode('@', $value, 2);
        $masked = mb_substr($name, 0, 1).str_repeat('*', max(1, min(6, mb_strlen($name) - 1))).'@'.$domain;

        return new self(self::EMAIL, $value, $masked);
    }

    private static function phone(string $raw, string $shopId): ?self
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = mb_substr($digits, 2);
        }

        // A local number ("050…") becomes international, so the same phone is one person whether
        // they typed it local or with the country code.
        if (str_starts_with($digits, '0')) {
            $digits = ((string) Settings::get('shoppers.country_code', $shopId)).mb_substr($digits, 1);
        }

        if (! preg_match('/^[1-9][0-9]{8,14}$/', $digits)) {
            return null;
        }

        $masked = str_repeat('*', mb_strlen($digits) - 4).mb_substr($digits, -4);

        return new self(self::PHONE, $digits, $masked);
    }

    /** Salted per shop, so the same phone in two shops cannot be joined. */
    public function hash(string $shopId): string
    {
        return hash('sha256', $shopId.'|'.$this->channel.'|'.$this->value);
    }
}
