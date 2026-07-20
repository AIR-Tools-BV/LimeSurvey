<?php
require_once 'TransformersApiClient.php';
require_once 'helper_functions.php';

class AirTools extends \PluginBase
{
    /**
     * @var string
     */
    static protected $description = 'AirTools Plugin';

    /**
     * @var string
     */
    static protected $name = 'AirTools';

    /**
     * @var string
     */
    protected $storage = 'DbStorage';

    protected $settings = array(
        'information' => array(
            'type' => 'info',
            'content' => '',
            'default' => false
        ),
        'api_key' => array(
            'type' => 'password',
            'label' => 'API Key Transformers group'
        ),
        'api_url' => array(
            'type' => 'text',
            'label' => 'API URL Transformers group',
            'default' => 'https://ai-project-505205990356.europe-west4.run.app'
        ),
        'export_key' => array(
            'type' => 'text',
            'label' => 'Statistics Export key',
        ),
    );

    public function init()
    {
        Yii::setPathOfAlias('AirTools', dirname(__FILE__));
        $this->subscribe('newUnsecureRequest');
        $this->subscribe('beforeControllerAction');
        $this->subscribe('newDirectRequest');
        $this->subscribe('beforeAdminMenuRender');
        $this->subscribe('beforeSurveyBarRender');
    }

    public function beforeSurveyBarRender()
    {
        $event = $this->event;
        $surveyId = $event->get('surveyId');
        
        $url = Yii::app()->createUrl(
            'admin/pluginhelper/sa/fullpagewrapper', 
            [
                'plugin' => 'AirTools', 
                'method' => 'index', 
                'widget' => 'document-to-conditions-widget',
                'surveyId' => $surveyId
            ]
        );

        $menuItem = new \LimeSurvey\Menu\MenuButton([
            'label' => '<i class="fa fa-magic"></i> AI routing conditions scripter',
            'href' => $url,
            'position' => 'right',
            'tooltip' => 'Convert a document to survey conditions',
            'buttonId' => 'document-to-conditions-button',
        ]);

        $event->append('menus', [$menuItem]);
    }

    public function beforeAdminMenuRender()
    {
        $event = $this->getEvent();
        $buttonTestOptions = [
            'buttonId' => 'analytics-button',
            'label' => 'Analytics',
            'href' => Yii::app()->createUrl('admin/pluginhelper/sa/fullpagewrapper/plugin/AirTools/method/index', ['widget' => 'analytics-widget']),
            'iconClass' => 'fa fa-link',
            'openInNewTab' => false,
            'isPrepended' => false,
            'tooltip' => 'Go to the analytics page',
        ];

        $menuTestButton = new \LimeSurvey\Menu\MenuButton($buttonTestOptions);

        $event->append('extraMenus', [$menuTestButton]);
    }

    public function newDirectRequest()
    {
        if (Yii::app()->request->getQuery('plugin') !== 'AirTools') {
            return;
        }
        // Handle the request
        $function = Yii::app()->request->getQuery('function');
        switch ($function) {
            case 'getDefaultGroupsByUser':
                $this->handleGetDefaultGroupsByUser();
                break;
            case 'getTask':
                $this->handleGetTask();
                break;
            case 'getTemplate':
                $this->handleGetTemplate();
                break;
            case 'getTemplateFile':
                $this->handleGetTemplateFile();
                break;
            case 'getTemplates':
                $this->handleGetTemplates();
                break;
            case 'listSurveys':
                $this->handleListSurveys();
            case 'listMySurveys':
                $this->handleListMySurveys();
                break;
            default:
                $this->sendErrorResponse(404, 'Function not found.');
                break;
        }
    }

    public function newUnsecureRequest()
    {
        //check if plugin is AirTools
        if (Yii::app()->request->getQuery('plugin') !== 'AirTools') {
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new CHttpException(405, 'Only POST requests are allowed.');
        }
        // Handle the request
        $function = Yii::app()->request->getQuery('function');
        switch ($function) {
            case 'addQuestion':
                $this->actionAddQuestion();
                break;
            case 'scoreSurvey':
                $this->handleScoreSurvey();
                break;
            case 'generateQuestionGroupByDescription':
                $this->handleGenerateQuestionGroupByDescription();
                break;
            case 'generateSurveyByDescription':
                $this->handleGenerateSurveyByDescription();
                break;
            case 'questionsDescriptionToSurveyGroup':
                $this->handleQuestionsDescriptionToSurveyGroup();
                break;
            case 'surveyDescriptionToSurvey':
                $this->handleSurveyDescriptionToSurvey();
                break;
            case 'documentToSurvey':
                $this->handleDocumentToSurvey();
                break;
            case 'documentToRoutingConditions':
                $this->handleGenerateSurveyConditionsFromDocument();
                break;
            case 'authenticateStatistics':
                $this->handleAuthenticateStatistics();
                break;
            case 'exportSurvey':
                $this->handleExportSurvey();
                break;
            case 'exportResponses':
                $this->handleExportResponses();
                break;
            case 'callback-documentToSurvey':
            case 'callback-questionsDescriptionToSurveyGroup':
            case 'callback-surveyConditions':
            case 'callback-surveyDescriptionToSurvey':
                $this->handleAsyncCallback($function);
                break;
            default:
                $this->sendErrorResponse(404, 'Function not found.');
        }
    }

