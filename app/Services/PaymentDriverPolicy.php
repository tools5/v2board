<?php

namespace App\Services;

/**
 * 支付驱动可用性策略（白名单/隔离）。
 *
 * 高危驱动默认不可用：BTCPay/Coinbase 须由管理员完成评估后显式写入
 * payment_secure_driver_allowlist 配置才放行；MGate 缺乏可信的结算/查询
 * 契约，永久隔离，任何配置都不能放行。
 */
class PaymentDriverPolicy
{
    private const HIGH_RISK_DRIVERS = ['BTCPay', 'Coinbase', 'MGate'];

    private const QUARANTINED_DRIVERS = ['MGate'];

    public static function isQuarantinedDriver(string $driver): bool
    {
        return in_array($driver, self::QUARANTINED_DRIVERS, true);
    }

    public static function isDriverAvailable(string $driver): bool
    {
        if (self::isQuarantinedDriver($driver)) {
            return false;
        }
        if (!in_array($driver, self::HIGH_RISK_DRIVERS, true)) {
            return true;
        }

        return in_array($driver, (array) config('v2board.payment_secure_driver_allowlist', []), true);
    }

    public static function isInstalledDriver(string $driver): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $driver) === 1
            && class_exists('\\App\\Payments\\' . $driver);
    }

    /**
     * 可进入白名单的驱动集合（高危驱动去掉永久隔离项）。
     */
    public static function allowlistableDrivers(): array
    {
        return array_values(array_diff(self::HIGH_RISK_DRIVERS, self::QUARANTINED_DRIVERS));
    }

    /**
     * 保存配置时收敛白名单：只保留可解除隔离的高危驱动，MGate 永远进不来。
     */
    public static function sanitizeAllowlist($value): array
    {
        // 只留字符串元素：嵌套数组会让 array_intersect 抛 Array to string conversion，
        // 整个配置保存请求连带 500
        $items = array_filter((array) $value, 'is_string');
        return array_values(array_intersect(
            self::allowlistableDrivers(),
            $items
        ));
    }
}
