<?php

namespace App\Services\OrderReturn;

use App\Exceptions\ServerErrorException;
use App\Models\Customer;
use App\Models\OrderReturn;
use App\Models\ProductStock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderReturnService
{
  public function findAll()
  {
    // Auth User
    $authUser = Auth::user();

    $query = OrderReturn::with('user', 'customer');

    if (!$authUser->isAdmin()) {
      $query->where('user_id', $authUser->id);
    }

    $data = $query->orderBy('created_at', 'desc')->get();

    return [
      'data' => $data,
      'total_sale_price' => (float)$data->sum('total_sale_price'),
      'total_cost_price' => (float)$data->sum('total_cost_price')
    ];
  }
  public function findOne($id)
  {
    // Auth User
    $authUser = Auth::user();

    $query = OrderReturn::with('user', 'customer', 'orderReturnDetails');

    if (!$authUser->isAdmin()) $query->where('user_id', $authUser->id);

    return $query->findOrFail($id);
  }
  public function create(array $data)
  {
    // Auth User
    $authUser = Auth::user();

    // Req Data
    $customerId = $data['customer_id'];
    $productList = $data['product_list'];
    $comment = $data['comment'] ?? null;

    // Customer
    $customer  = Customer::findOrFail($customerId);

    DB::beginTransaction();
    try {
      $newReturnOrder = OrderReturn::create([
        'user_id' => $authUser->id,
        'customer_id' => $customerId,
        'total_sale_price' => 0,
        'total_cost_price' => 0,
        'comment' => $comment
      ]);


      // Totals
      $totalSalePrice = 0;
      $totalCostPrice = 0;

      // Detail Items
      $detailItems = [];

      foreach ($productList as $item) {
        $productId = $item['product_id'];
        $amount = $item['amount'];
        $amountTypeId = $item['amount_type_id'];
        $polkaId = $item['polka_id'];

        // Product Stock
        $productStock = ProductStock::with('product')
          ->where('product_id', $productId)
          ->where('amount_type_id', $amountTypeId)
          ->findOrFail($polkaId);

        // Product 
        $product = $productStock->product;
        $productSalePrice = $product->sale_price;
        $productCostPrice = $product->sale_price;


        // Sum Price
        $sumSalePrice = $product->sale_price;
        $sumCostPrice = $product->cost_price;

        // Attach
        $detailItems[] = [
          "order_return_id" => $newReturnOrder->id,
          "product_id" => $product->id,
          "polka_id" => $productStock->id,
          "amount_type_id" => $amountTypeId,
          "amount" => $amount,
          "cost_price" => $productSalePrice,
          "sale_price" => $productCostPrice,
          "sum_cost_price" => $sumSalePrice,
          "sum_sale_price" => $sumCostPrice,
        ];

        // Change Product Stock
        $productStock->increment('amount', $amount);

        // Increment Total Prices
        $totalSalePrice += $sumSalePrice;
        $totalCostPrice += $sumCostPrice;
      }

      $newReturnOrder->orderReturnDetails()->createMany($detailItems);

      // Change Total Prices
      $newReturnOrder->total_sale_price = $totalSalePrice;
      $newReturnOrder->total_cost_price = $totalCostPrice;

      $newReturnOrder->save();

      // Change of Customer balance
      $customer->increment('balance', $totalSalePrice);

      // Finish
      DB::commit();

      return [
        'total_price' => $totalSalePrice
      ];
    } catch (\Exception $e) {
      DB::rollBack();
      throw new ServerErrorException($e->getMessage(), $e->getCode(), $e);
    }
  }
}
