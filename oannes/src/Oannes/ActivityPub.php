<?php

namespace Oannes;

final class ActivityPub
{
    public const PUBLIC_AUDIENCE = 'https://www.w3.org/ns/activitystreams#Public';

    public static function objectId(array $object): ?string
    {
        $id = $object['id'] ?? null;
        return is_string($id) && $id !== '' ? $id : null;
    }

    public static function objectType(array $object): string
    {
        $type = $object['type'] ?? 'Object';
        return is_string($type) ? $type : 'Object';
    }

    public static function inReplyTo(array $object): ?string
    {
        $value = $object['inReplyTo'] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value)) {
            $id = $value['id'] ?? null;
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }

    public static function attributedTo(array $object): ?string
    {
        $value = $object['attributedTo'] ?? $object['actor'] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value)) {
            $id = $value['id'] ?? null;
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }

    public static function published(array $object): string
    {
        $published = $object['published'] ?? $object['updated'] ?? null;
        return is_string($published) ? $published : '';
    }

    public static function aliases(array $object): array
    {
        $aliases = [];

        foreach (['id', 'url', 'atomUri', 'alsoKnownAs'] as $field) {
            $value = $object[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $aliases[] = $value;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item) && $item !== '') {
                        $aliases[] = $item;
                    } elseif (is_array($item) && isset($item['href']) && is_string($item['href'])) {
                        $aliases[] = $item['href'];
                    }
                }
            }
        }

        return array_values(array_unique($aliases));
    }

    public static function isPublicObject(array $object): bool
    {
        return in_array(self::PUBLIC_AUDIENCE, self::audience($object), true);
    }

    public static function audience(array $object): array
    {
        $audience = [];

        foreach (['to', 'cc', 'bto', 'bcc', 'audience'] as $field) {
            self::collectAudienceValue($object[$field] ?? null, $audience);
        }

        return array_values(array_unique($audience));
    }

    private static function collectAudienceValue(mixed $value, array &$audience): void
    {
        if (is_string($value) && $value !== '') {
            $audience[] = $value;
            return;
        }

        if (!is_array($value)) {
            return;
        }

        if (isset($value['id']) && is_string($value['id']) && $value['id'] !== '') {
            $audience[] = $value['id'];
            return;
        }

        foreach ($value as $item) {
            self::collectAudienceValue($item, $audience);
        }
    }

    public static function isActor(array $object): bool
    {
        return in_array(self::objectType($object), ['Person', 'Service', 'Application', 'Group', 'Organization'], true);
    }
}
