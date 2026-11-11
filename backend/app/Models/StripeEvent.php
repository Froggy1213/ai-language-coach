<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One Stripe webhook event, recorded so a redelivery is a no-op.
 */
#[Fillable(['stripe_event_id', 'type'])]
class StripeEvent extends Model
{
    //
}
