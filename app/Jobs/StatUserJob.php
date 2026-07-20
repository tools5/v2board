<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StatUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const IDEMPOTENCY_SCOPE = 'stat_user';
    private const MAX_SERVER_RATE = 99999999.99;
    private const MAX_USER_ID = 2147483647;

    protected $data;
    protected $server;
    protected $protocol;
    protected $recordType;
    protected $jobId;

    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol, $recordType = 'd')
    {
        $this->onQueue('stat');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
        $this->jobId = (string) Str::uuid();
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $serverRate = $this->server['rate'] ?? null;
        if (!is_numeric($serverRate)) {
            throw new \InvalidArgumentException('节点统计倍率无效');
        }
        $serverRate = (float) $serverRate;
        if (!is_finite($serverRate) || $serverRate < 0 || $serverRate > self::MAX_SERVER_RATE) {
            throw new \InvalidArgumentException('节点统计倍率无效');
        }

        $recordType = $this->recordType === 'm' ? 'm' : 'd';
        $recordAt = $recordType === 'm'
            ? strtotime(date('Y-m-01'))
            : strtotime(date('Y-m-d'));
        $rows = $this->normalizeRows();
        $jobId = $this->resolveJobId();
        $now = time();

        DB::transaction(function () use ($jobId, $rows, $serverRate, $recordType, $recordAt, $now) {
            $inserted = DB::table('v2_job_idempotency')->insertOrIgnore([
                'scope' => self::IDEMPOTENCY_SCOPE,
                'job_id' => $jobId,
                'created_at' => $now
            ]);

            if (!$inserted) {
                return;
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                $values = [];
                $bindings = [];
                foreach ($chunk as $row) {
                    $values[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                    array_push(
                        $bindings,
                        $row['user_id'],
                        $serverRate,
                        $row['upload'],
                        $row['download'],
                        $recordType,
                        $recordAt,
                        $now,
                        $now
                    );
                }

                DB::statement(
                    'INSERT INTO v2_stat_user '
                    . '(user_id, server_rate, u, d, record_type, record_at, created_at, updated_at) VALUES '
                    . implode(',', $values)
                    . ' ON DUPLICATE KEY UPDATE '
                    . 'u = u + VALUES(u), d = d + VALUES(d), updated_at = VALUES(updated_at)',
                    $bindings
                );
            }
        }, 3);

        DB::table('v2_job_idempotency')
            ->where('scope', self::IDEMPOTENCY_SCOPE)
            ->where('created_at', '<', time() - 604800)
            ->delete();
    }

    private function normalizeRows(): array
    {
        $rows = [];
        foreach ($this->data as $userId => $traffic) {
            if (
                !ctype_digit((string) $userId)
                || (int) $userId <= 0
                || (float) $userId > self::MAX_USER_ID
                || !is_array($traffic)
            ) {
                continue;
            }

            $upload = $this->normalizeBytes($traffic[0] ?? 0);
            $download = $this->normalizeBytes($traffic[1] ?? 0);
            if ($upload === 0 && $download === 0) {
                continue;
            }

            $rows[] = [
                'user_id' => (int) $userId,
                'upload' => $upload,
                'download' => $download
            ];
        }

        return $rows;
    }

    private function normalizeBytes($value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        $value = (float) $value;
        if (!is_finite($value) || $value <= 0) {
            return 0;
        }

        return (int) min(PHP_INT_MAX, round($value));
    }

    private function resolveJobId(): string
    {
        if (!empty($this->jobId)) {
            return (string) $this->jobId;
        }

        $queueId = null;
        if ($this->job) {
            try {
                $payload = $this->job->payload();
                $queueId = $payload['uuid'] ?? $this->job->getJobId();
            } catch (\Throwable $e) {
                $queueId = null;
            }
        }

        return hash('sha256', serialize([
            $this->data,
            $this->server,
            $this->protocol,
            $this->recordType,
            $queueId
        ]));
    }
}
