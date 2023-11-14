
<?php

use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Utils;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as S3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter as AwsS3PortableVisibilityConverter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Visibility;
use Szhorvath\FlysystemAwsS3Plus\AwsS3V3PlusAdapter;

beforeEach(function () {
    $this->config = [
        'bucket' => $_ENV['AWS_BUCKET'] = 'testbucket',
        'region' => $_ENV['AWS_DEFAULT_REGION'] = 'eu-west-1',
        'url' => $_ENV['AWS_URL'] = 'http://127.0.0.1:9000',
        'endpoint' => 'http://127.0.0.1:9000',
        'use_path_style_endpoint' => true,
        'throw' => true,
        'version' => 'latest',
        'credentials' => [
            'key' => $_ENV['AWS_ACCESS_KEY_ID'] = 'sail',
            'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] = 'password',
        ],
        'root' => $_ENV['AWS_ROOT'] = 'test',
    ];

    $this->client = new S3Client($this->config);

    createTestBucket($this->client, $this->config);

    $visibility = new AwsS3PortableVisibilityConverter(Visibility::PUBLIC);

    $this->flyAdapter = new S3Adapter($this->client, $this->config['bucket'], $this->config['root'], $visibility);

    $this->adapter = new AwsS3V3PlusAdapter(
        new Flysystem($this->flyAdapter),
        $this->flyAdapter,
        $this->config,
        $this->client
    );

});

afterEach(function () {
    $results = $this->client->listObjectsV2([
        'Bucket' => $this->config['bucket'],
    ]);

    foreach ($results['Contents'] as $content) {
        $versions = $this->client->listObjectVersions([
            'Bucket' => $this->config['bucket'],
            'Prefix' => $content['Key'],
        ]);

        if (! isset($versions['Versions'])) {
            $objects[] = ['Key' => $content['Key']];

            continue;
        }

        foreach ($versions['Versions'] as $version) {
            $objects[] = [
                'Key' => $version['Key'],
                'VersionId' => $version['VersionId'],
            ];
        }
    }

    $this->client->deleteObjects([
        'Bucket' => $this->config['bucket'],
        'Delete' => [
            'Objects' => $objects,
        ],
    ]);

    $this->client->deleteBucket(['Bucket' => $this->config['bucket']]);
});

it('should read', function () {
    $stream = Utils::streamFor('data');

    $this->client->putObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $this->config['root'].'/text.txt',
        'Body' => $stream,
    ]);

    expect($this->adapter->get('text.txt'))->toBe('data');
});

it('should read the latest version when the path called', function () {
    turnOnVersioning($this->client, $this->config);

    $this->client->putObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $this->config['root'].'/text.txt',
        'Body' => Utils::streamFor('DataVersion1'),
    ]);

    $this->client->putObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $this->config['root'].'/text.txt',
        'Body' => Utils::streamFor('DataVersionTwo'),
    ]);

    expect($this->adapter->get('text.txt'))->toBe('DataVersionTwo');
});

it('should retrieve all version of an object', function () {
    turnOnVersioning($this->client, $this->config);

    $path = $this->config['root'].'/text.txt';

    $this->client->putObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $path,
        'Body' => Utils::streamFor('DataVersion1'),
    ]);

    $this->client->putObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $path,
        'Body' => Utils::streamFor('DataVersionTwo'),
    ]);

    $versions = $this->adapter->versions($path);

    expect($versions)->toBeCollection()->toHaveCount(2);

    expect($versions[0])
        ->hash->toBeString()
        ->key->toBe($path)
        ->version->toBeString()
        ->type->toBe('file')
        ->latest->toBeTrue()
        ->updatedAt->toBeInstanceOf(CarbonImmutable::class)
        ->size->toBe(14);

    expect($versions[1])
        ->hash->toBeString()
        ->key->toBe($path)
        ->version->toBeString()
        ->type->toBe('file')
        ->latest->toBeFalse()
        ->updatedAt->toBeInstanceOf(CarbonImmutable::class)
        ->size->toBe(12);
});

function createTestBucket(S3Client $client, array $config): void
{
    $params = ['Bucket' => $config['bucket']];

    try {
        $client->headBucket($params);
    } catch (\Throwable $th) {
        $client->createBucket($params);
    }
}

function turnOnVersioning(S3Client $client, array $config): void
{
    $client->putBucketVersioning([
        'Bucket' => $config['bucket'],
        'VersioningConfiguration' => [
            'MFADelete' => 'Disabled',
            'Status' => 'Enabled',
        ],
    ]);
}
