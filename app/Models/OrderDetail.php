<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDetail extends Model
{
    //

    protected $fillable = [
        'order_id',
        'product_id',
        'amount_type_id',
        'stock_id',
        'completed_amount',
        'amount',
        'sale_price',
        'cost_price',
        'sum_sale_price',
        'sum_cost_price',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function amountType(): BelongsTo
    {
        return $this->belongsTo(AmountType::class, 'amount_type_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(ProductStock::class, 'stock_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }
}
