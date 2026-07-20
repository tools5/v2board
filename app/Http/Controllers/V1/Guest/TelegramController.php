<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    protected $msg;
    protected $commands = [];
    protected $telegramService;

    public function __construct()
    {
        // Do not validate access_token in constructor.
        // Route registration / route:list must not abort during controller resolution.
    }

    public function webhook(Request $request)
    {
        if (!$this->isAuthorizedWebhookRequest($request)) {
            abort(401);
        }

        $this->telegramService = new TelegramService();
        $payload = $request->input();
        $this->formatMessage($payload);
        $this->formatChatJoinRequest($payload);
        $this->handle();

        return response()->noContent();
    }

    private function isAuthorizedWebhookRequest(Request $request): bool
    {
        $secret = trim((string)config('v2board.telegram_webhook_secret', ''));
        if ($secret !== '') {
            $received = (string)$request->header('X-Telegram-Bot-Api-Secret-Token', '');
            return $received !== '' && hash_equals($secret, $received);
        }

        // Backward compatibility for existing webhook URLs. It is disabled once a
        // Telegram secret token has been provisioned by the admin endpoint.
        $botToken = trim((string)config('v2board.telegram_bot_token', ''));
        $legacyToken = (string)$request->query('access_token', '');
        return $botToken !== ''
            && $legacyToken !== ''
            && hash_equals(md5($botToken), $legacyToken);
    }

    public function handle()
    {
        if (!$this->msg) return;
        $msg = $this->msg;
        $commandName = explode('@', $msg->command);

        // To reduce request, only commands contains @ will get the bot name
        if (count($commandName) == 2) {
            $botName = $this->getBotName();
            if ($commandName[1] === $botName){
                $msg->command = $commandName[0];
            }
        }

        try {
            foreach (glob(base_path('app//Plugins//Telegram//Commands') . '/*.php') as $file) {
                $command = basename($file, '.php');
                $class = '\\App\\Plugins\\Telegram\\Commands\\' . $command;
                if (!class_exists($class)) continue;
                $instance = new $class();
                if ($msg->message_type === 'message') {
                    if (!isset($instance->command)) continue;
                    if ($msg->command !== $instance->command) continue;
                    $instance->handle($msg);
                    return;
                }
                if ($msg->message_type === 'reply_message') {
                    if (!isset($instance->regex)) continue;
                    if (!preg_match($instance->regex, $msg->reply_text, $match)) continue;
                    $instance->handle($msg, $match);
                    return;
                }
            }
        } catch (\Throwable $e) {
            $this->telegramService->sendMessage($msg->chat_id, $e->getMessage());
        }
    }

    public function getBotName()
    {
        $response = $this->telegramService->getMe();
        return $response->result->username;
    }

    private function formatMessage(array $data)
    {
        if (!isset($data['message']) || !is_array($data['message'])) return;
        $message = $data['message'];
        if (!isset($message['text'], $message['chat']['id'], $message['chat']['type'], $message['message_id'])) return;
        if (!is_string($message['text'])) return;

        $obj = new \StdClass();
        $text = explode(' ', $message['text']);
        $obj->command = $text[0];
        $obj->args = array_slice($text, 1);
        $obj->chat_id = $message['chat']['id'];
        $obj->message_id = $message['message_id'];
        $obj->message_type = 'message';
        $obj->text = $message['text'];
        $obj->is_private = $message['chat']['type'] === 'private';
        if (isset($message['reply_to_message']['text']) && is_string($message['reply_to_message']['text'])) {
            $obj->message_type = 'reply_message';
            $obj->reply_text = $message['reply_to_message']['text'];
        }
        $this->msg = $obj;
    }

    private function formatChatJoinRequest(array $data)
    {
        if (!isset($data['chat_join_request'])) return;
        if (!isset($data['chat_join_request']['from']['id'])) return;
        if (!isset($data['chat_join_request']['chat']['id'])) return;
        $user = \App\Models\User::where('telegram_id', $data['chat_join_request']['from']['id'])
            ->first();
        if (!$user) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );
            return;
        }
        $userService = new \App\Services\UserService();
        if (!$userService->isAvailable($user)) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );
            return;
        }
        $userService = new \App\Services\UserService();
        $this->telegramService->approveChatJoinRequest(
            $data['chat_join_request']['chat']['id'],
            $data['chat_join_request']['from']['id']
        );
    }
}
