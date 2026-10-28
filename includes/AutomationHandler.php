<?php

defined('ABSPATH') || exit;

use FluentBoards\App\Models\Task;

class NXT_FBS_AutomationHandler
{
	public function init()
	{
		add_action('fluent_boards/after_enqueue_assets', [$this, 'enqueueAssets']);
		add_filter('fluent_boards/app_vars', [$this, 'passButtonsToJs']);

		add_action('wp_ajax_nxt_fbs_run_automation', [$this, 'handleAjax']);
	}

	public function enqueueAssets()
	{
		wp_enqueue_script(
			'nxt-fbs-automation',
			NXT_FBS_EXT_URL . 'assets/fbs-automation.js',
			[],
			NXT_FBS_EXT_VERSION,
			true
		);

		wp_localize_script('nxt-fbs-automation', 'nxtFbsVars', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('nxt_fbs_automation'),
		]);
	}

	/**
	 * Liest Buttons aus wp_options und gibt sie als JS-Variable weiter.
	 * Nur id, label und confirm werden an den Client gesendet – keine Aktionsdetails.
	 */
	public function passButtonsToJs(array $vars): array
	{
		$buttons = NXT_FBS_AdminPage::getButtons();

		$vars['nxt_automation_buttons'] = array_map(fn($btn) => [
			'id'      => $btn['id'],
			'label'   => $btn['label'],
			'confirm' => $btn['confirm'] ?? '',
		], $buttons);

		return $vars;
	}

	/**
	 * AJAX-Handler: Empfängt task_id + button_id, sucht die passende Button-Konfiguration
	 * und delegiert die Ausführung an ActionExecutor.
	 */
	public function handleAjax()
	{
		check_ajax_referer('nxt_fbs_automation', 'nonce');

		$taskId   = intval($_POST['task_id'] ?? 0);
		$buttonId = sanitize_key($_POST['action_id'] ?? '');

		if (!$taskId || !$buttonId) {
			wp_send_json_error(['message' => __('Ungültige Parameter.', 'nxt-fbs-extension')], 400);
		}

		$task = Task::find($taskId);

		if (!$task) {
			wp_send_json_error(['message' => __('Task nicht gefunden.', 'nxt-fbs-extension')], 404);
		}

		if (!$this->userCanActOnTask($task)) {
			wp_send_json_error(['message' => __('Keine Berechtigung.', 'nxt-fbs-extension')], 403);
		}

		$btnConfig = $this->findButtonConfig($buttonId);

		if (!$btnConfig) {
			wp_send_json_error(['message' => __('Kein Button mit dieser ID gefunden.', 'nxt-fbs-extension')], 404);
		}

		$result = NXT_FBS_ActionExecutor::execute($task, $btnConfig['actions'] ?? []);

		if ($result['success']) {
			wp_send_json_success($result);
		} else {
			wp_send_json_error($result);
		}
	}

	private function findButtonConfig(string $buttonId): ?array
	{
		$buttons = NXT_FBS_AdminPage::getButtons();
		foreach ($buttons as $btn) {
			if (($btn['id'] ?? '') === $buttonId) {
				return $btn;
			}
		}
		return null;
	}

	private function userCanActOnTask(Task $task): bool
	{
		if (!function_exists('FluentBoardsApi')) {
			return false;
		}
		return FluentBoardsApi('tasks')->getTask($task->id) !== false;
	}
}
