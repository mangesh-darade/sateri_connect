<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Libraries\ContactAttributeService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/**
 * Contact attribute definitions (Cheerio "Attributes"): key, label, type, dropdown options, default.
 */
class Attributes extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.view')) {
            return $denied;
        }
        $service = service('contactAttributes');
        $rows    = $service->tableReady() ? $service->listWithUsage() : [];

        if ($this->request->isAJAX() || $this->request->getGet('json') === '1') {
            return $this->jsonResponse(true, ['attributes' => $rows, 'types' => ContactAttributeService::TYPES]);
        }

        return $this->render('attributes/index', [
            'pageTitle'  => 'Attributes',
            'attributes' => $rows,
            'types'      => ContactAttributeService::TYPES,
            'tableReady' => $service->tableReady(),
        ]);
    }

    public function store(): ResponseInterface
    {
        return $this->save(null);
    }

    public function update(int $id): ResponseInterface
    {
        return $this->save($id);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.edit')) {
            return $denied;
        }
        if (! service('contactAttributes')->deleteDefinition($id)) {
            return $this->jsonResponse(false, null, 'Attribute not found.', [], 404);
        }
        (new ActivityLogger())->log('delete', 'attributes', 'Attribute deleted', ['attribute_id' => $id]);

        return $this->jsonResponse(true, null, 'Attribute deleted. Values already saved on contacts are kept.');
    }

    protected function save(?int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.edit')) {
            return $denied;
        }
        try {
            $row = service('contactAttributes')->saveDefinition($this->requestInput(), $id);
        } catch (InvalidArgumentException $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 422);
        }
        (new ActivityLogger())->log($id === null ? 'create' : 'update', 'attributes', 'Attribute ' . $row['attr_key'] . ' saved', ['attribute_id' => (int) $row['id']]);

        return $this->jsonResponse(true, ['attribute' => $row], $id === null ? 'Attribute added.' : 'Attribute updated.');
    }
}
