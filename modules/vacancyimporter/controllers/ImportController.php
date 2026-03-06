<?php

namespace modules\vacancyimporter\controllers;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use craft\web\Controller;
use modules\vacancyimporter\services\ImporterService;
use yii\web\Response;

class ImportController extends Controller
{
    protected array|bool|int $allowAnonymous = ['import', 'expire'];
    public $enableCsrfValidation = false;

    public function actionImport(): Response
    {
        $this->requirePostRequest();

        if (($authResponse = $this->authoriseRequest()) !== null) {
            return $authResponse;
        }

        $payload = $this->requestJsonBody();
        if ($payload === null || !isset($payload['jobs']) || !is_array($payload['jobs'])) {
            return $this->jsonError('Invalid payload. Expected JSON object with a jobs array.', 400);
        }

        if (count($payload['jobs']) > 200) {
            return $this->jsonError('Payload exceeds the maximum of 200 jobs per request.', 413);
        }

        /** @var ImporterService $service */
        $service = $this->module->get('importer');
        $summary = $service->importJobs($payload['jobs']);

        return $this->asJson([
            'status' => 'ok',
            'created' => $summary['created'],
            'updated' => $summary['updated'],
            'failed' => $summary['failed'],
            'errors' => $summary['errors'],
        ]);
    }

    public function actionExpire(): Response
    {
        $this->requirePostRequest();

        if (($authResponse = $this->authoriseRequest()) !== null) {
            return $authResponse;
        }

        $payload = $this->requestJsonBody();
        if ($payload === null || !is_array($payload)) {
            return $this->jsonError('Invalid payload. Expected JSON object.', 400);
        }

        $sourceName = isset($payload['sourceName']) ? trim((string)$payload['sourceName']) : '';
        if ($sourceName === '') {
            return $this->jsonError('sourceName is required.', 400);
        }

        $cutoffDays = isset($payload['cutoffDays']) ? (int)$payload['cutoffDays'] : 7;
        if ($cutoffDays < 1) {
            return $this->jsonError('cutoffDays must be at least 1.', 400);
        }

        /** @var ImporterService $service */
        $service = $this->module->get('importer');
        $result = $service->expireJobs($sourceName, $cutoffDays);

        return $this->asJson([
            'status' => 'ok',
            'expiredCount' => $result['expiredCount'],
            'externalKeys' => $result['externalKeys'],
        ]);
    }

    private function authoriseRequest(): ?Response
    {
        $providedToken = (string)$this->request->getHeaders()->get('X-Import-Token', '');
        $expectedToken = (string)(getenv('IMPORT_TOKEN') ?: App::env('IMPORT_TOKEN'));

        if ($expectedToken === '') {
            Craft::error('IMPORT_TOKEN is not configured.', 'vacancy-importer');
        }

        if ($providedToken === '' || $expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            return $this->jsonError('Unauthorised', 401);
        }

        return null;
    }

    private function requestJsonBody(): ?array
    {
        $body = $this->request->getRawBody();
        if ($body === '') {
            return null;
        }

        try {
            $decoded = Json::decode($body);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function jsonError(string $message, int $status): Response
    {
        $response = $this->asJson([
            'status' => 'error',
            'message' => $message,
        ]);
        $response->setStatusCode($status);
        return $response;
    }
}
