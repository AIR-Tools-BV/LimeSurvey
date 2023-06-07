<?php

namespace LimeSurvey\Api\Rest\Endpoint;

use LSHttpRequest;
use Yii;
use DI\FactoryInterface;
use LimeSurvey\Api\{
    Rest\Endpoint,
    ApiException
};

class EndpointFactory
{
    protected FactoryInterface $diFactory;

    /**
     * @param FactoryInterface $diFactory
     */
    public function __construct(FactoryInterface $diFactory)
    {
        $this->diFactory = $diFactory;
    }

    /**
     * Create
     *
     * @param LSHttpRequest $request
     * @return Endpoint
     */
    public function create(LSHttpRequest $request)
    {
        $endpointConfig = $this->getEndpointConfig($request);
        return $this->diFactory->make(Endpoint::class, [
            'config' => $endpointConfig,
            'commandParams' => $this->getCommandParams($endpointConfig, $request)
        ]);
    }

    /**
     * Get Endpoint
     *
     * @param LSHttpRequest $request
     * @throws ApiException
     * @return array
     */
    protected function getEndpointConfig(LSHttpRequest $request)
    {
        // rest config contains specification of all endpoints
        $restConfig = Yii::app()->getConfig('rest');
        $apiVersion = $request->getParam('_api_version');
        $entity = $request->getParam('_entity');
        $id = $request->getParam('_id', null);
        $requestMethod = $request->getRequestType();

        // lookup the endpoint config matching the http request
        $endpointConfig = [];
        foreach ($restConfig as $key => $config) {
            $keyParts = explode('/', $key);

            $keyApiVersion = null;
            $keyEntity = null;
            $keyId = null;
            $keyPartsCount = count($keyParts);
            if ($keyPartsCount == 2) {
                [$keyApiVersion, $keyEntity] = $keyParts;
            } elseif ($keyPartsCount == 3) {
                [$keyApiVersion, $keyEntity, $keyId] = $keyParts;
            }

            $keyId = !empty($keyId)
                ? ltrim($keyId, '$')
                : false;

            if (
                $keyApiVersion == $apiVersion
                && $keyEntity == $entity
                && is_array($config[$requestMethod])
                && (false === $keyId || !is_null($id))
            ) {
                $endpointConfig = $config[$requestMethod];

                $endpointConfig['apiVersion'] = $apiVersion;
                break;
            }
        }

        if (!$endpointConfig) {
            throw new ApiException('Endpoint not configured');
        }
        if (!isset($endpointConfig['commandClass'])) {
            throw new ApiException('Command class not specified');
        }
        if (!class_exists($endpointConfig['commandClass'])) {
            throw new ApiException('Invalid command class');
        }

        return $endpointConfig;
    }

    /**
     * Get Command Params
     *
     * Parse REST API command params from HTTP request.
     *
     * @param array $endpoint
     * @param LSHttpRequest $request
     * @return array
     */
    public function getCommandParams($endpoint, LSHttpRequest $request)
    {
        $params = [];

        if (
            !empty($endpoint['auth'])
            && $endpoint['auth'] == 'session'
        ) {
            $params['sessionKey'] = $this->getAuthToken();
        }

        // REST route defines optional param '_id'
        if ($id = $request->getParam('_id')) {
            $params['_id'] = $id;
        }

        $content = $request->getRestParams();
        $query = $this->getParams($endpoint, $request);
        $source = [
            '_content' => $content,
            '_query' => $query
        ];

        return array_merge(
            $query,
            $params,
            $content,
            $source
        );
    }

    /**
     * Get Query Params
     *
     * Parse REST config params from the HTTP request.
     *
     * @param array $endpoint
     * @param LSHttpRequest $request
     * @return array
     */
    protected function getParams($endpoint, LSHttpRequest $request)
    {
        $result = [];
        if (
            isset($endpoint['params'])
            && is_array($endpoint['params'])
        ) {
            foreach ($endpoint['params'] as $paramName => $options) {
                if (!$options) {
                    continue; // turned off
                }
                $default = is_array($options) && isset($options['default'])
                    ? $options['default']
                    : null;
                $result[$paramName] = $request->getParam(
                    $paramName,
                    $default
                );
            }
        }
        return $result;
    }

    /**
     * Get auth token.
     *
     * Attempts to read from 'authToken' GET parameter and falls back to authorisation bearer token.
     *
     * @return string|null
     */
    public function getAuthToken()
    {
        $token = Yii::app()->request->getParam('authToken');
        if (!$token) {
            $token = $this->getAuthBearerToken();
        }
        return $token;
    }

    /**
     * Get auth bearer token.
     *
     * Attempts to read bearer token from authorisation header.
     *
     * @return string|null
     */
    protected function getAuthBearerToken()
    {
        $headers = $this->getAllHeaders();

        $token = null;
        if (
            isset($headers['Authorization'])
            && strpos(
                $headers['Authorization'],
                'Bearer '
            ) === 0
        ) {
            $token = substr($headers['Authorization'], 7);
        }

        return $token;
    }


    /**
     * Get all HTTP header key/values as an associative array for the current request.
     *
     * @source https://github.com/ralouphie/getallheaders
     * @return array The HTTP header key/value pairs.
     */
    protected function getAllHeaders()
    {
        $headers = array();

        $copy_server = array(
            'CONTENT_TYPE'   => 'Content-Type',
            'CONTENT_LENGTH' => 'Content-Length',
            'CONTENT_MD5'    => 'Content-Md5',
        );

        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) === 'HTTP_') {
                $key = substr($key, 5);
                if (!isset($copy_server[$key]) || !isset($_SERVER[$key])) {
                    $key = str_replace(
                        ' ',
                        '-',
                        ucwords(
                            strtolower(
                                str_replace('_', ' ', $key)
                            )
                        )
                    );
                    $headers[$key] = $value;
                }
            } elseif (isset($copy_server[$key])) {
                $headers[$copy_server[$key]] = $value;
            }
        }

        return $headers;
    }
}
