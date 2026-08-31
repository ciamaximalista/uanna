<?php

namespace Oannes;

use RuntimeException;

final class SimulationRunner
{
    private array $checks = [];

    public function run(int $iterations = 10): array
    {
        $this->checks = [];

        for ($i = 1; $i <= $iterations; $i++) {
            $this->scenarioFollowApprove($i);
            $this->scenarioFollowReject($i);
            $this->scenarioCreateModeration($i);
            $this->scenarioNotifiedReplyAppearsAndBumpsLocalThread($i);
            $this->scenarioInteractionAccept($i);
            $this->scenarioRemoteBoostFromFollowed($i);
            $this->scenarioReplyMentionAnnounce($i);
            $this->scenarioCanonicalReplyTarget($i);
            $this->scenarioLocalUserAutoFollow($i);
            $this->scenarioAdminSocializeUser($i);
            $this->scenarioDefaultFollowingForNewUser($i);
            $this->scenarioUserArchive($i);
            $this->scenarioInboxSecurity($i);
            $this->scenarioRemoteActorUpdateRefreshesAvatar($i);
            $this->scenarioNegativeInputs($i);
            $this->scenarioDeliveryDryRun($i);
            $this->scenarioDeliveryDeduplication($i);
            $this->scenarioOutboxCreateIdentity($i);
        }

        $failed = array_values(array_filter($this->checks, static fn (array $check): bool => !$check['ok']));

        return [
            'ok' => $failed === [],
            'iterations' => $iterations,
            'checks' => count($this->checks),
            'failed' => $failed,
        ];
    }

    private function scenarioFollowApprove(int $iteration): void
    {
        $env = $this->environment('follow-approve-' . $iteration);
        $this->receive($env, [
            'id' => 'https://remote.test/a/follow-approve-' . $iteration,
            'type' => 'Follow',
            'actor' => $env['remote_actor'],
            'object' => $env['config']['base_url'] . '/u/ana',
        ]);

        (new InboxWorker($env['store'], $env['queue']))->run();
        $moderation = $this->moderation($env);
        $pending = $moderation->pending('ana', 'follows');
        $this->check('follow approve pending exists', count($pending) === 1);
        $result = $moderation->approveFollow('ana', (string)$pending[0]['case_id'], 'sim');
        $followers = (new SocialGraph($env['store']))->followers('ana');
        $deliver = $this->deliverJobs($env['queue']);

        $this->check('follow approve status', ($result['status'] ?? null) === 'approved');
        $this->check('follow approve adds follower', count($followers) === 1);
        $this->check('follow approve queues Accept', ($deliver[0]['payload']['activity']['type'] ?? null) === 'Accept');
    }

    private function scenarioFollowReject(int $iteration): void
    {
        $env = $this->environment('follow-reject-' . $iteration);
        $this->receive($env, [
            'id' => 'https://remote.test/a/follow-reject-' . $iteration,
            'type' => 'Follow',
            'actor' => $env['remote_actor'],
            'object' => $env['config']['base_url'] . '/u/ana',
        ]);

        (new InboxWorker($env['store'], $env['queue']))->run();
        $moderation = $this->moderation($env);
        $pending = $moderation->pending('ana', 'follows');
        $result = $moderation->rejectFollow('ana', (string)$pending[0]['case_id'], 'sim');
        $followers = (new SocialGraph($env['store']))->followers('ana');
        $deliver = $this->deliverJobs($env['queue']);

        $this->check('follow reject status', ($result['status'] ?? null) === 'rejected');
        $this->check('follow reject does not add follower', count($followers) === 0);
        $this->check('follow reject queues Reject', ($deliver[0]['payload']['activity']['type'] ?? null) === 'Reject');
    }

