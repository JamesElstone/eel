<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(\eel_accounts\Service\ExpenseClaimService::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof \eel_accounts\Service\ExpenseClaimService) {
        $harness->skip('Expense claim service did not instantiate.');
    }

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'parses historical Word table claim lines', function () use ($harness, $instance): void {
        $result = expenseClaimServiceParseBulkLines($instance, expenseClaimServiceHistoricalPaste(), 'd/m/Y');

        $harness->assertSame(true, (bool)($result['success'] ?? false));
        $harness->assertCount(13, (array)($result['rows'] ?? []));
        $harness->assertSame(1272.40, (float)($result['total'] ?? 0));

        $rows = (array)$result['rows'];
        $harness->assertSame('2022-10-05', (string)($rows[0]['expense_date'] ?? ''));
        $harness->assertSame('05/10/2022', (string)($rows[0]['expense_date_display'] ?? ''));
        $harness->assertSame('ElectricFix, Wall Chaser', (string)($rows[0]['description'] ?? ''));
        $harness->assertSame(94.99, (float)($rows[0]['amount'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'formats bulk preview dates with company display format', function () use ($harness, $instance): void {
        $source = "DATE\tDESCRIPTION\tAMOUNT CLAIMED\n5/10/2022\tElectricFix, Wall Chaser\t£94.99";

        $ymd = expenseClaimServiceParseBulkLines($instance, $source, 'Y-m-d');
        $slash = expenseClaimServiceParseBulkLines($instance, $source, 'd/m/Y');
        $dash = expenseClaimServiceParseBulkLines($instance, $source, 'd-m-Y');

        $harness->assertSame('2022-10-05', (string)(($ymd['rows'][0] ?? [])['expense_date_display'] ?? ''));
        $harness->assertSame('05/10/2022', (string)(($slash['rows'][0] ?? [])['expense_date_display'] ?? ''));
        $harness->assertSame('05-10-2022', (string)(($dash['rows'][0] ?? [])['expense_date_display'] ?? ''));
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'ignores non-line rows in pasted claim forms', function () use ($harness, $instance): void {
        $source = "Claimant\tJames Elstone\nYear\t2022\tMonth\tOctober\nDATE\tDESCRIPTION\tAMOUNT CLAIMED\n-\t-\t-\n5/10/2022\tElectricFix, Wall Chaser\t£94.99\nTotal Amount Claimed (sum of above lines)\tB\t£94.99\nDirector's Signature\t\nAmount Paid\t\tDate Paid\t\tFA Proc. Date\t\tFA Ref #\t";
        $result = expenseClaimServiceParseBulkLines($instance, $source, 'd/m/Y');

        $harness->assertSame(true, (bool)($result['success'] ?? false));
        $harness->assertCount(1, (array)($result['rows'] ?? []));
        $harness->assertSame(94.99, (float)($result['total'] ?? 0));
    });
});

function expenseClaimServiceParseBulkLines(\eel_accounts\Service\ExpenseClaimService $service, string $source, string $dateFormat): array
{
    $method = new ReflectionMethod($service, 'parseBulkLineText');
    $method->setAccessible(true);

    return (array)$method->invoke($service, $source, $dateFormat);
}

function expenseClaimServiceHistoricalPaste(): string
{
    return "Claimant\tJames Elstone\n"
        . "Year\t2022\tMonth\tOctober\n"
        . "DATE\tDESCRIPTION\tAMOUNT CLAIMED\n"
        . "5/10/2022\tElectricFix, Wall Chaser\t£94.99\n"
        . "6/10/2022\tVirgin Media Broadband Connection\t£47.60\n"
        . "8/10/2022\tTrade Skills 4 U Limited, Course Materials\t£140.00\n"
        . "10/10/2022\tRS Components, VDE Plyers\t£116.24\n"
        . "11/10/2022\tKnaphill Print Co Ltd, Business Cards\t£54.00\n"
        . "14/10/2022\tCEF, Cable and Labels\t£62.09\n"
        . "17/10/2022\tElectricFix, Training Equipment\t£74.95\n"
        . "17/10/2022\tTLC Ltd, Training Equipment\t£357.42\n"
        . "22/10/2022\tWickes, USB LED Light\t£16.65\n"
        . "22/10/2022\tCEF, Heat Gun & Battery, Wiring Regulations\t£205.68\n"
        . "23/10/2022\tCEF, Area Light\t£71.94\n"
        . "23/10/2022\tFuel\t£18.69\n"
        . "28/10/2022\tWickes, Plywood for Training Equipment\t£12.15\n"
        . "-\t-\t-\n"
        . "Total Amount Claimed (sum of above lines)\tB\t£1,272.40\n"
        . "Claimant's Signature\t\n"
        . "----- OFFICE USE ONLY BELOW THIS LINE -----\n"
        . "A\t(Unpaid) Balance outstanding to claimant brought forwards from previous period claim form\tNIL (First Claim)\n"
        . "B\tAmount claimed during month\t£1,272.40\n"
        . "C\tPayments made to claimant during this month\t£0.00\n"
        . "D\tBalance outstanding to claimant\t£1,272.40";
}
