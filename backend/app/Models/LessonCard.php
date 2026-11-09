<?php

namespace App\Models;

use App\Enums\LessonCardStatus;
use Database\Factories\LessonCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
     * Batch-hydrate `recent_mistake_count` and `needs_practice` onto a collection
     * of lesson cards for `$userId` with a single grouped query (no N+1).
     *
     * @param  Collection<int, self>  $cards
     */
    public static function hydrateMistakeSignals(Collection $cards, int $userId): void
    {
        if ($cards->isEmpty()) {
            return;
        }

        $windowDays = (int) config('review.recurring_window_days', 7);
        $windowStart = Carbon::now()->subDays($windowDays);

        /** @var array<int, int> $countsByPointId */
        $countsByPointId = Mistake::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $windowStart)
            ->selectRaw('grammar_point_id, COUNT(*) as mistake_count')
            ->groupBy('grammar_point_id')
            ->pluck('mistake_count', 'grammar_point_id')
            ->map(static fn ($count): int => (int) $count)
            ->all();

        foreach ($cards as $card) {
            $count = $countsByPointId[$card->grammar_point_id] ?? 0;
            $isUncategorized = $card->relationLoaded('grammarPoint')
                && $card->grammarPoint?->code === GrammarPoint::UNCATEGORIZED_CODE;

            $card->setAttribute('recent_mistake_count', $count);
            $card->setAttribute('needs_practice', $count > 0 && ! $isUncategorized);
        }
    }

    /**
     * Number of mistakes recorded on this card's grammar point in the recent window.
     */
    public function getRecentMistakeCountAttribute(): int
    {
        if (array_key_exists('recent_mistake_count', $this->attributes)) {
            return (int) $this->attributes['recent_mistake_count'];
        }

        $userId = $this->roadmap?->user_id;

        if ($userId === null || $this->grammar_point_id === null) {
            return $this->attributes['recent_mistake_count'] = 0;
        }

        $windowDays = (int) config('review.recurring_window_days', 7);
        $count = Mistake::query()
            ->where('user_id', $userId)
            ->where('grammar_point_id', $this->grammar_point_id)
            ->where('created_at', '>=', Carbon::now()->subDays($windowDays))
            ->count();

        return $this->attributes['recent_mistake_count'] = $count;
    }

    /**
     * Whether recent mistakes indicate this topic needs attention.
     */
    public function getNeedsPracticeAttribute(): bool
    {
        if (array_key_exists('needs_practice', $this->attributes)) {
            return (bool) $this->attributes['needs_practice'];
        }

        $isUncategorized = $this->grammarPoint?->code === GrammarPoint::UNCATEGORIZED_CODE;

        return $this->attributes['needs_practice'] = ($this->recent_mistake_count > 0 && ! $isUncategorized);
    }

    /**
     * Scope the query to lesson cards belonging to the given user's roadmaps.
     *
     * @param  Builder<LessonCard>  $query
     */
    public function scopeForUser(Builder $query, User|int $user): void
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        $query->whereHas('roadmap', static fn (Builder $roadmapQuery): Builder => $roadmapQuery->where('user_id', $userId));
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
