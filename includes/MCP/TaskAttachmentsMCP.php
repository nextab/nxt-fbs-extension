<?php

defined('ABSPATH') || exit;

use FluentBoards\App\Modules\MCP\Helpers\MCPHelper;
use FluentBoards\App\Services\Libs\FileSystem;
use FluentBoards\App\Services\UploadService;
use FluentBoardsPro\App\Models\TaskAttachment;
use FluentBoardsPro\App\Services\AttachmentService;

/**
 * Task attachments for the Fluent Boards MCP server.
 *
 * Core Fluent Boards MCP has no attachment tools, so an AI agent can only add a
 * file to a task by driving the browser UI. This module adds three abilities:
 *
 * - list-task-attachments: read the attachments of a task.
 * - add-task-attachment: add a link, a file fetched from a public URL, or a
 *   small base64-encoded file.
 * - create-attachment-upload: hand out a one-time upload URL, so an agent with
 *   a shell can send a local file of any size with curl (multipart, field
 *   "file") without pushing its bytes through the model.
 *
 * Files are stored the same way Fluent Boards Pro stores chunked uploads and
 * go through AttachmentService::handleAttachment(), so activity log, counter,
 * hooks and cloud-storage filters behave as for an upload in the UI.
 */
class NXT_FBS_TaskAttachmentsMCP
{
	const REST_NAMESPACE    = 'nxt-fbs/v1';
	const REST_ROUTE        = '/attachment-upload';
	const TICKET_PREFIX     = 'nxt_fbs_upl_';
	const TICKET_TTL        = 15 * MINUTE_IN_SECONDS;
	const DOWNLOAD_TIMEOUT  = 60;

	const ABILITY_LIST   = 'fluent-boards/list-task-attachments';
	const ABILITY_ADD    = 'fluent-boards/add-task-attachment';
	const ABILITY_UPLOAD = 'fluent-boards/create-attachment-upload';

	public function init(): void
	{
		if (!defined('FLUENT_BOARDS_PRO_VERSION')
			|| !class_exists(AttachmentService::class)
			|| !class_exists(UploadService::class)
			|| !class_exists(FileSystem::class)
		) {
			return;
		}

		// Ability names must be known before the MCP server is created.
		add_filter('fluent_boards/mcp_ability_names', [$this, 'addAbilityNames']);
		add_action('fluent_boards/mcp_loaded', [$this, 'registerAbilities'], 20);
		add_action('rest_api_init', [$this, 'registerUploadRoute']);
	}

	/**
	 * @param mixed $names
	 * @return array<int, string>
	 */
	public function addAbilityNames($names): array
	{
		$names   = (array) $names;
		$names[] = self::ABILITY_LIST;
		$names[] = self::ABILITY_ADD;
		$names[] = self::ABILITY_UPLOAD;

		return array_values(array_unique($names));
	}