    private function scenarioCreateModeration(int $iteration): void
    {
        $env = $this->environment('create-' . $iteration);
        $parent = [
            'id' => $env['config']['base_url'] . '/u/ana/p/root-' . $iteration,
            'type' => 'Note',
            'attributedTo' => $env['config']['base_url'] . '/u/ana',
            'published' => gmdate('c'),
            'content' => 'Root',
        ];
        $env['store']->writeObject($parent);
        (new IndexBuilder($env['store']))->rebuild();

        $linkedButNotParent = $env['config']['base_url'] . '/u/ana/p/not-parent-' . $iteration;
        $this->receive($env, [
            'id' => 'https://remote.test/a/create-' . $iteration,
            'type' => 'Create',
            'actor' => $env['remote_actor'],
            'object' => [
                'id' => 'https://remote.test/notes/' . $iteration,
                'url' => 'https://remote.test/@alex/' . $iteration,
                'type' => 'Note',
                'attributedTo' => $env['remote_actor'],
                'published' => gmdate('c'),
                'content' => 'Reply with a link ' . $linkedButNotParent,
                'inReplyTo' => $parent['id'],
            ],
        ]);

        (new InboxWorker($env['store'], $env['queue']))->run();
        $moderation = $this->moderation($env);
        $pending = $moderation->pending('ana', 'creates');
        $repo = new ObjectRepository($env['store']);

        $this->check('reply create accepted without moderation', count($pending) === 0);
        $this->check('create stored by alias', $repo->findByIdOrAlias('https://remote.test/@alex/' . $iteration) !== null);
        $this->check('create child linked by inReplyTo', count($repo->childrenOf((string)$parent['id'])) === 1);
        $this->check('create content URL ignored for parentage', count($repo->childrenOf($linkedButNotParent)) === 0);
    }

    private function scenarioNotifiedReplyAppearsAndBumpsLocalThread(int $iteration): void
    {
        $env = $this->environment('notified-reply-' . $iteration);
        $store = $env['store'];
        $repo = new ObjectRepository($store);
        $oldRoot = [
            'id' => $env['config']['base_url'] . '/u/ana/p/notified-root-' . $iteration,
            'type' => 'Note',
            'attributedTo' => $env['config']['base_url'] . '/u/ana',
            'published' => '2020-01-01T00:00:00Z',
            'to' => [ActivityPub::PUBLIC_AUDIENCE],
            'content' => 'Old local root',
        ];
        $newerLocal = [
            'id' => $env['config']['base_url'] . '/u/ana/p/newer-local-' . $iteration,
            'type' => 'Note',
            'attributedTo' => $env['config']['base_url'] . '/u/ana',
            'published' => '2025-01-01T00:00:00Z',
            'to' => [ActivityPub::PUBLIC_AUDIENCE],
            'content' => 'Newer local post',
        ];

        $store->writeObject($oldRoot);
        $store->writeObject($newerLocal);
        (new IndexBuilder($store))->rebuild();

        $replyId = 'https://remote.test/notes/notified-reply-' . $iteration;
        $this->receive($env, [
            'id' => 'https://remote.test/a/notified-reply-' . $iteration,
            'type' => 'Create',
            'actor' => $env['remote_actor'],
            'object' => [
                'id' => $replyId,
                'type' => 'Note',
                'attributedTo' => $env['remote_actor'],
                'published' => '2026-01-01T00:00:00Z',
                'to' => [ActivityPub::PUBLIC_AUDIENCE],
                'content' => 'Reply to local user',
                'inReplyTo' => $oldRoot['id'],
            ],
        ]);

        (new InboxWorker($store, $env['queue'], $env['config']))->run();
        $repo = new ObjectRepository($store);
        $storedRoot = $repo->findByIdOrAlias((string)$oldRoot['id']);
        $byLocalActor = $repo->byActor($env['config']['base_url'] . '/u/ana', 2);
        $topLocalId = ActivityPub::objectId($byLocalActor[0] ?? []);
        $privateTimeline = $this->privateTimeline($env, 'ana', 10);
        $privateIds = array_map(static fn (array $object): ?string => ActivityPub::objectId($object), $privateTimeline);

        $this->check('notified reply stored as child', count($repo->childrenOf((string)$oldRoot['id'])) === 1);
        $this->check('reply to local user bumps thread date', ($storedRoot['_oannes_thread_activity_at'] ?? null) === '2026-01-01T00:00:00Z');
        $this->check('bumped local thread sorts before newer local post', $topLocalId === $oldRoot['id']);
        $this->check('notified reply enters private timeline', in_array($replyId, $privateIds, true));
    }

