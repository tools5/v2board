<?php

namespace App\Jobs;

use App\Models\MailLog;
use App\Support\MailHeaderValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $params;

    public $tries = 3;
    public $timeout = 10;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($params, $queue = 'send_email')
    {
        $this->onQueue($queue);
        $this->params = $params;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $params = is_array($this->params) ? $this->params : [];

        try {
            if (!is_array($this->params)) {
                throw new \InvalidArgumentException('邮件参数必须是数组');
            }

            $email = MailHeaderValidator::address($params['email'] ?? null, '收件人邮箱');
            $subject = MailHeaderValidator::text($params['subject'] ?? null, '邮件主题');
            $templateName = $this->requireTemplateName($params['template_name'] ?? null);
            $templateValue = $params['template_value'] ?? null;
            if (!is_array($templateValue)) {
                throw new \InvalidArgumentException('邮件模板参数必须是数组');
            }

            $params['email'] = $email;
            $params['subject'] = $subject;
            $params['template_name'] = $this->buildTemplateName($templateName);
            $params['template_value'] = $templateValue;

            $this->configureMailer();
            Mail::send(
                $params['template_name'],
                $params['template_value'],
                function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                }
            );
        } catch (\Throwable $e) {
            $this->writeLog($params, $e->getMessage());
            throw $e;
        }

        $log = [
            'email' => $params['email'],
            'subject' => $params['subject'],
            'template_name' => $params['template_name'],
            'error' => null
        ];
        $this->writeLog($params, null);
        return $log;
    }

    private function configureMailer(): void
    {
        if (config('v2board.email_host')) {
            Config::set('mail.host', config('v2board.email_host', config('mail.host')));
            Config::set('mail.port', config('v2board.email_port', config('mail.port')));
            Config::set('mail.encryption', config('v2board.email_encryption', config('mail.encryption')));
            Config::set('mail.username', config('v2board.email_username', config('mail.username')));
            Config::set('mail.password', config('v2board.email_password', config('mail.password')));
            Config::set('mail.from.address', config('v2board.email_from_address', config('mail.from.address')));
            Config::set('mail.from.name', config('v2board.app_name', 'V2Board'));
        }

        Config::set(
            'mail.from.address',
            MailHeaderValidator::address(config('mail.from.address'), '发件人邮箱')
        );

        $fromName = config('mail.from.name');
        if ($fromName !== null) {
            Config::set('mail.from.name', MailHeaderValidator::text($fromName, '发件人名称'));
        }

        app('mail.manager')->forgetMailers();
    }

    private function requireTemplateName($templateName): string
    {
        if (!is_string($templateName) || !preg_match('/^[A-Za-z0-9_-]+$/', $templateName)) {
            throw new \InvalidArgumentException('邮件模板名称无效');
        }

        return $templateName;
    }

    private function buildTemplateName(string $templateName): string
    {
        $template = config('v2board.email_template', 'default');
        if (!is_string($template) || !preg_match('/^[A-Za-z0-9_-]+$/', $template)) {
            throw new \InvalidArgumentException('邮件模板配置无效');
        }

        return 'mail.' . $template . '.' . $templateName;
    }

    protected function writeLog(array $params, $error): void
    {
        try {
            MailLog::create([
                'email' => $this->sanitizeLogValue($params['email'] ?? null, 64),
                'subject' => $this->sanitizeLogValue($params['subject'] ?? null, 255),
                'template_name' => $this->sanitizeLogValue($params['template_name'] ?? null, 255),
                'error' => $error
            ]);
        } catch (\Throwable $logException) {
            report($logException);
        }
    }

    private function sanitizeLogValue($value, int $limit): string
    {
        if (!is_scalar($value)) {
            return '[invalid]';
        }

        $value = str_replace(["\r", "\n"], ['\\r', '\\n'], (string) $value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }

        return substr($value, 0, $limit);
    }
}
