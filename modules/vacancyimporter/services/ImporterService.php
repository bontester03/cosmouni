<?php

namespace modules\vacancyimporter\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\ElementHelper;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use modules\vacancyimporter\Module;
use Throwable;

class ImporterService extends Component
{
    private const SECTION_HANDLE = 'importedVacancies';
    private const ENTRY_TYPE_HANDLE = 'importedVacancy';

    private const REQUIRED_FIELDS = [
        'title',
        'organisation',
        'location',
        'applyUrl',
        'sourceName',
        'sourceUrl',
        'externalKey',
    ];

    /**
     * @param array<int,array<string,mixed>> $jobs
     * @return array{created:int,updated:int,failed:int,errors:array<int,array<string,string>>}
     */
    public function importJobs(array $jobs): array
    {
        $summary = [
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $section = Craft::$app->getEntries()->getSectionByHandle(self::SECTION_HANDLE);
        if ($section === null) {
            throw new \RuntimeException('Section "importedVacancies" was not found.');
        }

        $entryType = $this->entryTypeForSection($section->id);
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($jobs as $job) {
            $externalKey = trim((string)($job['externalKey'] ?? ''));

            try {
                $this->validateJobPayload($job);

                $entry = $this->findEntryByExternalKey($externalKey, $siteId);
                $isNew = $entry === null;

                if ($isNew) {
                    $entry = new Entry();
                    $entry->sectionId = $section->id;
                    $entry->typeId = $entryType->id;
                    $entry->siteId = $siteId;
                    $entry->enabled = false;
                }

                $entry->title = trim((string)$job['title']);
                $entry->slug = ElementHelper::generateSlug($entry->title);
                $entry->setFieldValue('organisation', trim((string)$job['organisation']));
                $entry->setFieldValue('location', trim((string)$job['location']));
                $entry->setFieldValue('applyUrl', $this->linkValue((string)$job['applyUrl']));
                $entry->setFieldValue('sourceName', trim((string)$job['sourceName']));
                $entry->setFieldValue('sourceUrl', $this->linkValue((string)$job['sourceUrl']));
                $entry->setFieldValue('externalKey', $externalKey);

                $entry->setFieldValue('salary', $this->nullableTrim($job['salary'] ?? null));
                $entry->setFieldValue('contractType', $this->normaliseContractType($job['contractType'] ?? null));
                $entry->setFieldValue('closingDate', $this->parseDateField($job['closingDate'] ?? null));
                $entry->setFieldValue('jobReference', $this->nullableTrim($job['jobReference'] ?? null));
                $entry->setFieldValue('description', $this->nullableTrim($job['description'] ?? null));

                if ($isNew) {
                    $entry->setFieldValue('approvalStatus', 'pending');
                    $entry->setFieldValue('importedAt', DateTimeHelper::toDateTime($nowUtc->format(DateTime::ATOM)));
                }

                $entry->setFieldValue('lastSeenAt', DateTimeHelper::toDateTime($nowUtc->format(DateTime::ATOM)));

                if (!Craft::$app->getElements()->saveElement($entry)) {
                    throw new \RuntimeException($this->firstElementError($entry));
                }

                $this->upsertExternalKeyMap($externalKey, (int)$entry->id);

                if ($isNew) {
                    $summary['created']++;
                } else {
                    $summary['updated']++;
                }
            } catch (Throwable $e) {
                $summary['failed']++;
                $summary['errors'][] = [
                    'externalKey' => $externalKey,
                    'message' => $e->getMessage(),
                ];
                Craft::error(sprintf('Import failed for externalKey "%s": %s', $externalKey, $e->getMessage()), 'vacancy-importer');
            }
        }

        return $summary;
    }

    /**
     * @return array{expiredCount:int,externalKeys:array<int,string>}
     */
    public function expireJobs(string $sourceName, int $cutoffDays): array
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify(sprintf('-%d days', $cutoffDays));

        $entries = Entry::find()
            ->section(self::SECTION_HANDLE)
            ->site('*')
            ->status(null)
            ->sourceName($sourceName)
            ->lastSeenAt('< ' . $cutoff->format(DateTime::ATOM))
            ->all();

        $expiredKeys = [];

        foreach ($entries as $entry) {
            try {
                $entry->enabled = false;
                $entry->setFieldValue('approvalStatus', 'rejected');
                $entry->setFieldValue('reviewedBy', 'System expiry process');
                $entry->setFieldValue('reviewedAt', DateTimeHelper::toDateTime((new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTime::ATOM)));

                if (!Craft::$app->getElements()->saveElement($entry)) {
                    throw new \RuntimeException($this->firstElementError($entry));
                }

                $key = (string)$entry->getFieldValue('externalKey');
                if ($key !== '') {
                    $expiredKeys[] = $key;
                }
            } catch (Throwable $e) {
                Craft::error(sprintf('Expiry failed for entry #%d: %s', $entry->id, $e->getMessage()), 'vacancy-importer');
            }
        }

        return [
            'expiredCount' => count($expiredKeys),
            'externalKeys' => $expiredKeys,
        ];
    }

