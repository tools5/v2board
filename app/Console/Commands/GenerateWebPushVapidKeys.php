<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateWebPushVapidKeys extends Command
{
    protected $signature = 'webpush:vapid {--force : Replace existing VAPID keys}';
    protected $description = 'Generate Web Push VAPID keys and store them in .env';

    public function handle()
    {
        $envPath = base_path('.env');
        if (!is_file($envPath)) {
            $this->error('.env 文件不存在');
            return 1;
        }

        if (!$this->option('force')
            && config('webpush.vapid.public_key')
            && config('webpush.vapid.private_key')
        ) {
            $this->info('VAPID 密钥已存在，未做修改。使用 --force 可重新生成。');
            return 0;
        }

        $keys = VAPID::createVapidKeys();
        $subject = $this->resolveVapidSubject();

        $content = file_get_contents($envPath);
        $content = $this->setEnvValue($content, 'WEB_PUSH_ENABLED', 'true');
        $content = $this->setEnvValue($content, 'WEB_PUSH_VAPID_SUBJECT', $subject);
        $content = $this->setEnvValue($content, 'WEB_PUSH_PUBLIC_KEY', $keys['publicKey']);
        $content = $this->setEnvValue($content, 'WEB_PUSH_PRIVATE_KEY', $keys['privateKey']);

        file_put_contents($envPath, $content);
        $this->call('config:clear');
        $this->info('Web Push VAPID 密钥已生成并写入 .env。');
        $this->line('WEB_PUSH_VAPID_SUBJECT=' . $subject);
        $this->line('WEB_PUSH_PUBLIC_KEY=' . $keys['publicKey']);
        $this->warn('生产环境请确保 APP_URL 为 https:// 域名，或将 WEB_PUSH_VAPID_SUBJECT 设为 mailto:你的邮箱。');
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
