<?php

namespace App\Services\Order;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Telegram\TelegramChat;
use App\Telegram\MessageBody\Customer\OrderMessageBody;
use Illuminate\Support\Facades\Log;

class OrderCustomerTelegramService
{
  protected ?Order $order = null;
  protected ?Customer $customer = null;

  public function __construct(protected TelegramChat $telegramChat) {}

  public function setOrderAndCustomer(Order $order, Customer $customer): self
  {
    $this->order = $order;
    $this->customer = $customer;

    return $this;
  }

  // New Order Msg
  public function sendNewOrderMsg(): void
  {
    // Title
    $status = $this->order->status->name;
    $message = "<b>🟢$status</b>\n\n";

    // Order Details
    $message .= OrderMessageBody::makeMessage($this->order);

    $this->chat()
      ?->html($message)
      ->send();
  }

  // Process Order Msg
  public function sendProcessOrderMsg()
  {
    // Title
    $status = $this->order->status->name;
    $message = "<b>⏳$status</b>\n\n";

    // Order Details
    $message .= OrderMessageBody::makeMessage($this->order);

    $this->chat()
      ?->html($message)
      ->send();
  }

  // Added New Product
  public function sendAddedNewProductMsg(Product $product, array $addData)
  {

    // Title
    $message = "<b>➕ yangi mahsulot qo'shildi</b>\n\n";

    // Added Product
    $amount = number_format($addData['amount']);
    $price = number_format($addData['price']);
    $sumSalePrice = number_format($addData['sum_sale_price']);
    $message .= "{$product->name} \n";
    $message .= "   <code>$amount {$product->priceAmountType->name}</code>x";
    $message .= "<code>$price</code> = ";
    $message .= "$sumSalePrice\n\n";

    // Order Details
    $message .= OrderMessageBody::makeMessage($this->order);

    $this->chat()
      ?->html($message)
      ->send();
  }

  // Cancel Order Msg
  public function cancelOrderMsg() {}
  // Completed Order Msg
  public function sendCompletedOrderMsg()
  {
    // Title
    $status = $this->order->status->name;
    $message = "<b>✅$status</b>\n\n";

    // Order Details
    $message .= OrderMessageBody::makeMessage($this->order);

    $this->chat()
      ?->html($message)
      ->send();
  }
  // Submitted Order Msg
  public function sendSubmittedOrderMsg()
  {
    // Title
    $status = $this->order->status->name;
    $message = "<b>📦$status</b>\n\n";

    // Order Details
    $message .= OrderMessageBody::makeMessage($this->order);

    $this->chat()
      ?->html($message)
      ->send();
  }

  private function chat()
  {
    try {
      return TelegramChat::where('chat_id', $this->customer->telegram)
        ->firstOrFail();
    } catch (\Exception $e) {
      Log::error('Telegram chat not found.', [
        'error' => $e->getMessage(),
        'customer_id' => $this->customer->id,
        'time' => now(),
      ]);
    }
  }
}
