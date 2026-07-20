<?php

namespace App\Console\Commands;

use App\Jobs\OrderHandleJob;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Services\OrderService;

class CheckOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:order';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '订单检查任务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        ini_set('memory_limit', -1);
        Order::where(function ($query) {
            $query->where('status', OrderService::STATUS_PROCESSING)
                ->orWhere(function ($query) {
                    $query->where('status', OrderService::STATUS_PENDING)
                        ->whereNull('payment_id')
                        ->where('created_at', '<=', time() - 3600 * 2);
                });
        })
            ->select(['id', 'trade_no'])
            ->orderBy('id')
            ->chunkById(200, function ($orders) {
                foreach ($orders as $order) {
                    OrderHandleJob::dispatch($order->trade_no);
                }
            });
    }
}
