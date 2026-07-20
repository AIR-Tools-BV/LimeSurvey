<?php
use LimeSurvey\Libraries\FormExtension\Inputs\TextInput;
use LimeSurvey\Libraries\FormExtension\SaveFailedException;
class HelloWorld extends PluginBase
{
    static protected $description = 'HelloWorld';
    static protected $name = 'HelloWorld';

    /**
     * subscribe to all needed events
     * @see https://manual.limesurvey.org/Plugin_events
     */
    public function init()
    {
        Yii::app()->formExtensionService->add(
            'globalsettings.general',
            new TextInput([
                'name' => 'myinput',
                'label' => 'Label',
                'disabled' => true,
                'tooltip' => 'Moo moo moo',
                'help' => 'Some help text',
                'save' => function($request, $connection) {
                    $value = $request->getPost('myinput');
                    if ($value === 'some invalid value') {
                        throw new SaveFailedException("Could not save custom input 'myinput'");
                    } else {
                        SettingGlobal::setSetting('myinput', $value);
                    }
                },
                'load' => function () {
                    return getGlobalSetting('myinput');
                }
            ])
        );
    }
}