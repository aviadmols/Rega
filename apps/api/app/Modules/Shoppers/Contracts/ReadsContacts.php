<?php

namespace App\Modules\Shoppers\Contracts;

/**
 * Reading a phone number or an email the way this platform reads them.
 *
 * Another module collecting a contact must get the same answer as the sign-up does, or a number
 * accepted in one place and refused in another becomes a support ticket. The parsing itself is
 * Shoppers' business — the country code, the masking, the salted hash — so it stays there and is
 * offered through this.
 */
interface ReadsContacts
{
    /**
     * @return array{channel: string, value: string, masked: string, hash: string}|null null when it is neither
     */
    public function read(string $raw, string $shopId): ?array;
}
