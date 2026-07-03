<?php
$_['heading_title']     = 'KeyCRM Sync';

$_['text_extension']    = 'Розширення';
$_['text_home']         = 'Головна';
$_['text_success']      = 'Налаштування збережено!';
$_['text_edit']         = 'Налаштування синхронізації';
$_['text_general']      = 'Загальні';
$_['text_targets']      = 'CRM-системи';
$_['text_none']         = '--- Немає ---';
$_['text_on_create']    = 'При створенні замовлення';
$_['text_on_status']    = 'При зміні статусу';
$_['text_log_link']     = 'Журнал синхронізації';
$_['text_test_ok']      = 'Зʼєднання OK';
$_['text_secret_set']   = '(збережено — залиште порожнім, щоб не змінювати)';
$_['text_log']          = 'Журнал синхронізації (останні 100)';
$_['text_empty']        = 'Записів немає.';
$_['text_reverse']      = 'Зворотна синхронізація';
$_['text_never']        = 'ще не виконувалась';
$_['text_statuses_loaded'] = 'Статуси завантажено';

$_['entry_status']         = 'Статус модуля';
$_['entry_send_on']        = 'Коли надсилати';
$_['entry_trigger']        = 'Статус-тригер';
$_['entry_skip_zero']      = 'Пропускати позиції з нульовою ціною';
$_['entry_include_ship']   = 'Додавати вартість доставки';
$_['entry_retry']          = 'Повторні спроби (cron)';
$_['entry_max_attempts']   = 'Макс. спроб';
$_['entry_source']         = 'Мітка джерела';

$_['entry_enabled']    = 'Увімкнено';
$_['entry_base_url']   = 'URL API';
$_['entry_api_key']    = 'API-ключ';
$_['entry_source_id']  = 'Source ID';
$_['entry_form_id']    = 'Form ID';

$_['entry_reverse_enabled'] = 'Увімкнути зворотну синхронізацію';
$_['entry_reverse_notify']  = 'Повідомляти клієнта';
$_['entry_reverse_stock']   = 'Синхронізувати залишки';
$_['entry_reverse_last']    = 'Останній запуск';
$_['entry_reverse_map']     = 'Мапінг статусів';

$_['column_keycrm_status'] = 'Статус KeyCRM';
$_['column_oc_status']     = 'Статус OpenCart';

$_['column_order']     = 'Замовлення';
$_['column_target']    = 'CRM';
$_['column_status']    = 'Статус';
$_['column_external']  = 'ID у CRM';
$_['column_attempts']  = 'Спроб';
$_['column_error']     = 'Помилка';
$_['column_updated']   = 'Оновлено';

$_['button_save']          = 'Зберегти';
$_['button_test']          = 'Перевірити';
$_['button_refresh']       = 'Оновити';
$_['button_load_statuses'] = 'Завантажити статуси KeyCRM';

$_['help_send_on']     = 'Створення надсилає одразу після оформлення; зміна статусу — коли замовлення отримає обраний статус.';
$_['help_api_key']     = 'Зберігається у зашифрованому вигляді. Порожнє поле = не змінювати наявний ключ.';
$_['help_reverse']        = 'Cron-завдання «cc_crm_reverse» тягне з KeyCRM замовлення, оновлені з часу останнього запуску, і застосовує статуси та ТТН до замовлень OpenCart. Зворотна синхронізація нічого не надсилає у KeyCRM.';
$_['help_reverse_notify'] = 'Надсилати клієнту лист при зміні статусу замовлення зі зворотної синхронізації.';
$_['help_reverse_stock']  = 'Оновлювати кількість товарів (за SKU) із залишків KeyCRM.';
$_['help_reverse_map']    = 'Застосовуються лише статуси, що є в мапі. Введіть API-ключ на вкладці CRM-систем і натисніть «Завантажити статуси KeyCRM».';

$_['error_permission'] = 'У вас немає прав керувати цим модулем!';
