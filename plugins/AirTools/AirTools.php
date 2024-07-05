<?php
require_once 'TransformersApiClient.php';
class AirTools extends PluginBase
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
            'default' => 'https://api.acc.airtools.wearetransformers.nl'
        ),
    );


    public function init()
    {
        $this->subscribe('newUnsecureRequest');
        $this->subscribe('beforeControllerAction');
        $this->subscribe('newDirectRequest');
    }

    public function newDirectRequest()
    {
        //handle the request
        $target = Yii::app()->request->getQuery('target');
        $function = Yii::app()->request->getQuery('function');
        if ($target == "AirTools") {
            switch ($function) {
                case 'getDefaultGroupsByUser':
                    $this->handleGetDefaultGroupsByUser();
                    break;
                default:
                    $this->sendErrorResponse(404, 'Function not found.');
                    break;
            }
        }
    }

    public function newUnsecureRequest()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new CHttpException(405, 'Only POST requests are allowed.');
        }
        //handle the request
        $target = Yii::app()->request->getQuery('target');
        $function = Yii::app()->request->getQuery('function');
        if ($target == "AirTools") {
            switch ($function) {
                case 'addQuestion':
                    $this->actionAddQuestion();
                    break;
                case 'createQuestionsByDescription':
                    $this->handleCreateQuestionsByDescription();
                    break;
                case 'questionsDescriptionToSurveyGroup':
                    $this->handleQuestionsDescriptionToSurveyGroup();
                    break;
                case 'documentToSurvey':
                    $this->handleDocumentToSurvey();
                    break;

                default:
                    $this->sendErrorResponse(404, 'Function not found.');
            }
        }
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

    private function handleCreateQuestionsByDescription()
    {
        $data = $this->parseJsonPostRequest();
        $surveyId = $data['surveyId'] ?? null;
        //check if the user has the permission to add questions to this survey
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
            $api_client->createSurvey($description, $oSurvey->language);
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

        // $oSurvey = Survey::model()->findByPk($surveyId); for language

        $content = $data['content'] ?? '';
        if (empty($content)) {
            $this->sendErrorResponse(400, 'Missing description of questions.');
            return;
        }

        $api_client = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));
        $request = $api_client->unstructuredToLimesurvey($content);

        if ($request['httpCode'] !== 200) {
            $this->sendErrorResponse($request['httpCode'], 'Error while converting the questions: ' . $request['response']['message']);
            return;
        }

        $array_of_questions = $request['response']['questions'] ?? [];
        $array_of_split = $request['response']['split'] ?? [];

        if (empty($array_of_questions)) {
            $this->sendErrorResponse(500, 'No questions were returned from the API.');
            return;
        }

        Yii::import('application.helpers.admin.import_helper', true);

        $failedQuestions = [];

        foreach ($array_of_questions as $index => $question) {
            $tempFilePath = tempnam(sys_get_temp_dir(), 'lsq');
            file_put_contents($tempFilePath, $question);

            try {
                $options = array('autorename' => true, 'translinkfields' => true);
                $importResult = XMLImportQuestion($tempFilePath, $surveyId, $groupId, $options);
            } catch (Exception $e) {
                $failedQuestions[] = $array_of_split[$index] ?? 'Unknown question';
            } finally {
                unlink($tempFilePath);
            }
        }

        if (!empty($failedQuestions)) {
            $this->sendErrorResponse(500, 'Failed to import some questions.', ['failedQuestions' => $failedQuestions]);
            return;
        }

        $this->sendSuccessResponse(['message' => 'Questions added to survey.']);
    }

    private function actionAddQuestion()
    {
        // Retrieve survey ID and group ID from the query parameters
        $surveyId = Yii::app()->request->getQuery('surveyId');
        $groupId = Yii::app()->request->getQuery('groupId');

        if (empty($surveyId) || empty($groupId)) {
            throw new CHttpException(400, 'Missing surveyId or groupId.');
        }

        //check if the user has the permission to add questions to this survey
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
        $url = Yii::app()->createUrl('plugins/unsecure', ['plugin' => 'AirTools', 'target' => 'AirTools', 'function' => 'addQuestion']);
        $settings['information']['content'] = 'To add a question to a survey, send a POST request to the following URL: <a href="' . $url . '">' . $url . '</a> ';
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
    }

    private function addNewTab()
    {
        // Add the new tab to the survey creation page
        App()->clientScript->registerScript('add-new-tab', "
            $(document).ready(function() {
                var newTab = '<li class=\"nav-item\" role=\"presentation\">' +
                             '<a class=\"nav-link\" role=\"tab\" data-bs-toggle=\"tab\" href=\"#ai-widget\">AI Widget</a>' +
                             '</li>';
                $('#create-import-copy-survey').append(newTab);

                var newTabContent = '<div class=\"tab-pane fade\" id=\"ai-widget\" role=\"tabpanel\">' +
                                    '<div id=\"create-survey-widget\"></div>' +
                                    '</div>';
                $('.tab-content').append(newTabContent);
                window.mountReactApp('create-survey-widget');
            });
        ", CClientScript::POS_END);
        //register the script file
        App()->clientScript->registerScriptFile(App()->assetManager->publish(dirname(__FILE__) . '/assets/index.js'), CClientScript::POS_HEAD, ['type' => 'module']);
        //register the css file
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
        //register the css file
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

        // Instantiate the API client
        $apiClient = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));

        // Upload the document to the Transformers API
        try {
            $response = $apiClient->uploadDocument($file);
            if ($response['httpCode'] !== 200) {
                $this->sendErrorResponse($response['httpCode'], 'Error from Transformers API: ' . $response['response']['message']);
                return;
            }
        } catch (Exception $e) {
            $this->sendErrorResponse(500, 'Error uploading document: ' . $e->getMessage());
            return;
        }

        $groups = $response['response']['groups'] ?? [];
        if (empty($groups)) {
            $this->sendErrorResponse(500, 'No groups were returned from the API.');
            return;
        }
        $this->clearSurvey($surveyId);
        // Insert the questions into the survey
        $groupIds = $this->insertQuestionsIntoSurvey($surveyId, $groups);
        $this->sendSuccessResponse(['message' => 'Questions added to survey.', 'result'=> ['surveyId' => $surveyId, 'groups' => $groupIds]]);
    }

    private function insertQuestionsIntoSurvey($surveyId, $groups)
    {
        $oSurvey = Survey::model()->findByPk($surveyId);
        $groupId = null;
        $groupIds = [];
        foreach ($groups as $group) {
            $groupName = $group['name'];
            $questions = $group['questions'];

            // Create the question group
            $groupId = $this->createQuestionGroup($surveyId, $groupName, $oSurvey->language);
            $questionIds = [];
            // Import each question
            foreach ($questions as $question) {
                $qId = $this->importQuestion($surveyId, $groupId, $question);
                $questionIds[] = $qId;
            }
            $groupIds[] = ['groupId' => $groupId, 'questionIds' => $questionIds];
        }
        return $groupIds;
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

    private function createQuestionGroup($surveyId, $groupName, $language)
    {
        // Create a new question group and return its ID
        $group = new QuestionGroup;
        $group->sid = $surveyId;
        $group->group_name = $groupName;
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

    private function importQuestion($surveyId, $groupId, $questionContent)
    {
        $importResult = null;
        // Create a temporary file to store the question content
        $tempFilePath = tempnam(sys_get_temp_dir(), 'lsq');
        file_put_contents($tempFilePath, $questionContent);

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
        return (int)$importResult['newqid'];
    }
}