    private function scenarioInboxSecurity(int $iteration): void
    {
        $env = $this->environment('security-' . $iteration);
        $activity = [
            'id' => 'https://remote.test/a/security-' . $iteration,
            'type' => 'Follow',
            'actor' => $env['remote_actor'],
            'object' => $env['config']['base_url'] . '/u/ana',
        ];

        $this->receive($env, $activity);

        try {
            $this->receive($env, $activity);
            $this->check('duplicate activity rejected', false);
        } catch (\Throwable $e) {
            $this->check('duplicate activity rejected', $e->getMessage() === 'Duplicate inbox activity');
        }

        $update = [
            'id' => $activity['id'],
            'type' => 'Update',
            'actor' => $env['remote_actor'],
            'object' => [
                'id' => 'https://remote.test/objects/reused-update-' . $iteration,
                'type' => 'Note',
                'attributedTo' => $env['remote_actor'],
                'published' => gmdate('c'),
                'updated' => gmdate('c'),
                'content' => 'Updated object with reused activity id',
            ],
        ];
        $this->receive($env, $update);
        (new InboxWorker($env['store'], $env['queue']))->run();
        $updatedObject = (new ObjectRepository($env['store']))->findByIdOrAlias($update['object']['id']);
        $this->check('update with reused activity id accepted', $updatedObject !== null);

        $bad = $this->environment('bad-key-' . $iteration);
        $headers = (new HttpSignature())->signedPostHeaders(
            $bad['config']['base_url'] . '/u/ana/inbox',
            'https://remote.test/other#main-key',
            $bad['remote_private'],
            Json::encode($activity)
        );

        try {
            (new InboxService($bad['store'], $bad['queue'], new ActorRepository($bad['store']), $bad['config']))
                ->receive('ana', 'POST', '/u/ana/inbox', $headers, Json::encode($activity));
            $this->check('wrong keyId rejected', false);
        } catch (\Throwable $e) {
            $this->check('wrong keyId rejected', $e->getMessage() === 'HTTP Signature key does not match activity actor');
        }
    }

    private function scenarioRemoteActorUpdateRefreshesAvatar(int $iteration): void
    {
        $env = $this->environment('actor-update-' . $iteration);
        $old = $env['remote'];
        $old['icon'] = [
            'type' => 'Image',
            'url' => 'https://remote.test/media/old.png',
        ];
        $env['store']->writeActor($old);

        $update = [
            'id' => 'https://remote.test/a/actor-update-' . $iteration,
            'type' => 'Update',
            'actor' => $env['remote_actor'],
            'object' => [
                'id' => $env['remote_actor'],
                'type' => 'Person',
                'preferredUsername' => 'alex',
                'icon' => [
                    'type' => 'Image',
                    'url' => [
                        [
                            'href' => '/media/new.png',
                        ],
                    ],
                ],
            ],
        ];
        $this->receive($env, $update);
        (new InboxWorker($env['store'], $env['queue']))->run();

        $actor = (new ActorRepository($env['store']))->findById($env['remote_actor']);
        $info = (new Renderer(new ObjectRepository($env['store']), $env['config']))->actorInfo($env['remote_actor']);

        $this->check('remote actor update stores new icon', is_array($actor) && is_array($actor['icon']['url'] ?? null));
        $this->check('remote actor relative icon resolves against actor host', ($info['avatar'] ?? null) === 'https://remote.test/media/new.png');
    }

    private function scenarioDeliveryDryRun(int $iteration): void
    {
        $env = $this->environment('delivery-' . $iteration);
        $graph = new SocialGraph($env['store']);
        $graph->addFollower('ana', $env['remote']);
        $users = new LocalUsers($env['store'], $env['config']);
        $note = (new PostService($env['store'], $users, $env['queue'], $graph, $env['config']))
            ->createNote('ana', 'Local note ' . $iteration);
        $stats = (new DeliveryWorker($env['store'], $env['queue'], new KeyStore($env['store']), $env['config']))
            ->run(10, true);
        $pending = $this->deliverJobs($env['queue']);

        $this->check('post note creates id', is_string($note['id'] ?? null));
        $this->check('dry-run skips delivery', ($stats['skipped'] ?? 0) === 1 && ($stats['delivered'] ?? 1) === 0);
        $this->check('dry-run keeps job pending', count($pending) === 1);
    }

    private function scenarioDeliveryDeduplication(int $iteration): void
    {
        $env = $this->environment('delivery-dedup-' . $iteration);
        $activity = [
            'id' => 'https://example.test/u/ana/p/dedup-' . $iteration . '#create',
            'type' => 'Create',
            'actor' => 'https://example.test/u/ana',
            'object' => [
                'id' => 'https://example.test/u/ana/p/dedup-' . $iteration,
                'type' => 'Note',
            ],
        ];
        $payload = [
            'actor' => 'https://example.test/u/ana',
            'inbox' => 'https://remote.test/inbox',
            'activity' => $activity,
        ];

        $first = $env['queue']->enqueue('deliver', $payload);
        $second = $env['queue']->enqueue('deliver', $payload);
        $pending = $this->deliverJobs($env['queue']);
        $env['queue']->complete($pending[0]);
        $third = $env['queue']->enqueue('deliver', $payload);

        $this->check('delivery dedup returns same pending id', $first === $second && count($pending) === 1);
        $this->check('delivery dedup does not requeue completed id', $third === $first && count($this->deliverJobs($env['queue'])) === 0);
    }

