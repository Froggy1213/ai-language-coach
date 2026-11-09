<?php

namespace App\Models;

use App\Enums\CefrLevel;
use App\Scenarios\ScenarioContent;
use App\Scenarios\ScenarioRegister;
use Database\Factories\CustomScenarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'user_id',
    'language',
    'code',
    'user_prompt',
    'title',
    'summary',
    'level',
    'register',
    'setting',
    'ai_role',
    'ai_goal',
    'learner_role',
    'learner_goal',
    'complications',
    'opening_line',
    'success_criteria',
    'useful_phrases',
    'confirmed_at',
])]
class CustomScenario extends Model
{
    /** @use HasFactory<CustomScenarioFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => CefrLevel::class,
            'register' => ScenarioRegister::class,
            'complications' => 'array',
            'useful_phrases' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    public static function generateCode(): string
    {
        return 'custom_'.strtolower((string) Str::ulid());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->whereNotNull('confirmed_at');
    }

    public function toScenarioContent(): ScenarioContent
    {
        return ScenarioContent::fromArray(
            language: $this->language,
            code: $this->code,
            data: [
                'title' => $this->title,
                'summary' => $this->summary,
                'level' => $this->level->value,
                'register' => $this->register->value,
                'setting' => $this->setting,
                'ai_role' => $this->ai_role,
                'ai_goal' => $this->ai_goal,
                'learner_role' => $this->learner_role,
                'learner_goal' => $this->learner_goal,
                'complications' => $this->complications,
                'opening_line' => $this->opening_line,
                'success_criteria' => $this->success_criteria,
                'useful_phrases' => $this->useful_phrases,
            ],
            isCustom: true,
            userPrompt: $this->user_prompt,
        );
    }
}
