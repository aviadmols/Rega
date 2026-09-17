<?php

return [
    'products' => [
        'singular' => 'Product',
        'plural' => 'Catalog',
    ],
    'content' => [
        'singular' => 'Article',
        'plural' => 'Articles and guides',
    ],
    'sections' => [
        'product' => 'Product',
        'text' => 'Text from the store',
        'specs' => 'Specs and links',
        'content' => 'Article',
    ],
    'fields' => [
        'image' => 'Image',
        'title' => 'Title',
        'shop' => 'Shop',
        'price' => 'Price',
        'in_stock' => 'In stock',
        'variations' => 'Variations',
        'updated_in_store' => 'Updated in store',
        'synced_at' => 'Read by Rega',
        'removed_at' => 'No longer published since',
        'removed' => 'No longer published',
        'category' => 'Category',
        'categories' => 'Categories',
        'external_id' => 'ID in store',
        'sku' => 'SKU',
        'brand' => 'Brand',
        'short_description' => 'Short description',
        'description' => 'Description',
        'store_attributes' => 'Store attributes',
        'spec_fields' => 'Spec fields',
        'relations' => 'Upsells and cross-sells',
        'type' => 'Type',
        'excerpt' => 'Excerpt',
        'body' => 'Text',
        'url' => 'Address',
    ],
    'values' => [
        'yes' => 'Yes',
        'no' => 'No',
    ],
    'relations' => [
        'upsell' => 'Upsell',
        'cross_sell' => 'Cross-sell',
    ],
    'actions' => [
        'sync' => 'Sync catalog',
        'sync_help' => 'Reads every category, published product and shared article from the store. Runs in the background and takes about a minute per thousand products.',
        'open_in_store' => 'Open in store',
    ],
    'notifications' => [
        'sync_queued' => 'Catalog sync started',
        'sync_queued_body' => 'The result appears in Agent activity when the read ends.',
        'view_runs' => 'Agent activity',
    ],
    'empty' => [
        'heading' => 'No products read yet',
        'description' => 'Connect a store, then press Sync catalog.',
    ],
];
