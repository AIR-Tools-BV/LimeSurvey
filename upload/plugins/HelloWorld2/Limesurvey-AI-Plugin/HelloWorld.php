<?php
class HelloWorld extends PluginBase
{
    protected $storage = 'LimeSurvey\PluginManager\DbStorage';
    static protected $description = 'HelloWorld2';
    static protected $name = 'HelloWorld2';

    /**
     * subscribe to all needed events
     * @see https://manual.limesurvey.org/Plugin_events
     */
    public function init()
    {
        /** @see https://manual.limesurvey.org/Direct_(command) */
        $this->subscribe('direct');
    }

    /**
     * php application/commands/console.php plugin --target=HelloWorld
     */
    public function direct()
    {
        $this->api->addUserGroup("Test Plugin Group", "This is a test group created by a plugin");
        $event = $this->getEvent();

        echo 'HELLO WORLD PLUGIN' . PHP_EOL;
        exit;
    }

}