<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['name', 'url', 'plugin_version', 'avada_builder_version', 'avada_verified', 'last_seen_at'])]
#[Hidden(['token_hash'])]
class Site extends Model
{
    use HasFactory;

    public const TOKEN_PREFIX = 'gsk_';

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Give the site a new plugin token and return it. Only its hash is
     * stored, so the plain token can be shown once.
     */
    public function issueToken(): string
    {
        $token = self::TOKEN_PREFIX.Str::random(40);
        $this->token_hash = self::hashToken($token);
        $this->token_prefix = substr($token, 0, 10);

        return $token;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        if (! str_starts_with($token, self::TOKEN_PREFIX)) {
            return null;
        }

        return self::where('token_hash', self::hashToken($token))->first();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'avada_verified' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }
}
