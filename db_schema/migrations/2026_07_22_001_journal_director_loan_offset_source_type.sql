ALTER TABLE journals
  MODIFY source_type enum(
    'bank_csv',
    'director_loan_register',
    'director_loan_offset',
    'expense_register',
    'manual',
    'asset_register',
    'asset_depreciation',
    'asset_disposal'
  ) NOT NULL;
