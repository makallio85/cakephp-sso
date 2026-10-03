<?php
declare(strict_types=1);

namespace Sso\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\ORM\Exception\PersistenceFailedException;
use Sso\Service\OidcClient;
use Sso\Service\SsoException;

/**
 * Writes the identity provider connection.
 *
 * Options left out keep their stored value, so `--enable` alone switches an
 * existing connection on. The client secret is asked for interactively when
 * not given, so it need not appear in shell history.
 */
class ConfigureCommand extends Command
{
    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'sso configure';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Configure sign-in through the identity provider.')
            ->addOption('issuer', ['help' => 'Issuer URL, e.g. https://id.rocksoftware.fi/application/o/msg/'])
            ->addOption('client-id', ['help' => 'OAuth2 client id.'])
            ->addOption('client-secret', [
                'help' => 'OAuth2 client secret. Asked for when left out on first configuration.',
            ])
            ->addOption('required-group', [
                'help' => 'Provider group a user must belong to. Empty string removes the requirement.',
            ])
            ->addOption('enable', ['boolean' => true, 'help' => 'Switch sign-in through the provider on.'])
            ->addOption('disable', ['boolean' => true, 'help' => 'Switch sign-in through the provider off.']);
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments.
     * @param \Cake\Console\ConsoleIo $io IO.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        /** @var \Sso\Model\Table\SsoSettingsTable $settings */
        $settings = $this->fetchTable('Sso.SsoSettings');
        $current = $settings->current();

        $data = array_filter([
            'issuer_url' => $args->getOption('issuer'),
            'client_id' => $args->getOption('client-id'),
            'client_secret' => $args->getOption('client-secret'),
        ], fn($value) => $value !== null);
        if ($args->getOption('required-group') !== null) {
            $group = (string)$args->getOption('required-group');
            $data['required_group'] = $group === '' ? null : $group;
        }
        if ($args->getOption('enable')) {
            $data['is_enabled'] = true;
        }
        if ($args->getOption('disable')) {
            $data['is_enabled'] = false;
        }
        if ($current === null && !isset($data['client_secret']) && $io->interactive) {
            $data['client_secret'] = $io->ask('Client secret');
        }

        try {
            $setting = $settings->store($data);
        } catch (PersistenceFailedException $e) {
            $io->error('Not saved: ' . json_encode($e->getEntity()->getErrors()));

            return static::CODE_ERROR;
        }

        $io->success(sprintf(
            'Saved. Issuer %s, client %s, group %s, %s.',
            $setting->issuer_url,
            $setting->client_id,
            $setting->required_group ?? '(any)',
            $setting->is_enabled ? 'enabled' : 'disabled',
        ));

        try {
            (new OidcClient($setting, $settings->clientSecret($setting)))->discovery();
            $io->out('The provider answered its discovery document.');
        } catch (SsoException $e) {
            $io->warning('The provider could not be reached: ' . $e->getMessage());
        }

        return static::CODE_SUCCESS;
    }
}
