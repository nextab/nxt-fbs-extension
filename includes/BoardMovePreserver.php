<?php

defined('ABSPATH') || exit;

/**
 * Shared pre-move snapshot trigger for board transfers.
 *
 * Fluent Boards wipes board-scoped data inside changeBoardByTask() with no filter.
 * Snapshot must happen before that runs (REST move / bulk move / our automation).
 */
class NXT_FBS_BoardMovePreserver
{
	public function init(): void
	{
		if (!defined('FLUENT_BOARDS_PRO')) {
			return;
		}

		add_filter('rest_pre_dispatch', [$this, 'maybeSnapshotBeforeRestMove'], 5, 3);
		add_filter('rest_request_before_callbacks', [$this, 'maybeSnapshotBeforeRestCallbacks'], 5, 3);
	}

	/**
	 * Snapshot custom fields + time tracks for one task before a board move.
	 */
	public static function snapshotTask(int $taskId): void
	{
		if ($taskId <= 0) {
			return;
		}

		if (class_exists('NXT_FBS_CustomFieldPreserver')) {
			NXT_FBS_CustomFieldPreserver::snapshotTask($taskId);
		}

		if (class_exists('NXT_FBS_TimeTrackingPreserver')) {
			NXT_FBS_TimeTrackingPreserver::snapshotTask($taskId);
		}
	}

	/**
	 * @param mixed                 $response
	 * @param array<string, mixed>  $handler
	 * @param \WP_REST_Request      $request
	 * @return mixed
	 */
	public function maybeSnapshotBeforeRestCallbacks($response, $handler, $request)
	{
		$this->snapshotFromRestRequest($request);
		return $response;
	}

	/**
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public function maybeSnapshotBeforeRestMove($result, $server, $request)
	{
		if ($request instanceof \WP_REST_Request) {
			$this->snapshotFromRestRequest($request);
		}

		return $result;
	}

	private function snapshotFromRestRequest(\WP_REST_Request $request): void
	{
		$route = (string) $request->get_route();
		$uri   = (string) ($_SERVER['REQUEST_URI'] ?? '');

		$isMoveTask = (strpos($route, 'move-task') !== false || strpos($uri, 'move-task') !== false)
			&& (strpos($route, 'fluent-boards') !== false || strpos($uri, 'fluent-boards') !== false);

		if ($isMoveTask) {
			$taskId     = self::intParam($request, 'task_id');
			$newBoardId = self::intParam($request, 'newBoardId');

			if ($taskId <= 0 && preg_match('#/tasks/(\d+)/move-task#', $uri, $m)) {
				$taskId = (int) $m[1];
			}

			if ($newBoardId > 0 && $taskId > 0) {
				self::snapshotTask($taskId);
			}
			return;
		}

		$isBulk = (strpos($route, 'bulk-actions') !== false || strpos($uri, 'bulk-actions') !== false)
			&& (strpos($route, 'fluent-boards') !== false || strpos($uri, 'fluent-boards') !== false);

		if (!$isBulk) {
			return;
		}

		$action        = (string) (self::rawParam($request, 'action') ?? '');
		$targetBoardId = self::intParam($request, 'target_board_id');
		$boardId       = self::intParam($request, 'board_id');
		$taskIds       = self::rawParam($request, 'task_ids');

		$isCrossBoardMove = in_array($action, ['move_tasks', 'move_to_stage'], true)
			&& $targetBoardId > 0
			&& $targetBoardId !== $boardId;

		if ($isCrossBoardMove && is_array($taskIds)) {
			foreach ($taskIds as $taskId) {
				self::snapshotTask((int) $taskId);
			}
		}
	}

	private static function intParam(\WP_REST_Request $request, string $key): int
	{
		$value = self::rawParam($request, $key);
		return (int) ($value ?? 0);
	}

	/**
	 * @return mixed
	 */
	private static function rawParam(\WP_REST_Request $request, string $key)
	{
		$value = $request->get_param($key);
		if ($value !== null && $value !== '') {
			return $value;
		}

		$json = $request->get_json_params();
		if (is_array($json) && array_key_exists($key, $json)) {
			return $json[$key];
		}

		$urlParams = $request->get_url_params();
		if (is_array($urlParams) && array_key_exists($key, $urlParams)) {
			return $urlParams[$key];
		}

		$bodyParams = $request->get_body_params();
		if (is_array($bodyParams) && array_key_exists($key, $bodyParams)) {
			return $bodyParams[$key];
		}

		return null;
	}
}
