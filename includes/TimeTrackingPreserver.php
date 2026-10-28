<?php

defined('ABSPATH') || exit;

use FluentBoards\App\Models\Task;

/**
 * Preserves Fluent Boards Pro time tracks across board moves.
 *
 * Core hard-deletes fbs_time_tracks in changeBoardByTask() (query-builder delete,
 * no model events). Same pattern as custom fields: keep a live shadow on save,
 * restore after fluent_boards/task_moved_from_board.
 */
class NXT_FBS_TimeTrackingPreserver
{
	/** @var array<int, array<int, array<string, mixed>>> */
	private static array $snapshots = [];

	/** @var bool */
	private static bool $restoring = false;

	public function init(): void
	{
		if (!defined('FLUENT_BOARDS_PRO')) {
			return;
		}

		add_action('fluent_boards/task_moved_from_board', [$this, 'onTaskMoved'], 10, 3);
		add_action('init', [$this, 'registerModelHooks'], 20);
	}

	public function registerModelHooks(): void
	{
		$class = self::timeTrackClass();
		if (!$class) {
			return;
		}

		// Live shadow — same idea as fluent_boards/task_custom_field_changed.
		$class::saved(static function ($track) {
			if (self::$restoring) {
				return;
			}
			$taskId = (int) ($track->task_id ?? 0);
			if ($taskId > 0) {
				self::snapshotTask($taskId);
			}
		});

		$class::deleted(static function ($track) {
			if (self::$restoring) {
				return;
			}
			$taskId = (int) ($track->task_id ?? 0);
			if ($taskId > 0) {
				self::snapshotTask($taskId);
			}
		});
	}

	/**
	 * @param Task  $task
	 * @param mixed $oldBoard
	 * @param mixed $newBoard
	 */
	public function onTaskMoved($task, $oldBoard, $newBoard): void
	{
		if (!$task || !isset($task->id)) {
			return;
		}

		$taskId = (int) $task->id;
		$tracks = self::$snapshots[$taskId] ?? [];

		if ($tracks === []) {
			$tracks = self::loadPersistedSnapshot($taskId);
		}

		if ($tracks === []) {
			return;
		}

		$targetBoardId = (int) ($newBoard->id ?? $task->board_id ?? 0);
		if ($targetBoardId <= 0) {
			return;
		}

		self::$restoring = true;
		try {
			self::restoreTracks($taskId, $targetBoardId, $tracks);
			self::snapshotTask($taskId);
		} finally {
			self::$restoring = false;
		}

		unset(self::$snapshots[$taskId]);
	}

	public static function snapshotTask(int $taskId): void
	{
		if ($taskId <= 0) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'fbs_time_tracks';
		$rows  = $wpdb->get_results(
			$wpdb->prepare("SELECT * FROM {$table} WHERE task_id = %d", $taskId),
			ARRAY_A
		);

		if (!is_array($rows) || $rows === []) {
			self::deletePersistedSnapshot($taskId);
			unset(self::$snapshots[$taskId]);
			return;
		}

		$snapshot = [];

		foreach ($rows as $track) {
			$snapshot[] = self::normalizeTrackRow([
				'user_id'          => (int) ($track['user_id'] ?? 0),
				'started_at'       => $track['started_at'] ?? null,
				'completed_at'     => $track['completed_at'] ?? null,
				'status'           => (string) ($track['status'] ?? 'commited'),
				'working_minutes'  => (int) ($track['working_minutes'] ?? 0),
				'billable_minutes' => (int) ($track['billable_minutes'] ?? 0),
				'is_manual'        => (int) ($track['is_manual'] ?? 0),
				'message'          => $track['message'] ?? null,
				'created_at'       => $track['created_at'] ?? null,
				'updated_at'       => $track['updated_at'] ?? null,
			]);
		}

		self::$snapshots[$taskId] = $snapshot;
		self::persistSnapshot($taskId, $snapshot);
	}

	/**
	 * @param array<int, array<string, mixed>> $tracks
	 */
	private static function restoreTracks(int $taskId, int $targetBoardId, array $tracks): void
	{
		global $wpdb;

		$table = $wpdb->prefix . 'fbs_time_tracks';
		$now   = current_time('mysql');

		foreach ($tracks as $row) {
			$row = self::normalizeTrackRow($row);

			$wpdb->insert(
				$table,
				[
					'user_id'          => (int) ($row['user_id'] ?? 0),
					'board_id'         => $targetBoardId,
					'task_id'          => $taskId,
					'started_at'       => $row['started_at'] ?? null,
					'completed_at'     => $row['completed_at'] ?? null,
					'status'           => (string) ($row['status'] ?? 'commited'),
					'working_minutes'  => (int) ($row['working_minutes'] ?? 0),
					'billable_minutes' => (int) ($row['billable_minutes'] ?? 0),
					'is_manual'        => (int) ($row['is_manual'] ?? 0),
					'message'          => $row['message'] ?? null,
					'created_at'       => $row['created_at'] ?? $now,
					'updated_at'       => $row['updated_at'] ?? $now,
				],
				[
					'%d',
					'%d',
					'%d',
					'%s',
					'%s',
					'%s',
					'%d',
					'%d',
					'%d',
					'%s',
					'%s',
					'%s',
				]
			);
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function normalizeTrackRow(array $row): array
	{
		foreach (['started_at', 'completed_at', 'created_at', 'updated_at'] as $key) {
			if (!array_key_exists($key, $row)) {
				continue;
			}
			$row[$key] = self::stringifyDate($row[$key]);
		}

		if (isset($row['message']) && !is_string($row['message']) && $row['message'] !== null) {
			$row['message'] = (string) $row['message'];
		}

		return $row;
	}

	/**
	 * @param mixed $value
	 */
	private static function stringifyDate($value): ?string
	{
		if ($value === null || $value === '') {
			return null;
		}

		if ($value instanceof \DateTimeInterface) {
			return $value->format('Y-m-d H:i:s');
		}

		if (is_object($value) && method_exists($value, 'format')) {
			return (string) $value->format('Y-m-d H:i:s');
		}

		return (string) $value;
	}

	/**
	 * @return class-string|null
	 */
	private static function timeTrackClass(): ?string
	{
		$class = 'FluentBoardsPro\\App\\Modules\\TimeTracking\\Model\\TimeTrack';
		return class_exists($class) ? $class : null;
	}

	/**
	 * Durable shadow (not transient) — must survive until board move, possibly days later.
	 *
	 * @param array<int, array<string, mixed>> $snapshot
	 */
	private static function persistSnapshot(int $taskId, array $snapshot): void
	{
		update_option(self::optionKey($taskId), $snapshot, false);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function loadPersistedSnapshot(int $taskId): array
	{
		$data = get_option(self::optionKey($taskId), []);
		return is_array($data) ? $data : [];
	}

	private static function deletePersistedSnapshot(int $taskId): void
	{
		delete_option(self::optionKey($taskId));
	}

	private static function optionKey(int $taskId): string
	{
		return 'nxt_fbs_tt_shadow_' . $taskId;
	}
}
