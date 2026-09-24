<?php

namespace App\Modules\Leads\Enums;

/**
 * What a shop wants from the reader of a page.
 *
 * The goal decides the shape of everything downstream — how the offer is phrased, how soon it is
 * reasonable to ask for a phone number, and what the thank-you may promise. Asking for a call is
 * a bigger thing to ask than an email for a guide, and the flow should not pretend otherwise.
 */
enum LeadGoal: string
{
    /** A conversation with somebody who knows the subject. */
    case Advice = 'advice';

    /** A price for work this reader described. */
    case Quote = 'quote';

    /** A place held at something happening later. */
    case Signup = 'signup';

    /** Something to read, in exchange for somewhere to send it. */
    case Download = 'download';

    /**
     * How much a reader is being asked to give up, from one to four.
     *
     * A download asks least and a quote asks most, and the flow waits longer before asking the
     * more it is about to want.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Download => 1,
            self::Signup => 2,
            self::Advice => 3,
            self::Quote => 4,
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
