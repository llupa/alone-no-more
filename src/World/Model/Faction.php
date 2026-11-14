<?php

declare(strict_types=1);

namespace App\World\Model;

enum Faction: string
{
    case Alliance = 'Alliance';
    case Horde = 'Horde';

    public function opposing(): self
    {
        return self::Alliance === $this ? self::Horde : self::Alliance;
    }
}
