<?php

declare(strict_types=1);

namespace Thallo\Collections\Data;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;
use Thallo\Collections\Exceptions\RowValidationException;
use Thallo\Collections\Schema\CollectionDefinition;
use Thallo\Collections\Schema\CollectionField;
use Glueful\Repository\BlobRepository;

/**
 * Validates and coerces row input data against a collection's field schema.
 *
 * Returns a column map with coerced values ready for database insertion/update.
 * Throws RowValidationException carrying per-field errors on any failure.
 *
 * Validation rules per field type:
 *   collections.string              — scalar → string, capped at settings.length (default 255)
 *   collections.text                — scalar → string, no length cap (TEXT column)
 *   collections.json                — array/object → encoded; string must be valid JSON
 *   collections.integer             — "42" → 42; decimal strings rejected; 32-bit range unless bigint
 *   collections.decimal             — plain decimal notation; integer digits must fit precision-scale
 *   collections.boolean             — "true"/"1"/true → true; "false"/"0"/false → false
 *   collections.date                — Y-m-d format only
 *   collections.datetime            — Y-m-d H:i:s format only
 *   collections.email               — string, valid email, ≤ 255 chars (column width)
 *   collections.url                 — string, valid URL, ≤ 2048 chars (column width)
 *   collections.enum                — string in settings['values']
 *   collections.relation            — shape only: string (single) or string[] (multi); target not checked
 *   collections.asset               — blob uuid(s) must resolve to a non-deleted blob
 *   unique constraint               — queried against the collection's table; current row excluded on update
 *
 * Length/range checks mirror the materialized column types so oversized values become
 * per-field 422s here instead of raw driver errors at insert time.
 */
final class RowValidator
{
    /** Maximum elements accepted in a multi relation/asset value. */
    private const MAX_MULTI_REFS = 100;

    public function __construct(
        private readonly Connection $connection,
        private readonly BlobRepository $blobRepository,
    ) {
    }

    /**
     * Validate and coerce input data against the collection's schema.
     *
     * @param array<string, mixed> $input       Key-value field data from the caller.
     * @param bool                 $partial      When true, absent fields are skipped (partial update).
     * @param string|null          $currentUuid  UUID of the row being updated; used to exclude the
     *                                           current row from unique-constraint checks.
     * @return array<string, mixed> Coerced column map, containing only fields present in $input;
     *                              absent nullable fields are omitted (their columns default to NULL).
     * @throws RowValidationException on any per-field validation failure.
     */
    public function validate(
        CollectionDefinition $def,
        array $input,
        bool $partial,
        ?string $currentUuid = null,
    ): array {
        $errors  = [];
        $coerced = [];

        foreach ($def->fields as $field) {
            $isPresent = array_key_exists($field->name, $input);

            if (!$isPresent) {
                if ($partial) {
                    continue;
                }

                $isNullable = isset($field->settings['nullable'])
                    ? (bool) $field->settings['nullable']
                    : true;

                if (!$isNullable) {
                    $errors[$field->name] = sprintf("Field '%s' is required.", $field->name);
                }

                continue;
            }

            $value = $input[$field->name];

            if ($value === null) {
                $isNullable = isset($field->settings['nullable'])
                    ? (bool) $field->settings['nullable']
                    : true;

                if (!$isNullable) {
                    $errors[$field->name] = sprintf("Field '%s' cannot be null.", $field->name);
                    continue;
                }

                $coerced[$field->name] = null;
                continue;
            }

            [$coercedValue, $error] = $this->coerce($def, $field, $value, $currentUuid);

            if ($error !== null) {
                $errors[$field->name] = $error;
            } else {
                $coerced[$field->name] = $coercedValue;
            }
        }

        if ($errors !== []) {
            throw RowValidationException::make($errors);
        }

        return $coerced;
    }