    private function scenarioOutboxCreateIdentity(int $iteration): void
    {
        $env = $this->environment('outbox-create-' . $iteration);
        $users = new LocalUsers($env['store'], $env['config']);
        $note = (new PostService($env['store'], $users, $env['queue'], new SocialGraph($env['store']), $env['config']))
            ->createNote('ana', 'Outbox identity ' . $iteration);
        $router = new Router(
            $env['config'],
            $env['store'],
            new ObjectRepository($env['store']),
            new Renderer(new ObjectRepository($env['store']), $env['config']),
            $users,
        );
        $method = new \ReflectionMethod($router, 'createActivityForOutbox');
        $method->setAccessible(true);
        $activity = $method->invoke($router, $note);

        $this->check('outbox item is Create activity', ($activity['type'] ?? null) === 'Create');
        $this->check('outbox Create id differs from Note id', ($activity['id'] ?? null) === ($note['id'] ?? '') . '#create');
        $this->check('outbox Create object keeps stable Note id', ($activity['object']['id'] ?? null) === ($note['id'] ?? null));
    }

    private function scenarioCanonicalReplyTarget(int $iteration): void
    {
        $env = $this->environment('canonical-reply-' . $iteration);
        $users = new LocalUsers($env['store'], $env['config']);
        $graph = new SocialGraph($env['store']);
        $service = new PostService($env['store'], $users, $env['queue'], $graph, $env['config']);
        $remote = [
            'id' => 'https://remote.test/ap/objects/canonical-' . $iteration,
            'type' => 'Note',
            'attributedTo' => $env['remote_actor'],
            'published' => gmdate('c'),
            'to' => [ActivityPub::PUBLIC_AUDIENCE],
            'url' => 'https://remote.test/posts/canonical-' . $iteration,
            'context' => 'https://nammu.test/ap/objects/root-' . $iteration,
            'conversation' => 'https://nammu.test/ap/objects/root-' . $iteration,
            'content' => 'Canonical parent',
        ];
        $announce = [
            'id' => 'https://relay.test/activities/announce-' . $iteration,
            'type' => 'Announce',
            'actor' => 'https://relay.test/actor',
            'object' => $remote['url'],
            'published' => gmdate('c'),
            'to' => [ActivityPub::PUBLIC_AUDIENCE],
        ];
        $env['store']->writeObject($remote);
        $env['store']->writeObject($announce);
        (new IndexBuilder($env['store']))->rebuild();

        $fromHumanUrl = $service->createNote('ana', 'Reply to human url ' . $iteration, [
            'inReplyTo' => $remote['url'],
        ]);
        $fromAnnounce = $service->createNote('ana', 'Reply to announce ' . $iteration, [
            'inReplyTo' => $announce['id'],
        ]);

        $this->check('reply human URL canonicalized to object id', ($fromHumanUrl['inReplyTo'] ?? null) === $remote['id']);
        $this->check('reply Announce canonicalized to announced object id', ($fromAnnounce['inReplyTo'] ?? null) === $remote['id']);
        $this->check('reply preserves thread context', ($fromHumanUrl['context'] ?? null) === $remote['context']);
        $this->check('reply preserves thread conversation', ($fromHumanUrl['conversation'] ?? null) === $remote['conversation']);
    }

    private function scenarioLocalUserAutoFollow(int $iteration): void
    {
        $env = $this->environment('local-users-' . $iteration);
        $users = new LocalUsers($env['store'], $env['config']);
        $graph = new SocialGraph($env['store']);
        $users->create('bea', 'Bea');
        $anaActor = $users->actorId('ana');
        $beaActor = $users->actorId('bea');

        $this->check('new local user follows existing users', $graph->isFollowing('bea', $anaActor));
        $this->check('existing local users follow new user', $graph->isFollowing('ana', $beaActor));
        $this->check('new local user has existing followers', $graph->isFollower('bea', $anaActor));
        $this->check('existing local user has new follower', $graph->isFollower('ana', $beaActor));
    }

