<?php

declare(strict_types=1);

namespace Thallo\Collections\Exceptions;

/**
 * Thrown by RowValidator when row input data fails validation.
 *
 * Carries a structured $errors array keyed by field name, e.g.:
 *   ['title'  => "Field 'title' is required."]
 *   ['status' => "Field 'status' must be one of: draft, published."]
 *   ['cover'  => "Field 'cover' references a non-existent blob UUID 'xyz'."]
 */
final class RowValidationException extends \InvalidArgumentException
{
    /** @var array<string, string> */
    private readonly array $errors;

    /** @param array<string, string> $errors */
    private function __construct(array $errors)
    {
        parent::__construct('Row validation failed: ' . implode('; ', $errors));
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
