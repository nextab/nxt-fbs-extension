<?php

defined('ABSPATH') || exit;

/**
 * Plugin settings: module toggles via Settings API.
 */
class NXT_FBS_Settings
{
	const OPTION_KEY = 'nxt_fbs_modules';
	const MENU_SLUG  = 'nxt-fbs-settings';
	const GROUP      = 'nxt_fbs_settings';

	/**
	 * Module definitions: id => [label, description, default].
	 *
	 * @return array<string, array{label: string, description: string, default: bool}>
	 */
	public static function moduleDefinitions(): array
	{
		return [
			'board_move_preserve_custom_fields' => [
				'label'       => __('Custom Fields beim Board-Move erhalten', 'nxt-fbs-extension'),
				'description' => __('Behält Custom-Field-Werte beim Verschieben von Tasks zwischen Boards bei. Fluent Boards löscht die Relationen in changeBoardByTask() — ohne Filter.', 'nxt-fbs-extension'),
				'default'     => true,
			],
			'board_move_preserve_time' => [
				'label'       => __('Zeiterfassung beim Board-Move erhalten', 'nxt-fbs-extension'),
				'description' => __('Fallback: stellt getrackte Zeiten nach dem Board-Wechsel wieder her. Aktuell überflüssig — Fluent Boards behält Zeiten nativ. Anlassen erzeugt doppelte Einträge. Nur einschalten, wenn ein Update die Zeiten wieder löscht.', 'nxt-fbs-extension'),
				'default'     => false,
			],
			'automations' => [
				'label'       => __('Automations-Buttons', 'nxt-fbs-extension'),
				'description' => __('Ermöglicht konfigurierbare Aktions-Buttons an Tasks (Labels, Priorität, Board-Wechsel, Assignee, …).', 'nxt-fbs-extension'),
				'default'     => true,
			],
			'crm_companies_mcp' => [
				'label'       => __('FluentCRM Companies MCP', 'nxt-fbs-extension'),
				'description' => __('Stellt FluentCRM-Companies über den FluentCRM-MCP-Endpoint bereit (lesen, ändern, Kontakte zuordnen, Notizen). Voraussetzung: FluentCRM Companies-Modul + MCP aktiv, Fluent Toolkit installiert. Nach dem Aktivieren MCP-Client neu laden.', 'nxt-fbs-extension'),
				'default'     => false,
			],
			'crm_list_contacts_pagination_fix' => [
				'label'       => __('FluentCRM list-contacts Paginierung', 'nxt-fbs-extension'),
				'description' => __('Behebt den FluentCRM-MCP-Bug, bei dem page/per_page in list-contacts ignoriert werden (immer Seite 1). Empfohlen aktiv lassen.', 'nxt-fbs-extension'),
				'default'     => true,
			],
			'boards_task_labels_mcp' => [
				'label'       => __('Fluent Boards Task-Labels MCP', 'nxt-fbs-extension'),
				'description' => __('Erweitert Fluent-Boards-MCP create-task/update-task um Labels (ids, Titel oder Slugs). Ohne Core-Patch — überlebt Fluent-Boards-Updates.', 'nxt-fbs-extension'),
				'default'     => true,
			],
			'boards_attachments_mcp' => [
				'label'       => __('Fluent Boards Anhänge per MCP', 'nxt-fbs-extension'),
				'description' => __('Neue MCP-Tools list-task-attachments, add-task-attachment (Link, Datei von URL, kleine Datei als Base64) und create-attachment-upload (einmalige Upload-URL für lokale Dateien per curl). Voraussetzung: Fluent Boards Pro. Nach dem Aktivieren MCP-Client neu laden.', 'nxt-fbs-extension'),
				'default'     => true,
			],
			'mcp_ensure' => [
				'label'       => __('Fluent MCP sicherstellen', 'nxt-fbs-extension'),
				'description' => __('Erzwingt mcp_enabled für Fluent Boards + FluentCRM und hängt MCPInit erneut ein, falls Core den Server nicht registriert hat.', 'nxt-fbs-extension'),
				'default'     => true,
			],
			'mcp_endpoint_compat' => [
				'label'       => __('MCP Plain-URLs', 'nxt-fbs-extension'),
				'description' => __('Schreibt MCP-Endpoints auf index.php?rest_route=/… um. Notwendig wenn nginx /wp-json/* mit 404 beantwortet (Claude/Cursor bekommen sonst leere Tool-Listen).', 'nxt-fbs-extension'),
				'default'     => true,
			],
			'list_view_all_tasks' => [
				'label'       => __('Listenansicht: alle Karten', 'nxt-fbs-extension'),
				'description' => __('Fluent Boards 2.2 zeigt in der Listenansicht je Phase nur die ersten 20 Karten und lädt nie nach (der Scroll-Trigger hängt an einem Container, der in der Liste nicht scrollt). Lädt das Board stattdessen komplett – wie Kalender und Gantt – und zeigt alle Karten.', 'nxt-fbs-extension'),
				'default'     => true,
			],
		];
	}

	/**
	 * @return array<string, bool>
	 */
	public static function defaults(): array
	{
		$defaults = [];
		foreach (self::moduleDefinitions() as $id => $def) {
			$defaults[$id] = (bool) $def['default'];
		}
		return $defaults;
	}

	/**
	 * @return array<string, bool>
	 */
	public static function getModules(): array
	{
		$stored = get_option(self::OPTION_KEY, []);
		if (!is_array($stored)) {
			$stored = [];
		}

		return array_merge(self::defaults(), array_map('boolval', $stored));
	}

	public static function isModuleEnabled(string $module): bool
	{
		$modules = self::getModules();
		return !empty($modules[$module]);
	}

