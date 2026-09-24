<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\CefrLevel;
use App\Enums\RoadmapStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'target_language', 'current_level', 'timezone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Mirror of the column defaults, so a freshly created user is complete
     * before it is reloaded from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'current_level' => 'A1',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'current_level' => CefrLevel::class,
        ];
    }

    /**
     * The roadmap the learner is working through now.
     *
     * Regeneration archives the previous roadmap instead of deleting it, so the
     * relation has to name the active one — otherwise `User.roadmap` could
     * return the plan the user has already moved on from.
     *
     * @return HasOne<Roadmap, $this>
     */
    public function roadmap(): HasOne
    {
        return $this->hasOne(Roadmap::class)
            ->where('status', RoadmapStatus::Active)
            ->latest('id');
    }

    /**
     * @return HasMany<Assessment, $this>
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    /**
     * @return HasMany<VoiceSession, $this>
     */
    public function voiceSessions(): HasMany
    {
        return $this->hasMany(VoiceSession::class);
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
