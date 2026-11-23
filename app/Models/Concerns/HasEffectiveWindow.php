<?php

namespace App\Models\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

trait HasEffectiveWindow
{
    public function scopeEffective(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where('status', 'active')->where('starts_at', '<=', $at)
            ->where(fn (Builder $window) => $window->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }
}
