<?php

namespace Oannes;

final class Id
{
    public static function digest(string $id): string
    {
        return hash('sha256', $id);
    }

    public static function objectPath(string $dataDir, string $id): string
    {
        $digest = self::digest($id);
        return $dataDir . '/objects/' . substr($digest, 0, 2) . '/' . $digest . '.json';
    }

    public static function actorPath(string $dataDir, string $id): string
    {
        $digest = self::digest($id);
        return $dataDir . '/actors/' . substr($digest, 0, 2) . '/' . $digest . '.json';
    }
}

