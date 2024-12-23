<?php if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}
/*
| -------------------------------------------------------------------
| DATABASE CONNECTIVITY SETTINGS
| -------------------------------------------------------------------
| This file will contain the settings needed to access your database.
|
| For complete instructions please consult the 'Database Connection'
| page of the User Guide.
|
| -------------------------------------------------------------------
| EXPLANATION OF VARIABLES
| -------------------------------------------------------------------
|
|   'connectionString' Hostname, database, port and database type for
|    the connection. Driver example: mysql. Currently supported:
|               mysql, pgsql, mssql, sqlite, oci
|   'username' The username used to connect to the database
|   'password' The password used to connect to the database
|   'tablePrefix' You can add an optional prefix, which will be added
|               to the table name when using the Active Record class
|
*/
return array(
    'components' => array(
        'db' => array(
            'connectionString' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;',
                getenv('DB_HOST'),
                getenv('DB_PORT'),
                getenv('DB_NAME')
            ),
            'emulatePrepare' => true,
            'username' => getenv('DB_USERNAME'),
            'password' => getenv('DB_PASSWORD'),
            'charset' => 'utf8mb4',
            'tablePrefix' => 'lime_',
        ),

        'cache'=>array(
            'class'=>'CRedisCache',
            'hostname'=>'redis.default.svc.cluster.local',
            'port'=>6379,
            'database'=>0,
            'options'=>STREAM_CLIENT_CONNECT,
        ),

        'session' => array(
            'class' => 'application.core.web.DbHttpSession',
            'connectionID' => 'db',
            'sessionTableName' => '{{sessions}}',
            'cookieParams' => array(
                'httpOnly' => true,
                'secure' => true, // Ensure cookies are sent over HTTPS
                'sameSite' => 'Lax', // Adjust based on your requirements
            ),
            // Other session configurations...
        ),

        'request' => array(
            // Enable CSRF Validation
            'enableCsrfValidation' => true,
            // Assuming your load balancer terminates SSL and communicates with your backend over HTTP
            'csrfCookie' => array(
                'sameSite' => 'Lax',
                'secure' => true,
            ),
        ),

        'urlManager' => array(
            'urlFormat' => 'path',
            'rules' => array(
                // You can add your own rules here
            ),
            'showScriptName' => true,
        ),
        'log' => array(
            'routes' => array(
                'fileError' => array(
                    'class' => 'CFileLogRoute',
                    'levels' => 'trace, info, warning, error',
                    'except' => 'exception.CHttpException.404',
                    'logFile' => '/var/www/html/tmp/limesurvey-test-lars.log', // Update this to your desired log path
                ),
            ),
        ),

    ),
    // Use the following config variable to set modified optional settings copied from config-defaults.php
    'config'=>array(
        // debug: Set this to 1 if you are looking for errors. If you still get no errors after enabling this
        // then please check your error-logs - either in your hosting provider admin panel or in some /logs directory
        // on your webspace.
        // LimeSurvey developers: Set this to 2 to additionally display STRICT PHP error messages and put MySQL in STRICT mode and get full access to standard themes
        'debug'=>0,
        'debugsql'=>0, // Set this to 1 to enanble sql logging, only active when debug = 2
        // 'force_xmlsettings_for_survey_rendering' => true, // Uncomment if you want to force the use of the XML file rather than DB (for easy theme development)
        // 'use_asset_manager'=>true, // Uncomment if you want to use debug mode and asset manager at the same time
        // Update default LimeSurvey config here

        'mysqlEngine' => 'INNODB',
        'memory_limit' => '16384'
    )
);
/* End of file config.php */
/* Location: ./application/config/config.php */