    private function scenarioAdminSocializeUser(int $iteration): void
    {
        $env = $this->environment('socialize-' . $iteration);
        $users = new LocalUsers($env['store'], $env['config']);
        $users->create('bea', 'Bea');
        $remote = [
            'id' => 'https://remote.test/socialized-' . $iteration,
            'type' => 'Person',
            'preferredUsername' => 'socialized',
            'inbox' => 'https://remote.test/socialized/inbox',
        ];
        $env['store']->writeActor($remote);

        $router = new Router(
            $env['config'],
            $env['store'],
            new ObjectRepository($env['store']),
            new Renderer(new ObjectRepository($env['store']), $env['config']),
            $users,
        );
        $method = new \ReflectionMethod($router, 'socializeActor');
        $result = $method->invoke($router, (string)$remote['id']);
        $graph = new SocialGraph($env['store']);

        $this->check('admin socialize follows actor from first local user', $graph->isFollowing('ana', (string)$remote['id']));
        $this->check('admin socialize follows actor from second local user', $graph->isFollowing('bea', (string)$remote['id']));
        $this->check('admin socialize queues remote follows', count($this->deliverJobs($env['queue'])) === 2);
        $this->check('admin socialize reports additions', ($result['followed'] ?? 0) === 2);
    }

    private function scenarioDefaultFollowingForNewUser(int $iteration): void
    {
        $env = $this->environment('default-following-' . $iteration);
        $users = new LocalUsers($env['store'], $env['config']);
        $remote = [
            'id' => 'https://remote.test/default-' . $iteration,
            'type' => 'Person',
            'preferredUsername' => 'default',
            'inbox' => 'https://remote.test/default/inbox',
        ];
        $env['store']->writeActor($remote);
        (new InstanceSettings($env['store'], $env['config']))->addDefaultFollowingActor((string)$remote['id']);

        $users->create('bea', 'Bea');
        $router = new Router(
            $env['config'],
            $env['store'],
            new ObjectRepository($env['store']),
            new Renderer(new ObjectRepository($env['store']), $env['config']),
            $users,
        );
        $method = new \ReflectionMethod($router, 'applyDefaultFollowingToUser');
        $result = $method->invoke($router, 'bea');
        $graph = new SocialGraph($env['store']);

        $this->check('new user follows configured default remote actor', $graph->isFollowing('bea', (string)$remote['id']));
        $this->check('default remote follow queues one activity', count($this->deliverJobs($env['queue'])) === 1);
        $this->check('default remote follow reports addition', ($result['followed'] ?? 0) === 1);
    }

    private function scenarioUserArchive(int $iteration): void
    {
        $env = $this->environment('archive-' . $iteration);
        $users = new LocalUsers($env['store'], $env['config']);
        $post = (new PostService($env['store'], $users, $env['queue'], new SocialGraph($env['store']), $env['config']))
            ->createNote('ana', 'Archive me ' . $iteration);
        $mediaDir = rtrim((string)$env['config']['data_dir'], '/') . '/media/ana';
        if (!is_dir($mediaDir) && !mkdir($mediaDir, 0775, true) && !is_dir($mediaDir)) {
            throw new RuntimeException('Cannot create simulation media dir');
        }
        $mediaPath = $mediaDir . '/archive-test.txt';
        file_put_contents($mediaPath, 'archive attachment ' . $iteration);
        $post['attachment'] = [[
            'type' => 'Document',
            'mediaType' => 'text/plain',
            'url' => $env['config']['base_url'] . '/ana/s/archive-test.txt',
            'name' => 'Archive attachment',
        ]];
        $env['store']->writeObject($post);
        (new IndexBuilder($env['store']))->rebuild();
        $archive = new UserArchiveService($env['store'], $users, $env['config']);
        $xml = $archive->exportXml('ana');
        $zip = $archive->exportZip('ana');

        $import = $this->environment('archive-import-' . $iteration);
        $import['config']['base_url'] = 'https://imported.test';
        $importStore = new FileStore($import['config']['data_dir']);
        $importUsers = new LocalUsers($importStore, $import['config']);
        $result = (new UserArchiveService($importStore, $importUsers, $import['config']))->importArchive($zip, 'secret123', true);
        $imported = (new ObjectRepository($importStore))->byActor($importUsers->actorId('ana'), 10);
        $importedAttachment = is_array($imported[0]['attachment'][0] ?? null) ? $imported[0]['attachment'][0] : [];
        $deleted = $archive->deleteUserContent('ana');

        $this->check('archive export contains uanna root', str_contains($xml, '<uanna-user-archive'));
        $this->check('archive import restores objects', ($result['objects'] ?? 0) >= 1 && count($imported) >= 1);
        $this->check('archive import rewrites actor', ActivityPub::attributedTo($imported[0] ?? []) === $importUsers->actorId('ana'));
        $this->check('archive zip restores local media', str_starts_with((string)($importedAttachment['url'] ?? ''), 'https://imported.test/ana/s/'));
        $this->check('archive delete removes local content', $deleted >= 1 && (new ObjectRepository($env['store']))->findByIdOrAlias((string)$post['id']) === null);
    }