	public function init(): void
	{
		add_action('admin_menu', [$this, 'registerMenu']);
		add_action('admin_init', [$this, 'registerSettings']);
		add_filter('plugin_action_links_' . plugin_basename(NXT_FBS_EXT_PATH . 'nxt-fbs-extension.php'), [$this, 'addPluginActionLinks']);
	}

	/**
	 * @param array<int, string> $links
	 * @return array<int, string>
	 */
	public function addPluginActionLinks(array $links): array
	{
		$url = admin_url('options-general.php?page=' . self::MENU_SLUG);
		array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Einstellungen', 'nxt-fbs-extension') . '</a>');

		return $links;
	}

	public function registerMenu(): void
	{
		add_options_page(
			__('NXT Fluent Boards', 'nxt-fbs-extension'),
			__('NXT Fluent Boards', 'nxt-fbs-extension'),
			'manage_options',
			self::MENU_SLUG,
			[$this, 'renderPage']
		);
	}

	public function registerSettings(): void
	{
		register_setting(self::GROUP, self::OPTION_KEY, [
			'type'              => 'array',
			'sanitize_callback' => [$this, 'sanitizeModules'],
			'default'           => self::defaults(),
		]);

		add_settings_section(
			'nxt_fbs_modules_section',
			__('Module', 'nxt-fbs-extension'),
			static function () {
				echo '<p>' . esc_html__('Einzelne Erweiterungen an- oder ausschalten. Deaktivierte Module laden keinen Code.', 'nxt-fbs-extension') . '</p>';
			},
			self::MENU_SLUG
		);

		foreach (self::moduleDefinitions() as $id => $def) {
			add_settings_field(
				$id,
				$def['label'],
				[$this, 'renderModuleField'],
				self::MENU_SLUG,
				'nxt_fbs_modules_section',
				[
					'id'          => $id,
					'description' => $def['description'],
					'label_for'   => 'nxt_fbs_module_' . $id,
				]
			);
		}
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function renderModuleField(array $args): void
	{
		$id          = (string) ($args['id'] ?? '');
		$description = (string) ($args['description'] ?? '');
		$modules     = self::getModules();
		$checked     = !empty($modules[$id]);
		$fieldId     = 'nxt_fbs_module_' . $id;

		printf(
			'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s[%3$s]" value="1" %4$s /> %5$s</label>',
			esc_attr($fieldId),
			esc_attr(self::OPTION_KEY),
			esc_attr($id),
			checked($checked, true, false),
			esc_html__('Aktiv', 'nxt-fbs-extension')
		);

		if ($description !== '') {
			echo '<p class="description">' . esc_html($description) . '</p>';
		}
	}

	/**
	 * Unchecked checkboxes are absent from POST — treat missing keys as off.
	 *
	 * @param mixed $input
	 * @return array<string, bool>
	 */
	public function sanitizeModules($input): array
	{
		$input   = is_array($input) ? $input : [];
		$clean   = [];

		foreach (array_keys(self::moduleDefinitions()) as $id) {
			$clean[$id] = !empty($input[$id]);
		}

		return $clean;
	}

	public function renderPage(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Keine Berechtigung.', 'nxt-fbs-extension'));
		}

		$automationsEnabled = self::isModuleEnabled('automations');
		$automationsUrl     = admin_url('options-general.php?page=nxt-fbs-automations');

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__('NXT Fluent Boards', 'nxt-fbs-extension') . '</h1>';

		if ($automationsEnabled) {
			echo '<p><a href="' . esc_url($automationsUrl) . '">' . esc_html__('Automationen konfigurieren →', 'nxt-fbs-extension') . '</a></p>';
		}

		$this->renderMcpHealthPanel();

		echo '<form method="post" action="options.php">';
		settings_fields(self::GROUP);
		do_settings_sections(self::MENU_SLUG);
		submit_button(__('Einstellungen speichern', 'nxt-fbs-extension'));
		echo '</form>';
		echo '</div>';
	}

	private function renderMcpHealthPanel(): void
	{
		if (!class_exists('NXT_FBS_McpEndpointCompat')) {
			require_once NXT_FBS_EXT_PATH . 'includes/MCP/McpEndpointCompat.php';
		}

		$prettyOk = NXT_FBS_McpEndpointCompat::prettyRestReachable();
		$catalog  = NXT_FBS_McpEndpointCompat::endpointCatalog();

		echo '<hr />';
		echo '<h2>' . esc_html__('MCP Health', 'nxt-fbs-extension') . '</h2>';

		if ($prettyOk) {
			echo '<div class="notice notice-success inline"><p>'
				. esc_html__('Pretty /wp-json/ erreichbar. MCP-Clients dürfen die kurzen URLs nutzen.', 'nxt-fbs-extension')
				. '</p></div>';
		} else {
			echo '<div class="notice notice-error inline"><p>'
				. esc_html__('Pretty /wp-json/ antwortet nicht (nginx 404). Claude/Cursor müssen Plain-URLs nutzen — sonst Handshake ins Leere.', 'nxt-fbs-extension')
				. '</p></div>';
		}

		echo '<table class="widefat striped" style="max-width:960px"><thead><tr>';
		echo '<th>' . esc_html__('Server', 'nxt-fbs-extension') . '</th>';
		echo '<th>' . esc_html__('URL für MCP-Clients (Plain)', 'nxt-fbs-extension') . '</th>';
		echo '</tr></thead><tbody>';

		foreach ($catalog as $item) {
			echo '<tr><td><code>' . esc_html($item['slug']) . '</code></td>';
			echo '<td><code style="user-select:all">' . esc_html($item['plain']) . '</code></td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">'
			. esc_html__('Nach URL-Wechsel MCP-Connector in Claude Code entfernen und mit Plain-URL neu anlegen (Application Password unverändert).', 'nxt-fbs-extension')
			. '</p>';
	}
}
