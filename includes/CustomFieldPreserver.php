<?php

defined('ABSPATH') || exit;

use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Task;
use FluentBoardsPro\App\Models\CustomField;
use FluentBoardsPro\App\Services\Constant as ProConstant;
use FluentBoardsPro\App\Services\CustomFieldService;

/**
 * Preserves task custom field values across board moves.
 *
 * Fluent Boards detaches all task_custom_field relations in changeBoardByTask().
 * There is no filter to disable that. This class snapshots values before the wipe
 * and re-applies them on the target board by matching field slug / title.
 */
class NXT_FBS_CustomFieldPreserver
{
	/** @var array<int, array<int, array{slug: string, title: string, value: mixed}>> */
	private static array $snapshots = [];

	public function init(): void
	{
		if (!defined('FLUENT_BOARDS_PRO')) {
			return;
		}

		add_action('fluent_boards/task_custom_field_changed', [$this, 'onFieldChanged'], 10, 5);
		add_action('fluent_boards/task_moved_from_board', [$this, 'onTaskMoved'], 10, 3);
	}

	/**
	 * Keep a live shadow copy whenever a custom field value is saved.
	 *
	 * @param int         $taskId
	 * @param CustomField $customField
	 * @param mixed       $oldValue
	 * @param mixed       $newValue
	 * @param bool        $isNew
	 */
	public function onFieldChanged($taskId, $customField, $oldValue, $newValue, $isNew): void
	{
		$taskId = (int) $taskId;
		if ($taskId <= 0 || !$customField) {
			return;
		}

		self::snapshotTask($taskId);
	}

	/**
	 * Re-apply snapped values onto matching fields of the destination board.
	 *
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
		$fields = self::$snapshots[$taskId] ?? [];

		if ($fields === []) {
			$fields = self::loadPersistedSnapshot($taskId);
		}

		if ($fields === []) {
			return;
		}

		$targetBoardId = (int) ($newBoard->id ?? $task->board_id ?? 0);
		if ($targetBoardId <= 0) {
			return;
		}

		self::restoreFields($taskId, $targetBoardId, $fields);

		unset(self::$snapshots[$taskId]);
		self::deletePersistedSnapshot($taskId);
	}

	/**
	 * Capture current custom field values for a task (call before board move).
	 */
	public static function snapshotTask(int $taskId): void
	{
		if ($taskId <= 0 || !defined('FLUENT_BOARDS_PRO')) {
			return;
		}

		$relations = Relation::where('object_id', $taskId)
			->where('object_type', ProConstant::TASK_CUSTOM_FIELD)
			->get();

		if ($relations->isEmpty()) {
			return;
		}

		$snapshot = [];

		foreach ($relations as $relation) {
			$field = CustomField::find((int) $relation->foreign_id);
			if (!$field) {
				continue;
			}

			$settings = is_array($relation->settings) ? $relation->settings : [];
			if (!array_key_exists('value', $settings)) {
				continue;
			}

			$snapshot[] = [
				'slug'  => (string) ($field->slug ?? ''),
				'title' => (string) ($field->title ?? ''),
				'value' => $settings['value'],
			];
		}

		if ($snapshot === []) {
			return;
		}

		self::$snapshots[$taskId] = $snapshot;
		self::persistSnapshot($taskId, $snapshot);
	}

	/**
	 * @param array<int, array{slug: string, title: string, value: mixed}> $fields
	 */
	private static function restoreFields(int $taskId, int $targetBoardId, array $fields): void
	{
		$targetFields = CustomField::where('board_id', $targetBoardId)->get();
		if ($targetFields->isEmpty()) {
			return;
		}

		$bySlug  = [];
		$byTitle = [];

		foreach ($targetFields as $field) {
			$slug = self::normalizeKey((string) ($field->slug ?? ''));
			if ($slug !== '') {
				$bySlug[$slug] = $field;
			}

			$title = self::normalizeKey((string) ($field->title ?? ''));
			if ($title !== '') {
				$byTitle[$title] = $field;
			}
		}

		$service = new CustomFieldService();

		foreach ($fields as $item) {
			$match = null;
			$slug  = self::normalizeKey($item['slug'] ?? '');
			$title = self::normalizeKey($item['title'] ?? '');

			if ($slug !== '' && isset($bySlug[$slug])) {
				$match = $bySlug[$slug];
			} elseif ($title !== '' && isset($byTitle[$title])) {
				$match = $byTitle[$title];
			}

			if (!$match) {
				continue;
			}

			$value = self::prepareValueForSave($item['value'] ?? null, $match);
			$service->saveCustomFieldDataOfTask($taskId, (int) $match->id, $value);
		}
	}

	/**
	 * CustomFieldService checkbox handling expects string 'true' / 'false'.
	 *
	 * @param mixed       $value
	 * @param CustomField $field
	 * @return mixed
	 */
	private static function prepareValueForSave($value, CustomField $field)
	{
		$fieldType = $field->settings['custom_field_type'] ?? '';

		if ($fieldType === 'checkbox') {
			if (is_bool($value)) {
				return $value ? 'true' : 'false';
			}
			if ($value === 1 || $value === '1' || $value === 'true') {
				return 'true';
			}
			return 'false';
		}

		return $value;
	}

	private static function normalizeKey(string $value): string
	{
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		if (function_exists('mb_strtolower')) {
			return mb_strtolower($value, 'UTF-8');
		}

		return strtolower($value);
	}

	/**
	 * @param array<int, array{slug: string, title: string, value: mixed}> $snapshot
	 */
	private static function persistSnapshot(int $taskId, array $snapshot): void
	{
		set_transient(self::transientKey($taskId), $snapshot, HOUR_IN_SECONDS);
	}

	/**
	 * @return array<int, array{slug: string, title: string, value: mixed}>
	 */
	private static function loadPersistedSnapshot(int $taskId): array
	{
		$data = get_transient(self::transientKey($taskId));
		return is_array($data) ? $data : [];
	}

	private static function deletePersistedSnapshot(int $taskId): void
	{
		delete_transient(self::transientKey($taskId));
	}

	private static function transientKey(int $taskId): string
	{
		return 'nxt_fbs_cf_snap_' . $taskId;
	}
}
