<?php

declare(strict_types=1);

namespace Thallo\Collections\Schema;

/** Owns all physical identifier derivation for tenant-isolated collection tables. */
final class CollectionPhysicalName
{
    private const TABLE_PATTERN = '/^tc_[a-z2-7]{10}_[a-z0-9]{12}$/';
    private const BASE32 = 'abcdefghijklmnopqrstuvwxyz234567';

    public static function tenantToken(string $tenantUuid): string
    {
        return substr(self::base32(hash('sha256', $tenantUuid, true)), 0, 10);
    }

    public static function generate(string $tenantUuid): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $token = '';
        for ($i = 0; $i < 12; $i++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $name = 'tc_' . self::tenantToken($tenantUuid) . '_' . $token;
        if (strlen($name) > 63) {
            throw new \LogicException('Collection physical name exceeds PostgreSQL\'s 63-byte limit.');
        }

        return $name;
    }

    public static function isValid(string $tableName): bool
    {
        return preg_match(self::TABLE_PATTERN, $tableName) === 1;
    }

    public static function belongsToTenant(string $tableName, string $tenantUuid): bool
    {
        return self::isValid($tableName)
            && str_starts_with($tableName, 'tc_' . self::tenantToken($tenantUuid) . '_');
    }

    public static function indexName(string $tableName, string $fieldName, string $kind): string
    {
        $suffix = substr(hash('sha256', $tableName . '|' . $fieldName . '|' . $kind), 0, 12);
        return substr($tableName, 0, 40) . '_' . $suffix . '_' . ($kind === 'unique' ? 'u' : 'i');
    }

    private static function base32(string $bytes): string
    {
        $buffer = 0;
        $bits = 0;
        $result = '';
        foreach (unpack('C*', $bytes) ?: [] as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $result .= self::BASE32[($buffer >> $bits) & 31];
                $buffer &= (1 << $bits) - 1;
            }
        }
        if ($bits > 0) {
            $result .= self::BASE32[($buffer << (5 - $bits)) & 31];
        }

        return $result;
    }
}
