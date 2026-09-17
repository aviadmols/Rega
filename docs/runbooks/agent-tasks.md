# Running agent tasks

How product knowledge gets built for a shop, from the store feed to superlatives. Every step is
recorded in Agent activity.

## Order

1. **Sync the catalog.** Operator panel, Catalog, "Sync catalog". Runs on the worker. Or
   `php artisan catalog:sync <shop-slug>`.
2. **Add a vocabulary** for a branch of the catalog. Vocabularies, "Add vocabulary", from the
   `power-tools` template or a JSON file. A vocabulary lists product types, specs with units and
   ranges, fixed choices and tags, each with the patterns code matches first.
3. **Read products.** Agent tasks, "Create task file", task "Read products". Download the file.
4. **Run the file with a model.** Suggested: `claude-haiku-4-5`. The first line of the file holds
   the instructions (`system`). Every other line is one request. Produce one line per request:
   `{"custom_id": "...", "output": {...}}`.
5. **Upload the answers** on the task. Answers can come in several files. Code checks every answer
   before anything is saved.
6. **Check facts, tier 1.** Create a "Check facts" file, tier 1, for products and again for
   articles. Suggested model: `claude-haiku-4-5`.
7. **Check facts, tier 2.** Same, tier 2. Only what tier 1 was unsure about. Suggested model:
   `claude-sonnet-5`. What tier 2 is unsure about waits for a person in Product facts.
8. **Read articles** any time after the sync. Then check their facts as in steps 6 and 7.
9. **Compute superlatives.** Superlatives, "Compute superlatives".

The same steps from the command line:

```sh
php artisan enrichment vocabulary <shop> --template=power-tools --author="..."
php artisan enrichment tasks <shop> product_extraction --out=tasks.jsonl
php artisan enrichment results <batch-id> answers.jsonl --model=claude-haiku-4-5
php artisan enrichment tasks <shop> fact_review --tier=1 --subject=product --out=review.jsonl
php artisan enrichment rankings <shop>
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
