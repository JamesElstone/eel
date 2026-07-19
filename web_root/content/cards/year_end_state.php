<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_stateCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'year_end_state';
    }

    public function title(): string
    {
        return 'Year-End Readiness';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['backup.database'];
    }

    public function services(): array
    {
        return [
            [
                'key' => 'backup_status',
                'service' => \eel_accounts\Service\DatabaseBackupService::class,
                'method' => 'fetchBackupStatus',
            ],
        ];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $checklist = $this->checklist($context);
        if ($checklist === []) {
            return '<div class="helper">Year-end checklist is not available for the selected accounting period.</div>';
        }

        return $this->renderControls($context, $checklist);
    }

    private function renderControls(array $context, array $checklist): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriod = (array)($checklist['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));
        $review = (array)($checklist['review'] ?? []);
        $isLocked = !empty($review['is_locked']);
        $lockIntent = $isLocked ? 'unlock_period' : 'lock_period';
        $lockLabel = $isLocked ? 'Unlock Period' : 'Run Year-End Close and Lock';
        $lockTitle = $isLocked
            ? 'Reopen this accounting period for changes.'
            : 'Runs the live preflight, creates a fresh database backup, completes the final close tasks, and locks this accounting period against further changes.';
        $latestBackupAt = $this->latestBackupCreatedAt($context);
        $hasChecklistBlockers = !empty((($context['year_end'] ?? [])['checklist_has_blockers'] ?? false));
        $lockDisabled = !$isLocked && $hasChecklistBlockers;
        $lockDisabledTitle = 'Resolve year-end checklist warnings and blockers before running the year-end close and locking this accounting period.';
        $status = (string)($checklist['overall_status'] ?? '');

        return '
            <section class="panel-soft settings-stack" data-year-end-state-card="true">
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">Status</div>
                        <div class="summary-value"><span class="badge ' . HelperFramework::escape($this->badgeClass($status)) . '">' . HelperFramework::escape($this->statusLabel($status)) . '</span></div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Calculation basis</div>
                        <div class="summary-value">Live</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Latest backup</div>
                        <div class="summary-value">' . HelperFramework::escape($latestBackupAt !== '' ? $latestBackupAt : 'No backup available') . '</div>
                    </div>
                </div>
                <div class="helper">A fresh database backup is created automatically after the live preflight passes and before any closing entries are posted.</div>
                <div class="helper">' . HelperFramework::escape($this->statusHelp($status, $isLocked)) . '</div>
                <div class="actions-row">
                    ' . $this->actionForm($companyId, $accountingPeriodId, 'recalculate', 'Refresh Year-End Checklist', $isLocked, $isLocked ? 'This accounting period is locked.' : 'Re-checks the year-end readiness checklist using the latest ledger, review, tax, and confirmation data.') . '
                    ' . $this->backupForm($context) . '
                    ' . $this->actionForm(
                        $companyId,
                        $accountingPeriodId,
                        $lockIntent,
                        $lockLabel,
                        $lockDisabled,
                        $lockDisabled ? $lockDisabledTitle : $lockTitle
                    ) . '
                    <button class="button" type="button" disabled>Export checklist</button>
                </div>
            </section>';
    }

    private function backupForm(array $context): string
    {
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

        return '<form method="post" data-ajax="true" data-year-end-state-form="true">
            ' . $this->hiddenCardFields($context) . '
            <input type="hidden" name="card_action" value="Backup">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <button class="button primary" type="submit" name="intent" value="create_database_backup" data-processing-text="Creating Backup" data-processing-state="disabled">Backup</button>
        </form>';
    }

    private function actionForm(int $companyId, int $accountingPeriodId, string $intent, string $label, bool $disabled = false, string $title = ''): string
    {
        $disabledAttribute = $disabled ? ' disabled' : '';
        $titleAttribute = $title !== '' ? ' title="' . HelperFramework::escape($title) . '"' : '';

        return '<form method="post" data-ajax="true" data-year-end-state-form="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="card_action" value="YearEnd">
            <input type="hidden" name="intent" value="' . HelperFramework::escape($intent) . '">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <button class="button primary" type="submit" data-year-end-state-submit="true" data-year-end-state-running-label="' . HelperFramework::escape($this->runningLabel($intent)) . '"' . $disabledAttribute . $titleAttribute . '>' . HelperFramework::escape($label) . '</button>
        </form>';
    }

    private function hiddenCardFields(array $context): string
    {
        $html = '';
        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        return $html;
    }

    private function latestBackupCreatedAt(array $context): string
    {
        $backups = (array)(($context['services']['backup_status'] ?? [])['recent_backups'] ?? []);
        $latest = (array)($backups[0] ?? []);

        return trim((string)($latest['created_at'] ?? ''));
    }

    private function runningLabel(string $intent): string
    {
        return match ($intent) {
            'lock_period' => 'Running Year-End Close...',
            'unlock_period' => 'Unlocking...',
            'recalculate' => 'Refreshing...',
            default => 'Working...',
        };
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'pass', 'ready', 'locked' => 'success',
            'ready_for_review' => 'success',
            'fail', 'needs_attention' => 'danger',
            'warning', 'not_started' => 'warning',
            default => 'info',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'ready_for_review' => 'Ready to Close and Lock',
            'locked' => 'Locked',
            'in_progress' => 'Checks Need Review',
            'needs_attention' => 'Needs Attention',
            'not_started' => 'Not Started',
            default => HelperFramework::labelFromKey($status, '_'),
        };
    }

    private function statusHelp(string $status, bool $isLocked): string
    {
        if ($isLocked || $status === 'locked') {
            return 'This accounting period is locked. Unlock it only if you need to make further ledger changes.';
        }

        return match ($status) {
            'ready_for_review' => 'All blocking checks are clear. You can now run the year-end close tasks and lock this accounting period.',
            'needs_attention' => 'Resolve the blocking checklist items before running the year-end close and lock.',
            'in_progress' => 'Complete or acknowledge the remaining checklist items before running the year-end close and lock.',
            'not_started' => 'Refresh the year-end checklist once source data is available for this period.',
            default => 'Use the checklist to confirm this period is ready for the year-end close and lock.',
        };
    }

    private function renderErrors(array $errors): string
    {
        $html = '';
        foreach ($errors as $error) {
            $html .= '<div class="helper">' . HelperFramework::escape((string)$error) . '</div>';
        }

        return $html;
    }

    private function checklist(array $context): array
    {
        return (array)(($context['year_end'] ?? [])['checklist'] ?? (($context['services'] ?? [])['yearEndChecklist'] ?? []));
    }
}
