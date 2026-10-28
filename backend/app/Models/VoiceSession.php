<?php

namespace App\Models;

use App\Enums\VoiceSessionStatus;
use Database\Factories\VoiceSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['user_id', 'lesson_card_id', 'room_name', 'status', 'fail_reason', 'duration_sec', 'transcript'])]
class VoiceSession extends Model
{
    /** @use HasFactory<VoiceSessionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VoiceSessionStatus::class,
            'duration_sec' => 'integer',
            'transcript' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<LessonCard, $this>
     */
    public function lessonCard(): BelongsTo
    {
        return $this->belongsTo(LessonCard::class);
    }

    /**
     * @return HasMany<Mistake, $this>
     */
    public function mistakes(): HasMany
    {
        return $this->hasMany(Mistake::class, 'session_id');
    }

    public function hasAnalysisMarker(): bool
    {
        $transcript = $this->transcript;
        if (! is_array($transcript)) {
            return false;
        }

        foreach ($transcript as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'analysis') {
                return true;
            }
        }

        return false;
    }

    public function getAnalyzedAttribute(): bool
    {
        return $this->status === VoiceSessionStatus::Failed || $this->hasAnalysisMarker();
    }

    /**
     * @return list<string>
     */
    public function learnerUtterances(): array
    {
        $transcript = $this->transcript;
        if (! is_array($transcript)) {
            return [];
        }

        $utterances = [];
        foreach ($transcript as $turn) {
            if (! is_array($turn) || ($turn['type'] ?? null) === 'analysis') {
                continue;
            }

            $text = trim((string) ($turn['transcript'] ?? ''));
            if ($text !== '') {
                $utterances[] = $text;
            }
        }

        return $utterances;
    }

    public function hasLearnerUtterances(): bool
    {
        return $this->learnerUtterances() !== [];
    }

    public function markAnalyzed(int $mistakesCount): void
    {
        $transcript = $this->transcript ?? [];
        if (! is_array($transcript)) {
            $transcript = [];
        }

        $transcript[] = [
            'type' => 'analysis',
            'analyzed_at' => Carbon::now()->toIso8601String(),
            'mistakes_count' => $mistakesCount,
        ];

        $this->transcript = array_values($transcript);
        $this->save();
    }
}