    private function scenarioNegativeInputs(int $iteration): void
    {
        $unknown = $this->environment('unknown-actor-' . $iteration);
        $key = $this->keyPair();
        $activity = [
            'id' => 'https://unknown.test/a/follow-' . $iteration,
            'type' => 'Follow',
            'actor' => 'https://unknown.test/person',
            'object' => $unknown['config']['base_url'] . '/u/ana',
        ];
        $body = Json::encode($activity);
        $headers = (new HttpSignature())->signedPostHeaders(
            $unknown['config']['base_url'] . '/u/ana/inbox',
            'https://unknown.test/person#main-key',
            $key['private'],
            $body
        );

        try {
            (new InboxService($unknown['store'], $unknown['queue'], new ActorRepository($unknown['store']), $unknown['config']))
                ->receive('ana', 'POST', '/u/ana/inbox', $headers, $body);
            $this->check('unknown actor rejected', false);
        } catch (\Throwable $e) {
            $this->check('unknown actor rejected', $e->getMessage() === 'Unknown signed actor');
        }

        $large = $this->environment('large-body-' . $iteration);
        $large['config']['inbox_max_bytes'] = 64;
        $body = Json::encode([
            'id' => 'https://remote.test/a/large-' . $iteration,
            'type' => 'Follow',
            'actor' => $large['remote_actor'],
            'object' => str_repeat('x', 200),
        ]);
        $headers = (new HttpSignature())->signedPostHeaders(
            $large['config']['base_url'] . '/u/ana/inbox',
            $large['remote_actor'] . '#main-key',
            $large['remote_private'],
            $body
        );

        try {
            (new InboxService($large['store'], $large['queue'], new ActorRepository($large['store']), $large['config']))
                ->receive('ana', 'POST', '/u/ana/inbox', $headers, $body);
            $this->check('large body rejected', false);
        } catch (\Throwable $e) {
            $this->check('large body rejected', $e->getMessage() === 'Inbox body is too large');
        }

        $unsignedDigest = $this->environment('unsigned-digest-' . $iteration);
        $body = Json::encode([
            'id' => 'https://remote.test/a/unsigned-digest-' . $iteration,
            'type' => 'Follow',
            'actor' => $unsignedDigest['remote_actor'],
            'object' => $unsignedDigest['config']['base_url'] . '/u/ana',
        ]);
        $headers = $this->headersWithoutDigestSignature($unsignedDigest, $body);

        try {
            (new InboxService($unsignedDigest['store'], $unsignedDigest['queue'], new ActorRepository($unsignedDigest['store']), $unsignedDigest['config']))
                ->receive('ana', 'POST', '/u/ana/inbox', $headers, $body);
            $this->check('unsigned digest rejected', false);
        } catch (\Throwable $e) {
            $this->check('unsigned digest rejected', $e->getMessage() === 'HTTP Signature does not cover digest');
        }

        $cross = $this->environment('cross-attribution-' . $iteration);
        $this->receive($cross, [
            'id' => 'https://remote.test/a/cross-' . $iteration,
            'type' => 'Create',
            'actor' => $cross['remote_actor'],
            'object' => [
                'id' => 'https://remote.test/notes/cross-' . $iteration,
                'type' => 'Note',
                'attributedTo' => 'https://remote.test/someone-else',
                'content' => 'Cross attribution',
            ],
        ]);
        (new InboxWorker($cross['store'], $cross['queue']))->run();
        $moderation = $this->moderation($cross);
        $pending = $moderation->pending('ana', 'creates');

        try {
            $moderation->approveCreate('ana', (string)$pending[0]['case_id'], 'sim');
            $this->check('cross attribution rejected', false);
        } catch (\Throwable $e) {
            $this->check('cross attribution rejected', $e->getMessage() === 'Create actor does not match object attribution');
        }
    }

