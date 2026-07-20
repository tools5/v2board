<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OrderHandleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $tradeNo;

    public $tries = 3;
    public $timeout = 5;
    public $backoff = [5, 30, 120];
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($tradeNo)
    {
        $this->onQueue('order_handle');
        $this->tradeNo = $tradeNo;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $order = Order::where('trade_no', $this->tradeNo)
            ->first();

        if (!$order) return;

        $orderService = new OrderService($order);
        switch ((int) $order->status) {
            case OrderService::STATUS_PENDING:
                if ($order->payment_id === null && $order->created_at <= (time() - 3600 * 2)) {
                    $orderService->cancel();
                }
                break;
            case OrderService::STATUS_PROCESSING:
                if (!$orderService->open()) {
                    throw new \RuntimeException('Unable to process paid order ' . $this->tradeNo);
                }
                break;
        }
    }
}
