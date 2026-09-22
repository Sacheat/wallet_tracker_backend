<?php

namespace App\Services\NewsProviders;

use App\Models\Asset;

interface NewsProviderInterface
{
    /**
     * published_at is a plain ISO 8601 string, not a Carbon instance: a
     * Carbon object stored in the database cache driver fails to
     * unserialize correctly on the next cache hit (confirmed live —
     * __PHP_Incomplete_Class on read-back, valid on the write that created
     * it), so it's kept as a string end to end. String comparison already
     * sorts these chronologically since the format is fixed-width.
     *
     * @return array<int, array{title: string, url: string, source: string, published_at: string}>
     */
    public function getNews(Asset $asset): array;
}