    /**
     * Coerce and validate a single non-null field value.
     *
     * @param mixed $value
     * @return array{0: mixed, 1: string|null}  [coerced value, error message or null]
     */
    private function coerce(
        CollectionDefinition $def,
        CollectionField $field,
        mixed $value,
        ?string $currentUuid,
    ): array {
        $name = $field->name;

        switch ($field->type) {
            case 'collections.string':
            case 'collections.text':
                if (!is_string($value) && !is_int($value) && !is_float($value)) {
                    return [null, sprintf("Field '%s' must be a string.", $name)];
                }
                $coerced = (string) $value;
                if ($field->type === 'collections.string') {
                    $max = isset($field->settings['length']) ? (int) $field->settings['length'] : 255;
                    if (mb_strlen($coerced) > $max) {
                        return [null, sprintf("Field '%s' must be at most %d characters.", $name, $max)];
                    }
                }
                break;

            case 'collections.json':
                if (is_array($value)) {
                    $coerced = (string) json_encode($value, JSON_THROW_ON_ERROR);
                    break;
                }
                if (is_string($value) && json_validate($value)) {
                    $coerced = $value;
                    break;
                }
                return [null, sprintf(
                    "Field '%s' must be a JSON object/array or a valid JSON string.",
                    $name,
                )];

            case 'collections.integer':
                $filtered = filter_var($value, FILTER_VALIDATE_INT);
                if ($filtered === false) {
                    return [null, sprintf("Field '%s' must be an integer.", $name)];
                }
                // The column is a 32-bit INTEGER unless settings.bigint was set at
                // definition time — reject here instead of a driver overflow error.
                if (empty($field->settings['bigint']) && ($filtered > 2147483647 || $filtered < -2147483648)) {
                    return [null, sprintf(
                        "Field '%s' exceeds the 32-bit integer range (define the field with"
                        . " settings.bigint to store larger values).",
                        $name,
                    )];
                }
                $coerced = $filtered;
                break;

            case 'collections.decimal':
                if (!is_numeric($value) || preg_match('/^[+-]?\d+(\.\d+)?$/', (string) $value) !== 1) {
                    return [null, sprintf(
                        "Field '%s' must be a decimal number in plain notation.",
                        $name,
                    )];
                }
                $coerced   = (string) $value;
                $precision = isset($field->settings['precision']) ? (int) $field->settings['precision'] : 10;
                $scale     = isset($field->settings['scale']) ? (int) $field->settings['scale'] : 2;
                $intPart   = ltrim(explode('.', ltrim($coerced, '+-'))[0], '0');
                if (strlen($intPart) > $precision - $scale) {
                    return [null, sprintf(
                        "Field '%s' must fit %d integer digit(s) (precision %d, scale %d).",
                        $name,
                        $precision - $scale,
                        $precision,
                        $scale,
                    )];
                }
                break;

            case 'collections.boolean':
                $coerced = $this->coerceBoolean($value);
                if ($coerced === null) {
                    return [null, sprintf("Field '%s' must be a boolean.", $name)];
                }
                break;

            case 'collections.date':
                $coerced = $this->coerceDate($value);
                if ($coerced === null) {
                    return [null, sprintf("Field '%s' must be a valid date (Y-m-d).", $name)];
                }
                break;

            case 'collections.datetime':
                $coerced = $this->coerceDatetime($value);
                if ($coerced === null) {
                    return [null, sprintf("Field '%s' must be a valid datetime (Y-m-d H:i:s).", $name)];
                }
                break;

            case 'collections.email':
                if (!is_string($value)) {
                    return [null, sprintf("Field '%s' must be a string.", $name)];
                }
                $coerced = $value;
                if (mb_strlen($coerced) > 255 || !Utils::isValidEmail($coerced)) {
                    return [null, sprintf("Field '%s' must be a valid email address.", $name)];
                }
                break;

            case 'collections.url':
                if (!is_string($value)) {
                    return [null, sprintf("Field '%s' must be a string.", $name)];
                }
                $coerced = $value;
                if (mb_strlen($coerced) > 2048) {
                    return [null, sprintf("Field '%s' must be at most 2048 characters.", $name)];
                }
                if (Utils::validateUrl($coerced) === null) {
                    return [null, sprintf("Field '%s' must be a valid URL.", $name)];
                }
                break;

            case 'collections.enum':
                if (!is_string($value)) {
                    return [null, sprintf("Field '%s' must be a string.", $name)];
                }
                $coerced = $value;
                $allowed = (array) ($field->settings['values'] ?? []);
                // A missing/empty values list is a definition bug (create/addField now
                // reject it); fail closed rather than degrade to free text.
                if (!in_array($coerced, $allowed, true)) {
                    return [null, sprintf(
                        "Field '%s' must be one of: %s.",
                        $name,
                        implode(', ', array_filter($allowed, 'is_string')),
                    )];
                }
                break;

            case 'collections.relation':
                $isMulti = !empty($field->settings['multi']);
                if ($isMulti) {
                    $error = $this->validateUuidList($name, $value, 'UUIDs');
                    if ($error !== null) {
                        return [null, $error];
                    }
                    /** @var array<int, string> $value */
                    $coerced = (string) json_encode(array_values($value), JSON_THROW_ON_ERROR);
                } else {
                    if (!is_string($value) || $value === '') {
                        return [null, sprintf("Field '%s' must be a non-empty string UUID.", $name)];
                    }
                    $coerced = $value;
                }
                break;

            case 'collections.asset':
                $isMulti = !empty($field->settings['multi']);
                if ($isMulti) {
                    $error = $this->validateUuidList($name, $value, 'blob UUIDs');
                    if ($error !== null) {
                        return [null, $error];
                    }
                    /** @var array<int, string> $value */
                    foreach ($value as $i => $uuid) {
                        if ($this->blobRepository->findByUuidWithDeleteFilter($uuid, false) === null) {
                            return [null, sprintf(
                                "Field '%s' element %d references a non-existent blob UUID '%s'.",
                                $name,
                                (int) $i,
                                $uuid,
                            )];
                        }
                    }
                    $coerced = (string) json_encode(array_values($value), JSON_THROW_ON_ERROR);
                } else {
                    if (!is_string($value) || $value === '') {
                        return [null, sprintf("Field '%s' must be a non-empty string blob UUID.", $name)];
                    }
                    // The delete-filtering lookup: plain findByUuid() returns soft-deleted
                    // blobs, which would let a row reference a dead asset.
                    if ($this->blobRepository->findByUuidWithDeleteFilter($value, false) === null) {
                        return [null, sprintf(
                            "Field '%s' references a non-existent blob UUID '%s'.",
                            $name,
                            $value,
                        )];
                    }
                    $coerced = $value;
                }
                break;

            default:
                // A registered field type with no coercion rule is a pack misconfiguration,
                // not user input — fail loudly rather than silently storing it as a string.
                throw new \LogicException(sprintf(
                    "RowValidator: no coercion rule for field type '%s' (field '%s').",
                    $field->type,
                    $name,
                ));
        }

        // Unique constraint check (runs only when coercion succeeded).
        if (!empty($field->settings['unique'])) {
            $query = $this->connection
                ->table($def->tableName)
                ->where($name, $coerced);

            if ($currentUuid !== null) {
                $query->where('uuid', '!=', $currentUuid);
            }

            if ($query->count() > 0) {
                return [null, sprintf(
                    "Field '%s' must be unique, but value '%s' already exists.",
                    $name,
                    (string) $coerced,
                )];
            }
        }

        return [$coerced, null];
    }

