<?php

namespace craft\contentmigrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\PlainText;
use craft\fields\Url;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use yii\base\Exception;

class m260305_000001_create_imported_vacancies extends Migration
{
    private const EXTERNAL_KEYS_TABLE = '{{%vacancyimporter_externalkeys}}';

    public function safeUp(): bool
    {
        $this->createExternalKeyTable();
        $this->createOrUpdateSectionAndFields();

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260305_000001_create_imported_vacancies cannot be reverted.\n";
        return false;
    }

    private function createExternalKeyTable(): void
    {
        if ($this->db->tableExists(self::EXTERNAL_KEYS_TABLE)) {
            return;
        }

        $this->createTable(self::EXTERNAL_KEYS_TABLE, [
            'externalKey' => $this->string(255)->notNull(),
            'entryId' => $this->integer()->notNull(),
        ]);

        $this->addPrimaryKey('pk_vacancyimporter_externalkeys_externalKey', self::EXTERNAL_KEYS_TABLE, 'externalKey');
        $this->createIndex('uidx_vacancyimporter_externalkeys_entryId', self::EXTERNAL_KEYS_TABLE, 'entryId', true);
        $this->addForeignKey(
            'fk_vacancyimporter_externalkeys_entryId',
            self::EXTERNAL_KEYS_TABLE,
            'entryId',
            Table::ENTRIES,
            'id',
            'CASCADE',
            'CASCADE',
        );
    }

    private function createOrUpdateSectionAndFields(): void
    {
        $entriesService = Craft::$app->getEntries();

        $fields = [
            'organisation' => $this->ensurePlainTextField('organisation', 'Organisation'),
            'location' => $this->ensurePlainTextField('location', 'Location'),
            'salary' => $this->ensurePlainTextField('salary', 'Salary'),
            'contractType' => $this->ensureDropdownField('contractType', 'Contract Type', [
                ['label' => 'Full-time', 'value' => 'full_time'],
                ['label' => 'Part-time', 'value' => 'part_time'],
                ['label' => 'Temporary', 'value' => 'temporary'],
                ['label' => 'Permanent', 'value' => 'permanent'],
                ['label' => 'Casual', 'value' => 'casual'],
                ['label' => 'Apprenticeship', 'value' => 'apprenticeship'],
                ['label' => 'Unknown', 'value' => 'unknown'],
            ]),
            'closingDate' => $this->ensureDateField('closingDate', 'Closing Date', false),
            'applyUrl' => $this->ensureUrlField('applyUrl', 'Apply URL'),
            'jobReference' => $this->ensurePlainTextField('jobReference', 'Job Reference'),
            'description' => $this->ensurePlainTextField('description', 'Description', ['multiline' => true, 'initialRows' => 8]),

            'sourceName' => $this->ensurePlainTextField('sourceName', 'Source Name'),
            'sourceUrl' => $this->ensureUrlField('sourceUrl', 'Source URL'),
            'externalKey' => $this->ensurePlainTextField('externalKey', 'External Key'),
            'importedAt' => $this->ensureDateField('importedAt', 'Imported At', true),
            'lastSeenAt' => $this->ensureDateField('lastSeenAt', 'Last Seen At', true),

            'approvalStatus' => $this->ensureDropdownField('approvalStatus', 'Approval Status', [
                ['label' => 'Pending', 'value' => 'pending', 'default' => true],
                ['label' => 'Approved', 'value' => 'approved'],
                ['label' => 'Rejected', 'value' => 'rejected'],
            ]),
            'reviewedBy' => $this->ensurePlainTextField('reviewedBy', 'Reviewed By'),
            'reviewedAt' => $this->ensureDateField('reviewedAt', 'Reviewed At', true),
            'rejectionReason' => $this->ensurePlainTextField('rejectionReason', 'Rejection Reason', ['multiline' => true]),
        ];

        $section = $entriesService->getSectionByHandle('importedVacancies');
        $isNewSection = $section === null;

        if ($isNewSection) {
            $section = new Section([
                'name' => 'Imported Vacancies',
                'handle' => 'importedVacancies',
                'type' => Section::TYPE_CHANNEL,
                'enableVersioning' => true,
            ]);
        }

        $siteSettings = [];
        $existingSiteSettings = $isNewSection ? [] : $section->getSiteSettings();
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $settings = $existingSiteSettings[$site->id] ?? new Section_SiteSettings([
                'siteId' => $site->id,
                'hasUrls' => false,
                'uriFormat' => null,
                'template' => null,
            ]);

            // Imported entries should always be created disabled by default.
            $settings->enabledByDefault = false;
            $siteSettings[] = $settings;
        }
        $section->setSiteSettings($siteSettings);

        $entryType = null;
        foreach ($section->getEntryTypes() as $existingEntryType) {
            if ($existingEntryType->handle === 'importedVacancy') {
                $entryType = $existingEntryType;
                break;
            }
        }

        if ($entryType === null) {
            $entryType = new EntryType([
                'name' => 'Imported Vacancy',
                'handle' => 'importedVacancy',
                'hasTitleField' => true,
                'showStatusField' => true,
            ]);

            if (!$entriesService->saveEntryType($entryType)) {
                throw new Exception('Could not save importedVacancy entry type: ' . json_encode($entryType->getFirstErrors()));
            }
        }

        $sectionEntryTypes = $section->getEntryTypes();
        $hasEntryType = false;
        foreach ($sectionEntryTypes as $sectionEntryType) {
            if ($sectionEntryType->handle === 'importedVacancy') {
                $hasEntryType = true;
                break;
            }
        }
        if (!$hasEntryType) {
            $sectionEntryTypes[] = $entryType;
            $section->setEntryTypes($sectionEntryTypes);
        }

