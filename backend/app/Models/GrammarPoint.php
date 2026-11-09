<?php

namespace App\Models;

use App\Enums\CefrLevel;
use Database\Factories\GrammarPointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['language', 'code', 'title', 'category', 'level'])]
class GrammarPoint extends Model
{
    /** @use HasFactory<GrammarPointFactory> */
    use HasFactory;

    /**
     * Grammar points are seeded reference data and are never updated.
     */
    public const UPDATED_AT = null;

    /**
     * Sentinel code for errors the LLM cannot match to a real grammar point.
     *
     * Exactly one such row must exist per language, so that
     * `mistakes.grammar_point_id` can stay NOT NULL (plan §5).
     */
    public const UNCATEGORIZED_CODE = 'uncategorized';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => CefrLevel::class,
        ];
    }

    /**
     * @return BelongsToMany<self, $this>
     */
    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'grammar_point_prerequisites',
            'grammar_point_id',
            'prerequisite_grammar_point_id',
        );
    }

    /**
     * @return BelongsToMany<self, $this>
     */
    public function unlocks(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'grammar_point_prerequisites',
            'prerequisite_grammar_point_id',
            'grammar_point_id',
        );
    }

    /**
     * @return HasMany<LessonCard, $this>
     */
    public function lessonCards(): HasMany
    {
        return $this->hasMany(LessonCard::class);
    }

    /**
     * @return HasMany<Mistake, $this>
     */
    public function mistakes(): HasMany
    {
        return $this->hasMany(Mistake::class);
    }

    /**
     * @return HasMany<ReviewItem, $this>
     */
    public function reviewItems(): HasMany
    {
        return $this->hasMany(ReviewItem::class);
    }
}
