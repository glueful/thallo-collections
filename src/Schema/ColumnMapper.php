<?php

declare(strict_types=1);

namespace Thallo\Collections\Schema;

/**
 * Maps a CollectionField to a physical ColumnSpec (spec §4.3).
 *
 * Mapping table:
 *   collections.string      → string([length=255])
 *   collections.text  → text
 *   collections.integer   → bigInteger if bigint else integer
 *   collections.decimal   → decimal([precision, scale])
 *   collections.boolean   → boolean
 *   collections.date      → date
 *   collections.datetime  → timestamp
 *   collections.json      → text  (JSON stored as text for portability v1)
 *   collections.email     → string([255])
 *   collections.url       → string([2048])
 *   collections.enum      → string([255])
 *   collections.relation  → string([36]) single | text multi (JSON UUID array)
 *   collections.asset     → string([36]) single | text multi (JSON UUID array)
 */
final class ColumnMapper
{
    /** @var list<string> */
    private const SUPPORTED = [
        'collections.string',
        'collections.text',
        'collections.integer',
        'collections.decimal',
        'collections.boolean',
        'collections.date',
        'collections.datetime',
        'collections.json',
        'collections.email',
        'collections.url',
        'collections.enum',
        'collections.relation',
        'collections.asset',
    ];

    public function column(CollectionField $field): ColumnSpec
    {
        $s = $field->settings;
        $nullable = isset($s['nullable']) ? (bool) $s['nullable'] : true;
        $unique   = isset($s['unique'])   ? (bool) $s['unique']   : false;
        $index    = isset($s['index'])    ? (bool) $s['index']    : false;
        $name     = $field->name;

        [$type, $params] = match ($field->type) {
            'collections.string'   => ['string', [isset($s['length']) ? (int) $s['length'] : 255]],
            'collections.text'     => ['text', []],
            'collections.integer'  => [!empty($s['bigint']) ? 'bigInteger' : 'integer', []],
            'collections.decimal'  => ['decimal', [
                isset($s['precision']) ? (int) $s['precision'] : 10,
                isset($s['scale'])     ? (int) $s['scale']     : 2,
            ]],
            'collections.boolean'  => ['boolean', []],
            'collections.date'     => ['date', []],
            'collections.datetime' => ['timestamp', []],
            'collections.json'     => ['text', []],
            'collections.email'    => ['string', [255]],
            'collections.url'      => ['string', [2048]],
            'collections.enum'     => ['string', [255]],
            'collections.relation',
            'collections.asset'    => !empty($s['multi']) ? ['text', []] : ['string', [36]],
            default => throw new \InvalidArgumentException(sprintf(
                "Unsupported field type '%s'. Supported types: %s",
                $field->type,
                implode(', ', self::SUPPORTED),
            )),
        };

        return new ColumnSpec($name, $type, $params, $nullable, $unique, $index);
    }

    /** @return list<string> */
    public function supportedTypes(): array
    {
        return self::SUPPORTED;
    }
}