    private function scenarioInteractionAccept(int $iteration): void
    {
        $env = $this->environment('interaction-' . $iteration);
        $object = [
            'id' => $env['config']['base_url'] . '/u/ana/p/interaction-' . $iteration,
            'type' => 'Note',
            'attributedTo' => $env['config']['base_url'] . '/u/ana',
            'published' => gmdate('c'),
            'content' => 'Interaction target',
        ];
        $env['store']->writeObject($object);
        (new IndexBuilder($env['store']))->rebuild();

        foreach (['Like', 'Announce'] as $type) {
            $this->receive($env, [
                'id' => 'https://remote.test/a/' . strtolower($type) . '-' . $iteration,
                'type' => $type,
                'actor' => $env['remote_actor'],
                'object' => $object['id'],
                'published' => gmdate('c'),
            ]);
        }

        (new InboxWorker($env['store'], $env['queue']))->run(10);
        $interactions = new InteractionService(
            $env['store'],
            new LocalUsers($env['store'], $env['config']),
            $env['queue'],
            new SocialGraph($env['store']),
            new ActorRepository($env['store']),
            $env['config'],
        );
        $counts = $interactions->counts($object);

        $this->check('incoming like accepted without moderation', ($counts['likes'] ?? 0) === 1);
        $this->check('incoming announce accepted without moderation', ($counts['boosts'] ?? 0) === 1);
    }

    private function scenarioRemoteBoostFromFollowed(int $iteration): void
    {
        $env = $this->environment('remote-boost-' . $iteration);
        $graph = new SocialGraph($env['store']);
        $graph->addFollowing('ana', $env['remote']);

        $thirdParty = [
            'id' => 'https://third.test/posts/' . $iteration,
            'type' => 'Note',
            'attributedTo' => 'https://third.test/users/mara',
            'published' => gmdate('c'),
            'to' => [ActivityPub::PUBLIC_AUDIENCE],
            'content' => 'Boosted third-party object',
        ];

        $this->receive($env, [
            'id' => 'https://remote.test/a/boost-third-' . $iteration,
            'type' => 'Announce',
            'actor' => $env['remote_actor'],
            'object' => $thirdParty,
            'published' => gmdate('c'),
        ]);

        (new InboxWorker($env['store'], $env['queue']))->run(10);
        $repo = new ObjectRepository($env['store']);
        $interactions = new InteractionService(
            $env['store'],
            new LocalUsers($env['store'], $env['config']),
            $env['queue'],
            $graph,
            new ActorRepository($env['store']),
            $env['config'],
        );
        $boosts = $interactions->remoteBoostedObjectsForUser('ana', [$env['remote_actor']], 10);

        $this->check('followed remote boost caches third-party object', $repo->findByIdOrAlias($thirdParty['id']) !== null);
        $this->check('followed remote boost appears in user boost feed', count($boosts) === 1 && ($boosts[0]['id'] ?? null) === $thirdParty['id']);
    }

    private function scenarioReplyMentionAnnounce(int $iteration): void
    {
        $env = $this->environment('reply-announce-' . $iteration);
        $object = [
            'id' => $env['config']['base_url'] . '/u/ana/p/reply-announce-' . $iteration,
            'type' => 'Note',
            'attributedTo' => $env['config']['base_url'] . '/u/ana',
            'published' => gmdate('c'),
            'content' => 'Reply target',
        ];
        $env['store']->writeObject($object);
        (new IndexBuilder($env['store']))->rebuild();

        $sourceUrl = 'https://remote.test/posts/source-' . $iteration;
        $this->receive($env, [
            'id' => 'https://remote.test/a/update-source-' . $iteration,
            'type' => 'Update',
            'actor' => $env['remote_actor'],
            'object' => [
                'id' => 'https://remote.test/objects/source-' . $iteration,
                'type' => 'Article',
                'attributedTo' => $env['remote_actor'],
                'url' => $sourceUrl,
                'published' => gmdate('c'),
                'content' => 'Source with replies',
            ],
        ]);
        $this->receive($env, [
            'id' => 'https://remote.test/actor/reply-announces/' . $iteration,
            'type' => 'Announce',
            'actor' => $env['remote_actor'],
            'object' => $object['id'],
            'published' => gmdate('c'),
        ]);

        (new InboxWorker($env['store'], $env['queue']))->run(10);
        $counts = (new InteractionService(
            $env['store'],
            new LocalUsers($env['store'], $env['config']),
            $env['queue'],
            new SocialGraph($env['store']),
            new ActorRepository($env['store']),
            $env['config'],
        ))->counts($object);
        $notifyRoot = $env['store']->dataDir();
        $notify = $env['store']->readJson($notifyRoot . '/users/ana/notify/' . Id::digest('Webmention:' . $sourceUrl . ':' . $object['id']) . '.json');

        $this->check('reply announce does not count as boost', ($counts['boosts'] ?? 0) === 0);
        $this->check('reply announce becomes webmention', ($notify['type'] ?? null) === 'Webmention' && ($notify['actor'] ?? null) === $sourceUrl);
    }

