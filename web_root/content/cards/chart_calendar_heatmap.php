<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _chart_calendar_heatmapCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'chart_calendar_heatmap';
    }

    public function helper(array $context): string
    {
        return 'Server-rendered HTML calendar heat map example using accent colour levels.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'calendar_heatmap_charts',
                'service' => ChartService::class,
                'method' => 'demoCalendarCharts',
                'params' => [
                    'context' => ':chart_calendar_heatmap',
                ],
            ],
        ];
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);
        $context = (array)($pageContext[$this->key()] ?? []);

        $submittedDate = $request->input('calendar_heatmap_date', null);
        $currentDate = $this->normaliseDateInput($request->input('calendar_heatmap_selected_date', $context['selected_date'] ?? ''));
        $selectedDate = $this->normaliseDateInput($submittedDate ?? $currentDate);
        if ($selectedDate !== '') {
            $context['selected_date'] = $selectedDate;
        }

        $selectedYear = $this->normaliseYearInput($request->input('calendar_heatmap_year', $context['selected_year'] ?? '2026'));
        $context['selected_year'] = $selectedYear;

        if ($submittedDate === null && $currentDate !== '') {
            $context['selected_date'] = $this->dateInYear($currentDate, $selectedYear);
        }

        $pageContext[$this->key()] = $context;

        return $pageContext;
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['test.charts'];
    }

    public function render(array $context): string
    {
        $charts = (array)(($context['services'] ?? [])['calendar_heatmap_charts'] ?? []);
        $selectedDate = $this->selectedDate($context);

        return '<div class="chart-demo-grid chart-demo-grid-wide">'
            . $this->chartPanel((string)($charts['calendar_heatmap'] ?? ''), $selectedDate, $context)
            . '</div>';
    }

    private function chartPanel(string $chartHtml, string $selectedDate, array $context): string
    {
        return '<div class="chart-panel chart-panel-wide">'
            . '<form method="post" data-ajax="true">'
            . $this->hiddenInputs([
                'page' => (string)($context['page']['page_id'] ?? 'example_graphs'),
                'card_action' => 'CalendarHeatmap',
                'calendar_heatmap_selected_date' => $selectedDate,
            ])
            . $chartHtml
            . '</form>'
            . '<p class="helper calendar-heatmap-selection">Selected Day: ' . HelperFramework::escape($selectedDate) . '</p>'
            . '</div>';
    }

    private function selectedDate(array $context): string
    {
        $cardContext = (array)($context[$this->key()] ?? []);
        $selectedYear = $this->normaliseYearInput($cardContext['selected_year'] ?? '2026');
        $selectedDate = $this->normaliseDateInput($cardContext['selected_date'] ?? '');

        if ($selectedDate !== '' && str_starts_with($selectedDate, $selectedYear . '-')) {
            return $selectedDate;
        }

        return $selectedYear . '-05-14';
    }

    private function normaliseDateInput(mixed $value): string
    {
        if (is_array($value)) {
            $value = end($value);
        }

        $date = trim((string)$value);
        if ($date === '') {
            return '';
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();

        if (!$parsed instanceof DateTimeImmutable || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return '';
        }

        return $parsed->format('Y-m-d');
    }

    private function normaliseYearInput(mixed $value): string
    {
        if (is_array($value)) {
            $value = end($value);
        }

        $year = (int)$value;

        return in_array($year, [2024, 2025, 2026], true) ? (string)$year : '2026';
    }

    private function dateInYear(string $date, string $year): string
    {
        $candidate = $year . substr($date, 4);

        if ($this->normaliseDateInput($candidate) !== '') {
            return $candidate;
        }

        return $year . '-02-28';
    }

    private function hiddenInputs(array $fields): string
    {
        $html = '';

        foreach ($fields as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $html .= $this->hiddenInput((string)$name, $item);
                }
                continue;
            }

            $html .= $this->hiddenInput((string)$name, $value);
        }

        return $html;
    }

    private function hiddenInput(string $name, mixed $value): string
    {
        if (is_array($value) || is_object($value) || $value === null) {
            return '';
        }

        return '<input type="hidden" name="' . HelperFramework::escape($name) . '" value="' . HelperFramework::escape((string)$value) . '">';
    }
}
