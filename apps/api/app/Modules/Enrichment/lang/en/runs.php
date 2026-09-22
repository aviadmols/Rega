<?php

return [
    'task_file_created' => ':task: :count requests ready, :chars characters in total.',
    'nothing_to_do' => ':task: nothing new to read. Everything in scope was already read with this prompt and text.',
    'no_vocabulary' => 'This task needs a vocabulary for the shop. Save one in Vocabularies first.',
    'results_imported' => ':answers answers from :model: :facts facts saved, :problems problems, :pending requests still without an answer.',
    'no_answers' => 'The file had no answers that could be read.',
    'other_batch' => 'None of the answers in the file match a request in this batch. It was made for different products, text or prompt.',
    'rankings_computed' => ':rankings positions for :products products, in :sets comparable sets (at least :min products each).',
    'vocabulary_saved' => 'Vocabulary ":name" version :version saved: :types product types, :attributes specs and choices, :tags tags.',
    'article_products_matched' => ':links products chosen for :articles articles. :skipped articles got none: store pages, no value to shoppers, or not checked yet.',
    'vocabulary_invalid' => 'The vocabulary was not saved: :count problems.',
    'read_in_code' => 'Code read :products products (:changed changed): brand for :brands, :families size families, :types types from categories, :specs sizes from titles.',
    'read_promises' => 'Code read :pages pages: :shop_promises store promises and :product_promises points from product text.',
    'relations_computed' => ':relations relations: :complements complements for :products products, :families other sizes, :alternatives alternatives.',
    'relation_rules_saved' => 'Matching rules saved, version :version: :rules rules.',
    'relation_rules_invalid' => 'The matching rules were not saved: :count problems.',
];
