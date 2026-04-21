<?php

declare(strict_types=1);

namespace DigitalOceanCdn\Controllers;

use Atro\Core\Exceptions\BadRequest;
use Atro\Core\Exceptions\Forbidden;
use Espo\Core\Controllers\Base;
use Slim\Http\Request;

class DigitalOceanSpaces extends Base
{
    public function actionSync($params, $data, Request $request)
    {
        if (!$this->getUser()->isAdmin()) {
            throw new Forbidden();
        }
        $id = is_object($data) ? ($data->id ?? null) : ($data['id'] ?? null);
        if (empty($id)) {
            throw new BadRequest('Storage id is required');
        }
        $service = $this->getContainer()->get('serviceFactory')->create('DigitalOceanSpacesSync');
        return $service->queueSync((string)$id);
    }
}