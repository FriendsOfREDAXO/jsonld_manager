<?php

namespace FriendsOfRedaxo\JsonLdManager;

use JsonException;
use RuntimeException;

class CustomJsonLdHelper
{
    private const MAX_RAW_LENGTH = 30000;
    private const MAX_DEPTH = 20;

    /**
     * @return array{raw:string,data:array<string, mixed>,errors:array<int, string>,warnings:array<int, string>}
     */
    public static function parseCustomObject(string $rawJson): array
    {
        $rawJson = trim($rawJson);
        if ($rawJson === '') {
            return [
                'raw' => '',
                'data' => [],
                'errors' => [],
                'warnings' => [],
            ];
        }

        if (strlen($rawJson) > self::MAX_RAW_LENGTH) {
            return [
                'raw' => $rawJson,
                'data' => [],
                'errors' => ['Der Custom-JSON-Text ist zu lang (max. ' . self::MAX_RAW_LENGTH . ' Zeichen).'],
                'warnings' => [],
            ];
        }

        try {
            $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [
                'raw' => $rawJson,
                'data' => [],
                'errors' => ['Ungueltiges JSON: ' . $e->getMessage()],
                'warnings' => [],
            ];
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            return [
                'raw' => $rawJson,
                'data' => [],
                'errors' => ['Custom-Angaben muessen ein JSON-Objekt sein (z. B. {"key": "value"}).'],
                'warnings' => [],
            ];
        }

        $warnings = [];
        try {
            $sanitized = self::sanitizeObject($decoded, 0, $warnings);
        } catch (RuntimeException $e) {
            return [
                'raw' => $rawJson,
                'data' => [],
                'errors' => [$e->getMessage()],
                'warnings' => [],
            ];
        }

        return [
            'raw' => $rawJson,
            'data' => $sanitized,
            'errors' => [],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $customData
     * @param array<int,string> $protectedKeys
     * @return array<string,mixed>
     */
    public static function mergeIntoSchema(array $schema, array $customData, array $protectedKeys = ['@context', '@type', '@id']): array
    {
        foreach ($customData as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            if (in_array($key, $protectedKeys, true)) {
                continue;
            }

            if (
                isset($schema[$key])
                && is_array($schema[$key])
                && is_array($value)
                && !array_is_list($schema[$key])
                && !array_is_list($value)
            ) {
                $schema[$key] = self::mergeIntoSchema($schema[$key], $value, $protectedKeys);
                continue;
            }

            $schema[$key] = $value;
        }

        return $schema;
    }

    /**
     * @param array<string,mixed> $object
     * @param array<int,string> $warnings
     * @return array<string,mixed>
     */
    private static function sanitizeObject(array $object, int $depth, array &$warnings): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException('Custom-JSON ist zu tief verschachtelt.');
        }

        $clean = [];
        foreach ($object as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            if ($key === '@context' || $key === '@type' || $key === '@id') {
                $warnings[] = 'Der Schluessel "' . $key . '" wird ignoriert und nicht ueberschrieben.';
                continue;
            }

            $clean[$key] = self::sanitizeValue($value, $depth + 1, $warnings);
        }

        return $clean;
    }

    /**
     * @param mixed $value
     * @param array<int,string> $warnings
     * @return array<string, mixed>|array<int, mixed>|string|int|float|bool|null
     */
    private static function sanitizeValue($value, int $depth, array &$warnings): array|string|int|float|bool|null
    {
        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException('Custom-JSON ist zu tief verschachtelt.');
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $list = [];
                foreach ($value as $item) {
                    $list[] = self::sanitizeValue($item, $depth + 1, $warnings);
                }

                return $list;
            }

            return self::sanitizeObject($value, $depth + 1, $warnings);
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        // Sollte mit json_decode(..., true) eigentlich nie auftreten, bleibt als Sicherheitsnetz.
        return (string) $value;
    }
}
