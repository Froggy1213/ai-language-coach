<?php

namespace App\Models;

use App\Enums\LessonCardStatus;
use Database\Factories\LessonCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['roadmap_id', 'grammar_point_id', 'order_index', 'status', 'cheat_sheet', 'practice_prompt'])]
class LessonCard extends Model
{
    /** @use HasFactory<LessonCardFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LessonCardStatus::class,
            'cheat_sheet' => 'array',
            'order_index' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Roadmap, $this>
     */
    public function roadmap(): BelongsTo
    {
        return $this->belongsTo(Roadmap::class);
    }

    /**
     * @return BelongsTo<GrammarPoint, $this>
     */
    public function grammarPoint(): BelongsTo
    {
        return $this->belongsTo(GrammarPoint::class);
    }

    /**
     * @return HasMany<VoiceSession, $this>
     */
    public function voiceSessions(): HasMany
    {
        return $this->hasMany(VoiceSession::class);
    }
}
