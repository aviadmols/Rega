<?php

namespace Tests\Architecture;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Tests\TestCase;

/**
 * Every scheduled command has to be a command this application would actually accept.
 *
 * A schedule fails in the quietest way there is. Nothing watches its output, nothing records a
 * run, and a command that dies on its first line looks exactly like a command that had nothing to
 * do. The catalogue of the pilot shop went six days without a sync that way: the schedule passed
 * a boolean flag as ['--scheduled' => true], Laravel wrote it out as --scheduled="1", and Symfony
 * refuses a value for a flag that takes none.
 *
 * So every entry is parsed here against the real definition of the command it names.
 */
final class ScheduleTest extends TestCase
{
    public function test_every_scheduled_command_can_be_parsed_by_the_command_it_calls(): void
    {
        $artisan = app(Kernel::class);
        $artisan->bootstrap();
        $commands = $artisan->all();
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty($events, 'the application schedules something');

        foreach ($events as $event) {
            $line = trim(Str::after($event->command ?? '', 'artisan'));
            $line = trim(str_replace(["'", '"'], '', $line));

            if ($line === '') {
                continue;
            }

            $parts = str_getcsv($line, ' ', '"', '\\');
            $name = (string) ($parts[0] ?? '');

            $this->assertArrayHasKey($name, $commands, "scheduled command {$name} does not exist");

            $definition = $commands[$name]->getDefinition();

            try {
                // Exactly what the scheduler hands the console, against exactly what the command
                // declares it takes.
                (new ArgvInput(array_merge(['artisan'], array_slice($parts, 1)), new InputDefinition(
                    array_merge(array_values($definition->getArguments()), array_values($definition->getOptions())),
                )))->validate();
            } catch (\Throwable $e) {
                $this->fail("scheduled command \"{$line}\" is not one this application accepts: {$e->getMessage()}");
            }
        }
    }
}