    private function handleGenerateSurveyConditionsFromDocument()
    {
        // Check if a file was uploaded
        if (empty($_FILES['file'])) {
            $this->sendErrorResponse(400, 'No file uploaded.');
            return;
        }

        $file = $_FILES['file'];
        $surveyId = $_POST['surveyId'] ?? null;

        // Check survey ID and permissions
        if (empty($surveyId)) {
            $this->sendErrorResponse(400, 'Missing surveyId.');
            return;
        }
        if (!Permission::model()->hasSurveyPermission($surveyId, 'surveycontent', 'create')) {
            throw new CHttpException(403, 'You do not have permission to modify this survey.');
        }

        // Retrieve survey details
        $oSurvey = Survey::model()->findByPk($surveyId);
        $apiClient = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));

        // Define the callback URL
        $callback_url = Yii::app()->createAbsoluteUrl('plugins/unsecure', [
            'plugin' => 'AirTools',
            'target' => 'AirTools',
            'function' => 'callback-surveyConditions',
            'surveyId' => $surveyId,
        ]);

        Yii::import('application.helpers.export_helper', true);
        // Send the document to the Transformers API
        try {
            $quexml = quexml_export($surveyId, $oSurvey->language);
            $request = $apiClient->uploadDocumentForConditions($file, "conditions_" . $surveyId, $callback_url, $quexml);
            if ($request['httpCode'] !== 200) {
                $errorMessage = $request['response']['message'] ?? 'Error uploading document for conditions generation.';
                $this->sendErrorResponse($request['httpCode'], 'Transformers API error: ' . $errorMessage);
                return;
            }
            $this->sendSuccessResponse(['message' => 'Document processing started.', 'task' => $request['response']['task']]);
        } catch (Exception $e) {
            $this->sendErrorResponse(500, 'Error uploading document: ' . $e->getMessage());
        }
    }

    private function processSurveyConditionsCallback($surveyId, $data)
    {
        $conditions = $data;

        if (empty($conditions)) {
            $this->sendErrorResponse(500, 'No conditions were returned from the API.');
            return;
        }

        try {
            $errors = $this->applyConditionsToSurvey($surveyId, $conditions);
            $this->sendSuccessResponse([
                'message' => 'Conditions applied to the survey.',
                'result' => ['surveyId' => $surveyId, 'conditions' => $conditions, 'errors' => $errors]
            ]);
        } catch (Exception $e) {
            $this->sendErrorResponse(500, "Error during appying conditions: " . $e);
        }
    }

    private function stripBraces($string)
    {
        return str_replace(['{', '}'], '', $string);
    }

    private function applyConditionsToSurvey($surveyId, $conditions)
    {
        $errors = ["groups" => [], "questions" => []];
        LimeExpressionManager::SetSurveyId($surveyId);
        // Loop through each group in the PostRouting structure
        foreach ($conditions['groups'] as $group) {
            LimeExpressionManager::StartProcessingGroup($group['id'], false, $surveyId);
            try {
                // Check if the group has a condition and insert it if necessary
                if (!empty($group['condition'])) {
                    // get the group and update the ->relivance
                    $groupModel = QuestionGroup::model()->findByAttributes(['sid' => $surveyId, 'gid' => $group['id']]);
                    if (!$groupModel) {
                        throw new Exception('Group not found: ' . $group['id']);
                    };
                    $groupModel->grelevance = $this->stripBraces($group['condition']);
                    $groupModel->save();
                }
            } catch (Exception $e) {
                $errors["groups"][] = ["id" => $group['id'], "error" => $e->getMessage()];
            }

            // Loop through each question within the group
            foreach ($group['questions'] as $question) {
                try {
                    // Check if the question has a condition and insert it if necessary
                    if (!empty($question['condition'])) {
                        $questionModel = Question::model()->findByAttributes(['sid' => $surveyId, 'title' => $question['id']]);
                        if (!$questionModel) {
                            throw new Exception('Question not found: ' . $question['id']);
                        }
                        $questionModel->relevance = $this->stripBraces($question['condition']);
                        $questionModel->save();
                    }
                } catch (Exception $e) {
                    $errors["questions"][] = ["id" => $question['id'], "error" => $e->getMessage()];
                }
            }
            LimeExpressionManager::FinishProcessingGroup();
        }
        return $errors;
    }

    private function handleListSurveys()
    {
        $this->hasValidExportKey();
        $activeSurveys = Survey::model()->findAllByAttributes(['active' => 'Y']);
        $surveys = [];

        foreach ($activeSurveys as $survey) {
            $surveys[] = [
                'id' => $survey->sid,
                'title' => $survey->getLocalizedTitle(),
                'language' => $survey->language,
                'start_date' => $survey->startdate,
                'end_date' => $survey->expires,
            ];
        }

        $this->sendSuccessResponse($surveys);
    }

    private function handleExportSurvey()
    {
        $this->hasValidExportKey();
        $request = $this->parseJsonPostRequest();
        $surveyId = $request['surveyId'] ?? null;
        if (empty($surveyId)) {
            $this->sendErrorResponse(400, 'Survey ID is required.');
        }
        $survey = Survey::model()->findByPk($surveyId);
        if (!$survey) {
            $this->sendErrorResponse(404, 'Survey not found.');
        }
        Yii::app()->loadHelper('export');

        try {
            $tsvContent = tsvSurveyExport($surveyId);
            header('Content-Type: text/tab-separated-values');
            header('Content-Disposition: attachment; filename="survey_' . $surveyId . '.tsv"');
            echo $tsvContent;
            Yii::app()->end();
        } catch (Exception $e) {
            $this->sendErrorResponse(500, 'Error exporting survey: ' . $e->getMessage());
        }
    }

    private function handleExportResponses()
    {
        $this->hasValidExportKey();
        $request = $this->parseJsonPostRequest();
        $surveyId = $request['surveyId'] ?? null;
        $after = $request['savedAfter'] ?? null;
        if (empty($surveyId)) {
            $this->sendErrorResponse(400, 'Survey ID is required.');
        }
        $survey = Survey::model()->findByPk($surveyId);
        if (!$survey) {
            $this->sendErrorResponse(404, 'Survey not found.');
        }
        $iMaximum = SurveyDynamic::model($surveyId)->getMaxId();
        $iMinimum = SurveyDynamic::model($surveyId)->getMinId();
        Yii::app()->loadHelper('common');
        Yii::app()->loadHelper('admin/exportresults');
        $filter = $after ? "submitdate >= '$after' OR submitdate = '1980-01-01 00:00:00'" : '';
        //avoid rendomization of the fieldmap
        killSurveySession($surveyId);
        // Get info about the survey
        $thissurvey = getSurveyInfo($surveyId);

        try {
            $exportService = new ExportSurveyResultsService();
            $options = new FormattingOptions();
            $options->output = 'file'; // Set output to file
            $options->responseMinRecord = $iMinimum; // Starting from the first response
            $options->responseMaxRecord = $iMaximum; // Ending at the last response
            $options->responseCompletionState = 'complete'; // Get all responses
            $options->convertY = 'Y';
            $options->convertN = 'Y';
            // In MCQ "Other" field Limesurvey does not distinguish between Y/N and inputed strings.
            // Define special Y/N values to understand if the user filled the "Other" option or not.
            $options->yValue = 'airtools-Y';
            $options->nValue = 'airtools-N';
            $fieldMap = createFieldMap($survey, 'full', true, false, $survey->language);
            if ($thissurvey['savetimings'] === "Y") {
                //Append survey timings to the fieldmap array
                $fieldMap = $fieldMap + createTimingsFieldMap($surveyId, 'full', false, false, $survey->language);
            }
            $options->selectedColumns = array_keys($fieldMap);

            $responsesFile = $exportService->exportResponses(
                $surveyId,
                $survey->language,
                'csv',
                $options,
                $filter
            );

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="responses_' . $surveyId . '.csv"');
            readfile($responsesFile);
            Yii::app()->end();
        } catch (Exception $e) {
            $this->sendErrorResponse(500, 'Error exporting responses: ' . $e->getMessage());
        }
    }

    private function handleAsyncCallback($function)
    {
        $surveyId = Yii::app()->request->getQuery('surveyId');
        $groupId = Yii::app()->request->getQuery('groupId') ?? null;
        $apiClient = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));
        $headers = $this->getHeadersFromServer();
        $webhookSecret = $headers['X-WEBHOOK-SECRET'] ?? null;

        $request = $this->parseJsonPostRequest();

        if (!(isset($request['task']) && $apiClient->validate_shared_secret($webhookSecret, $request['task']['id']))) {
            $this->sendErrorResponse(403, 'Invalid webhook secret.');
            return;
        }

        $data = $request['data'] ?? [];

        switch ($function) {
            case 'callback-documentToSurvey':
                $this->processSurveyGroupsCallback($surveyId, $data);
                break;
            case 'callback-surveyDescriptionToSurvey':
                $this->processSurveyGroupsCallback($surveyId, $data);
                break;
            case 'callback-questionsDescriptionToSurveyGroup':
                $this->processSurveyQuestionsCallback($surveyId, $groupId, $data);
                break;
            case 'callback-surveyConditions':
                $this->processSurveyConditionsCallback($surveyId, $data);
                break;
            default:
                $this->sendErrorResponse(404, 'Callback function not found.');
                break;
        }
    }

    private function processSurveyGroupsCallback($surveyId, $data)
    {
        $oSurvey = Survey::model()->findByPk($surveyId);

        $groups = $data["survey_groups"] ?? [];
        if (empty($groups)) {
            $this->sendErrorResponse(500, 'No groups were returned from the API.');
            return;
        }

        $api_errors = $data['errors'] ?? [];

        $this->clearSurvey($surveyId);
        $groupResults = $this->insertQuestionsIntoSurvey($surveyId, $groups, $oSurvey->language);

        foreach ($api_errors as $api_error) {
            foreach ($groupResults as $groupResult) {
                if ($groupResult['groupName'] === $api_error['group_name']) {
                    $groupResult['questions'][$api_error['question_index']]['status'] = 'failed';
                    $groupResult['questions'][$api_error['question_index']]['error'] = $api_error['error'];
                }
            }
        }

        $this->sendSuccessResponse([
            'message' => 'Questions added to survey.',
            'result' => ['surveyId' => $surveyId, 'groups' => $groupResults]
        ]);
    }

    private function processSurveyQuestionsCallback($surveyId, $groupId, $data)
    {
        $qGroup = QuestionGroup::model()->findByAttributes(array('sid' => $surveyId, 'gid' => $groupId));

        $questions = $data['questions'] ?? [];
        $splits = $data['split'] ?? [];

        if (empty($questions)) {
            $this->sendErrorResponse(500, 'No questions were returned from the API.');
            return;
        }

        $failedQuestions = [];
        foreach ($questions as $index => $question) {
            try {
                $qId = $this->importQuestion($surveyId, $qGroup->gid, $question);
            } catch (Exception $e) {
                $failedQuestions[] = $splits[$index] ?? 'Unknown question';
            }
        }

        $qGroup->cleanOrder($surveyId);
        $this->sendSuccessResponse(['message' => 'Questions added to survey.', 'failedQuestions' => $failedQuestions]);
    }

    private function parseJsonPostRequest()
    {
        // Retrieve the JSON content from the request body
        $jsonContent = Yii::app()->request->getRawBody();
        if (empty($jsonContent)) {
            $this->sendErrorResponse(400, 'No JSON content provided.');
        }
        // Decode the JSON content
        $data = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendErrorResponse(400, 'Invalid JSON content provided.');
        }
        return $data;
    }

    private function handleGetDefaultGroupsByUser()
    {
        $survey_groups = SurveysGroups::model()->getSurveyGroupsList();
        $this->sendSuccessResponse($survey_groups);
    }

    private function handleGetTask()
    {
        $taskId = Yii::app()->request->getQuery('taskId');
        $api_client = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));
        $json_response = $api_client->getTask($taskId);
        if ($json_response['httpCode'] !== 200) {
            $this->sendErrorResponse($json_response['httpCode'], 'Error while getting the task.');
        }
        $this->sendSuccessResponse($json_response['response']);
    }

    private function handleGetTemplate()
    {
        $templateId = Yii::app()->request->getQuery('templateId');
        $surveyTitle = Yii::app()->request->getQuery('surveyTitle');
        $api_client = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));
        $json_response = $api_client->getTemplate($templateId, $surveyTitle);
        if ($json_response['httpCode'] !== 200) {
            $this->sendErrorResponse($json_response['httpCode'], 'Error while getting the template.');
        }
        $this->sendSuccessResponse($json_response['response']);
    }

    private function handleGetTemplateFile()
    {
        $templateId = Yii::app()->request->getQuery('templateId');
        $surveyTitle = Yii::app()->request->getQuery('surveyTitle');
        $api_client = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));
        $response = $api_client->getTemplateFile($templateId, $surveyTitle);
        if ($response['httpCode'] !== 200) {
            $this->sendErrorResponse($response['httpCode'], 'Error while getting the template file.');
        }
        header('Content-Type: application/xml', true, 200);
        echo $response['response'];
        Yii::app()->end();
    }

    private function handleGetTemplates()
    {
        $api_client = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));
        $json_response = $api_client->getTemplates();
        if ($json_response['httpCode'] !== 200) {
            $this->sendErrorResponse($json_response['httpCode'], 'Error while getting the templates.');
        }
        $this->sendSuccessResponse($json_response['response']);
    }

    private function handleScoreSurvey()
    {
        $data = $this->parseJsonPostRequest();
        $surveyId = $data['surveyId'] ?? null;
        if (empty($surveyId)) {
            $this->sendErrorResponse(400, 'Survey ID is required.');
        }
        $survey = Survey::model()->findByPk($surveyId);
        if (!$survey) {
            $this->sendErrorResponse(404, 'Survey not found.');
        }
        $api_client = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));
        $skipScored = $data['skipScored'] ?? true;
        $json_response = $api_client->scoreSurvey($surveyId, $skipScored);
        if ($json_response['httpCode'] !== 200) {
            $this->sendErrorResponse($json_response['httpCode'], 'Error while scoring the survey.');
        }
        $this->sendSuccessResponse($json_response['response']);
    }

    private function handleGenerateQuestionGroupByDescription()
    {
        $data = $this->parseJsonPostRequest();
        $surveyId = $data['surveyId'] ?? null;
        // Check if the user has the permission to add questions to this survey
        if (!Permission::model()->hasSurveyPermission($surveyId, 'surveycontent', 'create')) {
            throw new CHttpException(403, 'You do not have the permission to add questions to this survey.');
        }

        $oSurvey = Survey::model()->findByPk($surveyId);

        $description = $data['description'];
        if (empty($description)) {
            $this->sendErrorResponse(400, 'Missing description.');
        }

        $api_client = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));
        // Start the streaming response
        try {
            // Get the streaming response from the FastAPI backend
            header('Content-Type: application/x-ndjson', true, 200);
            $api_client->generateSurveyGroup($description, $oSurvey->language);
        } catch (Exception $e) {
            $this->sendErrorResponse(500, 'Error while streaming response: ' . $e->getMessage());
        }
    }

    private function handleGenerateSurveyByDescription()
    {
        $data = $this->parseJsonPostRequest();
        $surveyId = $data['surveyId'] ?? null;
        // Check if the user has the permission to add questions to this survey
        if (!Permission::model()->hasSurveyPermission($surveyId, 'surveycontent', 'create')) {
            throw new CHttpException(403, 'You do not have the permission to add questions to this survey.');
        }

        $oSurvey = Survey::model()->findByPk($surveyId);

        $description = $data['description'];
        if (empty($description)) {
            $this->sendErrorResponse(400, 'Missing description.');
        }

        $questionsNumber = $data['questionsNumber'] ?? null;
        $questionGroupsIds = $data['groupsIds'] ?? null;

        $api_client = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));
        // Start the streaming response
        try {
            // Get the streaming response from the FastAPI backend
            header('Content-Type: application/x-ndjson', true, 200);
            $api_client->generateSurvey($description, $oSurvey->language, $questionsNumber, $questionGroupsIds);
        } catch (Exception $e) {
            $this->sendErrorResponse(500, 'Error while streaming response: ' . $e->getMessage());
        }
    }

    private function handleQuestionsDescriptionToSurveyGroup()
    {
        $data = $this->parseJsonPostRequest();
        $surveyId = $data['surveyId'] ?? null;
        $groupId = $data['groupId'] ?? null;

        if (is_null($groupId)) {
            throw new CHttpException(404, 'Group ID is missing.');
        }

        if (!Permission::model()->hasSurveyPermission($surveyId, 'surveycontent', 'create')) {
            throw new CHttpException(403, 'You do not have the permission to add questions to this survey.');
        }

        $oSurvey = Survey::model()->findByPk($surveyId);
        $qGroup = QuestionGroup::model()->findByAttributes(array('sid' => $surveyId, 'gid' => $groupId));
        $language = $qGroup->language ? $qGroup->language : $oSurvey->language;
        $content = $data['content'] ?? '';
        if (empty($content)) {
            $this->sendErrorResponse(400, 'Missing description of questions.');
            return;
        }

        $api_client = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));
        $callback_url = Yii::app()->createAbsoluteUrl('plugins/unsecure', [
            'plugin' => 'AirTools',
            'target' => 'AirTools',
            'function' => 'callback-questionsDescriptionToSurveyGroup',
            'surveyId' => $surveyId,
            'groupId' => $groupId
        ]);
        $request = $api_client->unstructuredGroupToLimesurvey($content, $language, $surveyId, $callback_url);

        if ($request['httpCode'] !== 200) {
            $this->sendErrorResponse($request['httpCode'], 'Error while converting the questions');
            return;
        }

        $this->sendSuccessResponse(['message' => 'Questions added to survey group.', 'task' => $request['response']['task']]);
    }
    private function handleSurveyDescriptionToSurvey()
    {
        $data = $this->parseJsonPostRequest();
        $surveyId = $data['surveyId'] ?? null;

        if (!Permission::model()->hasSurveyPermission($surveyId, 'surveycontent', 'create')) {
            throw new CHttpException(403, 'You do not have the permission to add questions to this survey.');
        }

        $oSurvey = Survey::model()->findByPk($surveyId);
        $language = $oSurvey->language;
        $content = $data['content'] ?? '';
        if (empty($content)) {
            $this->sendErrorResponse(400, 'Missing description of questions.');
            return;
        }

        $api_client = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));

        // Upload the survey description to the Transformers API
        try {
            $callback_url = Yii::app()->createAbsoluteUrl('plugins/unsecure', [
                'plugin' => 'AirTools',
                'target' => 'AirTools',
                'function' => 'callback-surveyDescriptionToSurvey',
                'surveyId' => $surveyId
            ]);

            $request = $api_client->unstructuredSurveyToLimesurvey($content, $language, $surveyId, $callback_url);
            if ($request['httpCode'] !== 200) {
                if ($request['response'] && isset($request['response']['message'])) {
                    $this->sendErrorResponse($request['httpCode'], 'Error from Transformers API: ' . $request['response']['message']);
                } else {
                    $this->sendErrorResponse($request['httpCode'], 'Error uploading survey description.');
                }
                return;
            }
        } catch (Exception $e) {
            $this->sendErrorResponse(500, 'Error uploading survey description: ' . $e->getMessage());
            return;
        }

        $this->sendSuccessResponse(['message' => 'Survey description processing started.', 'task' => $request['response']['task']]);
    }


    private function actionAddQuestion()
    {
        // Retrieve survey ID and group ID from the query parameters
        $surveyId = Yii::app()->request->getQuery('surveyId');
        $groupId = Yii::app()->request->getQuery('groupId');

        if (empty($surveyId) || empty($groupId)) {
            throw new CHttpException(400, 'Missing surveyId or groupId.');
        }

        // Check if the user has the permission to add questions to this survey
        if (!Permission::model()->hasSurveyPermission($surveyId, 'surveycontent', 'create')) {
            throw new CHttpException(403, 'You do not have the permission to add questions to this survey.');
        }

        // Retrieve the XML content from the request body
        $xmlContent = Yii::app()->request->getRawBody();
        if (empty($xmlContent)) {
            throw new CHttpException(400, 'No XML content provided.');
        }

        // Create a temporary file to store the XML content
        $tempFilePath = tempnam(sys_get_temp_dir(), 'lsq');
        file_put_contents($tempFilePath, $xmlContent);

        // Import the question using the XMLImportQuestion function
        Yii::import('application.helpers.admin.import_helper', true);
        try {
            $options = array('autorename' => true, 'translinkfields' => true);
            $importResult = XMLImportQuestion($tempFilePath, $surveyId, $groupId, $options);
            unlink($tempFilePath); // Clean up the temporary file
        } catch (Exception $e) {
            unlink($tempFilePath); // Clean up the temporary file
            throw new CHttpException(500, 'Error importing question: ' . $e->getMessage());
        }

        // Respond with the import result
        header('Content-Type: application/json');
        echo json_encode($importResult);
        Yii::app()->end();
    }

    /**
     * Update the information content to show the good link
     * @params getValues
     */
    public function getPluginSettings($getValues = true)
    {
        if (!Permission::model()->hasGlobalPermission('settings', 'read')) {
            throw new CHttpException(403, 'You do not have the permission to access this page');
        }
        $settings = parent::getPluginSettings($getValues);
        if (!isset($settings['export_key']['current'])) {
            $settings['export_key']['current'] = generateRandomString(48);
            $this->set('export_key', $settings['export_key']['current']);
        }
        $url_analytics = Yii::app()->createUrl('plugins/direct', ['plugin' => 'AirTools', 'function' => 'analyticsPage']);
        $url = Yii::app()->createUrl('plugins/unsecure', ['plugin' => 'AirTools', 'target' => 'AirTools', 'function' => 'addQuestion']);
        $settings['information']['content'] = 'To add a question to a survey, send a POST request to the following URL: <a href="' . $url . '">' . $url . '</a> <br> For the analytics page: <a href="' . $url_analytics . '">' . $url_analytics . '</a>';
        return $settings;
    }

    public function beforeControllerAction()
    {
        $event = $this->getEvent();
        // Check if the current controller and action are the ones we want to modify
        if ($event->get('controller') === 'surveyAdministration' && $event->get('action') === 'newSurvey') {
            $this->addNewTab();
        } elseif ($event->get('controller') === 'questionGroupsAdministration' && $event->get('action') === 'view') {
            $this->addReactWidgetToQuestionGroupsPage();
        }
        if ($event->get('controller') === 'surveyAdministration' && $event->get("action") === 'view') {
            $this->addReactWidgetToQuestionGroupsPage();
        }
        if ($event->get('controller') === 'admin' && $event->get('action') === 'index') {
            $this->addReactChatWidget();
        }
    }

    private function addReactChatWidget()
    {
        $imagesPath = Yii::app()->assetManager->publish(dirname(__FILE__) . '/assets/images/');
        $assetsPath = Yii::app()->assetManager->publish(dirname(__FILE__) . '/assets/');

        App()->clientScript->registerScript('add-react-chat', "
        $(document).ready(function() {
            function mountReactWidget() {
                var widgetContainer = '<div id=\"chat-widget\"></div>';
                $('#in_survey_common_action').before(widgetContainer);
                window.AIRTOOLS_ASSETS_PATH = '$assetsPath';
                window.mountReactApp('chat-widget', {
                    imagesPath: '$imagesPath'
                });
            }
            mountReactWidget();

            // Detect URL changes
            window.addEventListener('popstate', function() {
                mountReactWidget();
            });
        });
    ", CClientScript::POS_END);
        App()->clientScript->registerScriptFile(App()->assetManager->publish(dirname(__FILE__) . '/assets/index.js'), CClientScript::POS_HEAD, ['type' => 'module']);
        // Register the css file
        App()->clientScript->registerCssFile(App()->assetManager->publish(dirname(__FILE__) . '/assets/index.css'));
    }

    public function index()
    {
        //get query parameter "widget" to determine which widget to show
        $widget = Yii::app()->request->getQuery('widget');

        App()->clientScript->registerScriptFile(App()->assetManager->publish(dirname(__FILE__) . '/assets/index.js'), CClientScript::POS_HEAD, ['type' => 'module']);
        // Register the css file
        App()->clientScript->registerCssFile(App()->assetManager->publish(dirname(__FILE__) . '/assets/index.css'));
        $this->addIconFonts();

        $this->loadWidget($widget);

        return $this->renderPartial('index', [], true);
    }

    private function loadWidget($name)
    {
        App()->clientScript->registerScriptFile(App()->assetManager->publish(dirname(__FILE__) . '/assets/index.js'), CClientScript::POS_HEAD, ['type' => 'module']);
        // Register the css file
        App()->clientScript->registerCssFile(App()->assetManager->publish(dirname(__FILE__) . '/assets/index.css'));

        App()->clientScript->registerScript($name, "
        var loaded = false;

        $(document).ready(function() {
            if (!loaded) {
                window.mountReactApp('$name');
                loaded = true;
            } 
            });
        ", CClientScript::POS_END);
    }

    private function addNewTab()
    {
        // Add the new tabs to the survey creation page
        App()->clientScript->registerScript('add-new-tabs', "
        $(document).ready(function() {
            $('#create-import-copy-survey .nav-link.active').removeClass('active');
            $('.tab-content .tab-pane.active').removeClass('show active');

            var newAITabs = '' +
                '<li class=\"nav-item\" role=\"presentation\">' +
                '<a class=\"nav-link\" role=\"tab\" data-bs-toggle=\"tab\" href=\"#document-to-survey-widget\">' +
                'AI document Import' +
                '<i class=\"fa fa-magic\" style=\"margin-left: 5px;\"></i>' + 
                '</a>' +
                '</li>' +
                '<li class=\"nav-item\" role=\"presentation\">' +
                '<a class=\"nav-link\" role=\"tab\" data-bs-toggle=\"tab\" href=\"#description-to-survey-widget\">' +
                'AI assisted Create' +
                '<i class=\"fa fa-magic\" style=\"margin-left: 5px;\"></i>' + 
                '</a>' +
                '</li>';
            
            $('#create-import-copy-survey').prepend(newAITabs);

            var newTabs = '' +
                '<li class=\"nav-item\" role=\"presentation\">' +
                '<a class=\"nav-link\" role=\"tab\" data-bs-toggle=\"tab\" href=\"#template-selection-widget\">' +
                'Template Selection' +
                '</a>' +
                '</li>';

            $('#create-import-copy-survey').append(newTabs);

            var newAITabsContent = '' +
                '<div class=\"tab-pane fade\" id=\"document-to-survey-widget\" role=\"tabpanel\">' +
                '<div id=\"document-to-survey-widget\"></div>' +
                '</div>' +
                '<div class=\"tab-pane fade\" id=\"description-to-survey-widget\" role=\"tabpanel\">' +
                '<div id=\"description-to-survey-widget\"></div>' +
                '</div>';

            $('.tab-content').prepend(newAITabsContent);

            var newTabsContent = '' +
                '<div class=\"tab-pane fade\" id=\"template-selection-widget\" role=\"tabpanel\">' +
                '<div id=\"template-selection-widget\"></div>' +
                '</div>';

            $('.tab-content').append(newTabsContent);

            // Get the window hash or set it as default.
            var hash = window.location.hash || '#document-to-survey-widget';
            if ($(hash).length === 0) {
                hash = '#document-to-survey-widget';
            }

            // Show the tab and the content.
            $(hash).addClass('active show');
            $('#create-import-copy-survey a[href=\"' + hash + '\"]').addClass('active');

            // Change the Inner html of the <a> with href = 'Copy'
            $('#create-import-copy-survey a[href=\"#copy\"]').html('Create from library');

            window.mountReactApp('document-to-survey-widget');
            window.mountReactApp('description-to-survey-widget');
            window.mountReactApp('template-selection-widget');
        });
    ", CClientScript::POS_END);

        // Register the script file
        App()->clientScript->registerScriptFile(App()->assetManager->publish(dirname(__FILE__) . '/assets/index.js'), CClientScript::POS_HEAD, ['type' => 'module']);
        // Register the CSS file
        App()->clientScript->registerCssFile(App()->assetManager->publish(dirname(__FILE__) . '/assets/index.css'));
        $this->addIconFonts();
    }

    private function addReactWidgetToQuestionGroupsPage()
    {
        // Add the React widget to the questionGroupsAdministration page
        App()->clientScript->registerScript('add-react-widget', "
        $(document).ready(function() {
            function mountReactWidget() {
                var widgetContainer = '<div id=\"ai-question-group-widget\" class=\"my-3\"></div>';
                $('#groupdetails').after(widgetContainer);
                window.mountReactApp('ai-question-group-widget');
            }
            mountReactWidget();

            // Detect URL changes
            window.addEventListener('popstate', function() {
                mountReactWidget();
            });
        });
    ", CClientScript::POS_END);
        App()->clientScript->registerScriptFile(App()->assetManager->publish(dirname(__FILE__) . '/assets/index.js'), CClientScript::POS_HEAD, ['type' => 'module']);
        // Register the css file
        App()->clientScript->registerCssFile(App()->assetManager->publish(dirname(__FILE__) . '/assets/index.css'));
    }

    private function addIconFonts()
    {
        App()->clientScript->registerScriptFile('https://kit.fontawesome.com/a00f3047db.js', CClientScript::POS_HEAD, ['crossorigin' => 'anonymous']);
    }

    private function sendErrorResponse($statusCode, $message, $additionalData = [])
    {
        http_response_code($statusCode);
        $response = ['error' => $message];
        if (!empty($additionalData)) {
            $response = array_merge($response, $additionalData);
        }
        echo json_encode($response);
        Yii::app()->end();
    }

    private function sendSuccessResponse($data)
    {
        header('Content-Type: application/json', true, 200);
        echo json_encode($data);
        Yii::app()->end();
    }

    private function handleDocumentToSurvey()
    {
        // Check if a file was uploaded
        if (empty($_FILES['file'])) {
            $this->sendErrorResponse(400, 'No file uploaded.');
            return;
        }

        $file = $_FILES['file'];

        // Get the survey ID from the POST request
        $surveyId = $_POST['surveyId'] ?? null;
        if (empty($surveyId)) {
            $this->sendErrorResponse(400, 'Missing surveyId.');
            return;
        }

        // Check if the user has permission to add questions to this survey
        if (!Permission::model()->hasSurveyPermission($surveyId, 'surveycontent', 'create')) {
            throw new CHttpException(403, 'You do not have the permission to add questions to this survey.');
        }

        $oSurvey = Survey::model()->findByPk($surveyId);
        $apiClient = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));

        // Upload the document to the Transformers API
        try {
            $callback_url = Yii::app()->createAbsoluteUrl('plugins/unsecure', ['plugin' => 'AirTools', 'target' => 'AirTools', 'function' => 'callback-documentToSurvey', 'surveyId' => $surveyId]);
            $request = $apiClient->uploadDocument($file, $oSurvey->language, $surveyId, $callback_url);
            if ($request['httpCode'] !== 200) {
                if ($request['response'] && isset($request['response']['message'])) {
                    $this->sendErrorResponse($request['httpCode'], 'Error from Transformers API: ' . $request['response']['message']);
                } else {
                    $this->sendErrorResponse($request['httpCode'], 'Error uploading document.');
                }
                return;
            }
        } catch (Exception $e) {
            $this->sendErrorResponse(500, 'Error uploading document: ' . $e->getMessage());
            return;
        }

        $this->sendSuccessResponse($request['response']);
    }

    private function insertQuestionsIntoSurvey($surveyId, $groups, $language)
    {
        $groupResults = [];
        $groups = array_reverse($groups); // We reverse the order otherwise the groups will be in the wrong order

        foreach ($groups as $groupIndex => $group) {
            $groupName = $group['name'];
            $groupRandomizationGroup = $group['randomization_group'] ?? '';
            $questions = $group['questions'];

            // Create the question group
            $groupId = $this->createQuestionGroup($surveyId, $groupName, $groupRandomizationGroup, $language);
            $questionResults = [];

            // Import each question
            foreach ($questions as $index => $question) {
                #handle question is None
                if (!isset($question)) {
                    $questionResults[] = ['index' => $index, 'status' => 'failed', 'error' => 'Question is empty'];
                    continue;
                }
                try {
                    $qId = $this->importQuestion($surveyId, $groupId, $question);
                    $questionResults[] = ['groupIndex' => $groupIndex, 'index' => $index, 'questionId' => $qId, 'status' => 'success'];
                } catch (Exception $e) {
                    $questionResults[] = ['index' => $index, 'status' => 'failed', 'error' => $e->getMessage()];
                }
            }

            // clean the order, this fixes the order of the questions and groups
            QuestionGroup::model()->findByAttributes(['sid' => $surveyId, 'gid' => $groupId])->cleanOrder($surveyId);

            $groupResults[] = [
                'groupId' => $groupId,
                'groupName' => $groupName,
                'questions' => $questionResults
            ];
        }

        return $groupResults;
    }

    private function importQuestion($surveyId, $groupId, $questionContent)
    {
        // Create a temporary file to store the question content
        $tempFilePath = tempnam(sys_get_temp_dir(), 'lsq');
        file_put_contents($tempFilePath, $questionContent);

        // Custom error handler to convert warnings to exceptions
        set_error_handler(function ($severity, $message, $file, $line) {
            if (error_reporting() === 0) {
                return false; // Error reporting is currently suppressed
            }
            if ($severity === E_WARNING) {
                throw new ErrorException($message, 0, $severity, $file, $line);
            }
            return false; // Let the PHP internal handler handle the error
        });

        // Validate the XML content before parsing
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($questionContent);
        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            unlink($tempFilePath); // Clean up the temporary file
            throw new CHttpException(500, 'Invalid XML content: ' . print_r($errors, true));
        }


        // Import the question using the XMLImportQuestion function
        Yii::import('application.helpers.admin.import_helper', true);
        try {
            $options = array('autorename' => true, 'translinkfields' => true);
            $importResult = XMLImportQuestion($tempFilePath, $surveyId, $groupId, $options);
            unlink($tempFilePath); // Clean up the temporary file
            return (int) $importResult['newqid'];
        } catch (Exception $e) {
            unlink($tempFilePath); // Clean up the temporary file
            throw new CHttpException(500, 'Error importing question: ' . $e->getMessage());
        } finally {
            // Restore the original error handler
            restore_error_handler();
        }
    }

    private function clearSurvey($surveyId)
    {
        // Delete all questions from the survey
        $questions = Question::model()->findAllByAttributes(['sid' => $surveyId]);
        foreach ($questions as $question) {
            $question->delete();
        }

        // Delete all question groups from the survey
        $groups = QuestionGroup::model()->findAllByAttributes(['sid' => $surveyId]);
        foreach ($groups as $group) {
            $group->delete();
        }
    }

    private function createQuestionGroup($surveyId, $groupName, $groupRandomizationGroup, $language)
    {
        // Create a new question group and return its ID
        $group = new QuestionGroup;
        $group->sid = $surveyId;
        $group->group_name = $groupName;
        $group->randomization_group = $groupRandomizationGroup;
        $group->language = $language;
        $group->save();
        $gid = $group->gid;
        $oQuestionGroupL10n = new QuestionGroupL10n();
        $oQuestionGroupL10n->group_name = $groupName;
        $oQuestionGroupL10n->description = '';
        $oQuestionGroupL10n->language = $language;
        $oQuestionGroupL10n->gid = $gid;

        if ($oQuestionGroupL10n->save()) {
            return (int) $group->gid;
        } else {
            throw new CHttpException(500, 'Error creating question group.');
        }
    }

    private function getHeadersFromServer()
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace('HTTP_', '', $key);
                $header = str_replace('_', '-', $header);
                $headers[$header] = $value;
            }
        }
        return $headers;
    }

    private function hasValidExportKey()
    {
        $headers = $this->getHeadersFromServer();
        $exportKey = $headers['X-EXPORT-KEY'] ?? null;
        if ($exportKey !== $this->get('export_key')) {
            $this->sendErrorResponse(401, 'Invalid export key.');
        }
        return true;
    }

    private function handleAuthenticateStatistics()
    {
        $user = Yii::app()->user;

        if (Yii::app()->user->isGuest) {
            $this->sendErrorResponse(401, 'Not authenticated');
        }
        $api_client = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));
        $isAdmin = Permission::isForcedSuperAdmin($user->getId()) || Permission::model()->hasGlobalPermission('superadmin', 'read');
        $surveyIds = $isAdmin ? [] : $this->listMySurveyIds();
        // Array of permissions
        $surveyPermissions = array(
            "read" => Permission::model()->hasGlobalPermission('surveys', 'read'),
            "create" => Permission::model()->hasGlobalPermission('surveys', 'create'),
            "update" => Permission::model()->hasGlobalPermission('surveys', 'update'),
            "delete" => Permission::model()->hasGlobalPermission('surveys', 'delete'),
            "export" => Permission::model()->hasGlobalPermission('surveys', 'export')
        );

        $user = User::model()->findByPk($user->getId());
        $json_response = $api_client->authenticateStatistics(
            $user->uid,
            $user->users_name,
            $surveyIds,
            $isAdmin,
            $surveyPermissions
        );
        if ($json_response['httpCode'] !== 200) {
            $this->sendErrorResponse($json_response['httpCode'], 'Error while authenticating the user.');
        }
        $this->sendSuccessResponse($json_response['response']);
    }

    private function handleListMySurveys()
    {
        if (Yii::app()->user->isGuest) {
            $this->sendErrorResponse(401, 'Not authenticated');
        }
        $this->sendSuccessResponse($this->listMySurveys());
    }

    private function listMySurveys()
    {
        $model = new Survey('search');
        $surveyData = $model->search(['pageSize' => 100000])->getData();
        $surveys = [];
        foreach ($surveyData as $survey) {
            $surveys[] = ['id' => $survey->sid, 'language' => $survey->language, 'title' => $survey->getLocalizedTitle(), 'active' => $survey->active, 'owner' => $survey->owner_id];
        }
        return $surveys;
    }

    private function listMySurveyIds()
    {
        $model = new Survey('search');
        $surveyData = $model->search(['pageSize' => 100000])->getData();
        $surveyIds = [];
        foreach ($surveyData as $survey) {
            $surveyIds[] = $survey->sid;
        }
        return $surveyIds;
    }
}
