<?php
declare(strict_types=1);

namespace Sso\Model\Table;

use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Sso\Model\Entity\SsoSessionEvent;
use Sso\Model\Entity\SsoSessionEventType;

/**
 * The sign-in history.
 *
 * @property \Sso\Model\Table\SsoSessionEventTypesTable $SsoSessionEventTypes
 * @property \Sso\Model\Table\SsoSignInMethodTypesTable $SsoSignInMethodTypes
 * @method \Sso\Model\Entity\SsoSessionEvent newEmptyEntity()
 */
class SsoSessionEventsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('sso_session_events');
        $this->setPrimaryKey('id');
        $this->setEntityClass(SsoSessionEvent::class);
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);
        $this->belongsTo('SsoSessionEventTypes', [
            'className' => 'Sso.SsoSessionEventTypes',
            'foreignKey' => 'sso_session_event_type_id',
        ]);
        $this->belongsTo('SsoSignInMethodTypes', [
            'className' => 'Sso.SsoSignInMethodTypes',
            'foreignKey' => 'sso_sign_in_method_type_id',
        ]);
    }

    /**
     * Writes one row.
     *
     * @param string $type One of the `SsoSessionEventType` value constants.
     * @param array{user_id?: int|null, email?: string|null, method?: string|null, actor_user_id?: int|null, ip_address?: string|null} $data What is known.
     * @return \Sso\Model\Entity\SsoSessionEvent
     */
    public function record(string $type, array $data = []): SsoSessionEvent
    {
        $email = $data['email'] ?? null;
        $event = $this->newEmptyEntity();
        $values = [
            'user_id' => $data['user_id'] ?? null,
            'email' => is_string($email) && $email !== '' ? mb_strtolower(mb_substr($email, 0, 190)) : null,
            'sso_session_event_type_id' => $this->SsoSessionEventTypes->idFor($type),
            'sso_sign_in_method_type_id' => isset($data['method'])
                ? $this->SsoSignInMethodTypes->idFor((string)$data['method'])
                : null,
            'actor_user_id' => $data['actor_user_id'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
        ];
        // One field at a time: set() with an array is deprecated from 5.2.
        foreach ($values as $field => $value) {
            $event->set($field, $value, ['guard' => false]);
        }

        /** @var \Sso\Model\Entity\SsoSessionEvent */
        return $this->saveOrFail($event);
    }

    /**
     * Refused password attempts in the last minutes, for one email address and
     * for one IP address.
     *
     * @param string $email The address typed.
     * @param string|null $ip The client's address.
     * @param int $minutes The window.
     * @return array{email: int, ip: int}
     */
    public function recentPasswordFailures(string $email, ?string $ip, int $minutes): array
    {
        $since = DateTime::now()->subMinutes($minutes);
        $failures = fn(): SelectQuery => $this->find()
            ->innerJoinWith('SsoSessionEventTypes')
            ->where([
                'SsoSessionEventTypes.value IN' => SsoSessionEventType::PASSWORD_FAILURES,
                $this->aliasField('created') . ' >=' => $since,
            ]);

        return [
            'email' => $failures()->where([$this->aliasField('email') => mb_strtolower($email)])->count(),
            'ip' => $ip === null ? 0 : $failures()->where([$this->aliasField('ip_address') => $ip])->count(),
        ];
    }

    /**
     * Each user's latest sign-in, by user id.
     *
     * @param array<int> $userIds The users.
     * @return array<int, \Sso\Model\Entity\SsoSessionEvent>
     */
    public function latestSignIns(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $latest = $this->find()
            ->select(['id' => $this->find()->func()->max($this->aliasField('id'))])
            ->innerJoinWith('SsoSessionEventTypes')
            ->where([
                $this->aliasField('user_id') . ' IN' => $userIds,
                'SsoSessionEventTypes.value' => SsoSessionEventType::SIGNED_IN,
            ])
            ->groupBy([$this->aliasField('user_id')]);
        $rows = $this->find()
            ->contain(['SsoSignInMethodTypes'])
            ->where([$this->aliasField('id') . ' IN' => $latest])
            ->all();

        $byUser = [];
        foreach ($rows as $row) {
            /** @var \Sso\Model\Entity\SsoSessionEvent $row */
            $byUser[(int)$row->user_id] = $row;
        }

        return $byUser;
    }

    /**
     * One user's history, newest first.
     *
     * @param int $userId The user.
     * @param int $limit How many rows.
     * @return array<\Sso\Model\Entity\SsoSessionEvent>
     */
    public function forUser(int $userId, int $limit = 100): array
    {
        /** @var array<\Sso\Model\Entity\SsoSessionEvent> */
        return $this->find()
            ->contain(['SsoSessionEventTypes', 'SsoSignInMethodTypes'])
            ->where([$this->aliasField('user_id') => $userId])
            ->orderBy([$this->aliasField('created') => 'DESC', $this->aliasField('id') => 'DESC'])
            ->limit($limit)
            ->all()
            ->toList();
    }

    /**
     * When an administrator last ended the user's sessions, or null.
     *
     * @param int $userId The user.
     * @return \Cake\I18n\DateTime|null
     */
    public function sessionsEndedAt(int $userId): ?DateTime
    {
        /** @var \Sso\Model\Entity\SsoSessionEvent|null $row */
        $row = $this->find()
            ->innerJoinWith('SsoSessionEventTypes')
            ->where([
                $this->aliasField('user_id') => $userId,
                'SsoSessionEventTypes.value' => SsoSessionEventType::SESSIONS_ENDED,
            ])
            ->orderBy([$this->aliasField('id') => 'DESC'])
            ->first();

        return $row?->created;
    }

    /**
     * Deletes rows older than the retention period.
     *
     * @param int $days The retention period.
     * @return int Rows deleted.
     */
    public function prune(int $days): int
    {
        return $this->deleteAll(['created <' => DateTime::now()->subDays(max(1, $days))]);
    }
}
