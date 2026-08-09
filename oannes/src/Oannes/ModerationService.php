<?php

namespace Oannes;

use RuntimeException;

final class ModerationService
{
    public function __construct(
        private readonly FileStore $store,
        private readonly LocalUsers $users,
        private readonly ActorRepository $actors,
        private readonly SocialGraph $graph,
        private readonly FileQueue $queue,
    ) {
    }

    public function pending(string $uid, string $kind = 'follows', int $limit = 50): array
    {
        $cases = [];
        $dir = $this->basePath($uid, $kind);

        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $caseId = basename($file, '.json');
            if ($this->caseDecision($uid, $kind, $caseId) !== null) {
                continue;
            }

            $case = $this->store->readJson($file);
            if (($case['status'] ?? null) !== 'pending') {
                continue;
            }

            if ($kind === 'follows' && $this->alreadyFollowerCase($uid, $case)) {
                continue;
            }

            $case['case_id'] = $caseId;
            $case['case_path'] = $file;
            $cases[] = $case;
        }

        usort($cases, static fn (array $a, array $b): int => strcmp(
            (string)($b['created_at'] ?? ''),
            (string)($a['created_at'] ?? '')
        ));

        return array_slice($cases, 0, $limit);
    }

    private function alreadyFollowerCase(string $uid, array $case): bool
    {
        $record = $case['record'] ?? null;
        $activity = is_array($record) ? ($record['activity'] ?? null) : null;

        if (!is_array($activity)) {
            return false;
        }

        try {
            return $this->graph->isFollower($uid, $this->actorFromActivity($activity));
        } catch (\Throwable) {
            return false;
        }
    }

    public function approveFollow(string $uid, string $caseId, string $adminUid): array
    {
        return $this->decideFollow($uid, $caseId, $adminUid, true);
    }

    public function rejectFollow(string $uid, string $caseId, string $adminUid): array
    {
        return $this->decideFollow($uid, $caseId, $adminUid, false);
    }

    public function approveCreate(string $uid, string $caseId, string $adminUid): array
    {
        $case = $this->readPendingCase($uid, 'creates', $caseId);
        $record = $case['record'] ?? null;
        $activity = is_array($record) ? ($record['activity'] ?? null) : null;

        if (!is_array($activity) || ActivityPub::objectType($activity) !== 'Create') {
            throw new RuntimeException('Moderation case is not a Create');
        }

        $object = $activity['object'] ?? null;

        if (!is_array($object)) {
            throw new RuntimeException('Create activity does not embed an object');
        }

        $objectId = ActivityPub::objectId($object);

        if ($objectId === null) {
            throw new RuntimeException('Create object has no id');
        }

        if (!in_array(ActivityPub::objectType($object), ['Note', 'Article', 'Page', 'Question'], true)) {
            throw new RuntimeException('Create object type is not publishable');
        }

        $activityActor = $this->actorFromActivity($activity);
        $objectActor = ActivityPub::attributedTo($object);

        if ($objectActor !== null && $objectActor !== $activityActor) {
            throw new RuntimeException('Create actor does not match object attribution');
        }

        $this->store->writeObject($object);
        (new IndexBuilder($this->store))->rebuild();

        $case['status'] = 'approved';
        $case['decided_at'] = gmdate('c');
        $case['decided_by'] = $adminUid;
        $case['stored_object_id'] = $objectId;
        $this->markCaseDecided($uid, 'creates', $caseId, $case);

        return [
            'ok' => true,
            'status' => 'approved',
            'object_id' => $objectId,
        ];
    }

    public function rejectCreate(string $uid, string $caseId, string $adminUid): array
    {
        $case = $this->readPendingCase($uid, 'creates', $caseId);
        $record = $case['record'] ?? null;
        $activity = is_array($record) ? ($record['activity'] ?? null) : null;

        if (!is_array($activity) || ActivityPub::objectType($activity) !== 'Create') {
            throw new RuntimeException('Moderation case is not a Create');
        }

        $case['status'] = 'rejected';
        $case['decided_at'] = gmdate('c');
        $case['decided_by'] = $adminUid;
        $this->markCaseDecided($uid, 'creates', $caseId, $case);

        return [
            'ok' => true,
            'status' => 'rejected',
        ];
    }

    private function decideFollow(string $uid, string $caseId, string $adminUid, bool $approve): array
    {
        $case = $this->readPendingCase($uid, 'follows', $caseId);
        $record = $case['record'] ?? null;
        $activity = is_array($record) ? ($record['activity'] ?? null) : null;

        if (!is_array($activity) || ActivityPub::objectType($activity) !== 'Follow') {
            throw new RuntimeException('Moderation case is not a Follow');
        }

        $localActor = $this->users->actorId($uid);
        $remoteActorId = $this->actorFromFollow($activity);
        $remoteActor = $this->actors->findById($remoteActorId);

        if ($remoteActor === null) {
            throw new RuntimeException('Follow actor is not available locally');
        }

        if ($approve && $this->graph->isFollower($uid, $remoteActorId)) {
            return [
                'ok' => true,
                'status' => 'approved',
                'remote_actor' => $remoteActorId,
                'already_follower' => true,
            ];
        }

        $inbox = $this->graph->inboxForActor($remoteActor);
        if ($inbox === null) {
            throw new RuntimeException('Follow actor has no inbox');
        }

        if ($approve) {
            $this->graph->addFollower($uid, $remoteActor);
        }

        $response = $this->followResponseActivity($localActor, $activity, $approve);
        $this->queue->enqueue('deliver', [
            'actor' => $localActor,
            'inbox' => $inbox,
            'activity' => $response,
        ]);

        $case['status'] = $approve ? 'approved' : 'rejected';
        $case['decided_at'] = gmdate('c');
        $case['decided_by'] = $adminUid;
        $case['response_activity'] = $response;
        $this->markCaseDecided($uid, 'follows', $caseId, $case);

        return [
            'ok' => true,
            'status' => $case['status'],
            'remote_actor' => $remoteActorId,
        ];
    }

    private function readPendingCase(string $uid, string $kind, string $caseId): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $caseId)) {
            throw new RuntimeException('Invalid moderation case id');
        }

        if ($this->caseDecision($uid, $kind, $caseId) !== null) {
            throw new RuntimeException('Moderation case is not pending');
        }

        $path = $this->casePath($uid, $kind, $caseId);

        if (!is_file($path)) {
            throw new RuntimeException('Moderation case not found');
        }

        $case = $this->store->readJson($path);

        if (($case['status'] ?? null) !== 'pending') {
            throw new RuntimeException('Moderation case is not pending');
        }

        return $case;
    }

    private function markCaseDecided(string $uid, string $kind, string $caseId, array $case): void
    {
        $decision = [
            'status' => (string)($case['status'] ?? 'decided'),
            'decided_at' => (string)($case['decided_at'] ?? gmdate('c')),
            'decided_by' => (string)($case['decided_by'] ?? ''),
            'case_id' => $caseId,
            'kind' => $kind,
            'stored_object_id' => (string)($case['stored_object_id'] ?? ''),
        ];

        $this->store->writeJson($this->decisionPath($uid, $kind, $caseId), $decision);

        try {
            $this->store->writeJson($this->casePath($uid, $kind, $caseId), $case);
        } catch (\Throwable) {
            // Older migrated moderation files can be owned by a different web user.
            // The sidecar decision above is authoritative for Uanna.
        }
    }

    private function caseDecision(string $uid, string $kind, string $caseId): ?array
    {
        $path = $this->decisionPath($uid, $kind, $caseId);
        if (!is_file($path)) {
            return null;
        }

        try {
            return $this->store->readJson($path);
        } catch (\Throwable) {
            return null;
        }
    }

    private function actorFromFollow(array $activity): string
    {
        return $this->actorFromActivity($activity);
    }

    private function actorFromActivity(array $activity): string
    {
        $actor = $activity['actor'] ?? null;

        if (is_string($actor) && $actor !== '') {
            return $actor;
        }

        if (is_array($actor)) {
            $id = ActivityPub::objectId($actor);
            if ($id !== null) {
                return $id;
            }
        }

        throw new RuntimeException('Activity has no actor');
    }

    private function followResponseActivity(string $localActor, array $follow, bool $approve): array
    {
        $activityId = ActivityPub::objectId($follow) ?? Id::digest(Json::encode($follow));
        $verb = $approve ? 'Accept' : 'Reject';

        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $localActor . '/activities/' . strtolower($verb) . '/' . Id::digest($activityId),
            'type' => $verb,
            'actor' => $localActor,
            'object' => $follow,
            'published' => gmdate('c'),
            'to' => [
                $this->actorFromFollow($follow),
            ],
        ];
    }

    private function basePath(string $uid, string $kind): string
    {
        return $this->store->dataDir() . '/moderation/inbox/' . rawurlencode($uid) . '/' . $kind;
    }

    private function casePath(string $uid, string $kind, string $caseId): string
    {
        return $this->basePath($uid, $kind) . '/' . $caseId . '.json';
    }

    private function decisionPath(string $uid, string $kind, string $caseId): string
    {
        return $this->store->dataDir() . '/state/moderation/inbox/' . rawurlencode($uid) . '/' . $kind . '/' . $caseId . '.json';
    }
}
