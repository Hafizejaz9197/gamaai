<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row of the append-only usage ledger. Rows are written by
 * App\Services\Usage\UsageMeter and are never changed or removed.
 */
#[Fillable([
    'account_id', 'site_id', 'request_id', 'kind', 'purpose', 'model', 'tokens',
    'input_tokens', 'output_tokens', 'estimated_cost_micros', 'provider_cost_micros', 'meta',
])]
class UsageEntry extends Model
{
    public const RESERVE = 'reserve';

    public const SETTLE = 'settle';

    public const RELEASE = 'release';

    public const ADJUST = 'adjust';

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Usage entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Usage entries cannot be deleted.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tokens' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_cost_micros' => 'integer',
            'provider_cost_micros' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
