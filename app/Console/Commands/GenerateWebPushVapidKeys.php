<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateWebPushVapidKeys extends Command
{
    protected $signature = 'webpush:vapid {--force : Replace existing VAPID keys} {--write-env : Also write keys into .env as fallback}';
    protected $description = 'Generate Web Push VAPID keys and save them to admin config (config/v2board.php)';

    public function handle()
    {
        /** @var \App\Services\WebPushService $webPushService */
        $webPushService = app(\App\Services\WebPushService::class);
        $current = $webPushService->getSettings();

        if (!$this->option('force')
            && !empty($current['public_key'])
            && !empty($current['private_key'])
        ) {
            $this->info('VAPID 密钥已存在，未做修改。使用 --force 可重新生成。');
            return 0;
        }

        try {
            $keys = $webPushService->generateVapidKeys();
            $settings = $webPushService->saveSettings([
                'enabled' => true,
                'vapid_subject' => $keys['vapid_subject'] ?: $this->resolveVapidSubject(),
                'public_key' => $keys['public_key'],
                'private_key' => $keys['private_key'],
            ]);
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return 1;
        }

        // Note: cannot use --env (Laravel reserves it for application environment).
        if ($this->option('write-env')) {
            $envPath = base_path('.env');
            if (is_file($envPath)) {
                $content = file_get_contents($envPath);
                $content = $this->setEnvValue($content, 'WEB_PUSH_ENABLED', 'true');
                $content = $this->setEnvValue($content, 'WEB_PUSH_VAPID_SUBJECT', $settings['vapid_subject']);
                $content = $this->setEnvValue($content, 'WEB_PUSH_PUBLIC_KEY', $settings['public_key']);
                $content = $this->setEnvValue($content, 'WEB_PUSH_PRIVATE_KEY', $settings['private_key']);
                file_put_contents($envPath, $content);
                $this->info('已同步写入 .env 作为回退配置。');
            }
        }

        $this->info('Web Push VAPID 密钥已生成并写入后台配置（config/v2board.php）。');
        $this->line('WEB_PUSH_VAPID_SUBJECT=' . $settings['vapid_subject']);
        $this->line('WEB_PUSH_PUBLIC_KEY=' . $settings['public_key']);
        $this->warn('也可在后台「Web Push 管理」页面直接配置，无需改 .env。');
        return 0;
    }

    private function resolveVapidSubject()
    {
        $candidates = [
            (string)env('WEB_PUSH_VAPID_SUBJECT', ''),
            (string)config('app.url', ''),
            (string)env('APP_URL', ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if (preg_match('/^mailto:/i', $candidate)) {
                return $candidate;
            }
            if (preg_match('/^https:\/\//i', $candidate)) {
                return $candidate;
            }
        }

        $mailFromAddress = (string)config('mail.from.address', env('MAIL_FROM_ADDRESS', ''));
        if (filter_var($mailFromAddress, FILTER_VALIDATE_EMAIL)) {
            return 'mailto:' . $mailFromAddress;
        }

        return 'mailto:admin@localhost';
    }

    private function setEnvValue($content, $key, $value)
    {
        $line = $key . '=' . $value;
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $content)) {
            return preg_replace($pattern, $line, $content);
        }

        return rtrim($content) . PHP_EOL . $line . PHP_EOL;
    }
}
