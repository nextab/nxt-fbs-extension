<?php

defined('ABSPATH') || exit;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Label;
use FluentBoards\App\Services\PermissionManager;

class NXT_FBS_AdminPage
{
	const OPTION_KEY   = 'nxt_fbs_automation_buttons';
	const MENU_SLUG    = 'nxt-fbs-automations';
	const SAVE_NONCE   = 'nxt_fbs_save_buttons';
	const DATA_NONCE   = 'nxt_fbs_get_data';

	public function init()
	{
		add_action('admin_menu', [$this, 'registerMenu']);
		add_action('wp_ajax_nxt_fbs_get_builder_data', [$this, 'ajaxGetBuilderData']);
		add_action('wp_ajax_nxt_fbs_save_buttons', [$this, 'ajaxSaveButtons']);
	}

	public function registerMenu()
	{
		add_submenu_page(
			'options-general.php',
			__('FBS Automationen', 'nxt-fbs-extension'),
			__('FBS Automationen', 'nxt-fbs-extension'),
			'manage_options',
			self::MENU_SLUG,
			[$this, 'renderPage']
		);
	}

	public function renderPage()
	{
		if (!current_user_can('manage_options')) {
			wp_die(__('Keine Berechtigung.', 'nxt-fbs-extension'));
		}

		wp_enqueue_script(
			'nxt-fbs-builder',
			NXT_FBS_EXT_URL . 'assets/automation-builder.js',
			[],
			NXT_FBS_EXT_VERSION,
			true
		);
		wp_enqueue_style(
			'nxt-fbs-builder',
			NXT_FBS_EXT_URL . 'assets/automation-builder.css',
			[],
			NXT_FBS_EXT_VERSION
		);
		wp_localize_script('nxt-fbs-builder', 'nxtFbsBuilder', [
			'ajaxUrl'   => admin_url('admin-ajax.php'),
			'dataNonce' => wp_create_nonce(self::DATA_NONCE),
			'saveNonce' => wp_create_nonce(self::SAVE_NONCE),
		]);

		$settingsUrl = admin_url('options-general.php?page=nxt-fbs-settings');

		echo '<div class="wrap" id="nxt-fbs-builder-app">';
		echo '<h1>' . esc_html__('FBS Automationen', 'nxt-fbs-extension') . '</h1>';
		echo '<p><a href="' . esc_url($settingsUrl) . '">← ' . esc_html__('Module & Einstellungen', 'nxt-fbs-extension') . '</a></p>';
		echo '<div id="nxt-fbs-builder-root"></div>';
		echo '</div>';
	}

	/**
	 * AJAX: Liefert Boards (mit Labels und Stages) und WP-User für den Builder.
	 */
	public function ajaxGetBuilderData()
	{
		check_ajax_referer(self::DATA_NONCE, 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Keine Berechtigung.', 'nxt-fbs-extension')], 403);
		}

		$boards = Board::with(['labels', 'stages', 'users'])->get();

		$boardData = $boards->map(function ($board) {
			return [
				'id'     => $board->id,
				'title'  => $board->title,
				'labels' => $board->labels->map(fn($l) => ['id' => $l->id, 'title' => $l->title, 'color' => $l->color ?? '']),
				'stages' => $board->stages->map(fn($s) => ['id' => $s->id, 'title' => $s->title]),
			];
		});

		$users = get_users(['fields' => ['ID', 'display_name', 'user_email']]);
		$userData = array_map(fn($u) => [
			'id'    => (int) $u->ID,
			'label' => $u->display_name . ' (' . $u->user_email . ')',
		], $users);

		$savedButtons = get_option(self::OPTION_KEY, []);

		wp_send_json_success([
			'boards'  => $boardData,
			'users'   => array_values($userData),
			'buttons' => $savedButtons,
		]);
	}

	/**
	 * AJAX: Speichert die Button-Konfiguration in wp_options.
	 */
	public function ajaxSaveButtons()
	{
		check_ajax_referer(self::SAVE_NONCE, 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Keine Berechtigung.', 'nxt-fbs-extension')], 403);
		}

		$raw = isset($_POST['buttons']) ? wp_unslash($_POST['buttons']) : '[]';

		if (!is_string($raw)) {
			wp_send_json_error(['message' => __('Ungültiges Format.', 'nxt-fbs-extension')], 400);
		}

		$buttons = json_decode($raw, true);

		if (!is_array($buttons)) {
			wp_send_json_error(['message' => __('JSON konnte nicht geparst werden.', 'nxt-fbs-extension')], 400);
		}

		$sanitized = self::sanitizeButtons($buttons);
		update_option(self::OPTION_KEY, $sanitized, false);

		wp_send_json_success(['message' => __('Gespeichert.', 'nxt-fbs-extension'), 'buttons' => $sanitized]);
	}

	/**
	 * Gibt die gespeicherten Buttons zurück (für AutomationHandler).
	 */
	public static function getButtons(): array
	{
		return (array) get_option(self::OPTION_KEY, []);
	}

	/**
	 * Validiert und bereinigt die eingehende Button-Konfiguration.
	 */
	private static function sanitizeButtons(array $buttons): array
	{
		$allowedPriorities = ['low', 'medium', 'high'];
		$allowedStatuses   = ['open', 'closed'];
		$allowedActionTypes = ['set_label', 'set_priority', 'set_status', 'move_board', 'set_assignee'];

		$clean = [];

		foreach ($buttons as $btn) {
			$id    = sanitize_key($btn['id'] ?? '');
			$label = sanitize_text_field($btn['label'] ?? '');

			if (!$id || !$label) {
				continue;
			}

			$cleanBtn = [
				'id'      => $id,
				'label'   => $label,
				'confirm' => sanitize_text_field($btn['confirm'] ?? ''),
				'actions' => [],
			];

			foreach ((array) ($btn['actions'] ?? []) as $action) {
				$type = sanitize_key($action['type'] ?? '');

				if (!in_array($type, $allowedActionTypes, true)) {
					continue;
				}

				$cleanAction = ['type' => $type];

				switch ($type) {
					case 'set_label':
						$cleanAction['label_ids'] = array_map('intval', (array) ($action['label_ids'] ?? []));
						break;

					case 'set_priority':
						$p = sanitize_text_field($action['priority'] ?? '');
						if (!in_array($p, $allowedPriorities, true)) continue 2;
						$cleanAction['priority'] = $p;
						break;

					case 'set_status':
						$s = sanitize_text_field($action['status'] ?? '');
						if (!in_array($s, $allowedStatuses, true)) continue 2;
						$cleanAction['status'] = $s;
						break;

					case 'move_board':
						$boardId = intval($action['board_id'] ?? 0);
						if (!$boardId) continue 2;
						$cleanAction['board_id'] = $boardId;
						break;

					case 'set_assignee':
						$userId = intval($action['user_id'] ?? 0);
						$mode   = in_array($action['mode'] ?? '', ['add', 'remove'], true) ? $action['mode'] : 'add';
						if (!$userId) continue 2;
						$cleanAction['user_id'] = $userId;
						$cleanAction['mode']    = $mode;
						break;
				}

				$cleanBtn['actions'][] = $cleanAction;
			}

			$clean[] = $cleanBtn;
		}

		return $clean;
	}
}
