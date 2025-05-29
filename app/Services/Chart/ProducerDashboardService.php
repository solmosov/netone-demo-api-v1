<?php

namespace App\Services\Chart;

use Carbon\Traits\Week;
use Carbon\WeekDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProducerDashboardService
{
    public function get()
    {
        $weeklySales = $this->getWeeklySales();

        return [
            "weekly_sales" => $weeklySales,
            "weekly_profit" => [],
            "weekly_days" => $this->getWeekDays(),
        ];
    }

    private function getWeeklySales()
    {
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        return DB::table('completed_orders')
            ->selectRaw('SUM(total_sale_price) as daily_sales')
            ->whereBetween('updated_at', [$startOfWeek, $endOfWeek])
            ->groupBy('updated_at')
            ->orderBy('updated_at', 'asc')
            ->get()
            ->map(function ($day) {
                return $day->daily_sales;
            });
    }

    private function getWeekDays()
    {
        return ["Ya", "Du", "Se", "Cho", "Pa", "Ju", "Sha", "Ya"];
    }
}
