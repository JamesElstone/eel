<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class CtFilingMappingService
{
    public const TARGET_RIM = 'ct600_rim';
    public const TARGET_COMPUTATION = 'computation_ixbrl';

    public function fetchProfiles(?string $targetType = null): array
    {
        if (!\InterfaceDB::tableExists('ct_filing_mapping_profiles')) {
            return [];
        }
        $params = [];
        $where = '';
        if ($targetType !== null) {
            $this->assertTarget($targetType);
            $where = ' WHERE p.target_type = :target_type';
            $params['target_type'] = $targetType;
        }
        return \InterfaceDB::fetchAll(
            'SELECT p.*, r.form_version AS rim_version, r.artifact_version AS rim_artifact_version,
                    c.taxonomy_version, c.artifact_version AS taxonomy_artifact_version
             FROM ct_filing_mapping_profiles p
             LEFT JOIN hmrc_ct_rim_packages r ON r.id = p.rim_package_id
             LEFT JOIN hmrc_ct_computation_packages c ON c.id = p.computation_package_id'
             . $where . ' ORDER BY p.created_at DESC, p.id DESC',
            $params
        );
    }

    public function activeProfile(string $targetType, int $packageId): ?array
    {
        $this->assertTarget($targetType);
        $column = $targetType === self::TARGET_RIM ? 'rim_package_id' : 'computation_package_id';
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ct_filing_mapping_profiles
             WHERE target_type = :target_type AND ' . $column . ' = :package_id
               AND status = :status AND compatibility_status = :compatibility
             ORDER BY revision_no DESC, id DESC LIMIT 1',
            ['target_type' => $targetType, 'package_id' => $packageId, 'status' => 'active', 'compatibility' => 'compatible']
        );
        return is_array($row) ? $row : null;
    }

    /**
     * Resolves a mapping profile only against a verified frozen CT-period model.
     * No tax calculation or ledger service is reachable through this operation.
     */
    public function mapFrozenFacts(
        string $targetType,
        array $filingModel,
        array $profile,
        ?array $knownMappings = null
    ): array
    {
        $this->assertTarget($targetType);
        if (empty($filingModel['available'])
            || trim((string)($filingModel['basis_version'] ?? '')) === ''
            || preg_match('/^[a-f0-9]{64}$/i', (string)($filingModel['basis_hash'] ?? '')) !== 1
            || (int)(($filingModel['run'] ?? [])['run_id'] ?? 0) <= 0
            || (int)(($filingModel['model'] ?? [])['ct_period']['id'] ?? 0) <= 0
            || !is_array($filingModel['seal'] ?? null)
            || $filingModel['seal'] === []) {
            return $this->mappingFailure('A verified, sealed CT-period filing model is required.');
        }
        if ((string)($profile['target_type'] ?? '') !== $targetType
            || (string)($profile['status'] ?? '') !== 'active'
            || (string)($profile['compatibility_status'] ?? '') !== 'compatible'
            || (int)($profile['id'] ?? 0) <= 0) {
            return $this->mappingFailure('An active compatible mapping profile for the requested target is required.');
        }

        $table = $targetType === self::TARGET_RIM
            ? 'ct600_rim_mappings'
            : 'ct_computation_ixbrl_mappings';
        $mappings = $knownMappings ?? \InterfaceDB::fetchAll(
            'SELECT * FROM ' . $table . ' WHERE profile_id = :profile_id ORDER BY sort_order, id',
            ['profile_id' => (int)$profile['id']]
        );
        if ($mappings === []) {
            return $this->mappingFailure('The active mapping profile contains no mappings.');
        }

        $facts = (array)($filingModel['facts'] ?? []);
        $resolved = [];
        $canonicalValues = [];
        $errors = [];
        foreach ($mappings as $mapping) {
            if (!is_array($mapping) || (int)($mapping['profile_id'] ?? 0) !== (int)$profile['id']) {
                $errors[] = 'A mapping row does not belong to the selected active profile.';
                continue;
            }
            $key = trim((string)($mapping['canonical_key'] ?? ''));
            $exists = $key !== '' && array_key_exists($key, $facts);
            $value = $exists ? $facts[$key] : null;
            $missing = !$exists || $value === null || $value === '';
            $required = !empty($mapping['is_required']) || (string)($mapping['null_policy'] ?? '') === 'error';
            if ($missing && $required) {
                $errors[] = 'Required canonical filing fact is missing: ' . ($key !== '' ? $key : '(blank key)') . '.';
                continue;
            }
            if ($missing && (string)($mapping['null_policy'] ?? 'omit') === 'omit') {
                continue;
            }
            if (!$missing && !$this->valueMatchesType($value, (string)($mapping['value_type'] ?? ''))) {
                $errors[] = 'Canonical filing fact has the wrong type: ' . $key . '.';
                continue;
            }
            $mapping['source_value'] = $value;
            $resolved[] = $mapping;
            $canonicalValues[$key] = $value;
        }
        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => array_values(array_unique($errors)),
                'basis_version' => (string)$filingModel['basis_version'],
                'basis_hash' => (string)$filingModel['basis_hash'],
                'computation_run_id' => (int)$filingModel['run']['run_id'],
                'ct_period_id' => (int)($filingModel['model']['ct_period']['id'] ?? 0),
                'canonical_values' => $canonicalValues,
                'mappings' => [],
            ];
        }

        return [
            'success' => true,
            'errors' => [],
            'target_type' => $targetType,
            'profile_id' => (int)$profile['id'],
            'basis_version' => (string)$filingModel['basis_version'],
            'basis_hash' => (string)$filingModel['basis_hash'],
            'computation_run_id' => (int)$filingModel['run']['run_id'],
            'ct_period_id' => (int)($filingModel['model']['ct_period']['id'] ?? 0),
            'canonical_values' => $canonicalValues,
            'mappings' => $resolved,
        ];
    }

    public function cloneDraft(string $targetType, int $packageId, string $actor): int
    {
        $this->assertTarget($targetType);
        $column = $targetType === self::TARGET_RIM ? 'rim_package_id' : 'computation_package_id';
        $source = \InterfaceDB::fetchOne(
            'SELECT * FROM ct_filing_mapping_profiles WHERE target_type = :target_type
             ORDER BY (status = :active) DESC, revision_no DESC, id DESC LIMIT 1',
            ['target_type' => $targetType, 'active' => 'active']
        );
        $revision = (int)(\InterfaceDB::fetchColumn(
            'SELECT COALESCE(MAX(revision_no), 0) + 1 FROM ct_filing_mapping_profiles WHERE target_type = :target_type AND ' . $column . ' = :package_id',
            ['target_type' => $targetType, 'package_id' => $packageId]
        ) ?: 1);
        \InterfaceDB::prepareExecute(
            'INSERT INTO ct_filing_mapping_profiles
             (target_type, rim_package_id, computation_package_id, profile_name, revision_no, status, parent_profile_id,
              content_hash, compatibility_status, compatibility_json, created_by)
             VALUES (:target_type, :rim_package_id, :computation_package_id, :profile_name, :revision_no, :status, :parent_profile_id,
                     :content_hash, :compatibility_status, :compatibility_json, :created_by)',
            [
                'target_type' => $targetType,
                'rim_package_id' => $targetType === self::TARGET_RIM ? $packageId : null,
                'computation_package_id' => $targetType === self::TARGET_COMPUTATION ? $packageId : null,
                'profile_name' => $targetType . '_package_' . $packageId,
                'revision_no' => $revision,
                'status' => 'draft',
                'parent_profile_id' => is_array($source) ? (int)$source['id'] : null,
                'content_hash' => hash('sha256', 'empty'),
                'compatibility_status' => 'pending',
                'compatibility_json' => null,
                'created_by' => $actor,
            ]
        );
        $profileId = (int)\InterfaceDB::lastInsertId();
        if (is_array($source)) {
            $this->copyMappings($targetType, (int)$source['id'], $profileId, $packageId);
        }
        $this->refreshContentHash($profileId);
        $this->audit($profileId, 'created', $actor, ['parent_profile_id' => is_array($source) ? (int)$source['id'] : null]);
        if (is_array($source)) {
            $this->audit($profileId, 'cloned', $actor, ['source_profile_id' => (int)$source['id'], 'unchanged_targets_only' => true]);
        }
        return $profileId;
    }

    public function validateProfile(int $profileId, string $actor): array
    {
        $profile = $this->profile($profileId);
        if ((string)$profile['status'] !== 'draft') {
            throw new \RuntimeException('Only draft mapping profiles can be validated.');
        }
        $target = (string)$profile['target_type'];
        $mappingTable = $target === self::TARGET_RIM ? 'ct600_rim_mappings' : 'ct_computation_ixbrl_mappings';
        $mappings = \InterfaceDB::fetchAll('SELECT * FROM ' . $mappingTable . ' WHERE profile_id = :profile_id ORDER BY id', ['profile_id' => $profileId]);
        $errors = [];
        if ($mappings === []) {
            $errors[] = 'The profile contains no mappings.';
        }
        $seen = [];
        foreach ($mappings as $mapping) {
            $source = trim((string)($mapping['canonical_key'] ?? ''));
            if ($source === '' || isset($seen[$source])) {
                $errors[] = $source === '' ? 'A mapping has no canonical source.' : 'Canonical source is mapped more than once: ' . $source;
            }
            $seen[$source] = true;
        }
        $inventory = $this->compatibility($profile, $mappings);
        $errors = array_values(array_unique(array_merge($errors, (array)$inventory['errors'])));
        $result = $errors === [] ? 'compatible' : 'incompatible';
        \InterfaceDB::prepareExecute(
            'UPDATE ct_filing_mapping_profiles SET status = :status, compatibility_status = :result,
             compatibility_json = :json, validated_by = :actor, validated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['status' => $errors === [] ? 'validated' : 'draft', 'result' => $result, 'json' => json_encode($inventory, JSON_UNESCAPED_SLASHES), 'actor' => $actor, 'id' => $profileId]
        );
        $this->refreshContentHash($profileId);
        $this->audit($profileId, 'validated', $actor, $inventory);
        return ['success' => $errors === [], 'errors' => $errors, 'compatibility' => $inventory];
    }

    public function saveMapping(string $targetType, int $profileId, array $input, string $actor): void
    {
        $this->assertTarget($targetType);
        $profile = $this->profile($profileId);
        if ((string)$profile['target_type'] !== $targetType || (string)$profile['status'] !== 'draft') {
            throw new \RuntimeException('Mappings can only be changed on a matching draft profile.');
        }
        $canonicalKey = trim((string)($input['canonical_key'] ?? ''));
        $valueType = trim((string)($input['value_type'] ?? ''));
        if ($canonicalKey === '' || !in_array($valueType, ['numeric', 'text', 'date', 'boolean', 'integer'], true)) {
            throw new \InvalidArgumentException('Select a canonical source and value type.');
        }
        if ($targetType === self::TARGET_RIM) {
            $target = trim((string)($input['target_xpath'] ?? ''));
            if ($target === '') { throw new \InvalidArgumentException('A catalogued RIM component path is required.'); }
            $exists = (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM hmrc_ct_rim_components WHERE package_id = :package_id AND component_path = :target', ['package_id' => (int)$profile['rim_package_id'], 'target' => $target]);
            if ($exists !== 1) { throw new \RuntimeException('The RIM target does not exist in this package inventory.'); }
            \InterfaceDB::prepareExecute(
                'INSERT INTO ct600_rim_mappings (profile_id, canonical_key, target_xpath, value_type, sign_multiplier, null_policy, is_required, sort_order)
                 VALUES (:profile_id, :canonical_key, :target, :value_type, :sign_multiplier, :null_policy, :is_required, :sort_order)
                 ON DUPLICATE KEY UPDATE target_xpath = VALUES(target_xpath), value_type = VALUES(value_type), sign_multiplier = VALUES(sign_multiplier), null_policy = VALUES(null_policy), is_required = VALUES(is_required), sort_order = VALUES(sort_order)',
                ['profile_id' => $profileId, 'canonical_key' => $canonicalKey, 'target' => $target, 'value_type' => $valueType, 'sign_multiplier' => (float)($input['sign_multiplier'] ?? 1), 'null_policy' => $this->nullPolicy($input['null_policy'] ?? 'omit'), 'is_required' => !empty($input['is_required']) ? 1 : 0, 'sort_order' => (int)($input['sort_order'] ?? 100)]
            );
        } else {
            $concept = trim((string)($input['taxonomy_concept'] ?? ''));
            $catalogue = \InterfaceDB::fetchOne('SELECT * FROM hmrc_ct_computation_concepts WHERE package_id = :package_id AND qname = :concept LIMIT 1', ['package_id' => (int)$profile['computation_package_id'], 'concept' => $concept]);
            if (!is_array($catalogue)) { throw new \RuntimeException('The taxonomy concept does not exist in this package inventory.'); }
            $dimensions = trim((string)($input['dimensions_json'] ?? ''));
            if ($dimensions !== '' && !is_array(json_decode($dimensions, true))) { throw new \InvalidArgumentException('Dimensions must be a JSON object.'); }
            \InterfaceDB::prepareExecute(
                'INSERT INTO ct_computation_ixbrl_mappings
                 (profile_id, canonical_key, taxonomy_concept, namespace_uri, local_name, value_type, period_type, context_profile,
                  unit_ref, decimals_value, dimensions_json, sign_multiplier, presentation_section, presentation_label, null_policy, is_required, sort_order)
                 VALUES (:profile_id, :canonical_key, :concept, :namespace_uri, :local_name, :value_type, :period_type, :context_profile,
                         :unit_ref, :decimals_value, :dimensions_json, :sign_multiplier, :section, :label, :null_policy, :is_required, :sort_order)
                 ON DUPLICATE KEY UPDATE taxonomy_concept = VALUES(taxonomy_concept), namespace_uri = VALUES(namespace_uri), local_name = VALUES(local_name), value_type = VALUES(value_type), period_type = VALUES(period_type), context_profile = VALUES(context_profile), unit_ref = VALUES(unit_ref), decimals_value = VALUES(decimals_value), dimensions_json = VALUES(dimensions_json), sign_multiplier = VALUES(sign_multiplier), presentation_section = VALUES(presentation_section), presentation_label = VALUES(presentation_label), null_policy = VALUES(null_policy), is_required = VALUES(is_required), sort_order = VALUES(sort_order)',
                ['profile_id' => $profileId, 'canonical_key' => $canonicalKey, 'concept' => $concept, 'namespace_uri' => (string)$catalogue['namespace_uri'], 'local_name' => (string)$catalogue['local_name'], 'value_type' => $valueType, 'period_type' => in_array((string)($input['period_type'] ?? ''), ['instant', 'duration'], true) ? (string)$input['period_type'] : ((string)($catalogue['period_type'] ?? '') ?: 'duration'), 'context_profile' => trim((string)($input['context_profile'] ?? 'ct_period')) ?: 'ct_period', 'unit_ref' => trim((string)($input['unit_ref'] ?? '')) ?: null, 'decimals_value' => trim((string)($input['decimals_value'] ?? '')) ?: null, 'dimensions_json' => $dimensions !== '' ? $dimensions : null, 'sign_multiplier' => (float)($input['sign_multiplier'] ?? 1), 'section' => trim((string)($input['presentation_section'] ?? '')) ?: 'tax_liability', 'label' => trim((string)($input['presentation_label'] ?? '')) ?: $canonicalKey, 'null_policy' => $this->nullPolicy($input['null_policy'] ?? 'omit'), 'is_required' => !empty($input['is_required']) ? 1 : 0, 'sort_order' => (int)($input['sort_order'] ?? 100)]
            );
        }
        $this->refreshContentHash($profileId);
        \InterfaceDB::prepareExecute('UPDATE ct_filing_mapping_profiles SET compatibility_status = :status, compatibility_json = NULL WHERE id = :id', ['status' => 'pending', 'id' => $profileId]);
        $this->audit($profileId, 'mapping_changed', $actor, ['canonical_key' => $canonicalKey]);
    }

    public function activateProfile(int $profileId, string $actor): void
    {
        $profile = $this->profile($profileId);
        if ((string)$profile['status'] !== 'validated' || (string)$profile['compatibility_status'] !== 'compatible') {
            throw new \RuntimeException('Only a validated, compatible mapping profile can be activated.');
        }
        $column = (string)$profile['target_type'] === self::TARGET_RIM ? 'rim_package_id' : 'computation_package_id';
        \InterfaceDB::beginTransaction();
        try {
            \InterfaceDB::prepareExecute(
                'UPDATE ct_filing_mapping_profiles SET status = :retired, retired_by = :actor, retired_at = CURRENT_TIMESTAMP
                 WHERE target_type = :target_type AND ' . $column . ' = :package_id AND status = :active AND id <> :id',
                ['retired' => 'retired', 'actor' => $actor, 'target_type' => $profile['target_type'], 'package_id' => $profile[$column], 'active' => 'active', 'id' => $profileId]
            );
            \InterfaceDB::prepareExecute(
                'UPDATE ct_filing_mapping_profiles SET status = :active, activated_by = :actor, activated_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['active' => 'active', 'actor' => $actor, 'id' => $profileId]
            );
            $this->audit($profileId, 'activated', $actor, []);
            \InterfaceDB::commit();
        } catch (\Throwable $exception) {
            \InterfaceDB::rollBack();
            throw $exception;
        }
    }

    public function retireProfile(int $profileId, string $actor): void
    {
        $profile = $this->profile($profileId);
        if ((string)$profile['status'] !== 'active') {
            throw new \RuntimeException('Only an active mapping profile can be retired.');
        }
        \InterfaceDB::prepareExecute(
            'UPDATE ct_filing_mapping_profiles SET status = :status, retired_by = :actor, retired_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['status' => 'retired', 'actor' => $actor, 'id' => $profileId]
        );
        if ((string)$profile['target_type'] === self::TARGET_COMPUTATION) {
            \InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_computation_runs SET ixbrl_status = :status WHERE ixbrl_mapping_profile_id = :profile_id',
                ['status' => 'stale', 'profile_id' => $profileId]
            );
        }
        $this->audit($profileId, 'retired', $actor, []);
    }

    private function compatibility(array $profile, array $mappings): array
    {
        $target = (string)$profile['target_type'];
        $inventoryTable = $target === self::TARGET_RIM ? 'hmrc_ct_rim_components' : 'hmrc_ct_computation_concepts';
        $packageColumn = $target === self::TARGET_RIM ? 'rim_package_id' : 'computation_package_id';
        $packageId = (int)$profile[$packageColumn];
        $inventory = \InterfaceDB::fetchAll('SELECT * FROM ' . $inventoryTable . ' WHERE package_id = :package_id', ['package_id' => $packageId]);
        $targets = [];
        foreach ($mappings as $mapping) {
            $targets[] = $target === self::TARGET_RIM ? (string)$mapping['target_xpath'] : (string)$mapping['taxonomy_concept'];
        }
        $available = [];
        $required = [];
        foreach ($inventory as $item) {
            $key = $target === self::TARGET_RIM ? (string)$item['component_path'] : (string)$item['qname'];
            $available[$key] = true;
            if (!empty($item['is_required'])) {
                $required[$key] = true;
            }
        }
        $missingTargets = array_values(array_filter(array_unique($targets), static fn(string $item): bool => !isset($available[$item])));
        $unmappedRequired = array_values(array_filter(array_keys($required), static fn(string $item): bool => !in_array($item, $targets, true)));
        $errors = [];
        if ($inventory === []) {
            $errors[] = 'The selected package has no catalogued schema or taxonomy inventory.';
        }
        if ($missingTargets !== []) {
            $errors[] = count($missingTargets) . ' mapping target(s) do not exist in the selected package.';
        }
        if ($unmappedRequired !== []) {
            $errors[] = count($unmappedRequired) . ' required package target(s) are unmapped.';
        }
        $sourceRows = \InterfaceDB::tableExists('ct_filing_canonical_sources') ? \InterfaceDB::fetchAll(
            'SELECT canonical_key, value_type, source_section, is_required
             FROM ct_filing_canonical_sources WHERE target_scope IN (:both, :target)',
            ['both' => 'both', 'target' => $target]
        ) : [];
        $mappedSources = array_map(static fn(array $mapping): string => (string)$mapping['canonical_key'], $mappings);
        $sourceInventory = [];
        foreach ($sourceRows as $sourceRow) {
            $sourceInventory[(string)$sourceRow['canonical_key']] = $sourceRow;
        }
        $unmappedSources = array_values(array_filter(
            array_map(static fn(array $row): string => (string)$row['canonical_key'], array_filter($sourceRows, static fn(array $row): bool => !empty($row['is_required']))),
            static fn(string $key): bool => !in_array($key, $mappedSources, true)
        ));
        if ($unmappedSources !== []) { $errors[] = count($unmappedSources) . ' required canonical source(s) are unmapped.'; }
        $invalidSources = [];
        $invalidRequiredPolicies = [];
        $invalidTypes = [];
        $invalidSections = [];
        foreach ($mappings as $mapping) {
            $key = (string)$mapping['canonical_key'];
            $source = $sourceInventory[$key] ?? null;
            if (!is_array($source)) {
                $invalidSources[] = $key;
                continue;
            }
            if ((string)$mapping['value_type'] !== (string)$source['value_type']) {
                $invalidTypes[] = $key;
            }
            if (!empty($source['is_required'])
                && empty($mapping['is_required'])
                && (string)($mapping['null_policy'] ?? '') !== 'error') {
                $invalidRequiredPolicies[] = $key;
            }
            if ($target === self::TARGET_COMPUTATION
                && (string)($mapping['presentation_section'] ?? '') !== (string)$source['source_section']) {
                $invalidSections[] = $key;
            }
        }
        if ($invalidSources !== []) { $errors[] = count(array_unique($invalidSources)) . ' mapping source(s) are not in the canonical filing inventory.'; }
        if ($invalidRequiredPolicies !== []) { $errors[] = count(array_unique($invalidRequiredPolicies)) . ' required canonical source mapping(s) do not fail closed when absent.'; }
        if ($invalidTypes !== []) { $errors[] = count(array_unique($invalidTypes)) . ' mapping value type(s) disagree with the canonical filing inventory.'; }
        if ($invalidSections !== []) { $errors[] = count(array_unique($invalidSections)) . ' computation mapping section(s) disagree with the canonical report model.'; }
        return [
            'errors' => $errors,
            'missing_targets' => $missingTargets,
            'unmapped_required_targets' => $unmappedRequired,
            'unmapped_canonical_sources' => $unmappedSources,
            'invalid_canonical_sources' => array_values(array_unique($invalidSources)),
            'invalid_required_policies' => array_values(array_unique($invalidRequiredPolicies)),
            'invalid_value_types' => array_values(array_unique($invalidTypes)),
            'invalid_presentation_sections' => array_values(array_unique($invalidSections)),
            'mapping_count' => count($mappings),
            'inventory_count' => count($inventory),
        ];
    }

    private function copyMappings(string $target, int $sourceId, int $profileId, int $packageId): void
    {
        if ($target === self::TARGET_RIM) {
            \InterfaceDB::prepareExecute(
                'INSERT INTO ct600_rim_mappings (profile_id, canonical_key, target_xpath, value_type, sign_multiplier, null_policy, is_required, sort_order)
                 SELECT :profile_id, m.canonical_key, m.target_xpath, m.value_type, m.sign_multiplier, m.null_policy, m.is_required, m.sort_order
                 FROM ct600_rim_mappings m INNER JOIN hmrc_ct_rim_components i
                   ON i.package_id = :package_id AND i.component_path = m.target_xpath WHERE m.profile_id = :source_id',
                ['profile_id' => $profileId, 'package_id' => $packageId, 'source_id' => $sourceId]
            );
            return;
        }
        \InterfaceDB::prepareExecute(
            'INSERT INTO ct_computation_ixbrl_mappings
             (profile_id, canonical_key, taxonomy_concept, namespace_uri, local_name, value_type, period_type, context_profile,
              unit_ref, decimals_value, dimensions_json, sign_multiplier, presentation_section, presentation_label, null_policy, is_required, sort_order)
             SELECT :profile_id, m.canonical_key, m.taxonomy_concept, m.namespace_uri, m.local_name, m.value_type, m.period_type, m.context_profile,
                    m.unit_ref, m.decimals_value, m.dimensions_json, m.sign_multiplier, m.presentation_section, m.presentation_label, m.null_policy, m.is_required, m.sort_order
             FROM ct_computation_ixbrl_mappings m INNER JOIN hmrc_ct_computation_concepts i
               ON i.package_id = :package_id AND i.qname = m.taxonomy_concept WHERE m.profile_id = :source_id',
            ['profile_id' => $profileId, 'package_id' => $packageId, 'source_id' => $sourceId]
        );
    }

    private function refreshContentHash(int $profileId): void
    {
        $profile = $this->profile($profileId);
        $table = (string)$profile['target_type'] === self::TARGET_RIM ? 'ct600_rim_mappings' : 'ct_computation_ixbrl_mappings';
        $rows = \InterfaceDB::fetchAll('SELECT * FROM ' . $table . ' WHERE profile_id = :profile_id ORDER BY canonical_key, id', ['profile_id' => $profileId]);
        foreach ($rows as &$row) {
            unset($row['id'], $row['created_at'], $row['updated_at']);
        }
        unset($row);
        \InterfaceDB::prepareExecute('UPDATE ct_filing_mapping_profiles SET content_hash = :hash WHERE id = :id', ['hash' => hash('sha256', (string)json_encode($rows, JSON_UNESCAPED_SLASHES)), 'id' => $profileId]);
    }

    private function profile(int $profileId): array
    {
        $profile = \InterfaceDB::fetchOne('SELECT * FROM ct_filing_mapping_profiles WHERE id = :id LIMIT 1', ['id' => $profileId]);
        if (!is_array($profile)) {
            throw new \RuntimeException('The mapping profile was not found.');
        }
        return $profile;
    }

    private function audit(int $profileId, string $event, string $actor, array $details): void
    {
        \InterfaceDB::prepareExecute(
            'INSERT INTO ct_filing_mapping_events (profile_id, event_type, actor, detail_json) VALUES (:profile_id, :event_type, :actor, :details)',
            ['profile_id' => $profileId, 'event_type' => $event, 'actor' => $actor, 'details' => json_encode($details, JSON_UNESCAPED_SLASHES)]
        );
    }

    private function assertTarget(string $targetType): void
    {
        if (!in_array($targetType, [self::TARGET_RIM, self::TARGET_COMPUTATION], true)) {
            throw new \InvalidArgumentException('Unsupported CT filing mapping target.');
        }
    }

    private function nullPolicy(mixed $value): string
    {
        $policy = trim((string)$value);
        return in_array($policy, ['omit', 'nil', 'error'], true) ? $policy : 'omit';
    }

    private function valueMatchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'numeric' => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
            'text' => is_string($value),
            default => false,
        };
    }

    private function mappingFailure(string $message): array
    {
        return [
            'success' => false,
            'errors' => [$message],
            'basis_version' => '',
            'basis_hash' => '',
            'computation_run_id' => 0,
            'ct_period_id' => 0,
            'canonical_values' => [],
            'mappings' => [],
        ];
    }
}
