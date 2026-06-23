<?php

return array(
    'config_version' => 2,
    'environments' => array(
        'sandbox' => array(
            'api_key' => 'pk_test_replace_me',
            'api_secret' => 'sk_test_replace_me',
            'project_id' => 'prj_replace_me',
            'webhook_secret' => 'whsec_test_replace_me',
            'base_url' => 'https://api.paymos.io',
        ),
        'live' => array(
            'api_key' => 'pk_live_replace_me',
            'api_secret' => 'sk_live_replace_me',
            'project_id' => 'prj_replace_me',
            'webhook_secret' => 'whsec_live_replace_me',
            'base_url' => 'https://api.paymos.io',
        ),
    ),
);
