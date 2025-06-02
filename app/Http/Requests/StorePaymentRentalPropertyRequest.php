<?php

namespace App\Http\Requests;

use App\Models\RentalPropertyCategory;
use App\Models\UserWallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePaymentRentalPropertyRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }


    public function rules(): array
    {
        return [
            'rental_property_id' => 'required|numeric|exists:rental_properties,id',
            'rental_property_category_id' => 'required|numeric|exists:rental_property_categories,id',
            'user_wallet_id' => 'required|exists:user_wallet,id',
            'amount' => 'required|numeric|min:0.01',
            'rate_amount' => 'required|numeric|min:0.01',
            'comment' => 'nullable|string'
        ];
    }

    public function withValidator(Validator $validator)
    {
        $validator->after(function ($validator) {
            $rentalPropertyCategory = $this->input('rental_property_category_id');
            $userWalletId = $this->input('user_wallet_id');
            $amount = $this->input('amount');

            $category = RentalPropertyCategory::findOrFail($rentalPropertyCategory);
            if (!$category->is_income) {
                $userWallet = UserWallet::findOrFail($userWalletId);
                if ($userWallet->amount < $amount) {
                    $validator->errors()->add('amount', "Bu hisobingizda mablag' yetarli emas");
                }
            }
        });
    }
}
