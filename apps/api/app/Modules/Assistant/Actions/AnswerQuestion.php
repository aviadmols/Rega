<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\Question;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Answers a shopper's question about one product.
 *
 * 1. The same question asked before about this product: the saved answer, no model.
 * 2. Contact details, too short, over the visitor's or the shop's daily questions: refused in code.
 * 3. A small model decides whether the question is about the product. When it is not, a fixed
 *    refusal, and the writing model never sees the question.
 * 4. The writing model answers from the product's approved facts, highlights and text only. When
 *    those do not say, it says so.
 *
 * Both calls ask SpendGuard first and record tokens and cost on one run. Every answer is saved.
 */
final class AnswerQuestion
{
    public const AGENT = 'assistant.answerer';

    public const ACTION = 'assistant.answer';

    public const PROMPT_VERSION = 1;

    private const MIN_CHARS = 3;

    private const SCOPE_OUTPUT_TOKENS = 400;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    /**
     * @return array{outcome: string, answer: string, from: string} outcome: answered, no_info, out_of_scope,
     *                                                              invalid, limit or unavailable; from: bank, model or none
     */
    public function handle(string $shopId, string $productExternalId, string $question, string $visitorHash, string $locale = 'he'): array
    {
        $question = Question::clean($question, (int) Settings::get('assistant.max_question_chars', $shopId));

        if (! Features::enabled('assistant.on_products', $shopId) || mb_strlen(Question::normalize($question)) < self::MIN_CHARS) {
            return $this->fixed('invalid', $locale);
        }

        return $this->tenant->run($shopId, function () use ($shopId, $productExternalId, $question, $visitorHash, $locale): array {
            $product = CatalogProduct::query()->whereNull('removed_at')->where('external_id', $productExternalId)->first();

            if ($product === null) {
                return $this->fixed('invalid', $locale);
            }

            $saved = AssistantAnswer::query()->where('product_id', $product->id)->where('question_key', Question::key($question))->first();

            if ($saved !== null) {
                $saved->forceFill(['asked_count' => $saved->asked_count + 1, 'last_asked_at' => now()])->save();

                return $saved->status === AssistantAnswer::SHOWN && $saved->outcome === AssistantAnswer::ANSWERED
                    ? ['outcome' => $saved->outcome, 'answer' => (string) $saved->answer, 'from' => 'bank']
                    : $this->fixed($saved->outcome === AssistantAnswer::OUT_OF_SCOPE ? 'out_of_scope' : 'no_info', $locale, 'bank');
            }

            if (Question::hasContactDetails($question)) {
                return $this->fixed('out_of_scope', $locale);
            }

            if (! $this->withinDailyLimits($shopId, $visitorHash)) {
                return $this->fixed('limit', $locale);
            }

            return $this->ask($shopId, $product, $question, $locale);
        });
    }

    /** @return array{outcome: string, answer: string, from: string} */
    private function ask(string $shopId, CatalogProduct $product, string $question, string $locale): array
    {
        $result = $this->fixed('unavailable', $locale);

        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: RunTrigger::Webhook,
            input: ['product' => $product->external_id, 'question' => $question],
            work: function (RunContext $run) use ($shopId, $product, $question, $locale, &$result): void {
                $answerModel = (string) Settings::get('assistant.answer_model');
                $scopeModel = (string) Settings::get('assistant.scope_model');
                $maxOutput = (int) Settings::get('assistant.answer_max_output_tokens');
                $effort = (string) Settings::get('assistant.reasoning_effort');
                $effort = $effort === 'model_default' ? null : $effort;

                $context = $this->context($product, (int) Settings::get('assistant.max_text_chars', $shopId));
                $estimatedInput = (int) ceil(mb_strlen((string) json_encode($context, JSON_UNESCAPED_UNICODE)) / 2) + 800;

                try {
                    $this->spend->assertCanSpend(
                        ($estimatedInput * $this->price('answer_input') + $maxOutput * $this->price('answer_output')
                        + 600 * $this->price('scope_input') + self::SCOPE_OUTPUT_TOKENS * $this->price('scope_output')) / 1_000_000,
                    );

                    $scope = $this->models->json(AiProviderName::OpenAi, $scopeModel, self::prompt('scope'), (string) json_encode([
                        'product' => ['title' => $context['title'], 'category' => $context['category']],
                        'question' => $question,
                    ], JSON_UNESCAPED_UNICODE), self::SCOPE_OUTPUT_TOKENS, $effort);
                    $this->record($run, $scopeModel, $scope, 'scope');

                    if (($scope->data['about_product'] ?? null) !== true) {
                        $this->save($product, $question, AssistantAnswer::OUT_OF_SCOPE, null, $scopeModel, $scope, 'scope', $run);
                        $result = $this->fixed('out_of_scope', $locale, 'model');
                        $run->output(['outcome' => AssistantAnswer::OUT_OF_SCOPE])->summary('assistant::runs.out_of_scope');

                        return;
                    }

                    $reply = $this->models->json(AiProviderName::OpenAi, $answerModel, self::prompt('answer'), (string) json_encode([
                        'product' => $context,
                        'question' => $question,
                    ], JSON_UNESCAPED_UNICODE), $maxOutput, $effort);
                    $this->record($run, $answerModel, $reply, 'answer');
                } catch (SpendCapReached $e) {
                    $run->fail('assistant::runs.spend_cap', [], $e->getMessage());

                    return;
                } catch (ModelCallFailed $e) {
                    $run->fail('assistant::runs.model_failed', ['reason' => $e->reason], $e->getMessage());

                    return;
                }

                $answer = trim((string) ($reply->data['answer'] ?? ''));
                $found = ($reply->data['found'] ?? false) === true && $answer !== '' && ! Question::hasContactDetails($answer) && ! self::mentionsPrice($answer);
                $outcome = $found ? AssistantAnswer::ANSWERED : AssistantAnswer::NO_INFO;

                $this->save($product, $question, $outcome, $found ? mb_substr($answer, 0, 1200) : null, $answerModel, $reply, 'answer', $run, $scope ?? null);
                $result = $found ? ['outcome' => $outcome, 'answer' => mb_substr($answer, 0, 1200), 'from' => 'model'] : $this->fixed('no_info', $locale, 'model');
                $run->output(['outcome' => $outcome, 'answer' => $found ? $answer : null])->summary('assistant::runs.'.$outcome);
            },
        );

