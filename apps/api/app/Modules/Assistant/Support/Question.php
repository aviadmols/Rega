<?php

namespace App\Modules\Assistant\Support;

/** A shopper's question as code sees it: normalized to find the same question again, and checked for contact details. */
final class Question
{
    private const CONTACT = '~https?://|www\.|\S+@\S+\.\S+|(?:\+?972|\b0)[\s-]?\d{1,2}[\s-]?\d{3}[\s-]?\d{4}\b~iu';

    /** Lowercase, punctuation and repeated spaces removed: "האם זה מתאים לחוץ?" and "האם זה מתאים לחוץ" are one question. */
    public static function normalize(string $question): string
    {
        $text = mb_strtolower(trim($question));
        $text = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function key(string $question): string
    {
        return hash('sha256', self::normalize($question));
    }

    /** Links, emails and phone numbers are never sent to a model or stored. */
    public static function hasContactDetails(string $question): bool
    {
        return preg_match(self::CONTACT, $question) === 1;
    }

    /** Collapses whitespace and cuts to $max characters. */
    public static function clean(string $question, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $question)), 0, $max);
    }
}