	public function registerAbilities(): void
	{
		if (!function_exists('wp_register_ability') || !class_exists(MCPHelper::class)) {
			return;
		}

		$taskTarget = [
			'board_id' => ['type' => 'integer'],
			'task_id'  => ['type' => 'integer'],
		];

		wp_register_ability(self::ABILITY_LIST, [
			'label'       => __('List Task Attachments', 'nxt-fbs-extension'),
			'description' => __('List the attachments (files and links) of a task.', 'nxt-fbs-extension'),
			'category'    => 'fluent-boards',
			'input_schema'=> [
				'type'       => 'object',
				'properties' => $taskTarget,
				'required'   => ['board_id', 'task_id'],
			],
			'execute_callback'    => [$this, 'listAttachments'],
			'permission_callback' => static function ($params = []) {
				$boardId = absint(((array) $params)['board_id'] ?? 0);
				return $boardId && MCPHelper::canReadBoard($boardId);
			},
			'meta' => [
				'show_in_rest' => true,
				'annotations'  => ['readonly' => true],
				'mcp'          => ['public' => true],
			],
		]);

		wp_register_ability(self::ABILITY_ADD, [
			'label'       => __('Add Task Attachment', 'nxt-fbs-extension'),
			'description' => __('Attach something to a task. Pass exactly one source: link_url adds a link attachment; source_url downloads a publicly reachable file and stores it on the task; content_base64 + file_name stores small inline files (keep it under about 1 MB, the bytes travel through the conversation). For local files of any size, call create-attachment-upload and send the file with curl instead. Allowed file types follow the Fluent Boards upload allow-list (no html, js, svg, php).', 'nxt-fbs-extension'),
			'category'    => 'fluent-boards',
			'input_schema'=> [
				'type'       => 'object',
				'properties' => $taskTarget + [
					'link_url'       => ['type' => 'string', 'description' => 'Add a link attachment pointing to this URL. The file is not copied.'],
					'source_url'     => ['type' => 'string', 'description' => 'Public http(s) URL of a file. The server downloads it and stores it as a file attachment.'],
					'content_base64' => ['type' => 'string', 'description' => 'File content, base64-encoded. Requires file_name.'],
					'file_name'      => ['type' => 'string', 'description' => 'File name with extension, e.g. "angebot.pdf". Required with content_base64; optional with source_url (defaults to the name in the URL).'],
					'title'          => ['type' => 'string', 'description' => 'Title of a link attachment. Ignored for files, which show their file name.'],
				],
				'required' => ['board_id', 'task_id'],
			],
			'execute_callback'    => [$this, 'addAttachment'],
			'permission_callback' => [$this, 'canWrite'],
			'meta' => [
				'show_in_rest' => true,
				'mcp'          => ['public' => true],
			],
		]);

		wp_register_ability(self::ABILITY_UPLOAD, [
			'label'       => __('Create Attachment Upload URL', 'nxt-fbs-extension'),
			'description' => __('Create a one-time upload URL for a task (valid 15 minutes, one file). Send the file as multipart/form-data with the field "file", e.g. curl -sS -F "file=@/path/to/file.pdf" "<upload_url>". The response of that request contains the new attachment. Use this for local files instead of content_base64.', 'nxt-fbs-extension'),
			'category'    => 'fluent-boards',
			'input_schema'=> [
				'type'       => 'object',
				'properties' => $taskTarget,
				'required'   => ['board_id', 'task_id'],
			],
			'execute_callback'    => [$this, 'createUploadTicket'],
			'permission_callback' => [$this, 'canWrite'],
			'meta' => [
				'show_in_rest' => true,
				'mcp'          => ['public' => true],
			],
		]);

		if (class_exists('\FluentBoards\App\Modules\MCP\Tools\ContextTools')
			&& method_exists('\FluentBoards\App\Modules\MCP\Tools\ContextTools', 'invalidateCache')
		) {
			\FluentBoards\App\Modules\MCP\Tools\ContextTools::invalidateCache();
		}
	}

	/**
	 * @param mixed $params
	 */
	public function canWrite($params = []): bool
	{
		$boardId = absint(((array) $params)['board_id'] ?? 0);
		return $boardId && MCPHelper::canWriteBoard($boardId);
	}

