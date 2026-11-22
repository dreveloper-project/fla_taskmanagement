<?php

namespace App\Models;

use Brick\Math\BigDecimal;

trait HasServiceFee
{
    public function getAgentFeeAttribute(mixed $value): string
    {
        return (string) BigDecimal::of((string) ($value === null || $value === '' ? '0' : $value))->toScale(2);
    }
}
