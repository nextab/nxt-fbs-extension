<?php

defined('ABSPATH') || exit;

use FluentBoards\App\Models\Label;
use FluentBoards\App\Modules\MCP\Helpers\MCPHelper;
use FluentBoards\App\Modules\MCP\Tools\BoardTools;
use FluentBoards\App\Modules\MCP\Tools\TaskTools;
use FluentBoards\App\Services\Constant;

/**
 * Extends Fluent Boards MCP create-task / update-task with label support.
 *
 * Core Fluent Boards MCP omits labels on write tools. This override unregisters
 * the stock abilities and re-registers them with labels in the schema, then
 * applies labels after the core handlers run — without patching Fluent Boards.
 */
class NXT_FBS_TaskLabelsMCP
{
	public function init(): void
	{
		add_action('init', [$this, 'boot'], 4);
	}

	public function boot(): void
	{
		if (!function_exists('wp_register_ability')) {
			return;
		}

		if (function_exists('fluent_boards_get_option') && fluent_boards_get_option('mcp_enabled', 'yes') !== 'yes') {
			return;
		}

		if (!class_exists(TaskTools::class) || !class_exists(MCPHelper::class)) {
			return;
		}

		add_action('fluent_boards/mcp_loaded', [$this, 'overrideTaskAbilities'], 20);
	}

	public function overrideTaskAbilities(): void
	{
		$this->registerCreateTask();
		$this->registerUpdateTask();

		if (class_exists('\FluentBoards\App\Modules\MCP\Tools\ContextTools')
			&& method_exists('\FluentBoards\App\Modules\MCP\Tools\ContextTools', 'invalidateCache')
		) {
			\FluentBoards\App\Modules\MCP\Tools\ContextTools::invalidateCache();
		}
	}

	private function registerCreateTask(): void
	{
		if (function_exists('wp_unregister_ability')) {
			wp_unregister_ability('fluent-boards/create-task');
		}

		wp_register_ability('fluent-boards/create-task', [
			'label'       => __('Create Task', 'nxt-fbs-extension'),
			'description' => __('Create a task in a board stage. Optional labels: board label ids, titles, or slugs (via get-board). Fires native Fluent Boards task-created hooks.', 'nxt-fbs-extension'),
			'category'    => 'fluent-boards',
			'input_schema'=> [
				'type'       => 'object',
				'properties' => [
					'board_id'       => ['type' => 'integer'],
					'stage_id'       => ['type' => 'integer'],
					'title'          => ['type' => 'string'],
					'description'    => ['type' => 'string'],
					'priority'       => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
					'due_at'         => ['type' => 'string', 'description' => 'Date/time parseable by WordPress. Site timezone.'],
					'started_at'     => ['type' => 'string', 'description' => 'Date/time parseable by WordPress. Site timezone.'],
					'crm_contact_id' => ['type' => 'integer'],
					'labels'         => [
						'type'        => 'array',
						'items'       => ['type' => ['string', 'integer']],
						'description' => 'Board label ids, titles, or slugs. Discover via get-board.',
					],
				],
				'required' => ['board_id', 'stage_id', 'title'],
			],
			'execute_callback'    => [$this, 'createTask'],
			'permission_callback' => static function ($params = []) {
				return BoardTools::canWriteBoard($params);
			},
			'meta' => [
				'show_in_rest' => true,
				'mcp'          => ['public' => true],
			],
		]);
	}

