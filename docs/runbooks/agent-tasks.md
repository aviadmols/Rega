# Running agent tasks

How product knowledge gets built for a shop, from the store feed to superlatives. Every step is
recorded in Agent activity.

## Order

1. **Sync the catalog.** Operator panel, Catalog, "Sync catalog". Runs on the worker. Or
   `php artisan catalog:sync <shop-slug>`.
2. **Add a vocabulary** for a branch of the catalog. Vocabularies, "Add vocabulary", from a
   template (`power-tools`, `wood`, `care-and-cleaning`, `fasteners`) or a JSON file. A
   vocabulary lists product types (optionally the store categories each stands for), specs with
   units and ranges (optionally read from the title with a pattern), fixed choices, tags and jobs
   ("good for"), each with the patterns code matches first.
3. **Read products in code.** Vocabularies, "Read products in code". No model and no cost: brand,
   size families, a type the category stands for, sizes in titles, what a shopper must choose, the
   price unit. What code settles is saved as approved facts with origin "code" and sent to the
   model as `known`, so the model answers only the rest. Run it again after every catalog sync;
   unchanged products are skipped.
4. **Read products.** Agent tasks, "Create task file", task "Read products". Download the file.
5. **Run the file with a model.** Suggested: `claude-haiku-4-5`. The first line of the file holds
   the instructions (`system`). Every other line is one request. Produce one line per request:
   `{"custom_id": "...", "output": {...}}`.
6. **Upload the answers** on the task. Answers can come in several files. Code checks every answer
   before anything is saved.
7. **Check facts, tier 1.** Create a "Check facts" file, tier 1, for products and again for
   articles. Suggested model: `claude-haiku-4-5`.
8. **Check facts, tier 2.** Same, tier 2. Only what tier 1 was unsure about. Suggested model:
   `claude-sonnet-5`. What tier 2 is unsure about waits for a person in Product facts.
9. **Read articles** any time after the sync. Then check their facts as in steps 6 and 7.
10. **Compute superlatives.** Superlatives, "Compute superlatives".
11. **Match products to articles.** Products for articles, "Match products to articles". Linked products first, then in-stock products from the categories a checker approved. Store pages get none.
12. **Compute relations.** Product relations, "Save matching rules" (template `hardware-store` or
    a JSON file), then "Compute relations": the merchant's cross-sells, merchant links pointing to
    the product, the rules (a battery of the same brand and voltage for a body-only tool, oil for
    wood meant for outdoor jobs), other sizes of the same product, and alternatives of the same type
    at a similar price. Every relation keeps its reasons. Rules can also name store categories
    (`categories`), for branches without a vocabulary yet, such as shelf supports. A product
    several rules find shows the label of the first one, so specific rules (wall fixings for a
    shelf) go before general ones (fasteners for wood builds). The widget shows one product from
    each rule in turn.
13. **Write highlights.** Agent tasks, "Create task file", task "Product highlights", per
    vocabulary, after the reviews above. Suggested model: `claude-sonnet-5` (shoppers read these
    words). Up to four points per product from its own text, each resting on a quote; code rejects
    quotes not in the text and numbers, superlatives or prices the quote does not have. Then check
    them (step 7): the checker reads the same longer text. A point whose quote the store repeats on
    many products (`widget.common_highlight_products`) is shown last and never becomes the quote.
14. **Scores from behavior.** Nothing to run by hand: `analytics:scores` runs every night at 04:15
    (Asia/Jerusalem) for every connected shop, or `php artisan analytics:scores <shop-slug>`. It
    scores each widget section across the shop, on each page, and each product or article inside
    a section: opens + 2 clicks + 4 adds to cart + 8 purchases. A page's score leans on the
    shop-wide rate until the page has data (`analytics.score_prior_exposures`). The widget orders
    sections by score. A section with no score yet gets the best known score, so it gets seen.
    Inside a section, clicked items come first. After `analytics.drop_related_after_opens` openings
    on a page, products shown there and never clicked are dropped, and spare products take their
    place. Preview visits do not count. The run's output lists sections from best to worst.

## The log

Scan and check log shows one product's whole story: the code reading, every fact with origin,
status, reason and checker verdict, every request sent to a model with the answer and the
problems code found in it, and the relations built. Its quality section counts the most common
answer problems across the shop, which is where to look before changing a vocabulary or a prompt.

The same steps from the command line:

```sh
php artisan enrichment vocabulary <shop> --template=wood --author="..."
php artisan enrichment code <shop>
php artisan enrichment tasks <shop> product_extraction --out=tasks.jsonl
php artisan enrichment results <batch-id> answers.jsonl --model=claude-haiku-4-5
php artisan enrichment tasks <shop> fact_review --tier=1 --subject=product --out=review.jsonl
php artisan enrichment rankings <shop>
php artisan enrichment rules <shop> --template=hardware-store
php artisan enrichment relations <shop>
```

## What code does before a model sees anything

- Removes "click here" lines, repeated lines, and paragraphs pasted into many products
  (brand boilerplate, found per batch).
- Finds every number with a unit, in Hebrew and English spellings, converted to one unit per
  dimension. `"½7` is 190.5 mm, `2X18V` is offered as 36 V and as 18 V.
- Finds the wording of choices and tags from the vocabulary patterns, and product type hints in
  the title.

The model answers with IDs from those lists. It never types a number or a quote for a spec.

## What gets approved without review

A claim is approved when code and the model agree and nothing else could have been meant:

- the product type is the single type hint code found in the title;
- a measurement whose unit can mean only one spec for that product type, with one value in the
  text, and not in a sentence about something optional ("ניתן לרכוש בנפרד");
- a choice or yes/no spec whose wording code found, when the model accepts one value.

Everything else goes to review: tags always, measurements that could be several specs, types that
differ from the hints, anything the model added on its own. With `enrichment.auto_approve` off for
a shop, nothing is approved without a person.

## Reusing answers

A request ID is `<task>-<subject>-<hash of what was asked>`. The same product text, vocabulary
and prompt give the same ID in any environment. Answers made for one batch can be uploaded to
another batch with the same requests; only matching IDs are read.

## Changing a prompt

Prompts live in `apps/api/app/Modules/Enrichment/Prompts/<task>.v<version>.md`. Never edit a
released version. Add `.v2.md`, raise the version in `PromptLibrary::CURRENT`, and run the pilot
branch again. Every batch stores the exact instructions it sent.
