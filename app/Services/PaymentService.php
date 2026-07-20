<?php

namespace App\Services;


use App\Models\Payment;
use App\Support\ConfiguredUrl;

class PaymentService
{
    public $method;
    protected $class;
    protected $config;
    protected $payment;
    protected $paymentModel;

    public function __construct($method, $id = NULL, $uuid = NULL)
    {
        if (!is_string($method) || !preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/', $method)) {
            throw new \InvalidArgumentException('Payment method is invalid');
        }

        $this->method = $method;
        $this->class = '\\App\\Payments\\' . $this->method;
        if (!self::isMethodAvailable($method)) {
            throw new \RuntimeException('Payment method is unavailable or missing required dependencies');
        }

        $payment = null;
        if ($id !== null) {
            $payment = Payment::where('id', $id)->where('payment', $method)->first();
        }
        if ($uuid !== null) {
            $payment = Payment::where('uuid', $uuid)->where('payment', $method)->first();
        }
        if (($id !== null || $uuid !== null) && !$payment) {
            throw new \RuntimeException('Payment configuration was not found');
        }

        $this->config = [];
        $this->paymentModel = $payment;
        if ($payment) {
            $this->config = $payment->config ?: [];
            $this->config['enable'] = (int) $payment->enable;
            $this->config['id'] = (int) $payment->id;
            $this->config['uuid'] = $payment->uuid;
            $this->config['notify_domain'] = $payment->notify_domain;
        }
        $this->payment = new $this->class($this->config);
    }

    public static function isMethodAvailable(string $method): bool
    {
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/', $method)) {
            return false;
        }

        $class = '\\App\\Payments\\' . $method;
        try {
            if (!class_exists($class)) {
                return false;
            }

            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable()) {
                return false;
            }
            foreach (['form', 'pay', 'notify'] as $requiredMethod) {
                if (!$reflection->hasMethod($requiredMethod) || !$reflection->getMethod($requiredMethod)->isPublic()) {
                    return false;
                }
            }
            if (method_exists($class, 'isAvailable')) {
                return (bool) $class::isAvailable();
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    public function notify($params)
    {
        return $this->payment->notify($params);
    }

    public function pay($order)
    {
        if (!$this->paymentModel || !(int) $this->paymentModel->enable) {
            throw new \RuntimeException('Payment method is not enabled');
        }

        $applicationUrl = ConfiguredUrl::applicationUrl();
        if ($applicationUrl === '') {
            throw new \RuntimeException('A valid application URL is required before starting a payment');
        }

        $notifyPath = '/api/v1/guest/payment/notify/'
            . rawurlencode($this->method)
            . '/'
            . rawurlencode((string)($this->config['uuid'] ?? ''));
        $notifyDomain = ConfiguredUrl::normalizeHttpUrl($this->config['notify_domain'] ?? '');
        $notifyUrl = rtrim($notifyDomain !== '' ? $notifyDomain : $applicationUrl, '/') . $notifyPath;
        $tradeNo = isset($order['trade_no']) ? (string)$order['trade_no'] : '';

        return $this->payment->pay([
            'notify_url' => $notifyUrl,
            'return_url' => rtrim($applicationUrl, '/') . '/#/order/' . rawurlencode($tradeNo),
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'stripe_token' => $order['stripe_token'] ?? null
        ]);
    }

    public function getPaymentId(): ?int
    {
        return $this->paymentModel ? (int) $this->paymentModel->id : null;
    }

    public function form()
    {
        $form = $this->payment->form();
        $keys = array_keys($form);
        foreach ($keys as $key) {
            if (isset($this->config[$key])) $form[$key]['value'] = $this->config[$key];
        }
        return $form;
    }
}
