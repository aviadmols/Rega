<?php

namespace App\Modules\Shoppers\Actions;

use App\Modules\Shoppers\Contracts\ReadsContacts;
use App\Modules\Shoppers\Support\Contact;

/** The platform's one reading of a phone number or an email, offered to other modules. */
final class ReadContact implements ReadsContacts
{
    public function read(string $raw, string $shopId): ?array
    {
        $contact = Contact::parse($raw, $shopId);

        if ($contact === null) {
            return null;
        }

        return [
            'channel' => $contact->channel,
            'value' => $contact->value,
            'masked' => $contact->masked,
            'hash' => $contact->hash($shopId),
        ];
    }
}
