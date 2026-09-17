<?php

namespace App\Modules\Enrichment\Tasks;

use App\Core\Facades\Settings;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Scanning\Boilerplate;
use App\Modules\Enrichment\Scanning\HighlightDigest;
use App\Modules\Enrichment\Scanning\ProductDigest;
use App\Modules\Enrichment\Scanning\TextNormalizer;
use App\Modules\Enrichment\Support\FactWriter;
use App\Modules\Enrichment\Support\VocabularyBranch;
use Illuminate\Support\Collection;

/**
 * What a shopper should know about a product, written from its own text: up to four points, each
 * a short phrase shown in bold and one sentence, each resting on a quote.
 *
 * Code rejects a point whose quote is not in the text, that has a number the quote does not have,
 * a superlative the quote does not make, a price or contact details. What passes waits for the
 * reviewer: shoppers read these words.
 */
final class ProductHighlightsTask implements AgentTask
{
    public const SUBJECT = 'product';

    public const MAX_HIGHLIGHTS = 4;

    private const KEY_CHARS = [2, 40];

    private const TEXT_CHARS = [8, 160];

    private const QUOTE_CHARS = [4, 300];

    /** A product with less text than this beyond its title has nothing to add to its spec list. */
    private const MIN_TEXT_CHARS = 80;

    private const SUPERLATIVES = '~הכי\s+\S+|\S+\s+ביותר|מס(?:פר|\')\s*1|best|cheapest|number\s+one~iu';

    private const PRICE_OR_CONTACT = '~₪|ש"ח|ש״ח|שקל|https?://|www\.|\S+@\S+|\b0\d{1,2}-?\d{7}\b~iu';

    public function __construct(private readonly FactWriter $facts) {}

    public function type(): TaskType
    {
        return TaskType::ProductHighlights;
    }

    public function requests(EnrichmentBatch $batch, int $limit): iterable
    {
        $vocabulary = $batch->vocabulary?->definition();

        if ($vocabulary === null) {
            return;
        }

        $maxChars = (int) Settings::get('enrichment.max_agent_text_chars', $batch->shop_id);
        $includeDone = (bool) ($batch->scope['include_done'] ?? false);
        $count = 0;

        $boilerplate = Boilerplate::find((function () use ($vocabulary) {
            foreach (VocabularyBranch::products($vocabulary)->lazyById(200) as $product) {
                yield ProductDigest::sections($product);
            }
        })());

        $batch->forceFill(['scope' => ($batch->scope ?? []) + ['boilerplate' => $boilerplate]])->save();

        foreach (VocabularyBranch::products($vocabulary)->lazyById(200) as $product) {
            $digest = HighlightDigest::build($product, $maxChars, $batch->prompt_hash, $boilerplate, HighlightDigest::known($this->approvedFacts($product->id), $vocabulary));

            if (mb_strlen((string) $digest->context['text']) - mb_strlen($product->title) < self::MIN_TEXT_CHARS) {
                continue;
            }

            if (! $includeDone && $this->alreadyWritten($product->id, $digest->inputHash)) {
                continue;
            }

            yield new TaskRequest(
                customId: 'hl-'.$product->external_id.'-'.substr($digest->inputHash, 0, 10),
                subjectType: self::SUBJECT,
                subjectId: $product->id,
                inputHash: $digest->inputHash,
                request: $digest->request,
                context: $digest->context,
            );

            if (++$count >= $limit) {
                return;
            }
        }
    }

    public function isStale(EnrichmentBatch $batch, EnrichmentBatchItem $item): bool
    {
        $product = CatalogProduct::query()->find($item->subject_id);

        if ($product === null || $product->removed_at !== null) {
            return true;
        }

        $maxChars = (int) Settings::get('enrichment.max_agent_text_chars', $batch->shop_id);
        $digest = HighlightDigest::build($product, $maxChars, $batch->prompt_hash, (array) ($batch->scope['boilerplate'] ?? []), []);

        return $digest->context['text_hash'] !== ($item->context['text_hash'] ?? null);
    }

