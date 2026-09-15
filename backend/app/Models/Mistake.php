<?php

namespace App\Models;

use Database\Factories\MistakeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['session_id', 'user_id', 'grammar_point_id', 'user_utterance', 'correction', 'explanation'])]
class Mistake extends Model
{
    /** @use HasFactory<MistakeFactory> */
    use HasFactory;

    /**
     * Mistakes are appended by the async analysis job and never edited.
     */
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<VoiceSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(VoiceSession::class, 'session_id');
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
