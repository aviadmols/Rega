<?php

namespace App\Modules\Tenancy\Enums;

/**
 * The kind of shop, and the level at which what one shop learns may reach the next.
 *
 * A shop's own knowledge — its products, its prices, what its shoppers asked — never leaves it.
 * What can travel is the shape of the trade: that a drill is bought with drill bits, that guides
 * in this trade open their conclusions with certain words. A vertical is the name of that shape,
 * so a shop opened tomorrow starts where the others got to instead of from nothing.
 *
 * The markers are how a catalogue is recognised without anyone filling in a form. They are
 * deliberately plain words a shop of this kind cannot avoid using.
 */
enum Vertical: string
{
    case HardwareStore = 'hardware-store';

    /**
     * Words that a shop of this kind has in its categories and product titles.
     *
     * @return list<string>
     */
    public function markers(): array
    {
        return match ($this) {
            self::HardwareStore => [
                'כלי עבודה', 'כלי', 'מקדח', 'מברג', 'מסור', 'פטיש', 'ברגים', 'בורג', 'דיבל',
                'צבע', 'לכה', 'דבק', 'סיליקון', 'עץ', 'פרקט', 'דק', 'נגר', 'חשמל', 'אינסטלציה',
                'גינה', 'פרגולה', 'במבוק', 'סנטף', 'פוליגל', 'ריהוט',
                'tool', 'drill', 'saw', 'hammer', 'screw', 'paint', 'timber', 'wood', 'garden',
            ],
        };
    }

    /** The relation-rule template and vocabulary set this vertical starts from. */
    public function template(): string
    {
        return $this->value;
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
