<?php
declare(strict_types=1);

namespace Sso\Service;

use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Psr\Http\Message\ServerRequestInterface;
use Sso\Model\Entity\SsoSessionEventType;
use Throwable;

/**
 * Writes the sign-in history. A row that cannot be written is logged and
 * skipped: the history never stops anyone signing in or out.
 */
class SignInHistory
{
    use LocatorAwareTrait;

    /**
     * @param string $type One of the `SsoSessionEventType` value constants.
     * @param array{user_id?: int|null, email?: string|null, method?: string|null, actor_user_id?: int|null} $data What is known.
     * @param \Psr\Http\Message\ServerRequestInterface|null $request The request, for the client's address.
     * @return void
     */
    public function record(string $type, array $data = [], ?ServerRequestInterface $request = null): void
    {
        try {
            /** @var \Sso\Model\Table\SsoSessionEventsTable $events */
            $events = $this->fetchTable('Sso.SsoSessionEvents');
            $events->record($type, $data + ['ip_address' => self::clientIp($request)]);
            if ($type === SsoSessionEventType::SIGNED_IN) {
                /** @var \Sso\Model\Table\SsoSettingsTable $settings */
                $settings = $this->fetchTable('Sso.SsoSettings');
                $events->prune($settings->sessionLimits()['retention_days']);
            }
        } catch (Throwable $e) {
            Log::error(sprintf('SSO sign-in history not written (%s): %s', $type, $e->getMessage()));
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
