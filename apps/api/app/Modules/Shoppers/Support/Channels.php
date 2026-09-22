<?php

namespace App\Modules\Shoppers\Support;

/**
 * Whether a code can actually be sent right now. A sign-up is kept either way — the shop still
 * gets the lead — but browsing follows the shopper between browsers only once they proved the
 * contact is theirs, and that needs a code to arrive.
 *
 * No code is ever created for a channel that cannot deliver it, so a shopper is never left waiting
 * for a message nobody sent. Phones wait for an SMS provider (019SMS, with the discount module).
 */
final class Channels
{
    public static function canVerify(string $channel, string $shopId): bool
    {
        return match ($channel) {
            Contact::EMAIL => self::canSendEmail(),
            default => false,
        };
    }

    /** The "log" mailer writes the message to the log instead of sending it: that is not a code. */
    public static function canSendEmail(): bool
    {
        return ! in_array((string) config('mail.default'), ['log', ''], true);
    }
}
