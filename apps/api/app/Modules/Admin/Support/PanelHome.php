<?php

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Models\User;

/**
 * Where each person belongs when nothing more specific was asked for: operators in the operator
 * panel, everyone else in the merchant panel.
 */
final class PanelHome
{
    public static function for(?User $user): string
    {
        return $user?->is_operator ? url('/'.User::OPERATOR_PANEL) : url('/'.User::MERCHANT_PANEL);
    }

    /**
     * Generic entry points that should not override the role-based home. A specific page someone
     * was sent to, such as a shop's merchant view, is still honoured.
     */
    public static function isGenericEntry(string $url): bool
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');

        return in_array($path, ['/', '/'.User::MERCHANT_PANEL, '/'.User::OPERATOR_PANEL], true);
    }
}
