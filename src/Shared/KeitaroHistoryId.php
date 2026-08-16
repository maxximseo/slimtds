<?php

declare(strict_types=1);

namespace App\Shared;

use Ramsey\Uuid\Uuid;

final class KeitaroHistoryId
{
    private const UUID_NAMESPACE = '35b46d77-9bf0-4a86-97f8-d636139fca64';

    public static function forName(string $name): string
    {
        return Uuid::uuid5(self::UUID_NAMESPACE, 'keitaro-history:' . $name)->toString();
    }

    public static function click(string $subid): string
    {
        return self::forName('click:' . $subid);
    }
}