        if (!$entriesService->saveSection($section)) {
            throw new Exception('Could not save Imported Vacancies section: ' . json_encode($section->getFirstErrors()));
        }

        $entryType = $entriesService->getEntryTypeByHandle('importedVacancy');
        if ($entryType === null) {
            throw new Exception('Could not load importedVacancy entry type after section save.');
        }

        $fieldLayout = $entryType->getFieldLayout();
        $fieldLayout->type = Entry::class;
        $fieldLayout->setTabs([
            [
                'name' => 'Vacancy',
                'elements' => [
                    ['type' => CustomField::class, 'fieldUid' => $fields['organisation']->uid],
                    ['type' => CustomField::class, 'fieldUid' => $fields['location']->uid],
                    ['type' => CustomField::class, 'fieldUid' => $fields['salary']->uid],
                    ['type' => CustomField::class, 'fieldUid' => $fields['contractType']->uid],
                    ['type' => CustomField::class, 'fieldUid' => $fields['closingDate']->uid],
                    ['type' => CustomField::class, 'fieldUid' => $fields['applyUrl']->uid],
                    ['type' => CustomField::class, 'fieldUid' => $fields['jobReference']->uid],
                    ['type' => CustomField::class, 'fieldUid' => $fields['description']->uid],
                ],
            ],
            [
                'name' => 'Source & Import',
                'elements' => [
                    ['type' => CustomField::class, 'fieldUid' => $fields['sourceName']->uid],
                    ['type' => CustomField::class, 'fieldUid' => $fields['sourceUrl']->uid],
                    ['type' => CustomField::class, 'fieldUid' => $fields['externalKey']->uid, 'required' => true],
                    ['type' => CustomField::class, 'fieldUid' => $fields['importedAt']->uid],
                    ['type' => CustomField::class, 'fieldUid' => $fields['lastSeenAt']->uid],
                ],
            ],
            [
                'name' => 'Review',
                'elements' => [
                    ['type' => CustomField::class, 'fieldUid' => $fields['approvalStatus']->uid],
                    ['type' => CustomField::class, 'fieldUid' => $fields['reviewedBy']->uid],
                    ['type' => CustomField::class, 'fieldUid' => $fields['reviewedAt']->uid],
                    ['type' => CustomField::class, 'fieldUid' => $fields['rejectionReason']->uid],
                ],
            ],
        ]);

        $entryType->setFieldLayout($fieldLayout);
        if (!$entriesService->saveEntryType($entryType)) {
            throw new Exception('Could not save importedVacancy entry type: ' . json_encode($entryType->getFirstErrors()));
        }

        // Craft does not support native uniqueness constraints for custom fields across entries,
        // so a dedicated table with a unique key is used for de-duplication by externalKey.
    }

    private function ensurePlainTextField(string $handle, string $name, array $config = []): PlainText
    {
        $field = Craft::$app->getFields()->getFieldByHandle($handle);
        if ($field !== null) {
            if (!$field instanceof PlainText) {
                throw new Exception("Field '$handle' already exists but is not a Plain Text field.");
            }
            return $field;
        }

        $field = new PlainText(array_merge([
            'name' => $name,
            'handle' => $handle,
            'instructions' => '',
            'searchable' => true,
        ], $config));

        if (!Craft::$app->getFields()->saveField($field)) {
            throw new Exception("Could not save field '$handle': " . json_encode($field->getFirstErrors()));
        }

        return $field;
    }

    private function ensureDropdownField(string $handle, string $name, array $options): Dropdown
    {
        $field = Craft::$app->getFields()->getFieldByHandle($handle);
        if ($field !== null) {
            if (!$field instanceof Dropdown) {
                throw new Exception("Field '$handle' already exists but is not a Dropdown field.");
            }
            return $field;
        }

        $field = new Dropdown([
            'name' => $name,
            'handle' => $handle,
            'instructions' => '',
            'searchable' => true,
            'options' => $options,
        ]);

        if (!Craft::$app->getFields()->saveField($field)) {
            throw new Exception("Could not save field '$handle': " . json_encode($field->getFirstErrors()));
        }

        return $field;
    }

    private function ensureDateField(string $handle, string $name, bool $withTime): Date
    {
        $field = Craft::$app->getFields()->getFieldByHandle($handle);
        if ($field !== null) {
            if (!$field instanceof Date) {
                throw new Exception("Field '$handle' already exists but is not a Date field.");
            }
            return $field;
        }

        $field = new Date([
            'name' => $name,
            'handle' => $handle,
            'instructions' => '',
            'searchable' => true,
            'showDate' => true,
            'showTime' => $withTime,
            'showTimeZone' => false,
        ]);

        if (!Craft::$app->getFields()->saveField($field)) {
            throw new Exception("Could not save field '$handle': " . json_encode($field->getFirstErrors()));
        }

        return $field;
    }

    private function ensureUrlField(string $handle, string $name): Url
    {
        $field = Craft::$app->getFields()->getFieldByHandle($handle);
        if ($field !== null) {
            if (!$field instanceof Url) {
                throw new Exception("Field '$handle' already exists but is not a URL field.");
            }
            return $field;
        }

        $field = new Url([
            'name' => $name,
            'handle' => $handle,
            'instructions' => '',
            'searchable' => true,
            'types' => ['url'],
            'showLabelField' => false,
        ]);

        if (!Craft::$app->getFields()->saveField($field)) {
            throw new Exception("Could not save field '$handle': " . json_encode($field->getFirstErrors()));
        }

        return $field;
    }
}
