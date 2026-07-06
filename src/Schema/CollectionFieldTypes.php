<?php

declare(strict_types=1);

namespace Thallo\Collections\Schema;

use Thallo\Contracts\Schema\FieldTypeDefinition;
use Thallo\Contracts\Schema\FieldTypeRegistry;

/**
 * Registers the full set of collection field types under the "collections.*" namespace.
 *
 * Capability rules (spec §4):
 *   - Scalar types (string, text, integer, decimal, boolean, date, datetime, email, url, enum):
 *       filterable+sortable+indexable=true  (text: filterable+sortable=false, indexable=true)
 *   - JSON/object types (json): filterable=false, sortable=false, indexable=false
 *   - Reference/asset types (relation, asset): filterable=false, sortable=false, multi=true
 *
 * No collections.* key may collide with a content.* key.
 */
final class CollectionFieldTypes
{
    public static function register(FieldTypeRegistry $registry): void
    {
        foreach (self::definitions() as $definition) {
            $registry->register($definition);
        }
    }

    /** @return list<FieldTypeDefinition> */
    private static function definitions(): array
    {
        return [
            // --- Scalar types: filterable, sortable, indexable ---
            self::make('collections.string', 'String', 'scalar', 'text-input', [
                'filterable' => true,
                'sortable'   => true,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => false,
            ]),
            // Long text: not filterable/sortable (no B-tree on unbounded text), but full-text indexable
            self::make('collections.text', 'Text', 'scalar', 'textarea', [
                'filterable' => false,
                'sortable'   => false,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => false,
            ]),
            self::make('collections.integer', 'Integer', 'scalar', 'number-input', [
                'filterable' => true,
                'sortable'   => true,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => false,
            ]),
            self::make('collections.decimal', 'Decimal', 'scalar', 'number-input', [
                'filterable' => true,
                'sortable'   => true,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => false,
            ]),
            self::make('collections.boolean', 'Boolean', 'scalar', 'toggle', [
                'filterable' => true,
                'sortable'   => true,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => false,
            ]),
            self::make('collections.date', 'Date', 'scalar', 'date-input', [
                'filterable' => true,
                'sortable'   => true,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => false,
            ]),
            self::make('collections.datetime', 'Date & Time', 'scalar', 'datetime-input', [
                'filterable' => true,
                'sortable'   => true,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => false,
            ]),
            self::make('collections.email', 'Email', 'scalar', 'text-input', [
                'filterable' => true,
                'sortable'   => true,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => false,
            ]),
            self::make('collections.url', 'URL', 'scalar', 'text-input', [
                'filterable' => true,
                'sortable'   => true,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => false,
            ]),
            self::make('collections.enum', 'Enum (select)', 'scalar', 'select', [
                'filterable' => true,
                'sortable'   => true,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => false,
            ]),
            // --- JSON/structured types: not filterable or sortable ---
            self::make('collections.json', 'JSON', 'json', 'json-editor', [
                'filterable' => false,
                'sortable'   => false,
                'indexable'  => false,
                'multi'      => false,
                'localized'  => false,
            ]),
            // --- Relation/asset types: not filterable/sortable, may be multi ---
            self::make('collections.relation', 'Relation', 'json', 'reference-picker', [
                'filterable' => false,
                'sortable'   => false,
                'indexable'  => false,
                'multi'      => true,
                'localized'  => false,
            ]),
            self::make('collections.asset', 'Asset', 'json', 'asset-picker', [
                'filterable' => false,
                'sortable'   => false,
                'indexable'  => false,
                'multi'      => true,
                'localized'  => false,
            ]),
        ];
    }

    /**
     * @param array{filterable:bool,sortable:bool,indexable:bool,multi:bool,localized:bool} $capabilities
     */
    private static function make(
        string $key,
        string $label,
        string $valueShape,
        string $adminWidget,
        array $capabilities,
    ): FieldTypeDefinition {
        return new class ($key, $label, $valueShape, $adminWidget, $capabilities) implements FieldTypeDefinition {
            /**
             * @param array{filterable:bool,sortable:bool,indexable:bool,multi:bool,localized:bool} $caps
             */
            public function __construct(
                private readonly string $key,
                private readonly string $label,
                private readonly string $valueShape,
                private readonly string $adminWidget,
                private readonly array $caps,
            ) {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return $this->label;
            }

            public function valueShape(): string
            {
                return $this->valueShape;
            }

            public function validationRules(): array
            {
                return [];
            }

            public function adminWidget(): string
            {
                return $this->adminWidget;
            }

            public function capabilities(): array
            {
                return $this->caps;
            }
        };
    }
}
