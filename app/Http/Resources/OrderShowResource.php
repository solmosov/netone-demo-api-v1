<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class OrderShowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "user" => $this->user,
            'ord_code' => $this->ord_code,
            "customer" => CustomerResource::make($this->customer),
            "order_details" => OrderDetailResource::collection($this->orderDetails),
            "total_sale_price" => (float) $this->total_sale_price,
            "total_cost_price" => Auth::user()->isAdmin() ? (float) $this->total_cost_price : 0,
            "completed_data" => OrderCompletedResource::make($this->completedOrder),
            "status" => $this->status,
            "updated_at" => $this->updated_at,
            "created_at" => $this->created_at,
        ];
    }
}
