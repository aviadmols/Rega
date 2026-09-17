<?php

namespace App\Modules\Admin\Tests;

use App\Modules\Admin\Filament\Operator\Resources\Users\Pages\EditUser;
use App\Modules\Admin\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

final class UserPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->operator = User::factory()->operator()->create();
        $this->actingAs($this->operator);
    }

    public function test_the_operator_sets_a_password_of_the_minimum_length_and_can_sign_in_with_it(): void
    {
        $password = str_repeat('a', User::MIN_PASSWORD_LENGTH - 1).'1';

        Livewire::test(EditUser::class, ['record' => $this->operator->getKey()])
            ->fillForm(['password' => $password])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check($password, $this->operator->fresh()->password));
    }

    public function test_a_password_below_the_minimum_is_refused(): void
    {
        $before = $this->operator->password;

        Livewire::test(EditUser::class, ['record' => $this->operator->getKey()])
            ->fillForm(['password' => str_repeat('a', User::MIN_PASSWORD_LENGTH - 1)])
            ->call('save')
            ->assertHasFormErrors(['password' => 'min']);

        $this->assertSame($before, $this->operator->fresh()->password);
    }

    public function test_browsers_are_told_not_to_autofill_the_operators_own_password(): void
    {
        $this->get("/operator/users/{$this->operator->getKey()}/edit")
            ->assertOk()
            ->assertSee('autocomplete="new-password"', false);
    }

    public function test_leaving_the_password_empty_keeps_the_current_one(): void
    {
        $before = $this->operator->password;

        Livewire::test(EditUser::class, ['record' => $this->operator->getKey()])
            ->fillForm(['name' => 'Renamed', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($before, $this->operator->fresh()->password);
        $this->assertSame('Renamed', $this->operator->fresh()->name);
    }
}
