<?php

declare(strict_types=1);

namespace Thallo\Collections\Support;

use Glueful\Helpers\Utils;

/**
 * Public row/collection identifiers: an optionally-prefixed nanoid.
 *
 * The random part delegates to the framework's canonical {@see Utils::generateNanoID()} —
 * the same CSPRNG-backed, unbiased generator every Thallo content repository uses for its
 * uuids (`Utils::generateNanoID(12)`). This adds only the optional `{prefix}_` so a
 * collection row can carry a human-readable, URL-safe public id, e.g. "prod_V1StGXR8Z5jdHi6".
 *
 * 16-char nanoid → a prefixed id like "sc_…" stays within the 24-char definition uuid column.
 */
final class PublicId
{
    private const SIZE = 16;

    public static function generate(string $prefix = ''): string
    {
        $id = Utils::generateNanoID(self::SIZE);

        return $prefix === '' ? $id : $prefix . '_' . $id;
    }
}
