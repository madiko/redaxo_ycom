<?php

rex_config::set('ycom', 'auth_request_ref', 'returnTo');
rex_config::set('ycom', 'auth_cookie_ttl', '14');
rex_config::set('ycom/auth', 'auth_rule', 'login_try_5_pause');
rex_config::set('ycom', 'login_field', 'email');

rex_sql_table::get(rex::getTable('article'))
    ->ensureColumn(new rex_sql_column('ycom_auth_type', 'int', false, '0'))
    ->alter();

// saml dummy file
rex_file::copy(__DIR__.'/install/saml.php', rex_addon::get('project')->getDataPath('saml.php'));

// termofuse -> termsofuse. Version < 3.0
try {
    rex_sql::factory()
    ->setDebug()
    ->setQuery('delete from `' . rex_yform_manager_field::table() . '` where `table_name`="rex_ycom_user" and `type_id`="value" and `type_name`="checkbox" and `name`="termofuse_accepted"', [])
    ->setQuery('update `rex_config` set `key`="article_id_jump_termsofuse" where `key`="article_id_jump_termofuse" and `namespace`="ycom/auth"', [])
    ->setQuery('alter table `rex_ycom_user` drop if exists `termofuse_accepted`', [])
    ;
} catch (rex_sql_exception $e) {
   // dump($e);
}

rex_delete_cache();