    private function environment(string $name): array
    {
        $dir = sys_get_temp_dir() . '/oannes-sim-' . preg_replace('/[^a-z0-9_-]/i', '-', $name) . '-' . bin2hex(random_bytes(4));
        $config = [
            'host' => 'example.test',
            'base_url' => 'https://example.test',
            'data_dir' => $dir,
            'root_dir' => dirname($dir),
            'public_path' => '/index.php',
            'local_actor_path' => '/u',
            'legacy_actor_paths' => [],
            'inbox_enabled' => true,
            'inbox_max_bytes' => 262144,
            'inbox_max_clock_skew_seconds' => 43200,
            'delivery_enabled' => false,
        ];
        $store = new FileStore($dir);
        $queue = new FileQueue($store);
        $localKey = $this->keyPair();
        $remoteKey = $this->keyPair();
        $remoteActor = 'https://remote.test/alex';
        $remote = [
            'id' => $remoteActor,
            'type' => 'Person',
            'inbox' => 'https://remote.test/inbox',
            'publicKey' => [
                'id' => $remoteActor . '#main-key',
                'owner' => $remoteActor,
                'publicKeyPem' => $remoteKey['public'],
            ],
        ];

        $store->writeJson($dir . '/actors/local/ana.json', [
            'uid' => 'ana',
            'name' => 'Ana',
        ]);
        (new KeyStore($store))->importLocal('ana', [
            'public' => $localKey['public'],
            'secret' => $localKey['private'],
        ]);
        $store->writeActor($remote);
        (new IndexBuilder($store))->rebuild();

        return [
            'dir' => $dir,
            'config' => $config,
            'store' => $store,
            'queue' => $queue,
            'remote' => $remote,
            'remote_actor' => $remoteActor,
            'remote_private' => $remoteKey['private'],
        ];
    }

    private function receive(array $env, array $activity): array
    {
        $body = Json::encode($activity);
        $headers = (new HttpSignature())->signedPostHeaders(
            $env['config']['base_url'] . '/u/ana/inbox',
            $env['remote_actor'] . '#main-key',
            $env['remote_private'],
            $body
        );

        return (new InboxService($env['store'], $env['queue'], new ActorRepository($env['store']), $env['config']))
            ->receive('ana', 'POST', '/u/ana/inbox', $headers, $body);
    }

    private function moderation(array $env): ModerationService
    {
        return new ModerationService(
            $env['store'],
            new LocalUsers($env['store'], $env['config']),
            new ActorRepository($env['store']),
            new SocialGraph($env['store']),
            $env['queue'],
        );
    }

    private function privateTimeline(array $env, string $uid, int $limit): array
    {
        $repo = new ObjectRepository($env['store']);
        $router = new Router(
            $env['config'],
            $env['store'],
            $repo,
            new Renderer($repo, $env['config']),
            new LocalUsers($env['store'], $env['config'])
        );
        $method = new \ReflectionMethod($router, 'privateTimeline');
        $method->setAccessible(true);

        return $method->invoke($router, $uid, $limit, 0);
    }

    private function deliverJobs(FileQueue $queue): array
    {
        return array_values(array_filter($queue->list('pending'), static fn (array $job): bool => ($job['type'] ?? null) === 'deliver'));
    }

    private function keyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false || !openssl_pkey_export($key, $private)) {
            throw new RuntimeException('Cannot generate simulation key');
        }

        $details = openssl_pkey_get_details($key);
        $public = is_array($details) ? ($details['key'] ?? null) : null;

        if (!is_string($public) || $public === '') {
            throw new RuntimeException('Cannot read simulation public key');
        }

        return [
            'private' => $private,
            'public' => $public,
        ];
    }

    private function headersWithoutDigestSignature(array $env, string $body): array
    {
        $url = $env['config']['base_url'] . '/u/ana/inbox';
        $parts = parse_url($url);
        $host = (string)($parts['host'] ?? 'example.test');
        $target = 'post ' . (string)($parts['path'] ?? '/u/ana/inbox');
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
        $signingString = "(request-target): {$target}\nhost: {$host}\ndate: {$date}";
        $signature = '';

        if (!openssl_sign($signingString, $signature, $env['remote_private'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Cannot sign simulation request');
        }

        return [
            'Host' => $host,
            'Date' => $date,
            'Digest' => $digest,
            'Content-Type' => 'application/activity+json',
            'Signature' => 'keyId="' . $env['remote_actor'] . '#main-key",algorithm="rsa-sha256",headers="(request-target) host date",signature="' . base64_encode($signature) . '"',
        ];
    }

    private function check(string $name, bool $ok): void
    {
        $this->checks[] = [
            'name' => $name,
            'ok' => $ok,
        ];
    }
}
