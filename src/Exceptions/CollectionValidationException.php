<?php

declare(strict_types=1);

namespace Thallo\Collections\Exceptions;

/**
 * Thrown by CollectionManager when input data fails validation.
 *
 * Carries a structured $errors array keyed by field path, e.g.:
 *   ['storage_mode' => 'Only table storage is supported in v1.']
 *   ['name'         => 'Name must match ^[a-z][a-z0-9_]*$.']
 *   ['fields.0.name' => "Field name 'id' conflicts with a system column."]
 */
final class CollectionValidationException extends \InvalidArgumentException
{
    /** @var array<string, string> */
    private readonly array $errors;

    /** @param array<string, string> $errors */
    private function __construct(array $errors)
    {
        parent::__construct('Collection validation failed: ' . implode('; ', $errors));
        $this->errors = $errors;
    }

    /** @param array<string, string> $errors */
    public static function make(array $errors): self
    {
        return new self($errors);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
