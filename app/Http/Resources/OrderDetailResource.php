<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class OrderDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "product" => ProductResource::make($this->product),
            "amount_type" => $this->amountType,
            "amount" => (float)$this->amount,
            "cost_price" => Auth::user()->isAdmin() ? (float)$this->cost_price : 0,
            "sale_price"=> (float) $this->sale_price,
            "sum_cost_price" => Auth::user()->isAdmin() ? (float)$this->sum_cost_price : 0,
            "sum_sale_price"=> (float) $this->sum_sale_price,
            "completed_amount" => (float)$this->completed_amount,
            "updated_at" => $this->updated_at,
            "created_at" => $this->created_at,
        ];
    }
}
