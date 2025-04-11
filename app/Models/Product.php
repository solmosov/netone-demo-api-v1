<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $fillable = [
        'price_amount_type_id',
        'name',
        'cost_price',
        'sale_price',
        'receive_price'
    ];


    public function priceAmountType(): BelongsTo
    {
        return $this->belongsTo(AmountType::class, 'price_amount_type_id');
    }

    public function stock(): HasOne
    {
        return $this->hasOne(ProductStock::class, 'product_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class, 'product_id');
    }
}
