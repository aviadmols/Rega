<?php

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;

/**
 * Creates or promotes the system operator. The only way the first operator comes to exist,
 * so no default password is ever seeded.
 *
 * On a server with no shell, the container entrypoint calls it with --password-env, so the
 * password travels in an environment variable and never appears in a command line or a log.
 * An existing user is only promoted: running it on every boot never resets a password.
 */
final class CreateOperatorCommand extends Command
{
    protected $signature = 'admin:operator
        {email : The operator\'s email address}
        {--name= : Display name (defaults to the part of the email before @)}
        {--locale= : Admin language, he or en}
        {--password-env= : Name of an environment variable holding the password}';

    protected $description = 'Create a system operator, or promote an existing user to operator';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $locale = $this->option('locale');

        $validator = Validator::make(
            ['email' => $email, 'locale' => $locale],
            ['email' => ['required', 'email'], 'locale' => ['nullable', 'in:'.implode(',', config('upsell.locales'))]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            if (! $user->is_operator) {
                $user->forceFill(['is_operator' => true])->save();
                $this->components->info("{$email} is now an operator. Their password is unchanged.");
            } else {
                $this->components->info("{$email} is already an operator. Nothing changed.");
            }

            return self::SUCCESS;
        }

        $generated = null;

        if (filled($variable = $this->option('password-env'))) {
            // getenv, not env(): with the config cached, env() returns null outside config files.
            $secret = (string) getenv((string) $variable);

            if (mb_strlen($secret) < User::MIN_PASSWORD_LENGTH) {
                $this->components->error("Environment variable {$variable} must hold a password of at least ".User::MIN_PASSWORD_LENGTH.' characters.');

                return self::FAILURE;
            }
        } elseif ($this->input->isInteractive()) {
            $secret = password(
                label: 'Password (leave empty to generate one)',
                validate: fn (string $v) => $v === '' || mb_strlen($v) >= User::MIN_PASSWORD_LENGTH ? null : 'At least '.User::MIN_PASSWORD_LENGTH.' characters.',
            );
        } else {
            $secret = '';
        }

        if ($secret === '') {
            $secret = $generated = Str::password(24);
        }

        User::query()->create([
            'name' => $this->option('name') ?: Str::before($email, '@'),
            'email' => $email,
            'password' => $secret,
            'is_operator' => true,
            'locale' => $locale,
        ])->forceFill(['email_verified_at' => now()])->save();

        $this->components->info("Operator {$email} created.");

        if ($generated !== null) {
            $this->components->warn('Generated password, shown once:');
            $this->line($generated);
        }

        return self::SUCCESS;
    }
}
