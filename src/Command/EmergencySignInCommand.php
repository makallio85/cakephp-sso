<?php
declare(strict_types=1);

namespace RockSoftware\Users\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Log\Log;
use Cake\Routing\Router;
use RockSoftware\Users\Service\UserProvisioner;

/**
 * Prints a single-use link that signs a user in without the identity provider.
 *
 * For when the provider is unavailable. Only someone with a shell on the
 * application can run it, so the link carries that person's authority.
 *
 * `--create` also creates the user when none has the address, for an empty
 * database such as a pull request preview, where AI agents sign in this way.
 * It is refused unless the application runs with `debug` on: a production
 * deployment never gets a user from a shell command.
 */
class EmergencySignInCommand extends Command
{
    private const DEFAULT_MINUTES = 15;
    private const MAX_MINUTES = 60;

    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'rock_software_users emergency_sign_in';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Print a single-use sign-in link for when the identity provider is unavailable.')
            ->addArgument('email', ['help' => 'Email address of the user to sign in.', 'required' => true])
            ->addOption('create', [
                'boolean' => true,
                'help' => 'Create the user when none has the address (only with debug on, never in production).',
            ])
            ->addOption('minutes', [
                'help' => sprintf('How long the link stays valid, at most %d.', self::MAX_MINUTES),
                'default' => (string)self::DEFAULT_MINUTES,
            ]);
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments.
     * @param \Cake\Console\ConsoleIo $io IO.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $email = (string)$args->getArgument('email');
        $minutes = max(1, min(self::MAX_MINUTES, (int)$args->getOption('minutes')));

        $provisioner = new UserProvisioner();
        $fields = UserProvisioner::fields();
        $user = $provisioner->users()->find()->where([$fields['email'] => $email])->first();
        if ($user === null && $args->getOption('create')) {
            if (Configure::read('debug') !== true) {
                $io->error('--create is refused: the application runs with debug off (production).');

                return static::CODE_ERROR;
            }
            $user = $provisioner->createUser($email);
            Log::warning(sprintf('Emergency sign-in created the user %s.', $email));
        }
        if ($user === null) {
            $io->error(sprintf('No user has the email address %s.', $email));

            return static::CODE_ERROR;
        }
        if ($fields['is_active'] !== null && !$user->get($fields['is_active'])) {
            $io->error(sprintf('%s is deactivated.', $email));

            return static::CODE_ERROR;
        }

        /** @var \RockSoftware\Users\Model\Table\EmergencySignInsTable $logins */
        $logins = $this->fetchTable('RockSoftware/Users.EmergencySignIns');
        $token = $logins->issue((int)$user->get('id'), $minutes);
        Log::warning(sprintf('Emergency sign-in link issued for %s.', $email));

        $io->out(Router::url([
            'plugin' => 'RockSoftware/Users',
            'controller' => 'SignIn',
            'action' => 'emergency',
            'prefix' => false,
            $token,
        ], true));
        $io->out(sprintf('Valid for %d minutes, once.', $minutes));

        return static::CODE_SUCCESS;
    }
}
