<?php

declare(strict_types=1);

namespace App\World\Model;

enum Gender: int
{
    case Male = 0;
    case Female = 1;

    public function label(): string
    {
        return strtolower($this->name);
    }
}
