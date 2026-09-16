<?php

namespace App\Modules\Tenancy\Enums;

enum ShopPlatform: string
{
    case WooCommerce = 'woocommerce';
    case Shopify = 'shopify';

    public function label(): string
    {
        return __("tenancy::shops.platforms.{$this->value}");
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $p) => $p->value, self::cases()),
            array_map(fn (self $p) => $p->label(), self::cases()),
        );
    }
}
