<?php

namespace modules\vacancyimporter;

use Craft;
use modules\vacancyimporter\controllers\ImportController;
use modules\vacancyimporter\services\ImporterService;
use yii\base\Module as BaseModule;

class Module extends BaseModule
{
    public const EXTERNAL_KEYS_TABLE = '{{%vacancyimporter_externalkeys}}';

    public function init(): void
    {
        parent::init();

        $this->controllerNamespace = 'modules\\vacancyimporter\\controllers';
        $this->setComponents([
            'importer' => ImporterService::class,
        ]);

        $this->controllerMap = [
            'import' => [
                'class' => ImportController::class,
                'defaultAction' => 'import',
            ],
            'expire' => [
                'class' => ImportController::class,
                'defaultAction' => 'expire',
            ],
        ];

        Craft::info('Vacancy Importer module loaded.', 'vacancy-importer');
    }
}
