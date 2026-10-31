<?php

namespace App\Support;

use App\Infrastructure\Mongo\Codec;
use MongoDB\BSON\UTCDateTime;

class ProductContentStore
{
    public function __construct(private readonly CatalogStore $store) {}

    public function find(string $productId): ?array
    {
        $document = $this->store->find('product_contents', $productId);
        return $document === null ? null : [
            'product_id' => $productId,
            'usage_instructions' => $document['usage_instructions'],
            'additional_info' => $document['additional_info'],
            'images' => array_values($document['images']),
            'updated_at' => $document['updated_at']->toDateTime()->format('c'),
        ];
    }

    public function save(string $productId, array $content): array
    {
        Codec::id($productId);
        return $this->store->transaction(function () use ($productId, $content) {
            $unit = $this->store->identity->unitOfWork();
            $document = ['_id' => $productId, 'product_id' => $productId,
                'usage_instructions' => $content['usage_instructions'] ?? null,
                'additional_info' => $content['additional_info'] ?? null,
                'images' => array_values($content['images'] ?? []), 'updated_at' => new UTCDateTime($unit->clock)];
            $this->store->collection('product_contents')->replaceOne(['_id' => $productId], $document, $unit->options() + ['upsert' => true]);
            $this->store->deferReferences('product_contents', $productId, $unit);
            return $this->find($productId);
        });
    }
}