	/* ------------------------------------------------------------------ */
	/* Abilities                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * @param mixed $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public function listAttachments($params)
	{
		$task = MCPHelper::resolveTask((array) $params);
		if (is_wp_error($task)) {
			return $task;
		}

		try {
			$items = TaskAttachment::where('object_id', (int) $task->id)
				->where('object_type', \FluentBoards\App\Services\Constant::TASK_ATTACHMENT)
				->orderBy('id', 'ASC')
				->get();
		} catch (\Throwable $e) {
			return $this->toolError(self::ABILITY_LIST, $e, $params);
		}

		$out = [];
		foreach ($items as $attachment) {
			$out[] = $this->formatAttachment($attachment);
		}

		return [
			'task'        => ['id' => (int) $task->id, 'board_id' => (int) $task->board_id],
			'attachments' => $out,
		];
	}

	/**
	 * @param mixed $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public function addAttachment($params)
	{
		$params = (array) $params;
		$task   = MCPHelper::resolveTask($params);
		if (is_wp_error($task)) {
			return $task;
		}

		$sources = array_filter([
			'link_url'       => trim((string) ($params['link_url'] ?? '')),
			'source_url'     => trim((string) ($params['source_url'] ?? '')),
			'content_base64' => trim((string) ($params['content_base64'] ?? '')),
		], 'strlen');

		if (count($sources) !== 1) {
			return MCPHelper::error('invalid_param', __('Pass exactly one of link_url, source_url or content_base64.', 'nxt-fbs-extension'));
		}

		$boardId = (int) $task->board_id;
		$taskId  = (int) $task->id;

		try {
			if (isset($sources['link_url'])) {
				$attachment = $this->addLink($taskId, $sources['link_url'], (string) ($params['title'] ?? ''));
			} elseif (isset($sources['source_url'])) {
				$attachment = $this->addFromUrl($boardId, $taskId, $sources['source_url'], (string) ($params['file_name'] ?? ''));
			} else {
				$attachment = $this->addFromBase64($boardId, $taskId, $sources['content_base64'], (string) ($params['file_name'] ?? ''));
			}
		} catch (\Throwable $e) {
			return $this->toolError(self::ABILITY_ADD, $e, $params);
		}

		if (is_wp_error($attachment)) {
			return $attachment;
		}

		return [
			'attachment' => $this->formatAttachment($attachment),
			'task'       => ['id' => $taskId, 'board_id' => $boardId],
		];
	}

	/**
	 * @param mixed $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public function createUploadTicket($params)
	{
		$task = MCPHelper::resolveTask((array) $params);
		if (is_wp_error($task)) {
			return $task;
		}

		$token = wp_generate_password(40, false, false);
		set_transient(self::ticketKey($token), [
			'user_id'  => get_current_user_id(),
			'board_id' => (int) $task->board_id,
			'task_id'  => (int) $task->id,
		], self::TICKET_TTL);

		// Plain rest_route form: works even where /wp-json/* is not routed to PHP.
		$url = add_query_arg([
			'rest_route' => '/' . self::REST_NAMESPACE . self::REST_ROUTE,
			'token'      => $token,
		], home_url('/index.php'));

		return [
			'upload_url'    => $url,
			'method'        => 'POST',
			'content_type'  => 'multipart/form-data',
			'file_field'    => 'file',
			'expires_at'    => gmdate('c', time() + self::TICKET_TTL),
			'max_file_size' => (new UploadService())->getFileUploadLimit(),
			'single_use'    => true,
			'example'       => 'curl -sS -F "file=@/path/to/file.pdf" "' . $url . '"',
			'task'          => ['id' => (int) $task->id, 'board_id' => (int) $task->board_id],
		];
	}

	/* ------------------------------------------------------------------ */
	/* Upload endpoint                                                    */
	/* ------------------------------------------------------------------ */

