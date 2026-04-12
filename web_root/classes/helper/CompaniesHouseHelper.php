<?php
declare(strict_types=1);

final class CompaniesHouseHelper
{
    public static function storedAddressLines(array $settings): array
    {
        $lines = [];

        foreach (
            [
                'registered_office_care_of',
                'registered_office_po_box',
                'registered_office_premises',
                'registered_office_address_line_1',
                'registered_office_address_line_2',
                'registered_office_locality',
                'registered_office_region',
                'registered_office_postal_code',
                'registered_office_country',
            ] as $field
        ) {
            $value = trim((string)($settings[$field] ?? ''));

            if ($value !== '') {
                $lines[] = $value;
            }
        }

        return array_values(array_unique($lines));
    }
}
