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
        $weeklySales = $this->getWeeklySales();
        $weeklyExpense = $this->getWeeklyExpense();
        $weeklyProfit = $this->getWeeklyProfit();

        return [
            "weekly" => [
                "weekly_sales" => $weeklySales,
                "weekly_expense" => $weeklyExpense,
                "weekly_profit" => $weeklyProfit,
                "weekly_days" => $this->getWeekDays()
            ],
        ];
    }

    private function getWeeklySales()
    {
        $data = DB::table('completed_orders')
            ->selectRaw('DATE(updated_at) as date, SUM(total_sale_price) as amount')
            ->where('user_id', $this->authUser->id)
            ->whereBetween('updated_at', $this->weeklyInterval)
            ->groupBy('date')
            ->get();

        return $this->generateWeeklyData($data);
    }

    private function getWeeklySalesCostPrice()
    {
        $data = DB::table('completed_orders')
            ->selectRaw('DATE(updated_at) as date, SUM(total_cost_price) as amount')
            ->where('user_id', $this->authUser->id)
            ->whereBetween('updated_at', $this->weeklyInterval)
            ->groupBy('date')
            ->get();

        return $this->generateWeeklyData($data);
    }

    private function getWeeklyReturn()
    {
        $data = DB::table('order_returns')
            ->selectRaw('DATE(created_at) as date, SUM(total_sale_price) as amount')
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

    public function getWeeklyRentalIncome()
    {
        $data = DB::table('payments')
            ->leftJoin('payment_wallet', 'payments.id', '=', 'payment_wallet.payment_id')
            ->leftJoin('rental_properties', 'payments.paymentable_id', '=', 'rental_properties.id')
            ->leftJoin('rental_property_actions', 'rental_properties.id', '=', 'rental_property_actions.rental_property_id')
            ->selectRaw('DATE(payments.created_at) as date, SUM(payment_wallet.sum_price) as amount')
            // ->where('rental_property_actions.is_income', '=', true)
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
        2. Return
        3. Expense
    */

    private function getWeeklyProfit()
    {
        $weeklySales = $this->getWeeklySales();
        $weeklySalesCostPrice = $this->getWeeklySalesCostPrice();
        $weeklyReturn = $this->getWeeklyReturn();
        $weeklyExpense = $this->getWeeklyExpense();
        $weeklyRentalIncome = $this->getWeeklyRentalIncome();

        $weekDaysCount = count($this->getWeekDays());

        $data = [];
        for ($i = 0; $i < $weekDaysCount; $i++) {
            $salePrice = $weeklySales[$i] ?? 0;
            $costPrice = $weeklySalesCostPrice[$i] ?? 0;
            $return = $weeklyReturn[$i] ?? 0;
            $expense = $weeklyExpense[$i] ?? 0;
            $rentalIncome = $weeklyRentalIncome[$i] ?? 0;

            $data[] = $salePrice - $costPrice - $return - $expense + $rentalIncome;
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