    private function entryTypeForSection(int $sectionId): object
    {
        $entryTypes = Craft::$app->getEntries()->getEntryTypesBySectionId($sectionId);
        foreach ($entryTypes as $entryType) {
            if ($entryType->handle === self::ENTRY_TYPE_HANDLE) {
                return $entryType;
            }
        }

        throw new \RuntimeException('Entry type "importedVacancy" was not found in section "importedVacancies".');
    }

    private function validateJobPayload(array $job): void
    {
        foreach (self::REQUIRED_FIELDS as $requiredField) {
            $value = isset($job[$requiredField]) ? trim((string)$job[$requiredField]) : '';
            if ($value === '') {
                throw new \InvalidArgumentException(sprintf('Missing required field: %s', $requiredField));
            }
        }
    }

    private function findEntryByExternalKey(string $externalKey, int $siteId): ?Entry
    {
        $mappedEntryId = (new \craft\db\Query())
            ->from(Module::EXTERNAL_KEYS_TABLE)
            ->select(['entryId'])
            ->where(['externalKey' => $externalKey])
            ->scalar();

        if ($mappedEntryId) {
            $mappedEntry = Craft::$app->getElements()->getElementById((int)$mappedEntryId, Entry::class, $siteId);
            if ($mappedEntry instanceof Entry) {
                return $mappedEntry;
            }
        }

        $fallbackEntry = Entry::find()
            ->section(self::SECTION_HANDLE)
            ->site('*')
            ->status(null)
            ->externalKey($externalKey)
            ->one();

        if ($fallbackEntry instanceof Entry) {
            $this->upsertExternalKeyMap($externalKey, (int)$fallbackEntry->id);
        }

        return $fallbackEntry ?: null;
    }

    private function upsertExternalKeyMap(string $externalKey, int $entryId): void
    {
        Craft::$app->getDb()->createCommand()->upsert(
            Module::EXTERNAL_KEYS_TABLE,
            ['externalKey' => $externalKey, 'entryId' => $entryId],
            ['entryId' => $entryId],
        )->execute();
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalised = trim((string)$value);
        return $normalised === '' ? null : $normalised;
    }

    private function parseDateField(mixed $value): ?DateTime
    {
        $normalised = $this->nullableTrim($value);
        if ($normalised === null) {
            return null;
        }

        $date = DateTimeHelper::toDateTime($normalised);
        if (!$date instanceof DateTime) {
            throw new \InvalidArgumentException(sprintf('Invalid date value: %s', $normalised));
        }

        return $date;
    }

    private function normaliseContractType(mixed $value): string
    {
        $normalised = strtolower((string)$value);

        return match ($normalised) {
            'full-time', 'full_time', 'full time' => 'full_time',
            'part-time', 'part_time', 'part time' => 'part_time',
            'temporary' => 'temporary',
            'permanent' => 'permanent',
            'casual' => 'casual',
            'apprenticeship' => 'apprenticeship',
            default => 'unknown',
        };
    }

    /**
     * @return array{type:string,value:string}
     */
    private function linkValue(string $url): array
    {
        return [
            'type' => 'url',
            'value' => trim($url),
        ];
    }

    private function firstElementError(Entry $entry): string
    {
        $errors = $entry->getFirstErrors();
        return $errors ? implode('; ', $errors) : 'Unknown element save failure.';
    }
}
