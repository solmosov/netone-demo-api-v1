<?php

namespace App\Http\Requests;

use App\Exceptions\InvalidDataException;
use App\Models\UserWallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class StorePaymentExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expense_id' => 'required|exists:expenses,id',
            'wallet_id' => [
                'required',
                'integer',
                'exists:user_wallet,wallet_id,user_id,' . Auth::id(),
                //                Rule::exists('user_wallet', 'wallet_id')->where('user_id', auth()->id()),
            ],
            'amount' => 'required|numeric|min:0',
            'rate_amount' => 'required|numeric|min:1',
            'comment' => 'nullable|string|min:3|max:255',
        ];
    }

    public function messages(): array
    {
        $userName = Auth::user()->name;

        return [
            'wallet_id.exists' => "Xurmatli $userName foydalanuvchi, sizda mavjud bo'lmagan hisobni tanlayapsiz!"
        ];
    }

    public function withValidator(Validator $validator)
    {
        $validator->after(function ($validator) {
            $authUser = Auth::user();
            $walletId = $this->input('wallet_id');
            $amount  = $this->input('amount');

            // User Wallet
            $userWallet = UserWallet::with(['wallet'])
                ->where('user_id', $authUser->id)
                ->where('wallet_id', $walletId)
                ->firstOrFail();

            if ($userWallet->amount < $amount) {
                throw new InvalidDataException("{$userWallet->wallet->name} -  hisobingizda mablag' yetarli emas! Hisobingizni tekshiring");
            }
        });
    }
}
