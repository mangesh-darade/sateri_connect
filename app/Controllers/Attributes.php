<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Libraries\ContactAttributeService;
use App\Libraries\DataTablesQuery;
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

        if ($this->request->isAJAX() || $this->request->getGet('json') === '1') {
            $rows = $service->tableReady() ? $service->listWithUsage() : [];

            return $this->jsonResponse(true, ['attributes' => $rows, 'types' => ContactAttributeService::TYPES]);
        }

        return $this->render('attributes/index', [
            'pageTitle'  => 'Attributes',
            'types'      => ContactAttributeService::TYPES,
            'tableReady' => $service->tableReady(),
            'undefinedKeys' => $service->tableReady() ? $service->undefinedKeys() : [],
        ]);
    }

    /** DataTables JSON for the attribute definitions (usage figures are computed for the visible page only). */
    public function data(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.view')) {
            return $denied;
        }
        $service = service('contactAttributes');
        if (! $service->tableReady()) {
            return $this->response->setJSON([
                'draw' => (int) ($this->request->getGet('draw') ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [],
            ]);
        }

        return $this->response->setJSON(DataTablesQuery::respond($this->request, [
            'builder'  => static fn () => db_connect()->table('contact_attributes a')
                ->select('a.id, a.attr_key, a.label, a.type, a.options, a.default_value'),
            'columns'  => [0 => 'a.label', 1 => 'a.attr_key', 2 => 'a.type'],
            'search'   => ['a.label', 'a.attr_key', 'a.type', 'a.options', 'a.default_value'],
            'order'    => [0, 'asc'],
            'tiebreak' => 'a.id',
            'map'      => static function (array $r) use ($service): array {
                $row = $service->describeRow($r);
                $row['id']        = (int) $row['id'];
                $row['type_label'] = ContactAttributeService::TYPES[$row['type']] ?? $row['type'];

                return $row;
            },
        ]));
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
