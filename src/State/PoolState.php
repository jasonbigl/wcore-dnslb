<?php

namespace Wcore\Dnslb\State;

/**
 * 池级别的运行状态（冷却、阈值持续时间、整池故障、暂停、发布进度）。
 */
final class PoolState
{
    public string $pool;
    public ?int $lastScaleOutAt = null;
    public ?int $lastScaleInAt = null;
    /** 指标开始持续高于扩容阈值的时间 */
    public ?int $outBreachSince = null;
    /** 指标开始持续低于缩容阈值的时间 */
    public ?int $inBreachSince = null;
    /** 整池 IPv4 不可用的开始时间 */
    public ?int $v4DownSince = null;
    public ?int $v6DownSince = null;
    /** 自动暂停（连续预热失败）截止时间 */
    public ?int $pausedUntil = null;
    /** 手动暂停（pause 命令）：停止所有自动增删机器（补足 min、削减到 max、扩缩容、自动修复） */
    public bool $manualPaused = false;
    /** 连续预热失败的节点数 */
    public int $warmupFailures = 0;
    /** 进行中的发布的开始时间；null 表示没有发布 */
    public ?int $rolloutStartedAt = null;
    /** 发布时每次同时替换的节点数 */
    public int $rolloutBatch = 1;
    /** 发布开始时标记为旧版本的节点数 */
    public int $rolloutTotal = 0;

    public function __construct(string $pool)
    {
        $this->pool = $pool;
    }

    public function downSince(string $family): ?int
    {
        return $family === Node::FAMILY_V6 ? $this->v6DownSince : $this->v4DownSince;
    }

    public function setDownSince(string $family, ?int $since): void
    {
        if ($family === Node::FAMILY_V6) {
            $this->v6DownSince = $since;
        } else {
            $this->v4DownSince = $since;
        }
    }

    /**
     * 是否暂停建机：手动暂停，或连续启动失败后的自动暂停。
     */
    public function isPaused(int $now): bool
    {
        return $this->manualPaused || ($this->pausedUntil !== null && $this->pausedUntil > $now);
    }

    public static function fromRow(array $row): self
    {
        $state = new self($row['pool']);
        foreach ([
            'last_scale_out_at' => 'lastScaleOutAt',
            'last_scale_in_at' => 'lastScaleInAt',
            'out_breach_since' => 'outBreachSince',
            'in_breach_since' => 'inBreachSince',
            'v4_down_since' => 'v4DownSince',
            'v6_down_since' => 'v6DownSince',
            'paused_until' => 'pausedUntil',
            'rollout_started_at' => 'rolloutStartedAt',
        ] as $column => $property) {
            $state->{$property} = $row[$column] === null ? null : (int) $row[$column];
        }
        $state->manualPaused = (bool) $row['manual_paused'];
        $state->warmupFailures = (int) $row['warmup_failures'];
        $state->rolloutBatch = (int) $row['rollout_batch'];
        $state->rolloutTotal = (int) $row['rollout_total'];
        return $state;
    }

    public function toRow(): array
    {
        return [
            'pool' => $this->pool,
            'last_scale_out_at' => $this->lastScaleOutAt,
            'last_scale_in_at' => $this->lastScaleInAt,
            'out_breach_since' => $this->outBreachSince,
            'in_breach_since' => $this->inBreachSince,
            'v4_down_since' => $this->v4DownSince,
            'v6_down_since' => $this->v6DownSince,
            'paused_until' => $this->pausedUntil,
            'manual_paused' => $this->manualPaused ? 1 : 0,
            'warmup_failures' => $this->warmupFailures,
            'rollout_started_at' => $this->rolloutStartedAt,
            'rollout_batch' => $this->rolloutBatch,
            'rollout_total' => $this->rolloutTotal,
        ];
    }
}
