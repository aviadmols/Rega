<?php

namespace App\Modules\Admin\Tests;

use App\Modules\Admin\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CreateOperatorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_operator_with_a_generated_password_when_not_interactive(): void
    {
        $this->artisan('admin:operator', ['email' => 'Aviad@Example.com', '--locale' => 'he', '--no-interaction' => true])
            ->expectsOutputToContain('Operator aviad@example.com created.')
            ->assertSuccessful();

        $user = User::query()->where('email', 'aviad@example.com')->sole();
        $this->assertTrue($user->is_operator);
        $this->assertSame('he', $user->locale);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_it_promotes_an_existing_user_without_touching_the_password(): void
    {
        $user = User::factory()->create(['email' => 'merchant@example.com']);
        $hash = $user->password;

        $this->artisan('admin:operator', ['email' => 'merchant@example.com', '--no-interaction' => true])->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->is_operator);
        $this->assertSame($hash, $user->password);
    }

    public function test_the_password_can_come_from_an_environment_variable_and_is_never_printed(): void
    {
        putenv('TEST_OPERATOR_PASSWORD=correct-horse-battery-staple');

        try {
            $this->artisan('admin:operator', ['email' => 'ops@example.com', '--password-env' => 'TEST_OPERATOR_PASSWORD', '--no-interaction' => true])
                ->doesntExpectOutputToContain('correct-horse-battery-staple')
                ->doesntExpectOutputToContain('Generated password')
                ->assertSuccessful();
        } finally {
            putenv('TEST_OPERATOR_PASSWORD');
        }

        $user = User::query()->where('email', 'ops@example.com')->sole();
        $this->assertTrue(Hash::check('correct-horse-battery-staple', $user->password));
    }

    public function test_running_it_again_on_every_boot_never_resets_the_password(): void
    {
        $user = User::factory()->operator()->create(['email' => 'ops@example.com']);
        $hash = $user->password;
        putenv('TEST_OPERATOR_PASSWORD=a-completely-different-password');

        try {
            $this->artisan('admin:operator', ['email' => 'ops@example.com', '--password-env' => 'TEST_OPERATOR_PASSWORD', '--no-interaction' => true])
                ->expectsOutputToContain('already an operator')
                ->assertSuccessful();
        } finally {
            putenv('TEST_OPERATOR_PASSWORD');
        }

        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_a_missing_or_short_environment_password_creates_nobody(): void
    {
        putenv('TEST_OPERATOR_PASSWORD=short');

        try {
            $this->artisan('admin:operator', ['email' => 'a@example.com', '--password-env' => 'TEST_OPERATOR_PASSWORD', '--no-interaction' => true])->assertFailed();
            $this->artisan('admin:operator', ['email' => 'a@example.com', '--password-env' => 'NOT_SET_ANYWHERE_123', '--no-interaction' => true])->assertFailed();
        } finally {
            putenv('TEST_OPERATOR_PASSWORD');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_rejects_bad_input(): void
    {
        $this->artisan('admin:operator', ['email' => 'not-an-email', '--no-interaction' => true])->assertFailed();
        $this->artisan('admin:operator', ['email' => 'a@example.com', '--locale' => 'fr', '--no-interaction' => true])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
