<?php
$_['heading_title']     = 'KeyCRM Sync';

$_['text_extension']    = 'Extensions';
$_['text_home']         = 'Home';
$_['text_success']      = 'Settings saved!';
$_['text_edit']         = 'Sync settings';
$_['text_general']      = 'General';
$_['text_targets']      = 'CRM systems';
$_['text_none']         = '--- None ---';
$_['text_on_create']    = 'On order placement';
$_['text_on_status']    = 'On status change';
$_['text_log_link']     = 'Sync journal';
$_['text_test_ok']      = 'Connection OK';
$_['text_secret_set']   = '(stored — leave blank to keep)';
$_['text_log']          = 'Sync journal (last 100)';
$_['text_empty']        = 'No records.';
$_['text_reverse']      = 'Reverse sync';
$_['text_never']        = 'not run yet';
$_['text_statuses_loaded'] = 'Statuses loaded';

$_['entry_status']         = 'Module status';
$_['entry_send_on']        = 'When to send';
$_['entry_trigger']        = 'Trigger status';
$_['entry_skip_zero']      = 'Skip zero-price items';
$_['entry_include_ship']   = 'Include shipping cost';
$_['entry_retry']          = 'Retry failed (cron)';
$_['entry_max_attempts']   = 'Max attempts';
$_['entry_source']         = 'Source label';

$_['entry_enabled']    = 'Enabled';
$_['entry_base_url']   = 'API URL';
$_['entry_api_key']    = 'API key';
$_['entry_source_id']  = 'Source ID';
$_['entry_form_id']    = 'Form ID';

$_['entry_reverse_enabled'] = 'Enable reverse sync';
$_['entry_reverse_notify']  = 'Notify customer';
$_['entry_reverse_stock']   = 'Sync stock levels';
$_['entry_reverse_last']    = 'Last run';
$_['entry_reverse_map']     = 'Status mapping';

$_['column_keycrm_status'] = 'KeyCRM status';
$_['column_oc_status']     = 'OpenCart status';

$_['column_order']     = 'Order';
$_['column_target']    = 'CRM';
$_['column_status']    = 'Status';
$_['column_external']  = 'CRM ID';
$_['column_attempts']  = 'Attempts';
$_['column_error']     = 'Error';
$_['column_updated']   = 'Updated';

$_['button_save']          = 'Save';
$_['button_test']          = 'Test';
$_['button_refresh']       = 'Refresh';
$_['button_load_statuses'] = 'Load KeyCRM statuses';

$_['help_send_on']     = 'Placement sends right after checkout; status change sends when the order reaches the selected status.';
$_['help_api_key']     = 'Stored encrypted. Blank field = keep the current key.';
$_['help_reverse']        = 'The "cc_crm_reverse" cron task pulls orders updated since the previous run from KeyCRM and applies statuses and tracking codes to OpenCart orders. Reverse sync never sends anything to KeyCRM.';
$_['help_reverse_notify'] = 'Email the customer when reverse sync changes the order status.';
$_['help_reverse_stock']  = 'Update product quantities (matched by SKU) from KeyCRM stock levels.';
$_['help_reverse_map']    = 'Only statuses present in the map are applied. Enter the API key on the CRM systems tab and click "Load KeyCRM statuses".';

$_['error_permission'] = 'You do not have permission to manage this module!';
