<?php

class TrackSeenQuestions extends PluginBase
{
    protected $storage = 'DbStorage'; // Use database for storage
    static protected $description = "Tracks whether questions were seen by the respondent";
    static protected $name = "Track Seen Questions";

    public function init()
    {
        $this->subscribe('beforeActivate');
        $this->subscribe('newUnsecureRequest');
        $this->subscribe('afterSurveyComplete');
    }

    public function afterSurveyComplete()
    {
        $surveyId = $this->event->get('surveyId');
        $questions = Question::model()->findAllByAttributes(['sid' => $surveyId], ['order' => 'parent_qid ASC']);
        $responseId = $this->getResponseId($surveyId);
        $relevantQuestionIds = [];
    
        foreach ($questions as $question) {
            $groupId = $question->gid;
            $questionId = $question->qid;
            $parentQuestionId = $question->parent_qid;
    
            $isRelevant = LimeExpressionManager::QuestionIsRelevant($questionId);
    
            if ($parentQuestionId == 0) {
                // Main question: if relevant, add to relevantQuestionIds and log
                if ($isRelevant) {
                    $relevantQuestionIds[] = $questionId;
                    $this->logQuestionSeen($surveyId, $groupId, $questionId, $responseId);
                }
            } elseif (in_array($parentQuestionId, $relevantQuestionIds) && $isRelevant) {
                // Sub-question: if parent is relevant and this question is relevant, log it
                $this->logQuestionSeen($surveyId, $groupId, $questionId, $responseId);
            }
        }
    }

    public function newUnsecureRequest()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new CHttpException(405, 'Only POST requests are allowed.');
        }

        $request = Yii::app()->request;
        if ($request->getQuery('function') === 'exportSeenQuestions') {
            $this->exportSeenQuestionsAsCsv();
        }
    }

    public function getResponseId($surveyId)
    {
        $iRespondeId = (isset($_SESSION["survey_{$surveyId}"]['srid'])) ? $_SESSION["survey_{$surveyId}"]['srid'] : null;
        if ($iRespondeId) {
            return $iRespondeId;
        }
        $sSessionToken = (isset($_SESSION["survey_{$surveyId}"]['token'])) ? $_SESSION["survey_{$surveyId}"]['token'] : null;
        $oResponse = SurveyDynamic::model($surveyId)->find("token =:token", array(':token' => $sSessionToken));
        return ($oResponse) ? $oResponse->id : null;
    }

    // Function to log that a question was seen
    protected function logQuestionSeen($surveyId, $groupId, $questionId, $response_id)
    {
        // Store the information in a custom table (you need to create this table in the DB)
        Yii::app()->db->createCommand()->insert('{{seen_questions}}', [
            'survey_id' => $surveyId,
            'group_id' => $groupId,
            'question_id' => $questionId,
            'response_id' => $response_id,
        ]);
    }

    // Hook into the activation process
    public function beforeActivate()
    {
        $this->createTableIfNotExists();
    }

    protected function createTableIfNotExists()
    {
        $db = Yii::app()->db;

        // Check if the table exists
        $tableName = '{{seen_questions}}';
        $tableExists = $db->schema->getTable($tableName, true);


        if ($tableExists === null) {
            // Create the table if it doesn't exist
            $db->createCommand()->createTable($tableName, [
                'id' => 'pk',
                'survey_id' => 'integer NOT NULL',
                'group_id' => 'integer NOT NULL',
                'question_id' => 'integer NOT NULL',
                'response_id' => 'integer NOT NULL',
            ]);
        }
    }

    /**
     * Retrieves the codes for the given question IDs
     */
    protected function getQuestionCodes($questionIds)
    {
        $questions = Question::model()->findAllByPk($questionIds);
        $questionCodes = [];

        foreach ($questions as $question) {
            $questionCodes[$question->qid] = $question->title;
            if ($question->parent_qid != 0) {
                $questionCodes[$question->qid] = $questionCodes[$question->parent_qid] . '[' . $question->title . ']';
            }
        }

        return $questionCodes;
    }

    // Export seen questions as CSV for a specific survey and respondents
    private function exportSeenQuestionsAsCsv()
    {
        $data = $this->parseJsonPostRequest();
        $surveyId = $data['surveyId'];
        $respondent_ids = $data['respondentIds'];
        $db = Yii::app()->db;

        $placeholders = implode(',', array_fill(0, count($respondent_ids), '?'));

        $params = array_merge([$surveyId], $respondent_ids);


        $command = $db->createCommand()
            ->select('survey_id, group_id, response_id, question_id')
            ->from('{{seen_questions}}')
            ->where('survey_id = ?')
            ->andWhere("response_id IN ($placeholders)");

        $seenQuestions = $command->queryAll(true, $params);
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename="seen_questions_' . $surveyId . '.csv"');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Write CSV headers
        fputcsv($output, ['survey_id', 'group_id',  'response_id', 'question_code']);

        // Get question codes
        $questionCodes = $this->getQuestionCodes(array_column($seenQuestions, 'question_id'));

        // Write the data
        foreach ($seenQuestions as $row) {
            $row['question_code'] = $questionCodes[$row['question_id']];
            unset($row['question_id']);
            fputcsv($output, $row);
        }

        // Close the output stream
        fclose($output);
        Yii::app()->end();
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
}
