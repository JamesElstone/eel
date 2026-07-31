ALTER TABLE hmrc_ct600_submissions
  MODIFY protocol_state enum(
    'prepared','validation_failed','ready','submitting','awaiting_poll',
    'final_received','delete_pending','closed','gateway_rejected',
    'transport_uncertain','invalidated'
  ) NOT NULL DEFAULT 'prepared';