	private function registerUpdateTask(): void
	{
		if (function_exists('wp_unregister_ability')) {
			wp_unregister_ability('fluent-boards/update-task');
		}

		wp_register_ability('fluent-boards/update-task', [
			'label'       => __('Update Task', 'nxt-fbs-extension'),
			'description' => __('Update task fields: title, description, status, priority, due_at, started_at, assignees, labels, crm_contact_id, or settings. Passing labels replaces the full label set; pass [] to clear.', 'nxt-fbs-extension'),
			'category'    => 'fluent-boards',
			'input_schema'=> [
				'type'       => 'object',
				'properties' => [
					'board_id'       => ['type' => 'integer'],
					'task_id'        => ['type' => 'integer'],
					'title'          => ['type' => 'string'],
					'description'    => ['type' => 'string'],
					'status'         => ['type' => 'string', 'enum' => ['open', 'closed']],
					'priority'       => ['type' => 'string', 'enum' => ['low', 'medium', 'high'], 'description' => 'Omit to leave unchanged. Pass an empty string to clear.'],
					'due_at'         => ['type' => 'string', 'description' => 'Omit to leave unchanged. Pass an empty string to clear.'],
					'started_at'     => ['type' => 'string', 'description' => 'Omit to leave unchanged. Pass an empty string to clear.'],
					'assignees'      => ['type' => 'array', 'items' => ['type' => 'integer']],
					'labels'         => [
						'type'        => 'array',
						'items'       => ['type' => ['string', 'integer']],
						'description' => 'Replace task labels with this set. Board label ids, titles, or slugs. Pass [] to clear all labels.',
					],
					'crm_contact_id' => ['type' => 'integer', 'description' => 'Omit to leave unchanged. Pass 0 to clear.'],
					'settings'       => ['type' => 'object'],
				],
				'required' => ['board_id', 'task_id'],
			],
			'execute_callback'    => [$this, 'updateTask'],
			'permission_callback' => static function ($params = []) {
				return BoardTools::canWriteBoard($params);
			},
			'meta' => [
				'show_in_rest' => true,
				'mcp'          => ['public' => true],
			],
		]);
	}

	/**
	 * @param mixed $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public function createTask($params)
	{
		$params = (array) $params;
		$labels = array_key_exists('labels', $params) ? $params['labels'] : null;
		unset($params['labels']);

		try {
			$result = TaskTools::createTask($params);
			if (is_wp_error($result)) {
				return $result;
			}

			if ($labels === null) {
				return $result;
			}

			$taskId  = (int) ($result['task']['id'] ?? 0);
			$boardId = (int) ($result['task']['board_id'] ?? ($params['board_id'] ?? 0));
			if (!$taskId || !$boardId) {
				return $result;
			}

			$applied = $this->applyLabelsToTask($boardId, $taskId, $labels);
			if (is_wp_error($applied)) {
				return $applied;
			}

			$result['task'] = MCPHelper::formatTaskSummary($applied);
			return $result;
		} catch (\Throwable $e) {
			return $this->toolError('fluent-boards/create-task', $e, $params);
		}
	}

	/**
	 * @param mixed $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public function updateTask($params)
	{
		$params = (array) $params;
		$hasLabels = array_key_exists('labels', $params);
		$labels    = $hasLabels ? $params['labels'] : null;
		unset($params['labels']);

		try {
			$result = TaskTools::updateTask($params);
			if (is_wp_error($result)) {
				return $result;
			}

			if (!$hasLabels) {
				return $result;
			}

			$taskId  = (int) ($result['task']['id'] ?? ($params['task_id'] ?? 0));
			$boardId = (int) ($result['task']['board_id'] ?? ($params['board_id'] ?? 0));
			if (!$taskId || !$boardId) {
				return $result;
			}

			$applied = $this->applyLabelsToTask($boardId, $taskId, $labels);
			if (is_wp_error($applied)) {
				return $applied;
			}

			MCPHelper::loadTaskDetails($applied);
			$result['task'] = MCPHelper::formatTask($applied);
			return $result;
		} catch (\Throwable $e) {
			return $this->toolError('fluent-boards/update-task', $e, $params);
		}
	}

	/**
	 * @param mixed $labels
	 * @return \FluentBoards\App\Models\Task|\WP_Error
	 */
	private function applyLabelsToTask(int $boardId, int $taskId, $labels)
	{
		$resolved = $this->resolveBoardLabelIds($boardId, $labels);
		if (is_wp_error($resolved)) {
			return $resolved;
		}

		$task = MCPHelper::resolveTask([
			'board_id' => $boardId,
			'task_id'  => $taskId,
		]);
		if (is_wp_error($task)) {
			return $task;
		}

		return $this->syncTaskLabels($task, $resolved);
	}

