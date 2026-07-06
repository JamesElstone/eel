<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Renderer;

final class YearEndApprovalRenderer
{
    public const NOTE_HIDDEN = 'hidden';
    public const NOTE_OPTIONAL = 'optional';
    public const NOTE_REQUIRED = 'required';

    public static function render(array $options): string
    {
        $companyId = (int)($options['companyId'] ?? 0);
        $accountingPeriodId = (int)($options['accountingPeriodId'] ?? 0);
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return (string)($options['missingContextHtml'] ?? '');
        }

        if (!array_key_exists('locked', $options)) {
            $options['locked'] = (new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId);
        }

        return !empty($options['acknowledged'])
            ? self::completed($options, $companyId, $accountingPeriodId)
            : self::pending($options, $companyId, $accountingPeriodId);
    }

    private static function completed(array $options, int $companyId, int $accountingPeriodId): string
    {
        $note = trim((string)($options['note'] ?? ''));
        $revokeIntent = trim((string)($options['revokeIntent'] ?? $options['intent'] ?? ''));
        if ($revokeIntent === '') {
            return '';
        }

        return '<section class="panel-soft success settings-stack">
            <div class="eyebrow">Approval</div>
            ' . ($note !== '' ? '<div class="summary-value">' . \HelperFramework::escape($note) . '</div>' : '') . '
            <div class="stat-foot">' . \HelperFramework::escape(self::approvedFoot(
                (string)($options['approvedAt'] ?? $options['acknowledgedAt'] ?? ''),
                (string)($options['approvedBy'] ?? $options['acknowledgedBy'] ?? '')
            )) . '</div>
            ' . (!empty($options['locked'])
                ? '<div class="helper">This accounting period is locked, so this approval cannot be revoked.</div>'
                : '<div class="actions-row">
                <div class="year-end-related-workflow">
                    <form method="post" data-ajax="true">
                        ' . self::commonFields($companyId, $accountingPeriodId, $revokeIntent) . '
                        ' . self::hiddenFields((array)($options['revokeFields'] ?? [])) . '
                        <button class="button" type="submit">Revoke approval</button>
                    </form>
                </div>
            </div>') . '
        </section>';
    }

    private static function pending(array $options, int $companyId, int $accountingPeriodId): string
    {
        $intent = trim((string)($options['intent'] ?? ''));
        $subject = trim((string)($options['subject'] ?? 'data'));
        if ($intent === '') {
            return '';
        }

        $locked = !empty($options['locked']);
        $disabled = $locked || !empty($options['disabled']);
        $disabledReason = trim((string)($options['disabledReason'] ?? ''));
        if ($locked && $disabledReason === '') {
            $disabledReason = 'This accounting period is locked, so this approval cannot be changed.';
        }
        $noteMode = self::noteMode((string)($options['noteMode'] ?? self::NOTE_OPTIONAL));
        $checkboxName = trim((string)($options['checkboxName'] ?? 'approval_confirmed'));
        $approveFields = (array)($options['approveFields'] ?? []);
        unset($approveFields[$checkboxName]);
        $buttonAttributes = $disabled
            ? ' disabled' . ($disabledReason !== '' ? ' title="' . \HelperFramework::escape($disabledReason) . '"' : '')
            : ' disabled data-year-end-ack-submit';

        return '<section class="panel-soft warn full settings-stack">
            <div class="eyebrow">Approval</div>
            <form method="post" data-ajax="true" class="form-grid" data-year-end-ack-form="true">
                ' . self::commonFields($companyId, $accountingPeriodId, $intent) . '
                ' . self::hiddenFields($approveFields) . '
                <label class="checkbox-row full">
                    <input type="checkbox" name="' . \HelperFramework::escape($checkboxName) . '" value="1" required data-year-end-ack-checkbox' . ($disabled ? ' disabled' : '') . '>
                    <span>I confirm that I have reviewed the ' . \HelperFramework::escape($subject) . ' shown above and approve it as accurate for Year End.</span>
                </label>
                ' . self::noteField($options, $noteMode, $disabled) . '
                ' . ($disabledReason !== '' ? '<div class="helper full">' . \HelperFramework::escape($disabledReason) . '</div>' : '') . '
                <div class="actions-row"><button class="button primary" type="submit"' . $buttonAttributes . '>Approve for Year End</button></div>
            </form>
        </section>';
    }

    private static function commonFields(int $companyId, int $accountingPeriodId, string $intent): string
    {
        return self::hiddenFields([
            'card_action' => 'YearEnd',
            'intent' => $intent,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]);
    }

    private static function noteField(array $options, string $noteMode, bool $disabled): string
    {
        if ($noteMode === self::NOTE_HIDDEN) {
            return '';
        }

        $noteName = trim((string)($options['noteName'] ?? 'approval_note'));
        if ($noteName === '') {
            return '';
        }

        $noteId = trim((string)($options['noteId'] ?? self::fieldId($noteName)));
        return '<div class="form-row full">
            <label for="' . \HelperFramework::escape($noteId) . '">Approval notes</label>
            <textarea class="input" id="' . \HelperFramework::escape($noteId) . '" name="' . \HelperFramework::escape($noteName) . '" rows="3"' . ($noteMode === self::NOTE_REQUIRED ? ' required' : '') . ($disabled ? ' disabled' : '') . '></textarea>
        </div>';
    }

    private static function hiddenFields(array $fields): string
    {
        $html = '';
        foreach ($fields as $name => $value) {
            $html .= self::hiddenField((string)$name, $value);
        }

        return $html;
    }

    private static function hiddenField(string $name, mixed $value): string
    {
        if ($name === '' || $value === null) {
            return '';
        }

        if (is_array($value)) {
            $html = '';
            foreach ($value as $childName => $childValue) {
                $fieldName = is_int($childName) ? $name . '[]' : $name . '[' . (string)$childName . ']';
                $html .= self::hiddenField($fieldName, $childValue);
            }

            return $html;
        }

        return '<input type="hidden" name="' . \HelperFramework::escape($name) . '" value="' . \HelperFramework::escape((string)$value) . '">';
    }

    private static function approvedFoot(string $approvedAt, string $approvedBy): string
    {
        $approvedAt = trim($approvedAt);
        $approvedBy = trim($approvedBy);

        return 'Approved'
            . ($approvedAt !== '' ? ' at ' . $approvedAt : '')
            . ($approvedBy !== '' ? ' by ' . $approvedBy : '')
            . '.';
    }

    private static function noteMode(string $noteMode): string
    {
        return in_array($noteMode, [self::NOTE_HIDDEN, self::NOTE_OPTIONAL, self::NOTE_REQUIRED], true)
            ? $noteMode
            : self::NOTE_OPTIONAL;
    }

    private static function fieldId(string $name): string
    {
        $id = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '-', $name) ?? '');
        return trim(str_replace('_', '-', $id), '-') ?: 'approval-notes';
    }
}
