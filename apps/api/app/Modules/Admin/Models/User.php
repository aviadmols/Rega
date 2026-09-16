<?php

namespace App\Modules\Admin\Models;

use App\Core\Facades\Features;
use App\Core\Localization\Locales;
use App\Modules\Admin\Database\Factories\UserFactory;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

/**
 * Someone who signs in to an admin panel.
 *
 * Operators (is_operator) run the whole system and see every shop. Everyone else is a
 * merchant user and sees only the shops they are a member of.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_operator
 * @property string|null $locale admin UI language; null follows the browser
 */
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    public const OPERATOR_PANEL = 'operator';

    public const MERCHANT_PANEL = 'merchant';

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'is_operator',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_operator' => 'boolean',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** @return BelongsToMany<Shop, $this> */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'shop_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function preferredLocale(): ?string
    {
        return Locales::isSupported($this->locale) ? $this->locale : null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            self::OPERATOR_PANEL => $this->is_operator,
            self::MERCHANT_PANEL => $this->is_operator || $this->shops()->exists(),
            default => false,
        };
    }

    /** @return Collection<int, Shop> */
    public function getTenants(Panel $panel): Collection
    {
        /** @var EloquentCollection<int, Shop> $shops */
        $shops = $this->is_operator
            ? Shop::query()->orderBy('name')->get()
            : $this->shops()->orderBy('name')->get();

        return $shops->filter(fn (Shop $shop): bool => $this->canAccessTenant($shop))->values();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Shop) {
            return false;
        }

        // Operators can open any shop's merchant view, to see exactly what the merchant sees.
        if ($this->is_operator) {
            return true;
        }

        return $tenant->status !== ShopStatus::Disabled
            && Features::enabled('admin.merchant_panel', $tenant->id)
            && $this->shops()->whereKey($tenant->getKey())->exists();
    }

    public function attachShop(Shop $shop, ShopRole $role = ShopRole::Member): void
    {
        $this->shops()->syncWithoutDetaching([$shop->getKey() => ['role' => $role->value]]);
    }
}
