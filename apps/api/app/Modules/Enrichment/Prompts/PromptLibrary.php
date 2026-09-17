<?php

namespace App\Modules\Enrichment\Prompts;

use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use RuntimeException;

/**
 * The instructions each task sends, kept as versioned Markdown files next to this class.
 *
 * Changing a prompt means adding a new file (".v2.md") and raising the version here, never
 * editing a released one: batches record the version and the exact text they sent.
 */
final class PromptLibrary
{
    /** @var array<string, int> task => current version */
    public const CURRENT = [
        'product_extraction' => 1,
        'fact_review' => 1,
        'content_mapping' => 1,
    ];

    public static function version(TaskType $task): int
    {
        return self::CURRENT[$task->value];
    }

    public static function template(TaskType $task, ?int $version = null): string
    {
        $version ??= self::version($task);
        $path = __DIR__."/{$task->value}.v{$version}.md";

        if (! is_file($path)) {
            throw new RuntimeException("Prompt {$task->value} v{$version} does not exist.");
        }

        return (string) file_get_contents($path);
    }

    /** The instructions with the vocabulary filled in, exactly as a model receives them. */
    public static function render(TaskType $task, ?VocabularyDefinition $vocabulary): string
    {
        $text = self::template($task);

        return trim(str_replace('{{vocabulary}}', $vocabulary === null ? '' : VocabularyText::render($vocabulary), $text))."\n";
    }

    public static function hash(string $renderedPrompt): string
    {
        return hash('sha256', $renderedPrompt);
    }
}
