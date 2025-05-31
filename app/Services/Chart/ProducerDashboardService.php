<?php

namespace App\Services\Chart;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProducerDashboardService
{

    private $weeklyInterval =  [];

    public function __construct()
    {
        $this->weeklyInterval = $this->getInterval();
    }

    public function get()
    {
        $weeklySales = $this->getWeeklySales();
        $weeklyProfit = $this->getWeeklyProfit();

        return [
            "weekly" => [
                "weekly_sales" => $weeklySales,
                "weekly_profit" => $weeklyProfit,
                "weekly_days" => $this->getWeekDays()
            ],
        ];
    }

    private function getWeeklySales()
    {
        $data = DB::table('completed_orders')
            ->selectRaw('DATE(updated_at) as date, SUM(total_sale_price) as sum_sale_price')
            ->whereBetween('updated_at', $this->weeklyInterval)
            ->groupBy('date')
            ->get();

        return $this->generateWeeklyData($data);
    }

    private function getWeeklyReturn()
    {
        $data = DB::table('order_returns')
            ->selectRaw('DATE(updated_at) as date, SUM(total_sale_price) as sum_sale_price')
            ->whereBetween('updated_at', $this->weeklyInterval)
            ->groupBy('date')
            ->get();

        return $this->generateWeeklyData($data);
    }

    private function getWeeklyExpense()
    {
        return DB::table('payments')
            ->leftJoin('payment_wallet', 'payments.id', '=', 'payment_wallet.payment_id')
            ->selectRaw('DATE(payments.created_at) as day, SUM(payment_wallet.sum_price) as daily_amount')
            ->where('payments.paymentable_type', 'App\Models\Expense')
            ->whereBetween('payments.created_at', $this->weeklyInterval)
            ->groupBy('day')
            ->orderBy('day', 'asc')
            ->get()
            ->map(fn($day) => (float)$day->daily_amount)
            ->toArray();
    }

    public function getWeeklyRentalIncome()
    {
        return DB::table('payments')
            ->leftJoin('payment_wallet', 'payments.id', '=', 'payment_wallet.payment_id')
            ->selectRaw('DATE(payments.created_at) as day, SUM(payment_wallet.sum_price) as daily_amount')
            ->where('payments.paymentable_type', 'App\Models\RentalPropertyAction')
            ->whereBetween('payments.created_at', $this->weeklyInterval)
            ->groupBy('day')
            ->orderBy('day', 'asc')
            ->get()
            ->map(fn($day) => (float) $day->daily_amount)
            ->toArray();
    }


    /* 
        1. Sales
        2. Return 
        3. Expense
    */

    private function getWeeklyProfit()
    {
        $weeklySales = $this->getWeeklySales();
        $weeklyReturn = $this->getWeeklyReturn();
        $weeklyExpense = $this->getWeeklyExpense();
        $weeklyRentalIncome = $this->getWeeklyRentalIncome();

        $weekDaysCount = count($this->getWeekDays());

        $data = [];
        for ($i = 0; $i < $weekDaysCount; $i++) {
            $sale = $weeklySales[$i] ?? 0;
            $return = $weeklyReturn[$i] ?? 0;
            $expense = $weeklyExpense[$i] ?? 0;
            $rentalIncome = $weeklyRentalIncome[$i] ?? 0;

            $data[] = $sale - $return - $expense + $rentalIncome;
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

            $result[] = $dayData ? (float) $dayData->sum_sale_price : 0;

            $start->addDay();
        }

        return $result;
    }
}