	/**
	 * @param mixed $items
	 * @return array<int>|\WP_Error
	 */
	private function resolveBoardLabelIds(int $boardId, $items)
	{
		$items = array_values(array_filter((array) $items, static function ($item) {
			return $item !== null && $item !== '';
		}));

		if (!$items) {
			return [];
		}

		$labels = Label::where('board_id', $boardId)
			->whereNull('archived_at')
			->get();

		$byId    = [];
		$bySlug  = [];
		$byTitle = [];
		foreach ($labels as $label) {
			$byId[(int) $label->id] = (int) $label->id;
			$slug = strtolower(trim((string) $label->slug));
			if ($slug !== '') {
				$bySlug[$slug] = (int) $label->id;
			}
			$title = strtolower(trim((string) $label->title));
			if ($title !== '') {
				$byTitle[$title] = (int) $label->id;
			}
		}

		$resolved   = [];
		$unresolved = [];

		foreach ($items as $item) {
			if (is_numeric($item)) {
				$id = (int) $item;
				if (isset($byId[$id])) {
					$resolved[] = $id;
					continue;
				}
				$unresolved[] = $item;
				continue;
			}

			$key = strtolower(trim((string) $item));
			if ($key === '') {
				continue;
			}

			if (isset($bySlug[$key])) {
				$resolved[] = $bySlug[$key];
				continue;
			}

			if (isset($byTitle[$key])) {
				$resolved[] = $byTitle[$key];
				continue;
			}

			$unresolved[] = $item;
		}

		if ($unresolved) {
			return MCPHelper::error('invalid_param', __('One or more labels were not found on this board', 'nxt-fbs-extension'), [
				'unresolved'       => array_values($unresolved),
				'available_labels' => MCPHelper::formatLabelList($labels),
			]);
		}

		return array_values(array_unique($resolved));
	}

	/**
	 * @param \FluentBoards\App\Models\Task $task
	 * @param array<int>                    $labelIds
	 * @return \FluentBoards\App\Models\Task
	 */
	private function syncTaskLabels($task, array $labelIds)
	{
		$task->load('labels');
		$currentIds = [];
		foreach ($task->labels as $label) {
			$currentIds[] = (int) $label->id;
		}

		$labelIds = array_values(array_unique(array_map('absint', $labelIds)));
		$toRemove = array_diff($currentIds, $labelIds);
		$toAdd    = array_diff($labelIds, $currentIds);

		if ($toRemove) {
			$task->labels()->detach($toRemove);
			foreach ($toRemove as $labelId) {
				$label = Label::find($labelId);
				if ($label) {
					do_action('fluent_boards/task_label', $task, $label, 'removed');
				}
			}
		}

		foreach ($toAdd as $labelId) {
			$task->labels()->syncWithoutDetaching([
				$labelId => [
					'object_type' => Constant::OBJECT_TYPE_TASK_LABEL,
				],
			]);
			$label = Label::find($labelId);
			if ($label) {
				do_action('fluent_boards/task_label', $task, $label, 'added');
			}
		}

		$task->load('labels');

		return $task;
	}

	/**
	 * @param mixed $params
	 * @return \WP_Error
	 */
	private function toolError(string $toolName, \Throwable $e, $params)
	{
		do_action('fluent_boards/mcp_tool_exception', $e, $toolName, $params);

		return new \WP_Error('failed', $e->getMessage(), [
			'tool'      => $toolName,
			'exception' => get_class($e),
		]);
	}
}
