<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const UPLOAD_KEY = 'v2board_upload_traffic';
    private const DOWNLOAD_KEY = 'v2board_download_traffic';
    private const MAX_USER_ID = 2147483647;

    protected $data;
    protected $server;
    protected $protocol;
    protected $jobId;

    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol)
    {
        $this->onQueue('traffic_fetch');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->jobId = (string) Str::uuid();
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $rate = $this->server['rate'] ?? null;
        if (!is_numeric($rate)) {
            throw new \InvalidArgumentException('节点流量倍率无效');
        }
        $rate = (float) $rate;
        if (!is_finite($rate) || $rate < 0) {
            throw new \InvalidArgumentException('节点流量倍率无效');
        }

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

            $upload = $traffic[0] ?? 0;
            $download = $traffic[1] ?? 0;
            if (!is_numeric($upload) || !is_numeric($download)) {
                continue;
            }

            $weightedUpload = max(0, (float) $upload) * $rate;
            $weightedDownload = max(0, (float) $download) * $rate;
            if (!is_finite($weightedUpload) || !is_finite($weightedDownload)) {
                throw new \OverflowException('节点流量数据超出可处理范围');
            }

            if ($weightedUpload == 0.0 && $weightedDownload == 0.0) {
                continue;
            }

            $rows[] = [
                'user_id' => (string) $userId,
                'upload' => $this->formatIncrement($weightedUpload),
                'download' => $this->formatIncrement($weightedDownload)
            ];
        }

        $jobId = $this->resolveJobId();
        foreach (array_chunk($rows, 1000) as $index => $chunk) {
            $this->writeChunk($jobId, $index, $chunk);
        }
    }

    private function writeChunk(string $jobId, int $index, array $rows): void
    {
        $markerKey = 'v2board_traffic_fetch_job:' . hash('sha256', $jobId . ':' . $index);
        $arguments = [];
        foreach ($rows as $row) {
            $arguments[] = $row['user_id'];
            $arguments[] = $row['upload'];
            $arguments[] = $row['download'];
        }

        $script = <<<'LUA'
if redis.call('EXISTS', KEYS[3]) == 1 then
    return 0
end

for i = 1, #ARGV, 3 do
    if tonumber(ARGV[i]) == nil or tonumber(ARGV[i + 1]) == nil or tonumber(ARGV[i + 2]) == nil then
        return redis.error_reply('invalid traffic increment')
    end

    local currentUpload = redis.call('HGET', KEYS[1], ARGV[i])
    local currentDownload = redis.call('HGET', KEYS[2], ARGV[i])
    if (currentUpload and tonumber(currentUpload) == nil) or (currentDownload and tonumber(currentDownload) == nil) then
        return redis.error_reply('invalid existing traffic value')
    end
end

for i = 1, #ARGV, 3 do
    redis.call('HINCRBYFLOAT', KEYS[1], ARGV[i], ARGV[i + 1])
    redis.call('HINCRBYFLOAT', KEYS[2], ARGV[i], ARGV[i + 2])
end

redis.call('SET', KEYS[3], '1', 'EX', 604800)
return 1
LUA;

        Redis::eval(
            $script,
            3,
            self::UPLOAD_KEY,
            self::DOWNLOAD_KEY,
            $markerKey,
            ...$arguments
        );
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
            $queueId
        ]));
    }

    private function formatIncrement(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }
}
