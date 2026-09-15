<?php

namespace App\Models;

use App\Enums\VoiceSessionStatus;
use Database\Factories\VoiceSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
