<?php

defined('ABSPATH') || exit;

/**
 * Shared helpers for FluentCRM company MCP tools.
 */
class NXT_FBS_CompanyMCPHelper
{
	/**
	 * @param array<string, mixed> $input
	 * @return \FluentCrm\App\Models\Company|\WP_Error
	 */
	public static function resolveCompany(array $input)
	{
		$companyId = isset($input['company_id']) ? (int) $input['company_id'] : 0;
		$email     = isset($input['email']) ? sanitize_email((string) $input['email']) : '';
		$name      = isset($input['name']) ? sanitize_text_field((string) $input['name']) : '';

		if ($companyId) {
			$company = \FluentCrm\App\Models\Company::find($companyId);
			if (!$company) {
				return self::error('not_found', __('Company not found', 'nxt-fbs-extension'), ['company_id' => $companyId]);
			}

			return $company;
		}

		if ($email !== '') {
			$company = \FluentCrm\App\Models\Company::where('email', $email)->first();
			if (!$company) {
				return self::error('not_found', __('Company not found', 'nxt-fbs-extension'), ['email' => $email]);
			}

			return $company;
		}

		if ($name !== '') {
			$company = \FluentCrm\App\Models\Company::where('name', $name)->first();
			if (!$company) {
				return self::error('not_found', __('Company not found', 'nxt-fbs-extension'), ['name' => $name]);
			}

			return $company;
		}

		return self::error('invalid_param', __('Provide company_id, email, or name', 'nxt-fbs-extension'));
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{page: int, per_page: int}
	 */
	public static function paginationFromInput(array $input, int $defaultPerPage = 15, int $maxPerPage = 100): array
	{
		if (class_exists('\FluentCrm\App\Modules\MCP\Helpers\MCPHelper')) {
			return \FluentCrm\App\Modules\MCP\Helpers\MCPHelper::paginationFromInput($input, $defaultPerPage, $maxPerPage);
		}

		$page    = max(1, (int) ($input['page'] ?? 1));
		$perPage = (int) ($input['per_page'] ?? $defaultPerPage);
		if ($perPage < 1) {
			$perPage = $defaultPerPage;
		}
		$perPage = min($perPage, $maxPerPage);

		$_REQUEST['page']     = $page;
		$_REQUEST['per_page'] = $perPage;

		return ['page' => $page, 'per_page' => $perPage];
	}

	/**
	 * @param mixed $value
	 */
	public static function toIso8601($value): ?string
	{
		if (!$value) {
			return null;
		}

		if ($value instanceof \DateTimeInterface) {
			return $value->format('c');
		}

		$timestamp = strtotime((string) $value);
		if (!$timestamp) {
			return null;
		}

		return gmdate('c', $timestamp);
	}

	/**
	 * @param \FluentCrm\App\Models\Company $company
	 * @return array<string, mixed>
	 */
	public static function formatCompanySummary($company): array
	{
		return [
			'id'                => (int) $company->id,
			'name'              => (string) $company->name,
			'email'             => (string) $company->email,
			'phone'             => (string) $company->phone,
			'industry'          => (string) $company->industry,
			'type'              => (string) $company->type,
			'website'           => (string) $company->website,
			'owner_id'          => $company->owner_id ? (int) $company->owner_id : null,
			'contacts_count'    => isset($company->subscribers_count)
				? (int) $company->subscribers_count
				: (method_exists($company, 'getContactsCount') ? (int) $company->getContactsCount() : null),
			'city'              => (string) $company->city,
			'country'           => (string) $company->country,
			'created_at'        => self::toIso8601($company->created_at),
			'updated_at'        => self::toIso8601($company->updated_at),
		];
	}

	/**
	 * @param \FluentCrm\App\Models\Company $company
	 * @param array<string, mixed>          $opts
	 * @return array<string, mixed>
	 */
	public static function formatCompanyForMCP($company, array $opts = []): array
	{
		$include = array_values(array_intersect(
			(array) ($opts['include'] ?? []),
			['owner', 'subscribers', 'notes', 'custom_values']
		));

		$data = self::formatCompanySummary($company);
		$data['description'] = (string) $company->description;
		$data['address']     = array_filter([
			'line_1'      => (string) $company->address_line_1,
			'line_2'      => (string) $company->address_line_2,
			'city'        => (string) $company->city,
			'state'       => (string) $company->state,
			'postal_code' => (string) $company->postal_code,
			'country'     => (string) $company->country,
		], static function ($value) {
			return $value !== null && $value !== '';
		});
		$data['social'] = array_filter([
			'linkedin_url' => (string) $company->linkedin_url,
			'facebook_url' => (string) $company->facebook_url,
			'twitter_url'  => (string) $company->twitter_url,
		], static function ($value) {
			return $value !== null && $value !== '';
		});

		if (in_array('custom_values', $include, true) || empty($include)) {
			$data['custom_values'] = method_exists($company, 'getCustomValues')
				? (array) $company->getCustomValues()
				: [];
		}

		if (in_array('owner', $include, true) && $company->relationLoaded('owner') && $company->owner) {
			$data['owner'] = [
				'id'         => (int) $company->owner->id,
				'email'      => (string) $company->owner->email,
				'first_name' => (string) $company->owner->first_name,
				'last_name'  => (string) $company->owner->last_name,
				'full_name'  => trim((string) $company->owner->full_name),
			];
		}

		if (in_array('subscribers', $include, true) && $company->relationLoaded('subscribers')) {
			$data['subscribers'] = [];
			foreach ($company->subscribers as $subscriber) {
				$data['subscribers'][] = [
					'id'         => (int) $subscriber->id,
					'email'      => (string) $subscriber->email,
					'first_name' => (string) $subscriber->first_name,
					'last_name'  => (string) $subscriber->last_name,
					'full_name'  => trim((string) $subscriber->full_name),
					'status'     => (string) $subscriber->status,
				];
			}
		}

		if (in_array('notes', $include, true) && $company->relationLoaded('notes')) {
			$data['notes'] = [];
			foreach ($company->notes as $note) {
				$data['notes'][] = self::formatNote($note);
			}
		}

		return $data;
	}

	/**
	 * @param mixed $paginated
	 * @return array<string, mixed>
	 */
	public static function formatCompanyList($paginated): array
	{
		$items = [];
		foreach ($paginated->items() as $company) {
			$items[] = self::formatCompanySummary($company);
		}

		return [
			'items'    => $items,
			'total'    => (int) $paginated->total(),
			'page'     => (int) $paginated->currentPage(),
			'per_page' => (int) $paginated->perPage(),
			'pages'    => (int) $paginated->lastPage(),
		];
	}

	/**
	 * @param \FluentCrm\App\Models\CompanyNote $note
	 * @return array<string, mixed>
	 */
	public static function formatNote($note): array
	{
		return [
			'id'          => (int) $note->id,
			'company_id'  => (int) $note->subscriber_id,
			'type'        => (string) $note->type,
			'title'       => (string) $note->title,
			'description' => (string) $note->description,
			'created_at'  => self::toIso8601($note->created_at),
			'created_by'  => method_exists($note, 'createdBy') ? $note->createdBy() : null,
		];
	}

	/**
	 * @param array<int|string> $values
	 * @return array<int>
	 */
	public static function normalizeIdList($values): array
	{
		$ids = [];
		foreach ((array) $values as $value) {
			$id = (int) $value;
			if ($id > 0) {
				$ids[] = $id;
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 * @param string               $code
	 * @param string               $message
	 * @param array<string, mixed> $details
	 * @return \WP_Error
	 */
	public static function error(string $code, string $message, array $details = [])
	{
		if (class_exists('\FluentCrm\App\Modules\MCP\Helpers\MCPHelper')) {
			return \FluentCrm\App\Modules\MCP\Helpers\MCPHelper::error($code, $message, $details);
		}

		return new \WP_Error($code, $message, $details);
	}

	public static function currentUserCanManageCompanies(): bool
	{
		return class_exists('\FluentCrm\App\Services\PermissionManager')
			&& \FluentCrm\App\Services\PermissionManager::currentUserCan('fcrm_manage_contact_cats');
	}

	public static function currentUserCanDeleteCompanies(): bool
	{
		return class_exists('\FluentCrm\App\Services\PermissionManager')
			&& \FluentCrm\App\Services\PermissionManager::currentUserCan('fcrm_manage_contact_cats_delete');
	}
}
