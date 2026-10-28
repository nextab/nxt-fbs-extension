<?php

defined('ABSPATH') || exit;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\TaskService;
use FluentBoards\App\Services\Constant;

/**
 * Führt eine konfigurierte Aktionssequenz für einen Task aus.
 *
 * Unterstützte Aktionstypen:
 *   set_label    – label_ids: int[]  (ersetzt alle bestehenden Labels)
 *   set_priority – priority: 'low'|'medium'|'high'
 *   set_status   – status: 'open'|'closed'
 *   move_board   – board_id: int
 *   set_assignee – user_id: int, mode: 'add'|'remove'
 */
class NXT_FBS_ActionExecutor
{
	/** @var string[] */
	private static array $allowedPriorities = ['low', 'medium', 'high'];

	/** @var string[] */
	private static array $allowedStatuses = ['open', 'closed'];

	/**
	 * Führt alle Aktionen einer Button-Konfiguration sequenziell aus.
	 *
	 * @param  Task  $task
	 * @param  array $actions  Array von Aktions-Objekten aus wp_options
	 * @return array{success: bool, message: string}
	 */
	public static function execute(Task $task, array $actions): array
	{
		if (empty($actions)) {
			return ['success' => false, 'message' => __('Keine Aktionen konfiguriert.', 'nxt-fbs-extension')];
		}

		$errors = [];

		foreach ($actions as $action) {
			$type   = $action['type'] ?? '';
			$result = self::runAction($task, $type, $action);

			if (!$result['success']) {
				$errors[] = $result['message'];
			}

			// Task neu laden, falls Board gewechselt wurde (IDs können sich ändern)
			if ($type === 'move_board' && $result['success']) {
				$task = Task::find($task->id);
				if (!$task) {
					return ['success' => false, 'message' => __('Task nach Board-Wechsel nicht mehr auffindbar.', 'nxt-fbs-extension')];
				}
			}
		}

		if (!empty($errors)) {
			return [
				'success' => false,
				'message' => implode(' | ', $errors),
			];
		}

		return ['success' => true, 'message' => __('Automation erfolgreich ausgeführt.', 'nxt-fbs-extension')];
	}

	/**
	 * @param  Task   $task
	 * @param  string $type
	 * @param  array  $action
	 * @return array{success: bool, message: string}
	 */
	private static function runAction(Task $task, string $type, array $action): array
	{
		switch ($type) {
			case 'set_label':
				return self::setLabel($task, $action);

			case 'set_priority':
				return self::setPriority($task, $action);

			case 'set_status':
				return self::setStatus($task, $action);

			case 'move_board':
				return self::moveBoard($task, $action);

			case 'set_assignee':
				return self::setAssignee($task, $action);

			default:
				return ['success' => false, 'message' => sprintf(__('Unbekannter Aktionstyp: %s', 'nxt-fbs-extension'), esc_html($type))];
		}
	}

	private static function setLabel(Task $task, array $action): array
	{
		$labelIds = array_map('intval', $action['label_ids'] ?? []);

		$task->labels()->detach();

		if (!empty($labelIds)) {
			$board        = Board::find($task->board_id);
			$boardLabelIds = $board ? $board->labels->pluck('id')->toArray() : [];
			$validIds     = array_intersect($labelIds, $boardLabelIds);

			foreach ($validIds as $labelId) {
				$task->labels()->syncWithoutDetaching([
					$labelId => ['object_type' => Constant::OBJECT_TYPE_TASK_LABEL],
				]);
			}

			do_action('fluent_boards/task_label', $task, $validIds, 'attached');
		}

		return ['success' => true, 'message' => ''];
	}

	private static function setPriority(Task $task, array $action): array
	{
		$priority = $action['priority'] ?? '';

		if (!in_array($priority, self::$allowedPriorities, true)) {
			return ['success' => false, 'message' => __('Ungültige Priorität.', 'nxt-fbs-extension')];
		}

		(new TaskService())->updateTaskProperty($task, 'priority', $priority);

		return ['success' => true, 'message' => ''];
	}

	private static function setStatus(Task $task, array $action): array
	{
		$status = $action['status'] ?? '';

		if (!in_array($status, self::$allowedStatuses, true)) {
			return ['success' => false, 'message' => __('Ungültiger Status.', 'nxt-fbs-extension')];
		}

		(new TaskService())->updateTaskProperty($task, 'status', $status);

		return ['success' => true, 'message' => ''];
	}

	private static function moveBoard(Task $task, array $action): array
	{
		$boardId = intval($action['board_id'] ?? 0);

		if (!$boardId) {
			return ['success' => false, 'message' => __('Kein Ziel-Board angegeben.', 'nxt-fbs-extension')];
		}

		try {
			if (class_exists('NXT_FBS_BoardMovePreserver')) {
				NXT_FBS_BoardMovePreserver::snapshotTask((int) $task->id);
			}
			(new TaskService())->changeBoardByTask($task, $boardId);
		} catch (\Exception $e) {
			return ['success' => false, 'message' => $e->getMessage()];
		}

		return ['success' => true, 'message' => ''];
	}

	private static function setAssignee(Task $task, array $action): array
	{
		$userId = intval($action['user_id'] ?? 0);
		$mode   = $action['mode'] ?? 'add';

		if (!$userId) {
			return ['success' => false, 'message' => __('Kein Benutzer angegeben.', 'nxt-fbs-extension')];
		}

		if ($mode === 'add') {
			FluentBoardsApi('tasks')->addAssignees($task->id, [$userId]);
		} else {
			FluentBoardsApi('tasks')->removeAssignees($task->id, [$userId]);
		}

		return ['success' => true, 'message' => ''];
	}
}
