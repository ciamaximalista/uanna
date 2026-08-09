#!/usr/bin/env php
<?php

use Oannes\Autoload;
use Oannes\Auth;
use Oannes\FileStore;
use Oannes\FileQueue;
use Oannes\DeliveryWorker;
use Oannes\Id;
use Oannes\IndexBuilder;
use Oannes\InboxWorker;
use Oannes\Json;
use Oannes\KeyStore;
use Oannes\SnacImporter;
use Oannes\SimulationRunner;
use Oannes\ThreadValidator;
use Oannes\ObjectRepository;
use Oannes\XmlExporter;
use Oannes\LocalUsers;
use Oannes\ModerationService;
use Oannes\PostService;
use Oannes\ReadinessReport;
use Oannes\SocialGraph;
use Oannes\ActorRepository;

require dirname(__DIR__) . '/src/Oannes/Autoload.php';
Autoload::register();

$config = require dirname(__DIR__) . '/config/oannes.php';
$store = new FileStore($config['data_dir']);
$command = $argv[1] ?? 'help';

try {
    $result = match ($command) {
        'analyse-snac' => (new SnacImporter($store))->analyse($argv[2] ?? dirname(__DIR__, 2)),
        'import-snac' => (new SnacImporter($store))->import($argv[2] ?? dirname(__DIR__, 2)),
        'rebuild-index' => (new IndexBuilder($store))->rebuild(),
        'validate-threads' => (new ThreadValidator($store))->validate(),
        'queue-list' => (new FileQueue($store))->list($argv[2] ?? 'pending'),
        'queue-run' => queue_run($store, $config, $argv[2] ?? null, $argv[3] ?? null),
        'inbox-run' => inbox_run($store, $argv[2] ?? null),
        'moderation-list' => moderation_list($store, $config, $argv[2] ?? null, $argv[3] ?? null),
        'moderation-approve-follow' => moderation_decide_follow($store, $config, $argv[2] ?? null, $argv[3] ?? null, true),
        'moderation-reject-follow' => moderation_decide_follow($store, $config, $argv[2] ?? null, $argv[3] ?? null, false),
        'moderation-approve-create' => moderation_decide_create($store, $config, $argv[2] ?? null, $argv[3] ?? null, true),
        'moderation-reject-create' => moderation_decide_create($store, $config, $argv[2] ?? null, $argv[3] ?? null, false),
        'set-password' => set_password($store, $argv[2] ?? null),
        'auth-audit' => auth_audit($store, $config),
        'export-xml' => export_xml($store, $argv[2] ?? null),
        'post-note' => post_note($store, $config, $argv[2] ?? null, $argv[3] ?? null),
        'simulate' => simulate($argv[2] ?? null),
        'readiness' => readiness($store, $config, $argv[2] ?? null),
        default => [
            'usage' => [
                'php oannes/bin/oannes.php analyse-snac /path/to/snac',
                'php oannes/bin/oannes.php import-snac /path/to/snac',
                'php oannes/bin/oannes.php rebuild-index',
                'php oannes/bin/oannes.php validate-threads',
                'php oannes/bin/oannes.php queue-list [pending|done]',
                'php oannes/bin/oannes.php queue-run [limit] [--dry-run]',
                'php oannes/bin/oannes.php inbox-run [limit]',
                'php oannes/bin/oannes.php moderation-list <uid> [follows]',
                'php oannes/bin/oannes.php moderation-approve-follow <uid> <case-id>',
                'php oannes/bin/oannes.php moderation-reject-follow <uid> <case-id>',
                'php oannes/bin/oannes.php moderation-approve-create <uid> <case-id>',
                'php oannes/bin/oannes.php moderation-reject-create <uid> <case-id>',
                'php oannes/bin/oannes.php set-password <uid>',
                'php oannes/bin/oannes.php auth-audit',
                'php oannes/bin/oannes.php export-xml <activitypub-id-or-url>',
                'php oannes/bin/oannes.php post-note <uid> <text>',
                'php oannes/bin/oannes.php simulate [iterations]',
                'php oannes/bin/oannes.php readiness [simulation-iterations]',
            ],
        ],
    };

    echo Json::encode($result);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

function queue_run(FileStore $store, array $config, ?string $limitArg, ?string $mode): array
{
    $limit = $limitArg !== null && $limitArg !== '' ? (int)$limitArg : 25;
    $dryRun = $mode === '--dry-run' || !(bool)($config['delivery_enabled'] ?? false);

    return (new DeliveryWorker(
        $store,
        new FileQueue($store),
        new KeyStore($store),
        $config,
    ))->run(max(1, $limit), $dryRun);
}

function inbox_run(FileStore $store, ?string $limitArg): array
{
    $limit = $limitArg !== null && $limitArg !== '' ? (int)$limitArg : 25;

    return (new InboxWorker($store, new FileQueue($store)))->run(max(1, $limit));
}

function moderation_list(FileStore $store, array $config, ?string $uid, ?string $kind): array
{
    if ($uid === null || $uid === '') {
        throw new InvalidArgumentException('Usage: moderation-list <uid> [follows]');
    }

    return moderation_service($store, $config)->pending($uid, $kind ?: 'follows');
}

function moderation_decide_follow(FileStore $store, array $config, ?string $uid, ?string $caseId, bool $approve): array
{
    if ($uid === null || $uid === '' || $caseId === null || $caseId === '') {
        throw new InvalidArgumentException('Usage: moderation-approve-follow <uid> <case-id>');
    }

    $service = moderation_service($store, $config);

    return $approve
        ? $service->approveFollow($uid, $caseId, 'cli')
        : $service->rejectFollow($uid, $caseId, 'cli');
}

function moderation_decide_create(FileStore $store, array $config, ?string $uid, ?string $caseId, bool $approve): array
{
    if ($uid === null || $uid === '' || $caseId === null || $caseId === '') {
        throw new InvalidArgumentException('Usage: moderation-approve-create <uid> <case-id>');
    }

    $service = moderation_service($store, $config);

    return $approve
        ? $service->approveCreate($uid, $caseId, 'cli')
        : $service->rejectCreate($uid, $caseId, 'cli');
}

function moderation_service(FileStore $store, array $config): ModerationService
{
    return new ModerationService(
        $store,
        new LocalUsers($store, $config),
        new ActorRepository($store),
        new SocialGraph($store),
        new FileQueue($store),
    );
}

function set_password(FileStore $store, ?string $uid): array
{
    if ($uid === null || $uid === '') {
        throw new InvalidArgumentException('Usage: set-password <uid>');
    }

    fwrite(STDERR, "Password for {$uid}: ");
    $password = trim((string)fgets(STDIN));

    if ($password === '') {
        throw new InvalidArgumentException('Password cannot be empty');
    }

    (new Auth($store))->setPassword($uid, $password);

    return [
        'ok' => true,
        'uid' => $uid,
    ];
}

function auth_audit(FileStore $store, array $config): array
{
    $users = new LocalUsers($store, $config);

    return (new Auth($store))->auditLocalUsers(array_keys($users->all()));
}

function export_xml(FileStore $store, ?string $id): array
{
    if ($id === null || $id === '') {
        throw new InvalidArgumentException('Usage: export-xml <activitypub-id-or-url>');
    }

    $object = (new ObjectRepository($store))->findByIdOrAlias($id);

    if ($object === null) {
        throw new InvalidArgumentException('Object not found');
    }

    $xml = (new XmlExporter())->objectToXml($object);
    $path = $store->dataDir() . '/xml/' . Id::digest($id) . '.xml';
    $store->writeText($path, $xml);

    return [
        'ok' => true,
        'path' => $path,
    ];
}

function post_note(FileStore $store, array $config, ?string $uid, ?string $text): array
{
    if ($uid === null || $text === null) {
        throw new InvalidArgumentException('Usage: post-note <uid> <text>');
    }

    $note = (new PostService(
        $store,
        new LocalUsers($store, $config),
        new FileQueue($store),
        new SocialGraph($store),
        $config,
    ))->createNote($uid, $text);

    return [
        'ok' => true,
        'id' => $note['id'],
    ];
}

function simulate(?string $iterationsArg): array
{
    $iterations = $iterationsArg !== null && $iterationsArg !== '' ? (int)$iterationsArg : 10;

    return (new SimulationRunner())->run(max(1, $iterations));
}

function readiness(FileStore $store, array $config, ?string $iterationsArg): array
{
    $iterations = $iterationsArg !== null && $iterationsArg !== '' ? (int)$iterationsArg : 10;

    return (new ReadinessReport($store, $config))->generate(max(1, $iterations));
}
