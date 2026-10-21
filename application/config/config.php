<?php if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}
/*
 * AIR-Tools config. Everything comes from the environment so one image runs locally and on Cloud Run.
 * Only settings that differ from upstream defaults belong here.
 */

$requireEnv = function ($name) {
    $value = getenv($name);
    if ($value === false || $value === '') {
        // Without this, LimeSurvey would silently generate random encryption keys per instance.
        throw new RuntimeException("Missing required environment variable $name");
    }
    return $value;
};

return array(
    'components' => array(
        'db' => array(
            'connectionString' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;',
                $requireEnv('DB_HOST'),
                $requireEnv('DB_PORT'),
                $requireEnv('DB_NAME')
            ),
            'emulatePrepare' => true,
            'username' => $requireEnv('DB_USERNAME'),
            'password' => $requireEnv('DB_PASSWORD'),
            'charset' => 'utf8mb4',
            'tablePrefix' => 'lime_',
        ),

        // Sessions in the DB, so any instance can serve any request.
        'session' => array(
            'class' => 'application.core.web.DbHttpSession',
            'connectionID' => 'db',
            'sessionTableName' => '{{sessions}}',
        ),

        // Behind Cloudflare and Cloud Run the Host header is the *.run.app origin.
        // Pin the public host so redirects and cookies stay on survey.air-tools.nl.
        'request' => getenv('PUBLIC_URL') ? array('hostInfo' => getenv('PUBLIC_URL')) : array(),

        'urlManager' => array(
            'urlFormat' => 'path',
            'rules' => array(),
            'showScriptName' => true,
        ),
    ),
    'config' => array(
        'debug' => 0,
        'debugsql' => 0,
        // Classic editor by default: the AirTools widgets attach to its pages. Users can still opt in.
        'editorEnabled' => false,
        // Same keys on every instance, or data encrypted on one cannot be read on another.
        'encryptionnonce' => $requireEnv('ENCRYPTION_NONCE'),
        'encryptionsecretboxkey' => $requireEnv('ENCRYPTION_SECRETBOX_KEY'),
    ),
);
