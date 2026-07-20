<?php
namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use \Curl\Curl;

class TelegramService {
    protected $api;
    protected $token;

    public function __construct($token = '')
    {
        $token = trim((string)$token);
        $this->token = $token !== ''
            ? $token
            : trim((string)config('v2board.telegram_bot_token', ''));
        $this->api = 'https://api.telegram.org/bot' . $this->token . '/';
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = '')
    {
        if (strtolower($parseMode) === 'markdown') {
            $text = $this->escapeMarkdownV2($text);
            $parseMode = 'MarkdownV2';
        }

        return $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode
        ]);
    }

    public function approveChatJoinRequest(int $chatId, int $userId)
    {
        $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function declineChatJoinRequest(int $chatId, int $userId)
    {
        $this->request('declineChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function getMe()
    {
        return $this->request('getMe');
    }

    public function setWebhook(string $url, string $secretToken = '')
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])) {
            throw new \InvalidArgumentException('Telegram webhook URL must use HTTPS');
        }
        if ($secretToken !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,256}\z/', $secretToken)) {
            throw new \InvalidArgumentException('Telegram webhook secret token is invalid');
        }

        $commands = $this->discoverCommands(base_path('app/Plugins/Telegram/Commands'));
        $this->setMyCommands($commands);

        $params = ['url' => $url];
        if ($secretToken !== '') {
            $params['secret_token'] = $secretToken;
        }

        return $this->request('setWebhook', $params);
    }

    public function discoverCommands(string $directory): array
    {
        $commands = [];

        foreach (glob($directory . '/*.php') as $file) {
            $className = 'App\\Plugins\\Telegram\\Commands\\' . basename($file, '.php');

            if (!class_exists($className)) {
                require_once $file;
            }

            if (!class_exists($className)) {
                continue;
            }

            try {
                $ref = new \ReflectionClass($className);

                if (
                    $ref->hasProperty('command') &&
                    $ref->hasProperty('description')
                ) {
                    $commandProp = $ref->getProperty('command');
                    $descProp = $ref->getProperty('description');

                    $command = $commandProp->isStatic()
                        ? $commandProp->getValue()
                        : $ref->newInstanceWithoutConstructor()->command;

                    $description = $descProp->isStatic()
                        ? $descProp->getValue()
                        : $ref->newInstanceWithoutConstructor()->description;

                    $commands[] = [
                        'command' => $command,
                        'description' => $description,
                    ];
                }
            } catch (\ReflectionException $e) {
                continue;
            }
        }
        return $commands;
    }
    
    public function setMyCommands(array $commands)
    {
        $this->request('setMyCommands', [
            'commands' => array_values($commands),
        ]);
    }

    private function request(string $method, array $params = [])
    {
        if (!preg_match('/\A[0-9]{5,20}:[A-Za-z0-9_-]{20,}\z/', $this->token)) {
            throw new \RuntimeException('Telegram bot token is not configured or invalid');
        }
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/', $method)) {
            throw new \InvalidArgumentException('Invalid Telegram API method');
        }

        $payload = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \InvalidArgumentException('Telegram request contains invalid data');
        }

        $curl = new Curl();
        $curl->setConnectTimeout(5);
        $curl->setTimeout(15);
        $curl->setHeader('Content-Type', 'application/json');
        $curl->setOpt(CURLOPT_FOLLOWLOCATION, false);

        try {
            $curl->post($this->api . $method, $payload);
            $response = $curl->response;
            $error = $curl->error;
            $errorMessage = $curl->errorMessage;
        } finally {
            $curl->close();
        }

        if (is_string($response)) {
            $response = json_decode($response);
        }
        if (is_object($response) && property_exists($response, 'ok') && $response->ok !== true) {
            $description = property_exists($response, 'description')
                ? $response->description
                : 'unknown error';
            throw new \RuntimeException('Telegram rejected the request: ' . $this->safeError($description));
        }
        if ($error) {
            throw new \RuntimeException('Telegram request failed: ' . $this->safeError($errorMessage));
        }
        if (!is_object($response) || !property_exists($response, 'ok') || $response->ok !== true) {
            throw new \RuntimeException('Telegram returned an invalid response');
        }

        return $response;
    }

    private function escapeMarkdownV2(string $text): string
    {
        return preg_replace('/([_*\[\]()~`>#+\-=|{}.!\\\\])/', '\\\\$1', $text);
    }

    private function safeError($message): string
    {
        $message = str_replace($this->token, '[redacted]', (string)$message);
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message);
        return function_exists('mb_substr')
            ? mb_substr($message, 0, 300, 'UTF-8')
            : substr($message, 0, 300);
    }

    public function sendMessageWithAdmin($message, $isStaff = false)
    {
        if (!config('v2board.telegram_bot_enable', 0)) return;
        $users = User::where(function ($query) use ($isStaff) {
            $query->where('is_admin', 1);
            if ($isStaff) {
                $query->orWhere('is_staff', 1);
            }
        })
            ->where('telegram_id', '!=', NULL)
            ->get();
        foreach ($users as $user) {
            SendTelegramJob::dispatch($user->telegram_id, $message);
        }
    }
}
