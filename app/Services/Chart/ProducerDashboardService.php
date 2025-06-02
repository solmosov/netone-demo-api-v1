<?php

namespace App\Services\Chart;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProducerDashboardService
{

    private $weeklyInterval =  [];
    protected $authUser;
    public function __construct()
    {
        $this->authUser = Auth::user();
        $this->weeklyInterval = $this->getInterval();
    }

    public function get()
    {
        $weeklySales = $this->getWeeklySales('total_sale_price');
        $weeklyExpense = $this->getWeeklyExpense();
        $weeklyProfit = $this->getWeeklyProfit();

        return [
            "weekly" => [
                "weekly_sales" => $weeklySales,
                "weekly_cost" => $weeklySales,
                "weekly_expense" => $weeklyExpense,
                "weekly_profit" => $weeklyProfit,
                "weekly_days" => $this->getWeekDays()
            ],
        ];
    }

    private function getWeeklySales(string $column)
    {
        $data = DB::table('completed_orders')
            ->selectRaw("DATE(updated_at) as date, SUM({$column}) as amount")
            ->where('user_id', $this->authUser->id)
            ->whereBetween('updated_at', $this->weeklyInterval)
            ->groupBy('date')
            ->get();

        return $this->generateWeeklyData($data);
    }

    private function getWeeklyReturn(string $column)
    {
        $data = DB::table('order_returns')
            ->selectRaw("DATE(created_at) as date, SUM({$column}) as amount")
            ->where('user_id', $this->authUser->id)
            ->whereBetween('created_at', $this->weeklyInterval)
            ->groupBy('date')
            ->get();

        return $this->generateWeeklyData($data);
    }

    private function getWeeklyExpense()
    {
        $data =  DB::table('payments')
            ->leftJoin('payment_wallet', 'payments.id', '=', 'payment_wallet.payment_id')
            ->selectRaw('DATE(payments.created_at) as date, SUM(payment_wallet.sum_price) as amount')
            ->where('payments.paymentable_type', 'App\Models\Expense')
            ->where('user_id', $this->authUser->id)
            ->whereBetween('payments.created_at', $this->weeklyInterval)
            ->groupBy('date')
            ->get();

        return $this->generateWeeklyData($data);
    }

    public function getWeeklyRental(bool $isIncome)
    {
        $data = DB::table('payments')
            ->leftJoin('payment_wallet', 'payments.id', '=', 'payment_wallet.payment_id')
            ->leftJoin('rental_property_actions', 'payments.paymentable_id', '=', 'rental_property_actions.id')
            ->selectRaw('DATE(payments.created_at) as date, SUM(payment_wallet.sum_price) as amount')
            ->where('rental_property_actions.is_income', '=', $isIncome)
            ->where('payments.paymentable_type', 'App\Models\RentalPropertyAction')
            ->where('user_id', $this->authUser->id)
            ->whereBetween('payments.created_at', $this->weeklyInterval)
            ->groupBy('date')
            ->get();
        return $this->generateWeeklyData($data);
    }


    /* 
        1. Sale Price
        2. Cost Price
        2. Return Sale Price
        3. Return Cost Price
        4. Expense
        5. Rental Property Income
        6. Rental Property Expense
    */

    private function getWeeklyProfit()
    {
        $weeklySalesSalePrice = $this->getWeeklySales('total_sale_price');
        $weeklySalesCostPrice = $this->getWeeklySales('total_cost_price');
        $weeklyReturnSalePrice = $this->getWeeklyReturn('total_sale_price');
        $weeklyReturnCostPrice = $this->getWeeklyReturn('total_cost_price');
        $weeklyExpense = $this->getWeeklyExpense();
        $weeklyRentalIncome = $this->getWeeklyRental(true);
        $weeklyRentalExpense = $this->getWeeklyRental(false);
        $weekDaysCount = count($this->getWeekDays());

        $data = [];
        for ($i = 0; $i < $weekDaysCount; $i++) {
            // Sales
            $salePrice = $weeklySalesSalePrice[$i] ?? 0;
            $costPrice = $weeklySalesCostPrice[$i] ?? 0;
            // Return
            $returnSalePrice = $weeklyReturnSalePrice[$i] ?? 0;
            $returnCostPrice = $weeklyReturnCostPrice[$i] ?? 0;
            // Expense
            $expense = $weeklyExpense[$i] ?? 0;

            // Rental Property
            $rentalIncome = $weeklyRentalIncome[$i] ?? 0;
            $rentalExpense = $weeklyRentalExpense[$i] ?? 0;

            // Sales Profit
            $salesProfit = $salePrice - $costPrice;
            $returnProfit = $returnSalePrice - $returnCostPrice;

            // Rental Profit
            $rentalProfit = $rentalIncome - $rentalExpense;

            $data[] =  $salesProfit - $returnProfit - $expense + $rentalProfit;
        }


        return $data;
    }

    private function getWeekDays()
    {
        return ["Du", "Se", "Cho", "Pa", "Ju", "Sha", "Ya"];
    }

    private function getInterval()
    {
        return [
            Carbon::now()->startOfWeek()->startOfDay(),
            Carbon::now()->endOfWeek()->endOfDay(),
        ];
    }

    private function generateWeeklyData(Collection $data)
    {
        $result = [];

        $start = $this->weeklyInterval[0]->copy();
        for ($i = 0; $i < 7; $i++) {
            $dateStr = $start->format('Y-m-d');

            $dayData  = $data->firstWhere('date', $dateStr);

            $result[] = $dayData ? (float) $dayData->amount : 0;

            $start->addDay();
        }

        return $result;
    }
}
