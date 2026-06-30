ALTER TABLE application_activity_flash_history
  MODIFY message_type enum('success','warning','error') NOT NULL;
