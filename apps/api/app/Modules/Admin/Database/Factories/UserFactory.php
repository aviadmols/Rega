<?php

namespace App\Modules\Admin\Database\Factories;

use App\Modules\Admin\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_operator' => false,
            'locale' => null,
        ];
    }

    public function operator(): static
    {
        return $this->state(['is_operator' => true]);
    }

    public function locale(?string $locale): static
    {
        return $this->state(['locale' => $locale]);
    }
}