    /**
     * Shape-check a multi relation/asset value: an array of at most MAX_MULTI_REFS
     * non-empty strings. Returns the error message, or null when valid. The cap bounds
     * the per-element existence checks — without it a single request could trigger tens
     * of thousands of lookups.
     */
    private function validateUuidList(string $name, mixed $value, string $what): ?string
    {
        if (!is_array($value)) {
            return sprintf("Field '%s' must be an array of %s.", $name, $what);
        }
        if (count($value) > self::MAX_MULTI_REFS) {
            return sprintf(
                "Field '%s' must reference at most %d %s.",
                $name,
                self::MAX_MULTI_REFS,
                $what,
            );
        }
        foreach ($value as $i => $uuid) {
            if (!is_string($uuid) || $uuid === '') {
                return sprintf("Field '%s' element %d must be a non-empty string UUID.", $name, (int) $i);
            }
        }

        return null;
    }

    private function coerceBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if ($value === 1) {
                return true;
            }
            if ($value === 0) {
                return false;
            }
            return null;
        }

        if (is_string($value)) {
            if (in_array(strtolower($value), ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array(strtolower($value), ['false', '0', 'no', 'off'], true)) {
                return false;
            }
        }

        return null;
    }

    private function coerceDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);
        if ($date === false) {
            return null;
        }

        // Guard against PHP silently accepting invalid dates (e.g. 2024-02-30).
        if ($date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function coerceDatetime(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
        if ($date === false) {
            return null;
        }

        if ($date->format('Y-m-d H:i:s') !== $value) {
            return null;
        }

        return $date->format('Y-m-d H:i:s');
    }
}
