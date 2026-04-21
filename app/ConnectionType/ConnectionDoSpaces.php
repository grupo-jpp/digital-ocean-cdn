<?php

declare(strict_types=1);

namespace DigitalOceanCdn\ConnectionType;

use Atro\ConnectionType\AbstractConnection;
use Atro\ConnectionType\ConnectionInterface;
use Atro\ConnectionType\TestConnectionInterface;
use Atro\Core\Exceptions\BadRequest;
use Aws\S3\S3Client;
use Espo\ORM\Entity;

class ConnectionDoSpaces extends AbstractConnection implements ConnectionInterface, TestConnectionInterface
{
    /**
     * Cria o client S3 a partir dos dados da Connection.
     */
    public function connect(Entity $connectionEntity)
    {
        $data = $this->extractData($connectionEntity);

        if ($data['endpoint'] === '' || $data['accessKey'] === '' || $data['secretKey'] === '') {
            throw new BadRequest('Digital Ocean Spaces: endpoint, access key and secret key are required.');
        }

        return new S3Client([
            'version'                 => 'latest',
            'region'                  => $data['region'] !== '' ? $data['region'] : 'us-east-1',
            'endpoint'                => $data['endpoint'],
            'use_path_style_endpoint' => false,
            'credentials'             => [
                'key'    => $data['accessKey'],
                'secret' => $data['secretKey'],
            ],
        ]);
    }

    /**
     * Testa a conexão (botão "Testar Conexão").
     */
    public function testConnection(Entity $connectionEntity): bool
    {
        try {
            /** @var S3Client $client */
            $client = $this->connect($connectionEntity);

            $bucket = $this->extractData($connectionEntity)['bucket'];
            if ($bucket !== '') {
                $client->headBucket(['Bucket' => $bucket]);
            } else {
                $client->listBuckets();
            }
            return true;
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('DO Spaces test connection: ' . $e->getMessage());
            throw new BadRequest('Digital Ocean Spaces: ' . $e->getMessage());
        }
    }

    /**
     * Lê os campos notStorable/dataField a partir da entity.
     * Suporta tanto get() direto quanto o objeto `data`.
     * Também normaliza o endpoint removendo o prefixo "{bucket}." se estiver presente,
     * evitando o erro de SSL por bucket duplicado no host.
     */
    protected function extractData(Entity $connectionEntity): array
    {
        $get = function (string $key) use ($connectionEntity) {
            $v = $connectionEntity->get($key);
            if ($v === null || $v === '') {
                $data = $connectionEntity->get('data');
                if (is_object($data) && isset($data->$key)) {
                    $v = $data->$key;
                } elseif (is_array($data) && isset($data[$key])) {
                    $v = $data[$key];
                }
            }
            return is_string($v) ? trim($v) : $v;
        };

        // password criptografada: tenta descriptografar; se falhar, mantém como veio
        $secret = (string)$get('doSpacesSecretKey');
        if ($secret !== '') {
            try {
                $decoded = $this->decryptPassword($secret);
                if (is_string($decoded) && $decoded !== '') {
                    $secret = $decoded;
                }
            } catch (\Throwable $e) {
                // mantém valor original
            }
        }

        $endpoint = rtrim((string)$get('doSpacesEndpoint'), '/');
        $bucket   = (string)$get('doSpacesBucket');

        // Se o endpoint já contém "{bucket}." no host, remove para evitar duplicação.
        if ($bucket !== '' && $endpoint !== '') {
            $parts = parse_url($endpoint);
            if (!empty($parts['host']) && str_starts_with($parts['host'], $bucket . '.')) {
                $newHost  = substr($parts['host'], strlen($bucket) + 1);
                $endpoint = ($parts['scheme'] ?? 'https') . '://' . $newHost;
            }
        }

        return [
            'endpoint'  => $endpoint,
            'region'    => (string)$get('doSpacesRegion'),
            'accessKey' => (string)$get('doSpacesAccessKey'),
            'secretKey' => $secret,
            'bucket'    => $bucket,
            'cdn'       => rtrim((string)$get('doSpacesCdnEndpoint'), '/'),
        ];
    }
}