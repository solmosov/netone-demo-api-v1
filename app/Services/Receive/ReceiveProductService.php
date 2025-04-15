<?php

namespace App\Services\Receive;

use App\Exceptions\InvalidDataException;
use App\Exceptions\ServerErrorException;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ReceiveProduct;
use App\Models\Supplier;
use App\Services\Status\StatusService;
use App\Services\Utils\DateFormatter;
use Illuminate\Support\Facades\DB;

class ReceiveProductService
{
    private string|null $startDate = null;
    private string|null $endDate = null;

    public function setDate(string $start, string $end): void
    {
        $this->startDate = DateFormatter::format($start, 'start');
        $this->endDate = DateFormatter::format($end, 'end');
    }

    public function findAll(): array
    {
        $query = ReceiveProduct::with(
            "user",
            "supplier",
            "status"
        );

        if (!auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        $data = $query->orderByDesc('id')
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->get();

        return [
            'data' => $data,
            'total_price' => $data->sum('total_price'),
            'total_count' => $data->count(),
        ];
    }

    public function create(array $data): array
    {
        // Data
        $supplierId = $data['supplier_id'];
        $dateReceived = $data['date_received'];
        $reqProductList = $data['product_list'];
        $comment = $data['comment'] ?? null;

         // Req Product Plucked List
        $reqPolkaArr = array_column($reqProductList, 'polka_id');

        // Stock
        $stockList = ProductStock::whereIn('id', $reqPolkaArr)->get();
        $pluckStockAmount = $stockList->pluck('amount', 'id')->toArray();
        $pluckStockName = $stockList->pluck('name', 'id');
        $pluckStockProductId = $stockList->pluck('product_id', 'id');

        if ($stockList->isEmpty()) {
            throw new InvalidDataException('Mahsulotlar uchun zaxira polka ochilmagan. Admin zaxira polka yaratishi kerak');
        }

        // Products
        $products = Product::whereIn('id', array_column($reqProductList, 'product_id'))->get();
        $pluckProductsName = $products->pluck('name', 'id')->toArray();
        $pluckProductsPriceAmountType = $products->pluck('price_amount_type_id', 'id')->toArray();

        // Stock Items
        foreach ($reqProductList as $item) {
            if (isset($pluckStockProductId[$item['polka_id']])) {
                // Check match
                if ($pluckStockProductId[$item['polka_id']] !== $item['product_id']) {


                    $stockName = $pluckStockName[$item['polka_id']];
                    $productName = $pluckProductsName[$item['product_id']];

                    throw new InvalidDataException("`$productName` mahsulot uchun `$stockName` nomli polka tegishli emas. Polkani to'g'ri tanlang");
                }

                if (!isset($pluckStockAmount[$item['polka_id']])) {
                    $productName = $pluckProductsName[$item['product_id']];
                    throw new InvalidDataException("`$productName` mahsulot uchun zaxira polka ochilmagan. Adminka zaxira polka yaratishi kerak");
                }

                // Check match stock product_id with request product_id
                if ($pluckStockProductId[$item['polka_id']] !== $item['product_id']) {
                    $stockName = $pluckStockName[$item['polka_id']];
                    $productName = $pluckProductsName[$item['product_id']];

                    throw new InvalidDataException("`$stockName` polka `$productName` mahsulotga tegishli emas!");
                }
            } else {
                throw new InvalidDataException("Polka topilmadi");
            }
        }


        // Supplier
        $supplier = Supplier::findOrFail($supplierId);

        // Status Receive Debt
        $statusReceiveDebt = StatusService::findByCode('receiveProductDebt');

        DB::beginTransaction();

        try {
            // New Receive
            $newReceive = ReceiveProduct::create([
                'user_id' => auth()->id(),
                'supplier_id' => $supplier->id,
                'status_id' => $statusReceiveDebt->id,
                'date_received' => $dateReceived,
                'total_price' => 0,
                'comment' => $comment,
            ]);
            $totalPrice = 0;

            // Attach Details
            $productList = [];

            foreach ($reqProductList as $item) {
                $sum = $item['amount'] * $item['price'];

                $productList[] = [
                    'receive_product_id' => $newReceive->id,
                    'product_id' => $item['product_id'],
                    'amount' => $item['amount'],
                    'price' => $item['price'],

                    'amount_type_id' => $pluckProductsPriceAmountType[$item['product_id']],
                    'status_id' => $statusReceiveDebt->id,
                    'sum_price' => $sum,
                ];

                $totalPrice += $sum;

                // Change Product Receive Price
                $productItem = Product::where('id', $item['product_id'])->firstOrFail();
                $productItem->receive_price = $item['price'];
                $productItem->cost_price = $item['price'];
                $productItem->save();

                // Change Stock Amount
                $stockItem = ProductStock::where('id', $item['polka_id'])
                    ->where('product_id', $item['product_id'])
                    ->firstOrFail();

                $stockItem->increment('amount', $item['amount']);
            }

            $newReceive->receiveProductDetails()->createMany($productList);

            $newReceive->total_price = $totalPrice;
            $newReceive->save();

            // Change of Supplier's balance
            $supplier->increment('balance', $totalPrice);
            DB::commit();

            $formatVal = number_format($totalPrice, 2, '.', ',');

            return [
                'message' => "Yuk muvaffaqiyatli qabul qilindi. Jami $formatVal uzs.",
                'data' => $newReceive
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ServerErrorException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function findOne(int $id): array
    {
        $query = ReceiveProduct::with(
            "user",
            "supplier",
            "receiveProductDetails",
            "status"
        );

        if (!auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        $data = $query->findOrFail($id);

        return [
            'data' => $data,
        ];
    }

}
