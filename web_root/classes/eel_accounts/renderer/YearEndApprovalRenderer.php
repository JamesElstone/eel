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
            <div class="eyebrow">Year End Confirmation</div>
            ' . ($note !== '' ? '<div class="summary-value">' . \HelperFramework::escape($note) . '</div>' : '') . '
            ' . self::approvedQuestionSummary((array)($options['questions'] ?? []), (array)($options['answers'] ?? [])) . '
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
        $confirmationText = trim((string)($options['confirmationText'] ?? ''));
        if ($confirmationText === '') {
            $confirmationText = 'I confirm that I have reviewed the ' . $subject . ' shown above and approve it as accurate for Year End.';
        }

        return self::staleEvidence($options) . '<section class="panel-soft warn full settings-stack">
            <div class="eyebrow">Year End Confirmation</div>
            <form method="post" data-ajax="true" class="form-grid" data-year-end-ack-form="true">
                ' . self::commonFields($companyId, $accountingPeriodId, $intent) . '
                ' . self::hiddenFields($approveFields) . '
                ' . self::questionFields((array)($options['questions'] ?? []), (array)($options['answers'] ?? []), $disabled) . '
                <label class="checkbox-row full">
                    <input type="checkbox" name="' . \HelperFramework::escape($checkboxName) . '" value="1" required data-year-end-ack-checkbox' . ($disabled ? ' disabled' : '') . '>
                    <span>' . \HelperFramework::escape($confirmationText) . '</span>
                </label>
                ' . self::noteField($options, $noteMode, $disabled) . '
                ' . ($disabledReason !== '' ? '<div class="helper full">' . \HelperFramework::escape($disabledReason) . '</div>' : '') . '
                <div class="actions-row"><button class="button primary" type="submit"' . $buttonAttributes . '>Approve for Year End</button></div>
            </form>
        </section>';
    }

    private static function staleEvidence(array $options): string
    {
        $state = trim((string)($options['acknowledgementState'] ?? ''));
        $approvedAt = trim((string)($options['approvedAt'] ?? $options['acknowledgedAt'] ?? ''));
        $approvedBy = trim((string)($options['approvedBy'] ?? $options['acknowledgedBy'] ?? ''));
        $note = trim((string)($options['note'] ?? ''));
        if (!in_array($state, ['stale', 'unverifiable'], true)
            && $approvedAt === '' && $approvedBy === '' && $note === '') {
            return '';
        }

        $message = $state === 'unverifiable'
            ? 'Review required — the current live basis could not be verified.'
            : 'Review required — underlying data changed.';

        return '<section class="panel-soft warn full settings-stack">
            <div class="eyebrow">Previous Year End Confirmation</div>
            <div class="summary-value">' . \HelperFramework::escape($message) . '</div>
            ' . ($note !== '' ? '<div class="helper">Original note: ' . \HelperFramework::escape($note) . '</div>' : '') . '
            <div class="stat-foot">' . \HelperFramework::escape(self::approvedFoot($approvedAt, $approvedBy)) . '</div>
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
            <label for="' . \HelperFramework::escape($noteId) . '">Confirmation notes</label>
            <textarea class="input" id="' . \HelperFramework::escape($noteId) . '" name="' . \HelperFramework::escape($noteName) . '" rows="3"' . ($noteMode === self::NOTE_REQUIRED ? ' required' : '') . ($disabled ? ' disabled' : '') . '></textarea>
        </div>';
    }

    /**
     * Questions are generated from the same canonical bundle which is hashed
     * for approval.  Cards must not hand-roll parallel acknowledgement forms.
     */
    private static function questionFields(array $questions, array $answers, bool $disabled): string
    {
        $html = '';
        foreach ($questions as $question) {
            $question = (array)$question;
            $id = trim((string)($question['id'] ?? ''));
            $prompt = trim((string)($question['prompt'] ?? ''));
            if ($id === '' || $prompt === '') {
                continue;
            }
            $field = 'approval_answers[' . $id . ']';
            $value = $answers[$id] ?? '';
            $required = !empty($question['required']) ? ' required' : '';
            $disabledAttribute = $disabled ? ' disabled' : '';
            if ((string)($question['type'] ?? '') === 'text') {
                $html .= '<div class="form-row full"><label>' . \HelperFramework::escape($prompt)
                    . '<textarea class="input" name="' . \HelperFramework::escape($field) . '" rows="3"'
                    . $required . $disabledAttribute . '>' . \HelperFramework::escape((string)$value) . '</textarea></label></div>';
                continue;
            }

            $options = (array)($question['options'] ?? []);
            $html .= '<fieldset class="panel-soft full"><legend>' . \HelperFramework::escape($prompt) . '</legend><div class="actions-row">';
            foreach ($options as $optionValue => $optionLabel) {
                $inputId = self::fieldId($id . '-' . (string)$optionValue);
                $html .= '<label for="' . \HelperFramework::escape($inputId) . '"><input id="'
                    . \HelperFramework::escape($inputId) . '" type="radio" name="' . \HelperFramework::escape($field)
                    . '" value="' . \HelperFramework::escape((string)$optionValue) . '"'
                    . ((string)$value === (string)$optionValue ? ' checked' : '') . $required . $disabledAttribute . '> '
                    . \HelperFramework::escape((string)$optionLabel) . '</label>';
            }
            $html .= '</div></fieldset>';
        }
        return $html;
    }

    private static function approvedQuestionSummary(array $questions, array $answers): string
    {
        if ($questions === [] || $answers === []) {
            return '';
        }
        $items = '';
        foreach ($questions as $question) {
            $question = (array)$question;
            $id = (string)($question['id'] ?? '');
            if ($id === '' || !array_key_exists($id, $answers)) {
                continue;
            }
            $options = (array)($question['options'] ?? []);
            $answer = (string)$answers[$id];
            $label = (string)($options[$answer] ?? $answer);
            $items .= '<dt>' . \HelperFramework::escape((string)($question['prompt'] ?? $id)) . '</dt><dd>'
                . \HelperFramework::escape($label) . '</dd>';
        }
        return $items !== '' ? '<dl class="definition-list">' . $items . '</dl>' : '';
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
