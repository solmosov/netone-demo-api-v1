<?php

namespace App\Services\Payment;

use App\Exceptions\InvalidDataException;
use App\Exceptions\ServerErrorException;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\UserWallet;
use App\Services\Status\StatusService;
use App\Services\Utils\DateFormatter;
use Illuminate\Support\Facades\DB;

class PaymentExpenseService
{
    public function findAll(array $data): array
    {
        $startDate = DateFormatter::format($data['startDate']);
        $endDate = DateFormatter::format($data['endDate'], 'end');
        $expenseId = $data['expense_id'] ?? null;

        $query = Payment::with('paymentable', 'user', 'wallets', 'status')
            ->where('paymentable_type', 'App\Models\Expense')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at', 'desc');

        if (!auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        // All data
        $data = $query->get();

        // Totals
        $totals = $this->getTotals($expenseId, [$startDate, $endDate]);

        return [
            'data' => $data,
            'totals' => $totals,
        ];
    }


    /**
     * @throws ServerErrorException
     * @throws InvalidDataException
     */
    public function create(array $data): array
    {
        $expenseId = $data['expense_id'];
        $walletId = $data['wallet_id'];
        $amount = $data['amount'];
        $rateAmount = $data['rate_amount'];
        $comment = $data['comment'] ?? null;

        // Validation User Wallet
        $userWallet = UserWallet::with('wallet.currency')
            ->where('user_id', auth()->id())
            ->where('wallet_id', $walletId)
            ->firstOrFail();

        // Expense
        $expense = Expense::findOrFail($expenseId);

        // Status Payment Expense
        $statusPaymentExpense = StatusService::findByCode('paymentExpense');

        DB::beginTransaction();

        try {
            // New Payment For Expense
            $payment = new Payment([
                'user_id' => auth()->id(),
                'status_id' => $statusPaymentExpense->id,
                'comment' => $comment
            ]);
            $expense->payments()->save($payment);

            // Attach Amount To Wallet
            $payment->wallets()->attach($walletId, [
                'amount' => $amount,
                'rate_amount' => $rateAmount,
                'sum_price' => $amount * $rateAmount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Decrement from User Wallet
            $userWallet->decrement('amount', $amount);

            // Finish
            DB::commit();

            // Amount Currency
            $currency = $userWallet->wallet->currency->symbol;

            $formatNumVal = number_format($amount, 2, '.', ',');

            return [
                'message' => "`$expense->name` xarajat uchun $formatNumVal $currency  muvaffaqiyatli o'tkazildi!",
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ServerErrorException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function findOne(int $expenseId): array
    {
        $query = Payment::with('paymentable', 'user', 'wallets', 'status')
            ->where('paymentable_type', 'App\Models\Expense')
            ->orderBy('created_at', 'desc');

        if (!auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        $data = $query->findOrFail($expenseId);

        return [
            'data' => $data,
        ];
    }

    private function getTotals(string|null $expenseId, array $date): array
    {
        $query = DB::table('payments')
            ->join('payment_wallet', 'payments.id', '=', 'payment_wallet.payment_id')
            ->select(
                DB::raw('SUM(payment_wallet.sum_price) as total_amount'),
                DB::raw('COUNT(payments.id) as total_count')
            )
            ->where('paymentable_type', 'App\Models\Expense');

        if ($expenseId) {
            $query->where('payments.paymentable_id', $expenseId);
        }

        $query->whereBetween('payments.created_at', [$date[0], $date[1]])
            ->first();

        $data = $query->first();

        return [
            "total_amount" => (float)$data->total_amount,
            "total_count" => (int)$data->total_count,
        ];
    }
}