        return $result;
    }

    /** @return array<string, mixed> what the writer may answer from */
    private function context(CatalogProduct $product, int $maxTextChars): array
    {
        $facts = EnrichmentFact::query()
            ->with('vocabulary')
            ->where('product_id', $product->id)
            ->where('status', FactStatus::Approved)
            ->orderBy('kind')->orderBy('key')
            ->get();

        $lines = [];
        $highlights = [];
        foreach ($facts as $fact) {
            if ($fact->kind === FactKind::Highlight) {
                $highlights[] = $fact->key.': '.$fact->value_text;

                continue;
            }

            $definition = $fact->vocabulary?->definition();
            $lines[] = match ($fact->kind) {
                FactKind::Type => $definition?->label('type', (string) $fact->value_text, 'he') ?? (string) $fact->value_text,
                FactKind::Choice => ($definition?->label('attribute', $fact->key, 'he') ?? $fact->key).': '.($definition?->label('attribute', $fact->key, 'he', (string) $fact->value_text) ?? $fact->value_text),
                FactKind::Spec => ($definition?->label('attribute', $fact->key, 'he') ?? $fact->key).': '.rtrim(rtrim(number_format((float) $fact->value_number, 3, '.', ''), '0'), '.').' '.$fact->unit,
                FactKind::Use => ($definition?->label('use', (string) $fact->value_text, 'he') ?? (string) $fact->value_text),
                default => $fact->key.': '.$fact->value_text,
            };
        }

        $text = trim(implode("\n", array_filter([$product->shortDescription(), $product->description()])));

        return [
            'title' => $product->title,
            'category' => implode(' > ', $product->categoryPaths()[0] ?? []),
            'facts' => array_values(array_unique($lines)),
            'highlights' => $highlights,
            'text' => mb_substr($text, 0, $maxTextChars),
        ];
    }

    private function withinDailyLimits(string $shopId, string $visitorHash): bool
    {
        $visitorKey = "assistant:visitor:{$shopId}:{$visitorHash}";
        $shopKey = "assistant:shop:{$shopId}";

        if (RateLimiter::tooManyAttempts($visitorKey, (int) Settings::get('assistant.questions_per_visitor_per_day', $shopId))
            || RateLimiter::tooManyAttempts($shopKey, (int) Settings::get('assistant.questions_per_shop_per_day', $shopId))) {
            return false;
        }

        RateLimiter::hit($visitorKey, 86400);
        RateLimiter::hit($shopKey, 86400);

        return true;
    }

    private function record(RunContext $run, string $model, ModelReply $reply, string $step): void
    {
        $run->usage('openai', $model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($this->price($step.'_input'), $this->price($step.'_output')));
    }

    private function save(CatalogProduct $product, string $question, string $outcome, ?string $answer, string $model, ModelReply $reply, string $step, RunContext $run, ?ModelReply $scope = null): void
    {
        $cost = $reply->costUsd($this->price($step.'_input'), $this->price($step.'_output'))
            + ($scope?->costUsd($this->price('scope_input'), $this->price('scope_output')) ?? 0.0);

        AssistantAnswer::query()->create([
            'shop_id' => $product->shop_id,
            'product_id' => $product->id,
            'question_key' => Question::key($question),
            'question' => $question,
            'answer' => $answer,
            'outcome' => $outcome,
            'prompt_version' => self::PROMPT_VERSION,
            'model' => $model,
            'input_tokens' => $reply->inputTokens + ($scope->inputTokens ?? 0),
            'output_tokens' => $reply->outputTokens + ($scope->outputTokens ?? 0),
            'cost_usd' => round($cost, 6),
            'run_id' => $run->runId,
            'last_asked_at' => now(),
        ]);
    }

    private function price(string $name): float
    {
        return (float) Settings::get("assistant.{$name}_usd_per_million");
    }

    /** A price in an answer goes stale; the page shows the real one. */
    private static function mentionsPrice(string $answer): bool
    {
        return preg_match('~₪|ש"ח|ש״ח|\d+\s*שקל~u', $answer) === 1;
    }

    private static function prompt(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../Prompts/'.$name.'.v'.self::PROMPT_VERSION.'.md');
    }

    /** @return array{outcome: string, answer: string, from: string} */
    private function fixed(string $outcome, string $locale, string $from = 'none'): array
    {
        return ['outcome' => $outcome, 'answer' => (string) __('assistant::answers.'.$outcome, [], $locale), 'from' => $from];
    }
}
