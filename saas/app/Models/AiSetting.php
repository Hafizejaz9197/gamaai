<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * The AI provider chosen in the admin dashboard. The API key is encrypted
 * with APP_KEY and never shown again once saved.
 */
#[Fillable(['provider', 'base_url', 'model', 'api_key', 'updated_by'])]
#[Hidden(['api_key'])]
class AiSetting extends Model
{
    public const ANTHROPIC = 'anthropic';

    public const OPENAI_COMPATIBLE = 'openai_compatible';

    public const PROVIDERS = [
        self::ANTHROPIC => 'Anthropic Claude',
        self::OPENAI_COMPATIBLE => 'OpenAI-compatible API (Gemini, Groq, OpenRouter and others)',
    ];

    /** The settings in use, or null when .env decides. */
    public static function current(): ?self
    {
        return self::query()->latest('id')->first();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
        ];
    }
}
