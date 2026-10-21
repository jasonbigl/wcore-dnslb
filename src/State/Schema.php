<?php

namespace Wcore\Dnslb\State;

/**
 * 表结构迁移。只能在末尾追加新版本，不能修改已发布的版本。
 * 版本 1 是首次发布时的完整结构，使用 IF NOT EXISTS，重复执行不会出错。
 */
final class Schema
{
    /**
     * @return string[][] 第 N 项是升级到版本 N+1 要执行的 SQL
     */
    public static function migrations(): array
    {
        return [
            [
                'CREATE TABLE IF NOT EXISTS nodes (
                    node_id TEXT PRIMARY KEY,
                    pool TEXT NOT NULL,
                    state TEXT NOT NULL,
                    provider TEXT NOT NULL,
                    provider_region TEXT NOT NULL,
                    launch_index INTEGER NOT NULL,
                    launch_started_at INTEGER,
                    weight INTEGER NOT NULL,
                    instance_id TEXT,
                    ipv4 TEXT,
                    ipv6 TEXT,
                    v4_healthy INTEGER NOT NULL DEFAULT 0,
                    v4_fails INTEGER NOT NULL DEFAULT 0,
                    v4_passes INTEGER NOT NULL DEFAULT 0,
                    v4_since INTEGER,
                    v4_error TEXT,
                    v6_healthy INTEGER NOT NULL DEFAULT 0,
                    v6_fails INTEGER NOT NULL DEFAULT 0,
                    v6_passes INTEGER NOT NULL DEFAULT 0,
                    v6_since INTEGER,
                    v6_error TEXT,
                    probed_at INTEGER,
                    replacement_for TEXT,
                    replaced_by TEXT,
                    replace_manual INTEGER NOT NULL DEFAULT 0,
                    outdated INTEGER NOT NULL DEFAULT 0,
                    dns_removed_at INTEGER,
                    last_error TEXT,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    state_changed_at INTEGER NOT NULL
                )',
                'CREATE INDEX IF NOT EXISTS nodes_pool_state ON nodes (pool, state)',
                'CREATE INDEX IF NOT EXISTS nodes_state ON nodes (state)',
                'CREATE TABLE IF NOT EXISTS pools (
                    pool TEXT PRIMARY KEY,
                    last_scale_out_at INTEGER,
                    last_scale_in_at INTEGER,
                    out_breach_since INTEGER,
                    in_breach_since INTEGER,
                    v4_down_since INTEGER,
                    v6_down_since INTEGER,
                    paused_until INTEGER,
                    manual_paused INTEGER NOT NULL DEFAULT 0,
                    warmup_failures INTEGER NOT NULL DEFAULT 0,
                    rollout_started_at INTEGER,
                    rollout_batch INTEGER NOT NULL DEFAULT 1,
                    rollout_total INTEGER NOT NULL DEFAULT 0
                )',
                'CREATE TABLE IF NOT EXISTS events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    at INTEGER NOT NULL,
                    level TEXT NOT NULL,
                    type TEXT NOT NULL,
                    pool TEXT,
                    node_id TEXT,
                    message TEXT NOT NULL,
                    context TEXT,
                    notified INTEGER NOT NULL DEFAULT 0
                )',
                'CREATE INDEX IF NOT EXISTS events_notified ON events (notified, at)',
                'CREATE INDEX IF NOT EXISTS events_node ON events (node_id, at)',
                'CREATE TABLE IF NOT EXISTS alerts_sent (
                    alert_key TEXT PRIMARY KEY,
                    last_sent_at INTEGER NOT NULL
                )',
                'CREATE TABLE IF NOT EXISTS meta (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                )',
                // 云上带本服务标签、但没有节点引用的实例（见 Lifecycle\OrphanTracker）
                'CREATE TABLE IF NOT EXISTS orphans (
                    provider TEXT NOT NULL,
                    instance_id TEXT NOT NULL,
                    region TEXT NOT NULL,
                    node_id TEXT,
                    first_seen_at INTEGER NOT NULL,
                    reported_at INTEGER,
                    PRIMARY KEY (provider, instance_id)
                )',
            ],
        ];
    }
}
