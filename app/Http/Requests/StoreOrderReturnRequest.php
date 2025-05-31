<?php

namespace App\Http\Requests;

use App\Models\ProductStock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreOrderReturnRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }


    public function rules(): array
    {
        return [
            'customer_id' => 'required|integer|exists:customers,id',
            'product_list' => 'required|array',
            'product_list.*.product_id' => 'required|integer|exists:products,id',
            'product_list.*.polka_id' => 'required|integer|exists:product_stocks,id',
            'product_list.*.amount' => 'required|numeric|min:0.01',
            'product_list.*.amount_type_id' => 'required|integer|exists:amount_types,id',
            'comment' => 'nullable|string'
        ];
    }

    public function withValidator(Validator $validator)
    {
        $validator->after(function ($validator) {
            $productList = $this->input('product_list');

            foreach ($productList as $key => $item) {
                $productId = $item['product_id'];
                $polkaId = $item['polka_id'];
                $amountTypeId = $item['amount_type_id'];

                // Product Stock
                $productStock = ProductStock::where('product_id', $productId)
                    ->findOrFail($polkaId);
                if ($productStock->amount_type_id !== $amountTypeId) {
                    $validator->errors()->add("product_list.{$key}.amount_type_id", "O'lchov birligi noto'g'ri tanlandi");
                }
            }
        });
    }
}
