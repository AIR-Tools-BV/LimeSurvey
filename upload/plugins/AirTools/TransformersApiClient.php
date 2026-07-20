<?php

class TransformersApiClient
{
    private $apiKey;
    private $apiUrl;
    private $apiPluginUri = '/v1/plugin';
    private $apiStatisticsUri = '/v1/statistics';

    public function __construct($apiKey, $apiUrl)
    {
        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($apiUrl, '/');
    }

    private function sendRequest($method, $endpoint, $data = null, $stream = false, $statisticsEndpoint = false, $content_type = "application/json")
    {
        $uri = $statisticsEndpoint ? $this->apiStatisticsUri : $this->apiPluginUri;
        $url = $this->apiUrl . $uri . $endpoint;

        $headers = [
            "Content-Type: {$content_type}",
            "Authorization: Bearer {$this->apiKey}",
        ];

        if ($data && isset($data['task_id'])) {
            $headers[] = "X-Webhook-Secret: " . $this->create_shared_secret($data['task_id']);
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => !$stream,
        ];

        if ($data) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        $ch = curl_init();
        curl_setopt_array($ch, $options);

        if ($stream) {
            if (!ob_get_level()) {
                ob_start();
            }
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
                echo $data;
                ob_flush();
                flush();
                return strlen($data);
            });
            curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception(curl_error($ch));
            }
            curl_close($ch);
        } else {
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception(curl_error($ch));
            }
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [
                'response' => $content_type === "application/json" ? json_decode($response, true) : $response,
                'httpCode' => $httpCode
            ];
        }
    }

    private function uploadFile($file, $parameters, $endpoint)
    {
        $url = $this->apiUrl . $this->apiPluginUri . $endpoint;
        $headers = [
            "Authorization: Bearer {$this->apiKey}",
        ];

        if (isset($parameters['task_id'])) {
            $headers[] = "X-Webhook-Secret: " . $this->create_shared_secret($parameters['task_id']);
        }

        $postFields = [
            'file' => new CURLFile($file['tmp_name'], $file['type'], $file['name']),
        ];

        // Merge any additional parameters into post fields
        foreach ($parameters as $key => $value) {
            if ($key !== 'file') {
                $postFields[$key] = $value;
            }
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception(curl_error($ch));
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['response' => json_decode($response, true), 'httpCode' => $httpCode];
    }

    public function uploadDocument($file, $language_code, $surveyId, $callbackUrl)
    {
        $parameters = [
            'language_code' => $language_code,
            'task_id' => $surveyId,
            'callback_url' => $callbackUrl,
        ];
        return $this->uploadFile($file, $parameters, '/upload');
    }

    public function uploadDocumentForConditions($file, $task_id, $callback_url, $survey_xml)
    {
        $parameters = [
            'task_id' => $task_id,
            'callback_url' => $callback_url,
            'survey_xml' => $survey_xml,
        ];
        return $this->uploadFile($file, $parameters, '/generate/conditions');
    }

    public function getStatus()
    {
        return $this->sendRequest('GET', '/status');
    }

    public function splitSurvey($surveyContent)
    {
        $data = ['content' => $surveyContent];
        return $this->sendRequest('POST', '/split', $data);
    }

    public function convertToLimesurvey($question, $language_code)
    {
        $data = ['question' => $question, 'language_code' => $language_code];
        return $this->sendRequest('POST', '/convert', $data);
    }

    public function generateSurveyGroup($description, $language)
    {
        $data = ['content' => $description, 'type' => "QUESTION_GROUP", 'language_code' => $language];
        return $this->sendRequest('POST', '/generate', $data, true);
    }

    public function generateSurvey($description, $language, $questionsNumber)
    {
        $data = ['content' => $description, 'type' => "SURVEY", 'language_code' => $language, 'questions_number' => $questionsNumber];
        return $this->sendRequest('POST', '/generate', $data, true);
    }

    public function scoreSurvey($surveyId, $skipScored)
    {
        $data = ['survey_id' => $surveyId, 'task_id' => $surveyId, 'skip_scored' => $skipScored];

        return $this->sendRequest('POST', '/score', $data, false, true);
    }

    public function unstructuredGroupToLimesurvey($unstructuredContent, $language_code, $surveyId, $callbackUrl)
    {
        $data = ['content' => $unstructuredContent, 'language_code' => $language_code, 'task_id' => $surveyId, 'callback_url' => $callbackUrl];
        return $this->sendRequest('POST', '/unstructured/group', $data);
    }

    public function unstructuredSurveyToLimesurvey($unstructuredContent, $language_code, $surveyId, $callbackUrl)
    {
        $data = ['content' => $unstructuredContent, 'language_code' => $language_code, 'task_id' => $surveyId, 'callback_url' => $callbackUrl];
        return $this->sendRequest('POST', '/unstructured/survey', $data);
    }

    public function getTask($taskId)
    {
        return $this->sendRequest('GET', "/task/" . $taskId);
    }

    public function getTemplateFile($templateId, $surveyTitle)
    {
        $queryString = $surveyTitle ? "?survey_title=" . urlencode($surveyTitle) : "";
        return $this->sendRequest('GET', "/template/" . $templateId . "/file" . $queryString, null, false, false, "application/xml");
    }

    public function getTemplate($templateId, $surveyTitle)
    {
        $queryString = $surveyTitle ? "?survey_title=" . urlencode($surveyTitle) : "";
        return $this->sendRequest('GET', "/template/" . $templateId . $queryString);
    }

    public function getTemplates()
    {
        return $this->sendRequest('GET', "/template");
    }

    public function getLibraryQuestionGroups()
    {
        return $this->sendRequest('GET', "/library/question-groups");
    }

    public function getLibraryQuestionTypes()
    {
        return $this->sendRequest('GET', "/library/question-types");
    }

    public function putLibraryQuestionGroup($question_group_id, $question_group_name, $question_group_description)
    {
        $body = ['question_group_name' => $question_group_name, 'question_group_description' => $question_group_description];
        return $this->sendRequest('PUT', "/library/question-groups/" . $question_group_id, $body);
    }

    public function putLibraryQuestionGroupsQuestions($question_group_id, $questions)
    {
        $body = ['questions' => $questions];
        return $this->sendRequest('PUT', "/library/question-groups/" . $question_group_id . "/questions", $body);
    }

    public function addLibraryQuestionGroup()
    {
        return $this->sendRequest('POST', "/library/question-groups");
    }

    public function deleteLibraryQuestionGroup($question_group_id)
    {
        return $this->sendRequest('DELETE', "/library/question-groups/" . $question_group_id);
    }

    public function deleteLibraryQuestionGroupsQuestion($question_group_id, $question_id)
    {
        return $this->sendRequest('DELETE', "/library/question-groups/" . $question_group_id . "/questions/" . $question_id);
    }

    private function create_shared_secret($task_id)
    {
        return password_hash(substr($this->apiKey, 0, -5) . $task_id, PASSWORD_DEFAULT);
    }

    public function validate_shared_secret($shared_secret, $task_id)
    {
        return password_verify(substr($this->apiKey, 0, -5) . $task_id, $shared_secret);
    }

    public function authenticateStatistics($userId, $userName, $surveyIds, $isAdmin, $surveyPermissions) {
        return $this->sendRequest('POST', "/auth", ['sub' => $userId, 'name' => $userName, 'survey_ids' => $surveyIds, 'is_admin' => $isAdmin, 'survey_permissions' => $surveyPermissions], false, true);
    }
}
