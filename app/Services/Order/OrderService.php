<?php

namespace App\Services\Order;

use App\Events\Order\OrderAddedNewProductEvent;
use App\Events\Order\OrderCompletedEvent;
use App\Events\Order\OrderCreatedEvent;
use App\Events\Order\OrderProcessedEvent;
use App\Events\Order\OrderProcessEvent;
use App\Events\Order\OrderSubmittedEvent;
use App\Exceptions\InvalidDataException;
use App\Exceptions\ServerErrorException;
use App\Models\CompletedOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\ProductStock;
use App\Services\GenerateOrderCode;
use App\Services\Status\StatusService;
use App\Services\Utils\DateFormatter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderService
{
    private string|null $startDate = null;
    private string|null $endDate = null;

    public function setDate(string $start, string $end): void
    {
        $this->startDate = DateFormatter::format($start, 'start');
        $this->endDate = DateFormatter::format($end, 'end');
    }

    public function findAll($statusCode): array
    {
        $query = Order::with(
            'user',
            'customer',
            'status'
        );


        if ($statusCode) {
            $status = StatusService::findByCode($statusCode);

            // Submitted
            if ($statusCode === 'orderSubmitted' || $statusCode === 'orderCompleted') {
                $query->with('completedOrder');
            } else if ($statusCode === 'orderCancel') {
                $query->with('cancelOrder');
            }

            $query->where('status_id', $status->id);
        }

        if (!Auth::user()->isAdmin()) {
            $query->where('user_id', Auth::id());
        }

        $data = $query
            ->orderByDesc('created_at')
            ->whereBetween('updated_at', [$this->startDate, $this->endDate])
            ->get();

        // Totals
        $totals = $this->getTotalsByStatusCode($statusCode);

        return [
            'data' => $data,
            'total_sale_price' => $totals['total_amount'],
            'total_count' => $totals['total_count'],
        ];
    }

    /**
     * @throws ServerErrorException
     */
    public function create(array $data): array
    {
        // New Order status
        $newOrderStatus = StatusService::findByCode('orderNew');

        // Get Request Products
        $productsId = array_column($data['product_list'] ?? [], 'product_id');
        $products = Product::whereIn('id', $productsId)->get();

        $pluckedCostPrice = $products->pluck('cost_price', 'id');
        $pluckedSalePrice = $products->pluck('sale_price', 'id');

        // Generate Order Code
        $orderCode = GenerateOrderCode::generate($data['customer_id']);

        DB::beginTransaction();

        try {
            $newOrder = Order::create([
                "user_id" => Auth::id(),
                "customer_id" => $data['customer_id'],
                "status_id" => $newOrderStatus->id,
                'total_cost_price' => 0,
                'total_sale_price' => 0,
                'ord_code' => $orderCode
            ]);


            $totalCostPrice = 0;
            $totalSalePrice = 0;

            // Create Order Details
            $detailItemList = [];
            foreach ($data['product_list'] as $item) {
                $costPrice = $pluckedCostPrice[$item['product_id']];
                $salePrice = $pluckedSalePrice[$item['product_id']];
                $sumCostPrice = $costPrice * $item['amount'];
                $sumSalePrice = $salePrice * $item['amount'];

                $detailItemList[] = [
                    'product_id' => $item['product_id'],
                    'amount' => $item['amount'],
                    'amount_type_id' => $item['amount_type_id'],
                    'cost_price' => $costPrice,
                    'sale_price' => $salePrice,
                    'sum_cost_price' => $sumCostPrice,
                    'sum_sale_price' => $sumSalePrice,
                ];

                $totalCostPrice += $sumCostPrice;
                $totalSalePrice += $sumSalePrice;
            }

            $newOrder->orderDetails()->createMany($detailItemList);


            $newOrder->total_cost_price = $totalCostPrice;
            $newOrder->total_sale_price = $totalSalePrice;

            $newOrder->save();

            DB::commit();

            // Event
            OrderCreatedEvent::dispatch($newOrder);

            return [
                "message" => "Yangi buyurtma muvaffaqiyatli qo'shildi!",
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ServerErrorException($e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    public function findOne(int $id): array
    {
        $query = Order::with(
            'user',
            'customer',
            'status',
            'orderDetails',
            'cancelOrder',
            'completedOrder'
        )->where('id', $id);

        if (!Auth::user()->isAdmin()) {
            $query->where('user_id', Auth::id());
        }

        $order = $query->firstOrFail();

        return [
            "data" => $order,
        ];
    }

    public function confirm(int $id): array
    {
        $newOrderStatus = StatusService::findByCode('orderNew');

        $order = Order::where('user_id', Auth::id())
            ->where('status_id', $newOrderStatus->id)
            ->findOrFail($id);

        // Status Code
        $statusInProgress = StatusService::findByCode('orderInProgress');

        $order->status_id = $statusInProgress->id;
        $order->save();

        // Event
        OrderProcessedEvent::dispatch($order);

        return [
            'message' => "Buyurtma tasdiqlandi va hozir jarayonda",
            'data' => [
                'status' => $statusInProgress
            ]
        ];
    }

    /**
     * @throws ServerErrorException
     * @throws InvalidDataException
     */
    public function addProduct(array $data, int $id): array
    {
        $productId = $data['product_id'];
        $amount = $data['amount'];
        $amountTypeId = $data['amount_type_id'];


        // Product
        $product = Product::findOrFail($productId);

        // Status
        $statusOrderInProgress = StatusService::findByCode('orderInProgress');

        // Order
        $order = Order::where('user_id', Auth::id())
            ->where('status_id', $statusOrderInProgress->id)
            ->findOrFail($id);

        // Validate Order Details
        $detailItemExists = OrderDetail::where('order_id', $order->id)
            ->where('product_id', $productId)
            ->exists();
        if ($detailItemExists) {
            throw new InvalidDataException("Bu mahsulot allaqachon mavjud");
        }


        // Create Order Details Item
        $sumCostPrice = $product->cost_price * $amount;
        $sumSalePrice = $product->sale_price * $amount;

        DB::beginTransaction();

        try {
            $order->orderDetails()->create([
                'product_id' => $productId,
                'amount' => $amount,
                'amount_type_id' => $amountTypeId,
                'cost_price' => $product->cost_price,
                'sale_price' => $product->sale_price,
                'sum_cost_price' => $sumCostPrice,
                'sum_sale_price' => $sumSalePrice,
            ]);


            $order->increment('total_cost_price', $sumCostPrice);
            $order->increment('total_sale_price', $sumSalePrice);

            DB::commit();

            // Event
            OrderAddedNewProductEvent::dispatch($order, $product, [
                "price" => (float) $product->sale_price,
                "amount" => (float) $amount,
                "sum_sale_price" => (float) $sumSalePrice,
            ]);

            return [
                'message' => "#$order->id buyurtmaga `$product->name` nomli mahsulot muvaffaqiyatli qo'shildi"
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ServerErrorException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @throws InvalidDataException
     * @throws ServerErrorException
     */
    public function completed(array $data, int $id)
    {
        // Req Data
        $reqItemsList = $data['items_list'];
        $reqComment = $data['comment'] ?? null;
        $reqOrderId = $id;

        // Auth User
        $authUserId = Auth::id();

        // Status
        $statusCompleted = StatusService::findByCode('orderCompleted');
        $orderNewStatus = StatusService::findByCode('orderNew');
        $orderInProgressStatus = StatusService::findByCode('orderInProgress');
        // Order
        $currentOrder = Order::whereIn('status_id', [$orderNewStatus->id, $orderInProgressStatus->id])->findOrFail($id);


        DB::beginTransaction();
        try {
            // New Completed Order
            $newCompletedOrder = CompletedOrder::create([
                'user_id' => $authUserId,
                'order_id' => $reqOrderId,
                'status_id' => $statusCompleted->id,
                'comment' => $reqComment,
                'total_cost_price' => 0,
                'total_sale_price' => 0,
                'customer_old_balance' => 0,
            ]);


            // Totals
            $totalSale = 0;
            $totalCost = 0;

            // Add Completed Amount
            foreach ($reqItemsList as $item) {
                $orderItemId = $item['item_id'];
                $stockId = $item['stock_id'];
                $completedAmount = $item['completed_amount'];

                // Order
                $orderItem = OrderDetail::where('order_id', $reqOrderId)->findOrFail($orderItemId);

                // Stock
                $stockItem = ProductStock::with('product')->findOrFail($stockId);

                // Validate
                if ($orderItem->product_id != $stockItem->product_id) {
                    throw new InvalidDataException("$stockItem->name zaxira polka, {$stockItem->product->name} mahsulotga tegishli emas");
                }

                if ($orderItem->amount_type_id !== $stockItem->amount_type_id) {
                    throw new InvalidDataException("$stockItem->name zaxira polka bilan buyurtma {$stockItem->product->name} mahsulot o'lchov birligi mos emas");
                }

                if ($stockItem->amount < $completedAmount) {
                    throw new InvalidDataException("$stockItem->name zaxira polkada zaxira yetarli emas");
                }
                

                // Product Price
                $salePrice = $orderItem->sale_price;
                $costPrice = $orderItem->cost_price;

                // Sum Price
                $sumSalePrice = $salePrice * $completedAmount;
                $sumCostPrice = $costPrice * $completedAmount;

                // Set Completed Amount
                $orderItem->completed_amount = $completedAmount;
                $orderItem->stock_id = $stockId;
                $orderItem->save();

                // Decrement form stock
                $stockItem->decrement('amount', $completedAmount);

                // Totals
                $totalSale += $sumSalePrice;
                $totalCost += $sumCostPrice;
            }
            // Set Totals
            $newCompletedOrder->total_sale_price = $totalSale;
            $newCompletedOrder->total_cost_price = $totalCost;

            $currentOrder->status_id = $statusCompleted->id;
            $currentOrder->save();

            DB::commit();

            // Event
            OrderCompletedEvent::dispatch($currentOrder);

            return [
                'message' => "Buyurtma topshirishga tayyor, hozirgi holati tayyorlandi",
                'data' => [
                    'status' => $statusCompleted
                ]
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ServerErrorException($e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * @throws ServerErrorException
     */
    public function submit(array $data, int $id)
    {
        // Data
        $comment = $data['comment'] ?? null;

        $statusCompleted = StatusService::findByCode('orderCompleted');

        // Order
        $order = Order::with('orderDetails')
            ->where('status_id', $statusCompleted->id)
            ->findOrFail($id);

        // Status Code
        $statusSubmitted = StatusService::findByCode('orderSubmitted');

        // Customer
        $customer = Customer::findOrFail($order->customer_id);

        // Completed Order
        $completedOrder = CompletedOrder::where('order_id', $order->id)->firstOrFail();

        DB::beginTransaction();

        try {

            // Order
            $order->status_id = $statusSubmitted->id;
            $order->save();

            // Completed Order
            $completedOrder->submitted_comment = $comment;
            $completedOrder->status_id = $statusSubmitted->id;
            $completedOrder->customer_old_balance = $order->customer->balance;
            $completedOrder->save();

            // Customer
            $customer->decrement('balance', $completedOrder->total_sale_price);

            DB::commit();

            // Event
            OrderSubmittedEvent::dispatch($order);

            return [
                'message' => "Buyurtma muvaffaqiyatli topshirildi! Mijoz balansini tekshiring",
                'data' => [
                    'status' => $statusSubmitted
                ]
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ServerErrorException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function getTotalsByStatusCode(?string $statusCode): array
    {
        switch ($statusCode) {
            case 'orderCompleted':
            case 'orderSubmitted':
                return $this->getTotalsOrderCompleted($statusCode);
            default:
                return $this->getTotalsOrderOther($statusCode);
        }
    }

    private function getTotalsOrderCompleted(string $statusCode): array
    {
        $status = StatusService::findByCode($statusCode);

        $result = DB::table('completed_orders')
            ->select(
                DB::raw('SUM(completed_orders.total_sale_price) as total_amount'),
                DB::raw('COUNT(completed_orders.id) as total_count')
            )
            ->whereBetween('completed_orders.updated_at', [$this->startDate, $this->endDate])
            ->where('completed_orders.status_id', $status->id)
            ->first();

        return [
            'total_amount' => (float) $result->total_amount,
            'total_count' => $result->total_count,
        ];
    }

    private function getTotalsOrderOther(?string $statusCode): array
    {



        $query = DB::table('orders')
            ->leftJoin('completed_orders', 'orders.id', '=', 'completed_orders.order_id')
            ->select(
                DB::raw('SUM(IF(completed_orders.order_id IS NULL, orders.total_sale_price, completed_orders.total_sale_price)) as total_amount'),
                DB::raw('COUNT(orders.id) as total_count')
            )
            ->whereBetween('orders.updated_at', [$this->startDate, $this->endDate]);

        // Status When Exist
        if ($statusCode) {
            $statusId = StatusService::findByCode($statusCode);
            $query->where('orders.status_id', $statusId->id);
        }
        $result = $query->first();

        return [
            'total_amount' => (float) $result->total_amount,
            'total_count' => $result->total_count,
        ];
    }
}
