<?php

namespace App\Models;

use Database\Factories\ReviewItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'grammar_point_id', 'ease_factor', 'interval_days', 'repetition_number', 'next_review_at'])]
class ReviewItem extends Model
{
    /** @use HasFactory<ReviewItemFactory> */
    use HasFactory;

    /**
     * Created lazily on the first mistake for a (user, grammar point) pair,
     * so there is no meaningful creation timestamp (plan §5).
     */
    public const CREATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ease_factor' => 'decimal:2',
            'interval_days' => 'integer',
            'repetition_number' => 'integer',
            'next_review_at' => 'datetime',
        ];
    }

    /**
     * Items whose review date has arrived, soonest first.
     *
     * Used by the `dueReviews` query through `@all(scopes: ["due"])`.
     *
     * @param  Builder<ReviewItem>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('next_review_at', '<=', now())->orderBy('next_review_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<GrammarPoint, $this>
     */
    public function grammarPoint(): BelongsTo
    {
        return $this->belongsTo(GrammarPoint::class);
    }
}
