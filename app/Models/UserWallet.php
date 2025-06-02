<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class UserWallet extends Model
{
    protected $table = 'user_wallet';

    protected $fillable = ['user_id', 'wallet_id', 'amount'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->with('roles');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'paymentable');
    }
}