    public function apply(EnrichmentBatch $batch, EnrichmentBatchItem $item, array $output, string $model): ItemOutcome
    {
        if ((string) ($output['id'] ?? '') !== (string) $item->request['id']) {
            return ItemOutcome::rejected(['wrong_id']);
        }

        if (! is_array($output['highlights'] ?? null)) {
            return ItemOutcome::rejected(['no_highlights']);
        }

        $text = (string) ($item->context['text'] ?? '');
        $problems = [];
        $accepted = [];

        foreach (array_values($output['highlights']) as $i => $highlight) {
            $n = $i + 1;

            if ($n > self::MAX_HIGHLIGHTS) {
                $problems[] = "too_many:{$n}";

                continue;
            }

            if ($problem = $this->problem(is_array($highlight) ? $highlight : [], $text, array_column($accepted, 'key'))) {
                $problems[] = "{$problem}:{$n}";

                continue;
            }

            $accepted[] = [
                'key' => trim((string) $highlight['key']),
                'text' => trim((string) $highlight['text']),
                'quote' => trim((string) $highlight['quote']),
            ];
        }

        $this->facts->supersedePrevious('product_id', $item->subject_id, [FactKind::Highlight]);

        foreach ($accepted as $position => $highlight) {
            $this->facts->write($batch, $item, 'product_id', $model, [
                'kind' => FactKind::Highlight,
                'key' => mb_substr($highlight['key'], 0, 80),
                // The order the writer chose, most useful first.
                'value_number' => $position + 1,
                'value_text' => mb_substr($highlight['text'], 0, 255),
                'quote' => $highlight['quote'],
                'origin' => FactOrigin::Model,
                'status' => FactStatus::AwaitingReview,
                'status_reason' => 'highlight',
            ]);
        }

        return new ItemOutcome(ItemStatus::Applied, count($accepted), count($problems), $problems);
    }

    /**
     * @param  array<string, mixed>  $highlight
     * @param  list<string>  $keys  already accepted
     */
    private function problem(array $highlight, string $text, array $keys): ?string
    {
        $key = trim((string) ($highlight['key'] ?? ''));
        $sentence = trim((string) ($highlight['text'] ?? ''));
        $quote = trim((string) ($highlight['quote'] ?? ''));
        $written = $key.' '.$sentence;

        return match (true) {
            ! self::within($key, self::KEY_CHARS) || ! self::within($sentence, self::TEXT_CHARS) || ! self::within($quote, self::QUOTE_CHARS) => 'length',
            in_array(TextNormalizer::forMatching($key), array_map(TextNormalizer::forMatching(...), $keys), true) => 'repeated',
            ! TextNormalizer::contains($text, $quote) => 'quote_not_in_text',
            preg_match(self::PRICE_OR_CONTACT, $written) === 1 => 'price_or_contact',
            self::unquoted('~\d+(?:[.,]\d+)?~u', $written, $quote) => 'number_not_in_quote',
            self::unquoted(self::SUPERLATIVES, $written, $quote) => 'superlative_not_in_quote',
            default => null,
        };
    }

    /** @param array{0: int, 1: int} $bounds */
    private static function within(string $value, array $bounds): bool
    {
        $length = mb_strlen($value);

        return $length >= $bounds[0] && $length <= $bounds[1];
    }

    /** Whether the written words say something matching $pattern that the quote does not. */
    private static function unquoted(string $pattern, string $written, string $quote): bool
    {
        preg_match_all($pattern, $written, $matches);

        foreach ($matches[0] as $match) {
            if (! TextNormalizer::contains($quote, $match)) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, EnrichmentFact> */
    private function approvedFacts(string $productId): Collection
    {
        return EnrichmentFact::query()
            ->where('product_id', $productId)
            ->where('status', FactStatus::Approved)
            ->whereIn('kind', [FactKind::Type, FactKind::Choice, FactKind::Spec, FactKind::Flag, FactKind::Tag, FactKind::Use, FactKind::Brand])
            ->get();
    }

    private function alreadyWritten(string $productId, string $inputHash): bool
    {
        return EnrichmentFact::query()->where('product_id', $productId)->where('kind', FactKind::Highlight)->where('input_hash', $inputHash)->where('status', '!=', FactStatus::Superseded)->exists()
            || EnrichmentBatchItem::query()->where('subject_id', $productId)->where('input_hash', $inputHash)->whereIn('status', [ItemStatus::Pending, ItemStatus::Applied])->exists();
    }
}
