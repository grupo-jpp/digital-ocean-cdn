<?php

declare(strict_types=1);

namespace DigitalOceanCdn\Connection;

use Atro\Connections\AbstractConnection;
use Atro\Core\Exceptions\BadRequest;
use Atro\Entities\Connection;
use Aws\S3\S3Client;

class ConnectionDigitalOceanSpaces extends AbstractConnection
{
    /**
     * Chamado pelo botão "Testar Conexão".
     * Deve retornar o client conectado OU lançar BadRequest com mensagem clara.
     */
    public function connect(Connection $connection)
    {
        $region    = trim((string)$connection->get('doRegion')) ?: 'us-east-1';
        $endpoint  = rtrim(trim((string)$connection->get('doEndpoint')), '/');
        $accessKey = trim((string)$connection->get('doAccessKey'));
        $secretKey = trim((string)$connection->get('doSecretKey'));
        $bucket    = trim((string)$connection->get('doBucket'));

        if ($endpoint === '' || $accessKey === '' || $secretKey === '') {
            throw new BadRequest($this->translate('fillAllRequiredFields', 'exceptions', 'Connection'));
        }

        try {
            $client = new S3Client([
                'version'                 => 'latest',
                'region'                  => $region,
                'endpoint'                => $endpoint,
                'use_path_style_endpoint' => false,
                'credentials'             => [
                    'key'    => $accessKey,
                    'secret' => $secretKey,
                ],
            ]);

            if ($bucket !== '') {
                $client->headBucket(['Bucket' => $bucket]);
            } else {
                $client->listBuckets();
            }
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('DO Spaces connection error: ' . $e->getMessage());
            throw new BadRequest('Digital Ocean Spaces: ' . $e->getMessage());
        }

        return $client;
    }
}