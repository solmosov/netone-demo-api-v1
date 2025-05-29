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
        return DB::table('completed_orders')
            ->selectRaw('SUM(total_sale_price) as daily_sales')
            ->whereBetween('updated_at', $this->weeklyInterval)
            ->groupBy('updated_at')
            ->orderBy('updated_at', 'asc')
            ->get()
            ->map(fn($day)  => (float) $day->daily_sales)
            ->toArray();
    }

    private function getWeeklyReturn()
    {
        return DB::table('order_returns')
            ->selectRaw('SUM(total_sale_price) as daily_amount')
            ->whereBetween('updated_at', $this->weeklyInterval)
            ->groupBy('updated_at')
            ->orderBy('updated_at', 'asc')
            ->get()
            ->map(fn($day) => (float) $day->daily_amount)
            ->toArray();
    }

    private function getWeeklyExpense()
    {
        return DB::table('payments')
            ->leftJoin('payment_wallet', 'payments.id', '=', 'payment_wallet.payment_id')
            ->selectRaw('DATE(payments.created_at) as day, SUM(payment_wallet.sum_price) as daily_amount')
            ->where('paymentable_type', 'App\Models\Expense')
            ->whereBetween('payments.created_at', $this->weeklyInterval)
            ->groupBy('day')
            ->orderBy('day', 'asc')
            ->get()
            ->map(fn($day) => (float)$day->daily_amount)
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

        $length = max(count($weeklySales), count($weeklyReturn));
        $length = max($length, count($weeklyExpense));



        $data = [];
        for ($i = 0; $i < $length; $i++) {
            $sale = $weeklySales[$i] ?? 0;
            $return = $weeklyReturn[$i] ?? 0;
            $expense = $weeklyExpense[$i] ?? 0;


            $data[] = $sale - $return - $expense;
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
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek(),
        ];
    }
}
