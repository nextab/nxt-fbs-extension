<?php

defined('ABSPATH') || exit;

/**
 * Execute handlers for FluentCRM company MCP abilities.
 */
class NXT_FBS_CompanyTools
{
	/**
	 * @param mixed $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function listCompanies($params)
	{
		$params = (array) $params;
		$pagination = NXT_FBS_CompanyMCPHelper::paginationFromInput($params);

		$sortBy = sanitize_key((string) ($params['sort_by'] ?? 'id'));
		$allowedSortBy = ['id', 'name', 'email', 'created_at', 'updated_at'];
		if (!in_array($sortBy, $allowedSortBy, true)) {
			$sortBy = 'id';
		}

		$sortType = strtoupper((string) ($params['sort_type'] ?? 'DESC'));
		if (!in_array($sortType, ['ASC', 'DESC'], true)) {
			$sortType = 'DESC';
		}

		$query = \FluentCrm\App\Models\Company::query()
			->with(['owner'])
			->withCount('subscribers')
			->searchBy(sanitize_text_field((string) ($params['search'] ?? '')))
			->orderBy($sortBy, $sortType);

		if (!empty($params['type'])) {
			$query->ofType(sanitize_text_field((string) $params['type']));
		}

		if (!empty($params['industry'])) {
			$query->ofIndustry(sanitize_text_field((string) $params['industry']));
		}

		// FluentCRM MCP tools rely on $_REQUEST after paginationFromInput().
		$paginated = $query->paginate();

		return NXT_FBS_CompanyMCPHelper::formatCompanyList($paginated);
	}

	/**
	 * @param mixed $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function getCompany($params)
	{
		$params = (array) $params;
		$resolved = NXT_FBS_CompanyMCPHelper::resolveCompany($params);
		if (is_wp_error($resolved)) {
			return $resolved;
		}

		$include = !empty($params['include']) && is_array($params['include'])
			? array_values(array_intersect($params['include'], ['owner', 'subscribers', 'notes', 'custom_values']))
			: ['owner', 'custom_values'];

		$with = ['owner'];
		if (in_array('subscribers', $include, true)) {
			$with[] = 'subscribers';
		}
		if (in_array('notes', $include, true)) {
			$with[] = 'notes';
		}

		$company = \FluentCrm\App\Models\Company::with($with)->find($resolved->id);
		if (!$company) {
			return NXT_FBS_CompanyMCPHelper::error('not_found', __('Company not found', 'nxt-fbs-extension'), [
				'company_id' => (int) $resolved->id,
			]);
		}

		return NXT_FBS_CompanyMCPHelper::formatCompanyForMCP($company, ['include' => $include]);
	}

	/**
	 * @param mixed $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function upsertCompany($params)
	{
		$params = (array) $params;
		$data     = [];

		$allowedFields = [
			'id',
			'name',
			'email',
			'phone',
			'industry',
			'type',
			'description',
			'website',
			'owner_id',
			'address_line_1',
			'address_line_2',
			'postal_code',
			'city',
			'state',
			'country',
			'timezone',
			'employees_number',
			'logo',
			'linkedin_url',
			'facebook_url',
			'twitter_url',
			'date_of_start',
			'custom_values',
		];

		foreach ($allowedFields as $field) {
			if (array_key_exists($field, $params)) {
				$data[$field] = $params[$field];
			}
		}

		if (empty($data['id']) && empty($data['name'])) {
			return NXT_FBS_CompanyMCPHelper::error('invalid_param', __('name is required when creating a company', 'nxt-fbs-extension'));
		}

		if (isset($data['owner_id'])) {
			$data['owner_id'] = (int) $data['owner_id'] ?: null;
		}

		if (isset($data['id'])) {
			$data['id'] = (int) $data['id'];
		}

		$existed = false;
		if (!empty($data['id'])) {
			$existed = (bool) \FluentCrm\App\Models\Company::find((int) $data['id']);
		} elseif (!empty($data['name'])) {
			$existed = (bool) \FluentCrm\App\Models\Company::where('name', $data['name'])->first();
		}

		$companyApi = FluentCrmApi('companies');
		$company    = $companyApi->createOrUpdate($data);

		if (!$company) {
			return NXT_FBS_CompanyMCPHelper::error('invalid_param', __('Unable to create or update company', 'nxt-fbs-extension'), [
				'provided' => array_keys($data),
			]);
		}

		$company->load(['owner']);

		return [
			'ok'      => true,
			'action'  => $existed ? 'updated' : 'created',
			'company' => NXT_FBS_CompanyMCPHelper::formatCompanyForMCP($company, [
				'include' => ['owner', 'custom_values'],
			]),
		];
	}

	/**
	 * @param mixed $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function deleteCompany($params)
	{
		$params = (array) $params;
		$resolved = NXT_FBS_CompanyMCPHelper::resolveCompany($params);
		if (is_wp_error($resolved)) {
			return $resolved;
		}

		$companyId = (int) $resolved->id;
		$name      = (string) $resolved->name;

		do_action('fluent_crm/before_company_delete', $resolved);
		$resolved->delete();
		do_action('fluent_crm/company_deleted', $companyId);

		return [
			'ok'         => true,
			'action'     => 'deleted',
			'deleted_id' => $companyId,
			'name'       => $name,
			'note'       => __('Company permanently deleted.', 'nxt-fbs-extension'),
		];
	}

	/**
	 * @param mixed $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function attachContactsToCompany($params)
	{
		$params = (array) $params;
		$contactIds = NXT_FBS_CompanyMCPHelper::normalizeIdList($params['contact_ids'] ?? []);
		$companyIds = NXT_FBS_CompanyMCPHelper::normalizeIdList(
			$params['company_ids'] ?? (isset($params['company_id']) ? [$params['company_id']] : [])
		);

		if (!$contactIds || !$companyIds) {
			return NXT_FBS_CompanyMCPHelper::error('invalid_param', __('contact_ids and company_ids are required', 'nxt-fbs-extension'));
		}

		$result = FluentCrmApi('companies')->attachContactsByIds($contactIds, $companyIds);
		if (!$result) {
			return NXT_FBS_CompanyMCPHelper::error('invalid_param', __('Unable to attach contacts to companies', 'nxt-fbs-extension'), [
				'contact_ids' => $contactIds,
				'company_ids' => $companyIds,
			]);
		}

		return [
			'ok'              => true,
			'action'          => 'attached',
			'contact_ids'     => $contactIds,
			'company_ids'     => $companyIds,
			'companies_count' => count($result['companies'] ?? []),
			'contacts_count'  => count($result['subscribers'] ?? []),
		];
	}

	/**
	 * @param mixed $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function detachContactsFromCompany($params)
	{
		$params = (array) $params;
		$contactIds = NXT_FBS_CompanyMCPHelper::normalizeIdList($params['contact_ids'] ?? []);
		$companyIds = NXT_FBS_CompanyMCPHelper::normalizeIdList(
			$params['company_ids'] ?? (isset($params['company_id']) ? [$params['company_id']] : [])
		);

		if (!$contactIds || !$companyIds) {
			return NXT_FBS_CompanyMCPHelper::error('invalid_param', __('contact_ids and company_ids are required', 'nxt-fbs-extension'));
		}

		$result = FluentCrmApi('companies')->detachContactsByIds($contactIds, $companyIds);
		if (!$result) {
			return NXT_FBS_CompanyMCPHelper::error('invalid_param', __('Unable to detach contacts from companies', 'nxt-fbs-extension'), [
				'contact_ids' => $contactIds,
				'company_ids' => $companyIds,
			]);
		}

		return [
			'ok'                      => true,
			'action'                  => 'detached',
			'contact_ids'             => $contactIds,
			'company_ids'             => $companyIds,
			'last_primary_company_id' => $result['last_primary_company_id'] ?? false,
		];
	}

	/**
	 * @param mixed $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function addCompanyNote($params)
	{
		$params = (array) $params;
		$resolved = NXT_FBS_CompanyMCPHelper::resolveCompany($params);
		if (is_wp_error($resolved)) {
			return $resolved;
		}

		$title       = trim((string) ($params['title'] ?? ''));
		$description = (string) ($params['description'] ?? '');
		$type        = sanitize_key((string) ($params['type'] ?? 'note'));
		$allowedTypes = ['note', 'call', 'email', 'meeting', 'quote'];
		if (!in_array($type, $allowedTypes, true)) {
			$type = 'note';
		}

		if ($title === '' || $description === '') {
			return NXT_FBS_CompanyMCPHelper::error('invalid_param', __('title and description are required', 'nxt-fbs-extension'));
		}

		$noteData = [
			'subscriber_id' => (int) $resolved->id,
			'type'          => $type,
			'title'         => $title,
			'description'   => $description,
			'created_at'    => !empty($params['created_at']) ? sanitize_text_field((string) $params['created_at']) : current_time('mysql'),
		];

		if (class_exists('\FluentCrm\App\Services\Sanitize')) {
			$noteData = \FluentCrm\App\Services\Sanitize::contactNote($noteData);
		}

		$note = \FluentCrm\App\Models\CompanyNote::create(wp_unslash($noteData));
		do_action('fluent_crm/company_note_added', $note, $resolved, $noteData);

		return [
			'ok'   => true,
			'note' => NXT_FBS_CompanyMCPHelper::formatNote($note),
		];
	}

	/**
	 * @param mixed $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function deleteCompanyNote($params)
	{
		$params = (array) $params;
		$noteId = (int) ($params['note_id'] ?? 0);
		if (!$noteId) {
			return NXT_FBS_CompanyMCPHelper::error('invalid_param', __('note_id is required', 'nxt-fbs-extension'));
		}

		$note = \FluentCrm\App\Models\CompanyNote::find($noteId);
		if (!$note) {
			return NXT_FBS_CompanyMCPHelper::error('not_found', __('Note not found', 'nxt-fbs-extension'), ['note_id' => $noteId]);
		}

		$company = \FluentCrm\App\Models\Company::find((int) $note->subscriber_id);
		if (!$company) {
			return NXT_FBS_CompanyMCPHelper::error('not_found', __('Company not found for note', 'nxt-fbs-extension'), ['note_id' => $noteId]);
		}

		$deletedId = (int) $note->id;
		$title     = (string) $note->title;
		$note->delete();

		do_action('fluent_crm/company_note_deleted', $deletedId, $company);

		return [
			'ok'         => true,
			'action'     => 'deleted',
			'deleted_id' => $deletedId,
			'company_id' => (int) $company->id,
			'title'      => $title,
		];
	}
}
