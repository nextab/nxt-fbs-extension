<?php

defined('ABSPATH') || exit;

/*
Plugin Name: NXT Fluent Boards Extension
Description: Erweitert Fluent Boards um Automations-Buttons, alle Karten in der Listenansicht, FluentCRM-Companies-MCP, list-contacts-Paginierungs-Fix, Task-Labels und Anhänge per MCP, MCP-Endpoint-Compat und erhält Custom-Field-Werte (optional Zeiterfassung) beim Verschieben zwischen Boards.
Version: 1.8.0
Author: nexTab
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
Text Domain: nxt-fbs-extension
*/

if (!defined('NXT_FBS_EXT')) {
	define('NXT_FBS_EXT', true);
	define('NXT_FBS_EXT_VERSION', '1.8.0');
	define('NXT_FBS_EXT_PATH', plugin_dir_path(__FILE__));
	define('NXT_FBS_EXT_URL', plugin_dir_url(__FILE__));
}

add_action('plugins_loaded', function () {
	if (!defined('FLUENT_BOARDS')) {
		add_action('admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>NXT Fluent Boards Extension</strong> benötigt das Plugin <strong>Fluent Boards</strong>, das aktuell nicht aktiv ist.</p></div>';
		});
		return;
	}

	// Das FluentBoards-Frontend-Portal wirft alle Skripte und
	// Styles raus, die nicht auf seiner Freigabeliste stehen – ohne diesen
	// Eintrag laufen Automations-Buttons und Listenansicht-Fix nur in der
	// WP-Admin-Ansicht.
	add_filter('fluent_boards/portal_asset_listed_slugs', function ($slugs) {
		$slugs   = (array) $slugs;
		$slugs[] = '\/nxt-fbs-extension\/';

		return $slugs;
	});

	require_once NXT_FBS_EXT_PATH . 'includes/Settings.php';
	require_once NXT_FBS_EXT_PATH . 'includes/AdminPage.php';

	(new NXT_FBS_Settings())->init();

	// One-shot: strip obsolete show_more_fix key from stored module settings.
	if (!get_option('nxt_fbs_cleanup_show_more_done')) {
		$modules = get_option(NXT_FBS_Settings::OPTION_KEY, []);
		if (is_array($modules) && array_key_exists('show_more_fix', $modules)) {
			unset($modules['show_more_fix']);
			update_option(NXT_FBS_Settings::OPTION_KEY, $modules);
		}
		update_option('nxt_fbs_cleanup_show_more_done', 1, false);
	}

	// One-shot: split board_move_preserve into custom-fields vs time-tracking.
	if (!get_option('nxt_fbs_split_board_move_done')) {
		$modules = get_option(NXT_FBS_Settings::OPTION_KEY, []);
		if (is_array($modules) && array_key_exists('board_move_preserve', $modules)) {
			$old = !empty($modules['board_move_preserve']);
			if (!array_key_exists('board_move_preserve_custom_fields', $modules)) {
				$modules['board_move_preserve_custom_fields'] = $old;
			}
			if (!array_key_exists('board_move_preserve_time', $modules)) {
				$modules['board_move_preserve_time'] = false;
			}
			unset($modules['board_move_preserve']);
			update_option(NXT_FBS_Settings::OPTION_KEY, $modules);
		}
		update_option('nxt_fbs_split_board_move_done', 1, false);
	}

	$preserveCustomFields = NXT_FBS_Settings::isModuleEnabled('board_move_preserve_custom_fields');
	$preserveTimeTracking = NXT_FBS_Settings::isModuleEnabled('board_move_preserve_time');

	if ($preserveCustomFields || $preserveTimeTracking) {
		require_once NXT_FBS_EXT_PATH . 'includes/BoardMovePreserver.php';
		(new NXT_FBS_BoardMovePreserver())->init();

		if ($preserveCustomFields) {
			require_once NXT_FBS_EXT_PATH . 'includes/CustomFieldPreserver.php';
			(new NXT_FBS_CustomFieldPreserver())->init();
		}

		if ($preserveTimeTracking) {
			require_once NXT_FBS_EXT_PATH . 'includes/TimeTrackingPreserver.php';
			(new NXT_FBS_TimeTrackingPreserver())->init();
		}
	}

	if (NXT_FBS_Settings::isModuleEnabled('list_view_all_tasks')) {
		require_once NXT_FBS_EXT_PATH . 'includes/ListViewAllTasks.php';
		(new NXT_FBS_ListViewAllTasks())->init();
	}

	if (NXT_FBS_Settings::isModuleEnabled('automations')) {
		require_once NXT_FBS_EXT_PATH . 'includes/ActionExecutor.php';
		require_once NXT_FBS_EXT_PATH . 'includes/AutomationHandler.php';

		(new NXT_FBS_AdminPage())->init();
		(new NXT_FBS_AutomationHandler())->init();
	}

	if (NXT_FBS_Settings::isModuleEnabled('crm_companies_mcp')) {
		require_once NXT_FBS_EXT_PATH . 'includes/MCP/CompaniesMCP.php';
		(new NXT_FBS_CompaniesMCP())->init();
	}

	if (NXT_FBS_Settings::isModuleEnabled('crm_list_contacts_pagination_fix')) {
		require_once NXT_FBS_EXT_PATH . 'includes/MCP/ContactListPaginationFix.php';
		(new NXT_FBS_ContactListPaginationFix())->init();
	}

	if (NXT_FBS_Settings::isModuleEnabled('boards_task_labels_mcp')) {
		require_once NXT_FBS_EXT_PATH . 'includes/MCP/TaskLabelsMCP.php';
		(new NXT_FBS_TaskLabelsMCP())->init();
	}

	if (NXT_FBS_Settings::isModuleEnabled('boards_attachments_mcp')) {
		require_once NXT_FBS_EXT_PATH . 'includes/MCP/TaskAttachmentsMCP.php';
		(new NXT_FBS_TaskAttachmentsMCP())->init();
	}

	if (NXT_FBS_Settings::isModuleEnabled('mcp_ensure')) {
		require_once NXT_FBS_EXT_PATH . 'includes/MCP/McpEnsure.php';
		(new NXT_FBS_McpEnsure())->init();
	}

	if (NXT_FBS_Settings::isModuleEnabled('mcp_endpoint_compat')) {
		require_once NXT_FBS_EXT_PATH . 'includes/MCP/McpEndpointCompat.php';
		(new NXT_FBS_McpEndpointCompat())->init();
	}
});
