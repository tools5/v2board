<?php

/**
 * 将 OAuth 绑定合并到指定本站用户（默认 UID 1）。
 *
 * 规则：
 * - 只处理：v2_oauth_user 标记用户，或邮箱为 *@oauth.local 的占位账号（可用 --source 指定）
 * - 绑定迁入 keep 用户；若 keep 已有同平台绑定，则跳过源绑定（默认不删源绑定、不删源用户）
 * - 默认不删除源用户；仅当显式传入 --delete-source 时才删除已无剩余绑定的源用户
 *
 * 用法：
 *   php scripts/merge_oauth_into_user1.php                    # 预览
 *   php scripts/merge_oauth_into_user1.php --apply            # 只迁移可迁绑定，不删账号
 *   php scripts/merge_oauth_into_user1.php --apply --source=24
 *   php scripts/merge_oauth_into_user1.php --apply --source=24 --delete-source  # 慎用
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\InviteCode;
use App\Models\OauthUser;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\UserOauth;
use App\Services\AuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$apply = in_array('--apply', $argv, true);
$deleteSource = in_array('--delete-source', $argv, true);
$keepUserId = 1;
$onlySourceUserIds = [];

foreach ($argv as $argument) {
    if (strpos($argument, '--keep=') === 0) {
        $keepUserId = max(1, (int)substr($argument, 7));
    }
    if (strpos($argument, '--source=') === 0) {
        $parts = explode(',', substr($argument, 9));
        foreach ($parts as $part) {
            $sourceId = (int)trim($part);
            if ($sourceId > 0) {
                $onlySourceUserIds[] = $sourceId;
            }
        }
    }
}

echo ($apply ? "[APPLY MODE]\n" : "[DRY RUN]\n");
echo "Keep user_id = {$keepUserId}\n";
echo 'Delete source users = ' . ($deleteSource ? 'YES' : 'NO (default)') . "\n";
if (!empty($onlySourceUserIds)) {
    echo 'Only source UIDs: ' . implode(', ', $onlySourceUserIds) . "\n";
}
echo "\n";

if (!Schema::hasTable('v2_user_oauth')) {
    fwrite(STDERR, "v2_user_oauth missing\n");
    exit(1);
}

$keepUser = User::find($keepUserId);
if (!$keepUser) {
    fwrite(STDERR, "User #{$keepUserId} not found\n");
    exit(1);
}

echo "=== Keep user ===\n";
echo sprintf(
    "UID %d | %s | tg=%s | banned=%s | plan=%s\n\n",
    $keepUser->id,
    $keepUser->email,
    $keepUser->telegram_id,
    $keepUser->banned,
    $keepUser->plan_id
);

echo "=== All OAuth bindings ===\n";
$bindings = UserOauth::orderBy('user_id')->orderBy('id')->get();
if ($bindings->isEmpty()) {
    echo "(empty)\n";
}
foreach ($bindings as $binding) {
    echo sprintf(
        "bid=%d user=%d %s ext=%s name=%s email=%s never_pwd=%s\n",
        $binding->id,
        $binding->user_id,
        $binding->provider,
        $binding->provider_user_id,
        $binding->provider_username,
        $binding->provider_email,
        $binding->password_never_set
    );
}
echo 'total bindings: ' . $bindings->count() . "\n\n";

echo "=== oauth_user markers ===\n";
if (Schema::hasTable('v2_oauth_user')) {
    $oauthUsers = OauthUser::orderBy('user_id')->get();
    if ($oauthUsers->isEmpty()) {
        echo "(empty)\n";
    }
    foreach ($oauthUsers as $oauthUser) {
        echo sprintf(
            "ou=%d user=%d %s primary=%s:%s\n",
            $oauthUser->id,
            $oauthUser->user_id,
            $oauthUser->email,
            $oauthUser->primary_provider,
            $oauthUser->primary_provider_user_id
        );
    }
} else {
    echo "(no v2_oauth_user table)\n";
}
echo "\n";

$candidateUserIds = [];
if (!empty($onlySourceUserIds)) {
    $candidateUserIds = $onlySourceUserIds;
} else {
    if (Schema::hasTable('v2_oauth_user')) {
        $candidateUserIds = array_merge(
            $candidateUserIds,
            OauthUser::where('user_id', '!=', $keepUserId)->pluck('user_id')->all()
        );
    }

    $keepEmailLower = strtolower((string)$keepUser->email);
    if ($keepEmailLower !== '' && !preg_match('/@oauth\.local$/i', $keepEmailLower)) {
        $candidateUserIds = array_merge(
            $candidateUserIds,
            UserOauth::whereRaw('LOWER(provider_email) = ?', [$keepEmailLower])
                ->where('user_id', '!=', $keepUserId)
                ->pluck('user_id')
                ->all()
        );
        $candidateUserIds = array_merge(
            $candidateUserIds,
            User::whereRaw('LOWER(email) = ?', [$keepEmailLower])
                ->where('id', '!=', $keepUserId)
                ->pluck('id')
                ->all()
        );
    }

    $candidateUserIds = array_merge(
        $candidateUserIds,
        User::where('email', 'like', '%@oauth.local')
            ->where('id', '!=', $keepUserId)
            ->pluck('id')
            ->all()
    );
}

$candidateUserIds = array_values(array_unique(array_map('intval', $candidateUserIds)));
$candidateUserIds = array_values(array_filter($candidateUserIds, function ($userId) use ($keepUserId) {
    return $userId > 0 && $userId !== $keepUserId;
}));
sort($candidateUserIds);

echo "=== Candidates ===\n";
if (empty($candidateUserIds)) {
    echo "(none)\n";
}
foreach ($candidateUserIds as $candidateUserId) {
    $candidateUser = User::find($candidateUserId);
    if (!$candidateUser) {
        echo "UID {$candidateUserId} (user row missing)\n";
        continue;
    }
    $isOauthManaged = Schema::hasTable('v2_oauth_user')
        && OauthUser::where('user_id', $candidateUserId)->exists();
    $bindingCount = UserOauth::where('user_id', $candidateUserId)->count();
    $orderCount = Schema::hasTable('v2_order') ? Order::where('user_id', $candidateUserId)->count() : 0;
    echo sprintf(
        "UID %d | %s | oauth_managed=%s | bindings=%d | orders=%d | plan=%s\n",
        $candidateUser->id,
        $candidateUser->email,
        $isOauthManaged ? 'yes' : 'no',
        $bindingCount,
        $orderCount,
        $candidateUser->plan_id
    );
    foreach (UserOauth::where('user_id', $candidateUserId)->get() as $userBinding) {
        echo sprintf(
            "  - %s ext=%s email=%s\n",
            $userBinding->provider,
            $userBinding->provider_user_id,
            $userBinding->provider_email
        );
    }
}
echo "\n";

$mergeUserIds = [];
foreach ($candidateUserIds as $candidateUserId) {
    $candidateUser = User::find($candidateUserId);
    if (!$candidateUser) {
        continue;
    }
    if (!empty($onlySourceUserIds)) {
        $mergeUserIds[] = $candidateUserId;
        continue;
    }
    $isOauthManaged = Schema::hasTable('v2_oauth_user')
        && OauthUser::where('user_id', $candidateUserId)->exists();
    $isPlaceholder = (bool)preg_match('/@oauth\.local$/i', (string)$candidateUser->email);
    if ($isOauthManaged || $isPlaceholder) {
        $mergeUserIds[] = $candidateUserId;
    }
}
$mergeUserIds = array_values(array_unique($mergeUserIds));

echo "=== Will process into UID {$keepUserId} ===\n";
echo empty($mergeUserIds) ? "(none)\n" : implode(', ', $mergeUserIds) . "\n";
echo 'Source accounts will ' . ($deleteSource ? 'be DELETED if no bindings left' : 'be KEPT') . "\n\n";

if (!$apply) {
    echo "Dry run only. Re-run with --apply to execute.\n";
    echo "Examples:\n";
    echo "  php scripts/merge_oauth_into_user1.php --apply\n";
    echo "  php scripts/merge_oauth_into_user1.php --apply --source=24\n";
    echo "  # UID 24 不删：不要加 --delete-source\n";
    exit(0);
}

if (empty($mergeUserIds)) {
    echo "Nothing to merge.\n";
    echo "Note: UID {$keepUserId} 上的多个平台绑定无需合并账号，后台列表已按用户聚合显示。\n";
    exit(0);
}

DB::beginTransaction();
try {
    $movedBindings = 0;
    $skippedBindings = 0;
    $deletedUsers = [];
    $notes = [];

    foreach ($mergeUserIds as $sourceUserId) {
        $sourceUser = User::find($sourceUserId);
        if (!$sourceUser) {
            continue;
        }

        $sourceBindings = UserOauth::where('user_id', $sourceUserId)->get();
        foreach ($sourceBindings as $sourceBinding) {
            $sameExternal = UserOauth::where('provider', $sourceBinding->provider)
                ->where('provider_user_id', $sourceBinding->provider_user_id)
                ->where('user_id', $keepUserId)
                ->first();
            if ($sameExternal) {
                // 外部 ID 已在 keep 上：仅在 --delete-source 时清理源重复绑定
                if ($deleteSource) {
                    $sourceBinding->delete();
                    $notes[] = "drop duplicate external {$sourceBinding->provider}:{$sourceBinding->provider_user_id} from UID {$sourceUserId}";
                } else {
                    $skippedBindings++;
                    $notes[] = "skip duplicate external {$sourceBinding->provider}:{$sourceBinding->provider_user_id} on UID {$sourceUserId} (keep source user)";
                }
                continue;
            }

            $providerTaken = UserOauth::where('provider', $sourceBinding->provider)
                ->where('user_id', $keepUserId)
                ->first();
            if ($providerTaken) {
                // keep 已有同平台不同外部 ID：保留双方，不覆盖、不删源
                $skippedBindings++;
                $notes[] = sprintf(
                    'skip conflicting %s on UID %d (source_ext=%s keep_ext=%s) — both accounts kept',
                    $sourceBinding->provider,
                    $sourceUserId,
                    $sourceBinding->provider_user_id,
                    $providerTaken->provider_user_id
                );
                continue;
            }

            $sourceBinding->user_id = $keepUserId;
            if (!preg_match('/@oauth\.local$/i', (string)$keepUser->email)) {
                $sourceBinding->password_never_set = 0;
            }
            $sourceBinding->save();
            $movedBindings++;
            $notes[] = "move {$sourceBinding->provider}:{$sourceBinding->provider_user_id} -> UID {$keepUserId}";
        }

        // 可选：仅迁移订单/邀请到 keep（不删源账号）
        // 默认不自动改 invite/order，避免误伤独立账号；仅 --delete-source 时顺带迁移后删除
        if ($deleteSource) {
            if ($sourceUser->telegram_id && !$keepUser->telegram_id) {
                User::where('telegram_id', $sourceUser->telegram_id)
                    ->where('id', '!=', $keepUserId)
                    ->update(['telegram_id' => null]);
                $keepUser->telegram_id = $sourceUser->telegram_id;
                $keepUser->save();
                $notes[] = "transfer telegram_id {$sourceUser->telegram_id} -> UID {$keepUserId}";
            }

            User::where('invite_user_id', $sourceUserId)->update(['invite_user_id' => $keepUserId]);
            if (Schema::hasTable('v2_order')) {
                Order::where('user_id', $sourceUserId)->update(['user_id' => $keepUserId]);
            }
            if (Schema::hasTable('v2_ticket')) {
                Ticket::where('user_id', $sourceUserId)->update(['user_id' => $keepUserId]);
            }

            $remainingBindings = UserOauth::where('user_id', $sourceUserId)->count();
            if ($remainingBindings > 0) {
                $notes[] = "WARN UID {$sourceUserId} still has {$remainingBindings} binding(s), skip delete";
                continue;
            }

            if (Schema::hasTable('v2_oauth_user')) {
                OauthUser::where('user_id', $sourceUserId)->delete();
            }
            InviteCode::where('user_id', $sourceUserId)->delete();
            if (Schema::hasTable('v2_ticket')) {
                $tickets = Ticket::where('user_id', $sourceUserId)->get();
                foreach ($tickets as $ticket) {
                    TicketMessage::where('ticket_id', $ticket->id)->delete();
                }
                Ticket::where('user_id', $sourceUserId)->delete();
            }
            try {
                (new AuthService($sourceUser))->removeAllSession();
            } catch (Throwable $exception) {
                // ignore
            }
            $sourceUser->delete();
            $deletedUsers[] = $sourceUserId;
            $notes[] = "deleted UID {$sourceUserId}";
        } else {
            $remainingBindings = UserOauth::where('user_id', $sourceUserId)->count();
            $notes[] = "kept UID {$sourceUserId} (remaining bindings={$remainingBindings})";
        }
    }

    DB::commit();

    echo "Done.\n";
    echo "Moved bindings: {$movedBindings}\n";
    echo "Skipped bindings: {$skippedBindings}\n";
    echo 'Deleted users: ' . (empty($deletedUsers) ? '(none)' : implode(', ', $deletedUsers)) . "\n";
    if (!empty($notes)) {
        echo "Notes:\n";
        foreach ($notes as $note) {
            echo "  - {$note}\n";
        }
    }

    echo "\n=== Bindings on UID {$keepUserId} after ===\n";
    foreach (UserOauth::where('user_id', $keepUserId)->orderBy('id')->get() as $finalBinding) {
        echo sprintf(
            "bid=%d %s ext=%s name=%s email=%s\n",
            $finalBinding->id,
            $finalBinding->provider,
            $finalBinding->provider_user_id,
            $finalBinding->provider_username,
            $finalBinding->provider_email
        );
    }
} catch (Throwable $exception) {
    DB::rollBack();
    fwrite(STDERR, 'Merge failed: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    exit(1);
}
