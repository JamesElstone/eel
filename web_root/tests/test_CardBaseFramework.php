<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->check(CardBaseFramework::class, 'loads as an abstract base class', function () use ($harness): void {
    $reflection = new ReflectionClass(CardBaseFramework::class);
    $harness->assertTrue($reflection->isAbstract());
});

if (!class_exists('_pagination_controls_testCard', false)) {
    final class _pagination_controls_testCard extends CardBaseFramework
    {
        public function render(array $context): string
        {
            return $this->paginationControls(
                $context,
                [
                    'items' => [11, 12, 13, 14, 15],
                    'page' => 3,
                    'page_size' => 5,
                    'total_items' => 30,
                    'total_pages' => 6,
                ],
                'Rows',
                'list',
                ['filter' => 'active'],
                'get',
                ['data-test' => 'pager'],
                'button compact',
                '<span class="page-marker">Page 3</span>',
                'pagination-extra'
            );
        }
    }
}

$harness->check(CardBaseFramework::class, 'array pagination controls do not require shared card actions', function () use ($harness): void {
    $card = new _user_logon_history_logCard();
    $rows = [];

    for ($i = 1; $i <= 6; $i++) {
        $rows[] = [
            'occurred_at' => '2026-05-10 10:0' . $i . ':00',
            'user_display_name' => 'Test User',
            'attempted_email_address' => '',
            'event_type' => 'login_success',
            'success' => 1,
            'ip_address' => '127.0.0.1',
            'browser_label' => 'Test Browser',
            'user_agent' => '',
            'reason' => '',
        ];
    }

    $html = $card->render([
        'page' => [
            'page_id' => 'users',
            'page_cards' => ['user_logon_history_log'],
            'user_logon_history_log_page' => 1,
        ],
        'services' => [
            'logon_rows' => $rows,
            'users' => [],
        ],
        'user_logon_history_log' => [
            'selected_user_id' => 0,
        ],
    ]);

    $harness->assertSame(false, str_contains($html, 'name="card_action" value="UserLogonHistory"'));
    $harness->assertTrue(str_contains($html, 'name="_pagination" value="1"'));
    $harness->assertTrue(str_contains($html, 'name="user_logon_history_log_page" value="2"'));
});

$harness->check(CardBaseFramework::class, 'renders first last and custom pagination controls', function () use ($harness): void {
    $html = (new _pagination_controls_testCard())->render([
        'page' => [
            'page_id' => 'users',
        ],
    ]);

    $harness->assertTrue(str_contains($html, '<div class="status-head pagination-extra">'));
    $harness->assertTrue(str_contains($html, 'Rows 11-15 of 30'));
    $harness->assertTrue(str_contains($html, '<span class="page-marker">Page 3</span>'));
    $harness->assertTrue(str_contains($html, '>First</button>'));
    $harness->assertTrue(str_contains($html, '>Prev</button>'));
    $harness->assertTrue(str_contains($html, '>Next</button>'));
    $harness->assertTrue(str_contains($html, '>Last</button>'));
    $harness->assertTrue(str_contains($html, 'method="get"'));
    $harness->assertTrue(str_contains($html, 'name="pagination_controls_test_list" value="6"'));
    $harness->assertTrue(str_contains($html, 'name="filter" value="active"'));
});
