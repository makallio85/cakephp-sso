<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Psr\Http\Message\ServerRequestInterface;
use RockSoftware\Users\Model\Entity\SessionEventType;
use Throwable;

/**
 * Writes the sign-in history. A row that cannot be written is logged and
 * skipped: the history never stops anyone signing in or out.
 */
class SignInHistory
{
    use LocatorAwareTrait;

    /**
     * @param string $type One of the `SessionEventType` value constants.
     * @param array{user_id?: int|null, email?: string|null, method?: string|null, actor_user_id?: int|null} $data What is known.
     * @param \Psr\Http\Message\ServerRequestInterface|null $request The request, for the client's address.
     * @return void
     */
    public function record(string $type, array $data = [], ?ServerRequestInterface $request = null): void
    {
        try {
            /** @var \RockSoftware\Users\Model\Table\SessionEventsTable $events */
            $events = $this->fetchTable('RockSoftware/Users.SessionEvents');
            $events->record($type, $data + ['ip_address' => self::clientIp($request)]);
            if ($type === SessionEventType::SIGNED_IN) {
                /** @var \RockSoftware\Users\Model\Table\SettingsTable $settings */
                $settings = $this->fetchTable('RockSoftware/Users.Settings');
                $events->prune($settings->sessionLimits()['retention_days']);
            }
        } catch (Throwable $e) {
            Log::error(sprintf('Sign-in history not written (%s): %s', $type, $e->getMessage()));
        }
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface|null $request The request.
     * @return string|null
     */
    public static function clientIp(?ServerRequestInterface $request): ?string
    {
        if ($request === null) {
            return null;
        }
        $ip = method_exists($request, 'clientIp')
            ? (string)$request->clientIp()
            : (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');

        return $ip === '' ? null : mb_substr($ip, 0, 45);
    }
}