	public function registerUploadRoute(): void
	{
		register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
			'methods'             => 'POST',
			'callback'            => [$this, 'handleUpload'],
			// The single-use token is the credential; it is checked in the callback.
			'permission_callback' => '__return_true',
			'args'                => [
				'token' => ['type' => 'string', 'required' => true],
			],
		]);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handleUpload(\WP_REST_Request $request)
	{
		$token = (string) $request->get_param('token');
		if (!preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
			return new \WP_Error('invalid_token', __('Upload URL is invalid or has expired.', 'nxt-fbs-extension'), ['status' => 403]);
		}

		$key    = self::ticketKey($token);
		$ticket = get_transient($key);

		// delete_transient() only succeeds for one request, which makes the URL single-use.
		if (!is_array($ticket) || !delete_transient($key)) {
			return new \WP_Error('invalid_token', __('Upload URL is invalid or has expired.', 'nxt-fbs-extension'), ['status' => 403]);
		}

		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;
		if (!is_array($file) || is_array($file['name'] ?? null)) {
			return new \WP_Error('missing_file', __('Send exactly one file in the multipart field "file". The upload URL has been used up; create a new one.', 'nxt-fbs-extension'), ['status' => 400]);
		}

		if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
			return new \WP_Error('upload_failed', sprintf(
				/* translators: %d: PHP upload error code */
				__('The file did not arrive (PHP upload error %d). Check the server upload limit. The upload URL has been used up; create a new one.', 'nxt-fbs-extension'),
				(int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)
			), ['status' => 400]);
		}

		wp_set_current_user((int) $ticket['user_id']);
		if (!MCPHelper::canWriteBoard((int) $ticket['board_id'])) {
			return new \WP_Error('forbidden', __('You no longer have write access to this board.', 'nxt-fbs-extension'), ['status' => 403]);
		}

		try {
			$attachment = $this->storeFile(
				(int) $ticket['board_id'],
				(int) $ticket['task_id'],
				(string) $file['tmp_name'],
				(string) ($file['name'] ?? ''),
				true
			);
		} catch (\Throwable $e) {
			do_action('fluent_boards/mcp_tool_exception', $e, self::ABILITY_UPLOAD, $ticket);
			return new \WP_Error('upload_failed', $e->getMessage(), ['status' => 400]);
		}

		return new \WP_REST_Response([
			'attachment' => $this->formatAttachment($attachment),
			'task'       => ['id' => (int) $ticket['task_id'], 'board_id' => (int) $ticket['board_id']],
		], 201);
	}

	/* ------------------------------------------------------------------ */
	/* Sources                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * @return TaskAttachment
	 */
	private function addLink(int $taskId, string $url, string $title)
	{
		$url = esc_url_raw($url, ['http', 'https']);
		if (!$url || !wp_http_validate_url($url)) {
			throw new \InvalidArgumentException(__('link_url must be a valid http(s) URL.', 'nxt-fbs-extension'));
		}

		$data = [
			'type' => 'url',
			'url'  => $url,
		];
		$title = sanitize_text_field($title);
		if ($title !== '') {
			$data['title'] = $title;
		}

		return (new AttachmentService())->handleAttachment($taskId, $data);
	}

	/**
	 * @return TaskAttachment
	 */
	private function addFromUrl(int $boardId, int $taskId, string $url, string $fileName)
	{
		$url = esc_url_raw($url, ['http', 'https']);
		if (!$url || !wp_http_validate_url($url)) {
			throw new \InvalidArgumentException(__('source_url must be a public http(s) URL.', 'nxt-fbs-extension'));
		}

		if ($fileName === '') {
			$fileName = rawurldecode(wp_basename((string) wp_parse_url($url, PHP_URL_PATH)));
		}
		$this->assertFileName($fileName);

		self::loadFileApi();
		$limit = (new UploadService())->getFileUploadLimit();
		$tmp   = wp_tempnam($fileName);

		try {
			// wp_safe_remote_get() refuses private and local addresses.
			$response = wp_safe_remote_get($url, [
				'timeout'             => self::DOWNLOAD_TIMEOUT,
				'redirection'         => 3,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => $limit + 1,
			]);

			if (is_wp_error($response)) {
				throw new \RuntimeException(sprintf(
					/* translators: %s: error message */
					__('Download failed: %s', 'nxt-fbs-extension'),
					$response->get_error_message()
				));
			}

			$code = (int) wp_remote_retrieve_response_code($response);
			if ($code !== 200) {
				throw new \RuntimeException(sprintf(
					/* translators: %d: HTTP status code */
					__('Download failed with HTTP status %d.', 'nxt-fbs-extension'),
					$code
				));
			}

			return $this->storeFile($boardId, $taskId, $tmp, $fileName, false);
		} finally {
			if (is_file($tmp)) {
				wp_delete_file($tmp);
			}
		}
	}

	/**
	 * @return TaskAttachment
	 */
	private function addFromBase64(int $boardId, int $taskId, string $content, string $fileName)
	{
		$this->assertFileName($fileName);

		// Accept data URIs as well as bare base64.
		if (strncmp($content, 'data:', 5) === 0 && ($comma = strpos($content, ',')) !== false) {
			$content = substr($content, $comma + 1);
		}

		$limit = (new UploadService())->getFileUploadLimit();
		if ((int) floor(strlen($content) * 3 / 4) > $limit + 3) {
			throw new \InvalidArgumentException(__('File size is too large.', 'nxt-fbs-extension'));
		}

		$bytes = base64_decode(preg_replace('/\s+/', '', $content), true);
		if ($bytes === false || $bytes === '') {
			throw new \InvalidArgumentException(__('content_base64 is not valid base64.', 'nxt-fbs-extension'));
		}

		self::loadFileApi();
		$tmp = wp_tempnam($fileName);
		try {
			if (file_put_contents($tmp, $bytes) === false) {
				throw new \RuntimeException(__('Could not write the temporary file.', 'nxt-fbs-extension'));
			}
			unset($bytes);

			return $this->storeFile($boardId, $taskId, $tmp, $fileName, false);
		} finally {
			if (is_file($tmp)) {
				wp_delete_file($tmp);
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Storage                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Moves a file into the board's upload folder and creates the attachment,
	 * following FluentBoardsPro AttachmentController::addTaskAttachmentFileChunk().
	 *
	 * @return TaskAttachment
	 */
	private function storeFile(int $boardId, int $taskId, string $sourcePath, string $fileName, bool $isPhpUpload)
	{
		$fileName = $this->assertFileName($fileName);

		$uploadService = new UploadService();
		$fileSize      = (int) @filesize($sourcePath);
		if ($fileSize <= 0) {
			throw new \RuntimeException(__('File is empty.', 'fluent-boards'));
		}
		if ($fileSize > $uploadService->getFileUploadLimit()) {
			throw new \RuntimeException(__('File size is too large', 'fluent-boards'));
		}

		$canonicalMimeType = AttachmentService::getMimeTypeFromFileName($fileName);

		$uploadDir = wp_upload_dir();
		if (empty($uploadDir['basedir']) || empty($uploadDir['baseurl'])) {
			throw new \RuntimeException(__('Upload directory information not available.', 'fluent-boards-pro'));
		}

		if (method_exists(FileSystem::class, '_installUploadProtectionRules') && !FileSystem::installUploadProtectionRules()) {
			throw new \RuntimeException(__('Could not create the upload directory. Please check directory permissions.', 'fluent-boards-pro'));
		}

		$boardDir = FileSystem::setSubDir('board_' . $boardId)->getDir();
		if (!file_exists($boardDir) && !wp_mkdir_p($boardDir)) {
			throw new \RuntimeException(__('Could not create the upload directory. Please check directory permissions.', 'fluent-boards-pro'));
		}

		$storedName = wp_unique_filename($boardDir, apply_filters('fluent_boards/uploaded_file_name_prefix', wp_generate_uuid4() . '-') . $fileName);
		$storedPath = trailingslashit($boardDir) . $storedName;

		$moved = $isPhpUpload ? move_uploaded_file($sourcePath, $storedPath) : copy($sourcePath, $storedPath);
		if (!$moved) {
			throw new \RuntimeException(__('File upload failed.', 'fluent-boards-pro'));
		}

		// Files that normally render inline must match their declared extension.
		$detectedMimeType = AttachmentService::detectFileMimeType($storedPath);
		$responsePolicy   = AttachmentService::getAttachmentResponsePolicy($fileName, $detectedMimeType);
		if (in_array($canonicalMimeType, AttachmentService::VERIFIED_INLINE_MIME_TYPES, true)
			&& $responsePolicy['disposition'] !== 'inline'
		) {
			wp_delete_file($storedPath);
			throw new \RuntimeException(__('File contents do not match the file extension.', 'fluent-boards-pro'));
		}

		$uploadFolder = apply_filters('fluent_boards/upload_folder_name', FLUENT_BOARDS_UPLOAD_DIR);
		$baseUrl      = trailingslashit($uploadDir['baseurl']) . trim($uploadFolder, '/') . '/board_' . $boardId . '/';

		$fileData = [
			'name'      => $fileName,
			'size'      => $fileSize,
			'type'      => $canonicalMimeType,
			'file'      => $storedName,
			'url'       => $baseUrl . rawurlencode($storedName),
			'full_path' => $storedPath,
			'driver'    => 'local',
		];

		$fileArray = [
			'name'          => $fileName,
			'type'          => $canonicalMimeType,
			'tmp_name'      => $storedPath,
			'size'          => $fileSize,
			'size_in_bytes' => $fileSize,
		];

		try {
			return (new AttachmentService())->handleAttachment($taskId, $fileData, $fileArray);
		} catch (\Throwable $e) {
			if (is_file($storedPath)) {
				wp_delete_file($storedPath);
			}
			throw $e;
		}
	}

	/**
	 * Sanitizes the file name and checks it against the Fluent Boards allow-list.
	 */
	private function assertFileName(string $fileName): string
	{
		$fileName = sanitize_file_name(wp_basename($fileName));
		if ($fileName === '' || !pathinfo($fileName, PATHINFO_EXTENSION)) {
			throw new \InvalidArgumentException(__('file_name with an extension is required, e.g. "document.pdf".', 'nxt-fbs-extension'));
		}

		if (!(new UploadService())->isFileTypeSupported(['name' => $fileName])
			|| !AttachmentService::getMimeTypeFromFileName($fileName)
		) {
			throw new \InvalidArgumentException(sprintf(
				/* translators: %s: file extension */
				__('File type .%s is not allowed for attachments.', 'nxt-fbs-extension'),
				strtolower(pathinfo($fileName, PATHINFO_EXTENSION))
			));
		}

		return $fileName;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	private static function loadFileApi(): void
	{
		if (!function_exists('wp_tempnam')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
	}

	private static function ticketKey(string $token): string
	{
		// Store only a hash, so the token cannot be read back from the options table.
		return self::TICKET_PREFIX . substr(hash('sha256', $token), 0, 32);
	}

	/**
	 * @param TaskAttachment $attachment
	 * @return array<string, mixed>
	 */
	private function formatAttachment($attachment): array
	{
		$isLink = $attachment->attachment_type === 'url';

		return [
			'id'         => (int) $attachment->id,
			'title'      => (string) $attachment->title,
			'type'       => $isLink ? 'link' : 'file',
			'mime_type'  => $isLink ? null : (string) $attachment->attachment_type,
			'file_size'  => $isLink ? null : (int) $attachment->file_size,
			'url'        => (string) $attachment->secure_url,
			'created_at' => class_exists(MCPHelper::class) ? MCPHelper::toIso8601($attachment->created_at) : (string) $attachment->created_at,
		];
	}

	/**
	 * @param mixed $params
	 */
	private function toolError(string $toolName, \Throwable $e, $params): \WP_Error
	{
		if (is_array($params) && isset($params['content_base64'])) {
			$params['content_base64'] = '[' . strlen((string) $params['content_base64']) . ' chars]';
		}
		do_action('fluent_boards/mcp_tool_exception', $e, $toolName, $params);

		$code = $e instanceof \InvalidArgumentException ? 'invalid_param' : 'failed';

		return new \WP_Error($code, $e->getMessage(), [
			'tool'      => $toolName,
			'exception' => get_class($e),
		]);
	}
}
