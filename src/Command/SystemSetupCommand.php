<?php

declare(strict_types=1);

namespace Shopware\Production\Command;

use Defuse\Crypto\Key;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\OutputStyle;
use Symfony\Component\Console\Style\SymfonyStyle;

class SystemSetupCommand extends Command
{
    public static $defaultName = 'system:setup';

    /**
     * @var string
     */
    private $projectDir;

    public function __construct(string $projectDir)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force setup and recreate everything')
            ->addOption('no-check-db-connection', null, InputOption::VALUE_NONE, 'dont check db connection')
            ->addOption('database-url', null, InputOption::VALUE_OPTIONAL, 'Database dsn')
            ->addOption('generate-jwt-keys', null, InputOption::VALUE_NONE, 'Generate jwt private and public key')
            ->addOption('jwt-passphrase', null, InputOption::VALUE_OPTIONAL, 'JWT private key passphrase', 'shopware')
            ->addOption('cli', null, InputOption::VALUE_OPTIONAL, 'CLI based install')
            ->addOption('APP_ENV', null, InputOption::VALUE_OPTIONAL, 'Application environment')
            ->addOption('APP_URL', null, InputOption::VALUE_OPTIONAL, 'Application URL')
            ->addOption('BLUE_GREEN_DEPLOYMENT', null, InputOption::VALUE_OPTIONAL, 'Blue green deployment')
            ->addOption('DATABASE_URL', null, InputOption::VALUE_OPTIONAL, 'Database dsn - mysql://user:password@host:port')
            ->addOption('DATABASE_NAME', null, InputOption::VALUE_OPTIONAL, 'Database name')

        ;
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force setup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = [
            'SHOPWARE_ES_HOSTS' => 'elasticsearch:9200',
            'SHOPWARE_ES_ENABLED' => '0',
            'SHOPWARE_ES_INDEXING_ENABLED' => '0',
            'SHOPWARE_ES_INDEX_PREFIX' => 'sw',
            'SHOPWARE_HTTP_CACHE_ENABLED' => '1',
            'SHOPWARE_HTTP_DEFAULT_TTL' => '7200',
            'SHOPWARE_CDN_STRATEGY_DEFAULT' => 'id',
            'BLUE_GREEN_DEPLOYMENT' => 1,
            'MAILER_URL' => 'smtp://localhost:25?encryption=&auth_mode=',
        ];

        $io = new SymfonyStyle($input, $output);

        $io->title('Shopware setup process');
        $io->text('This tool will setup your instance.');

        if (!$input->getOption('force') && file_exists($this->projectDir . '/.env')) {
            $io->comment('Instance has already been set-up. To start over, please delete your .env file.');

            return 0;
        }

        if ($input->getOption('cli')) {
       		$env['APP_ENV'] = $input->getOption('APP_ENV') ?? $_ENV["APP_ENV"];
       		$env['APP_URL'] = trim($input->getOption('APP_URL')) ?? $_ENV["APP_URL"] ;
       		$env['BLUE_GREEN_DEPLOYMENT'] = (int) $input->getOption('BLUE_GREEN_DEPLOYMENT') ?? $_ENV["BLUE_GREEN_DEPLOYMENT"];
       		$this->generateJwt($input, $io);
       		$key = Key::createNewRandomKey();
       		$env['APP_SECRET'] = $key->saveToAsciiSafeString();
        	$env['INSTANCE_ID'] = $this->generateInstanceId();
        	$env['DATABASE_URL'] = $input->getOption('DATABASE_URL') ? $this->getDsn($input, $io)."/".$input->getOption('DATABASE_NAME') : $_ENV["DATABASE_URL"]."/".$_ENV["DATABASE_NAME"];

	        $this->createEnvFile($input, $io, $env);
	        return 0;

        }

        $io->section('Application information');
        $env['APP_ENV'] = $io->choice('Application environment', ['prod', 'dev'], 'prod');

        // TODO: optionally check http connection (create test file in public and request)
        $env['APP_URL'] = $io->ask('URL to your /public folder', 'http://shopware.local', static function (string $value): string {
            $value = trim($value);

            if ($value === '') {
                throw new \RuntimeException('Shop URL is required.');
            }

            if (!filter_var($value, FILTER_VALIDATE_URL)) {
                throw new \RuntimeException('Invalid URL.');
            }

            return $value;
        });

        $io->section('Application information');
        $env['BLUE_GREEN_DEPLOYMENT'] = (int) ($io->choice('Blue Green Deployment', ['yes', 'no'], 'yes') === 'yes');

        $io->section('Generate keys and secrets');

        $this->generateJwt($input, $io);

        $key = Key::createNewRandomKey();
        $env['APP_SECRET'] = $key->saveToAsciiSafeString();
        $env['INSTANCE_ID'] = $this->generateInstanceId();

        $io->section('Database information');

        /** @var \Throwable|null $exception */
        $exception = null;
        do {
            try {
                $exception = null;
                $env['DATABASE_URL'] = $this->getDsn($input, $io);
            } catch (\Throwable $e) {
                $exception = $e;
                $io->error($exception->getMessage());
            }
        } while ($exception && $io->confirm('Retry?', false));

        if ($exception) {
            throw $exception;
        }

        $this->createEnvFile($input, $io, $env);

        return 0;
    }

    private function getDsn(InputInterface $input, SymfonyStyle $io): string
    {
        $emptyValidation = static function (string $value): string {
            if (trim($value) === '') {
                throw new \RuntimeException('This value is required.');
            }

            return $value;
        };

        $dsn = $input->getOption('database-url') ? $input->getOption('database-url') : $input->getOption('DATABASE_URL');
        if (\is_string($dsn)) {
            $params = parse_url($dsn);
            $dsnWithoutDb = sprintf(
                '%s://%s:%s@%s:%s',
                $params['scheme'],
                $params['user'],
                $params['pass'],
                $params['host'],
                $params['port']
            );
        } else {
            $dbUser = $io->ask('Database user', 'app', $emptyValidation);
            $dbPass = $io->askHidden('Database password');
            $dbHost = $io->ask('Database host', 'localhost', $emptyValidation);
            $dbPort = $io->ask('Database port', '3306', $emptyValidation);
            $dbName = $io->ask('Database name', 'shopware', $emptyValidation);

            $dsnWithoutDb = sprintf(
                'mysql://%s:%s@%s:%d',
                $dbUser,
                $dbPass,
                $dbHost,
                $dbPort
            );
            $dsn = $dsnWithoutDb . '/' . $dbName;
        }

        if (!$input->getOption('no-check-db-connection')) {
            $io->note('Checking database credentials');

            $connection = DriverManager::getConnection(['url' => $dsnWithoutDb, 'charset' => 'utf8mb4'], new Configuration());
            $connection->exec('SELECT 1');
        }

        return $dsn;
    }

    private function createEnvFile(InputInterface $input, SymfonyStyle $output, array $configuration): void
    {
        $output->note('Preparing .env');

        $envVars = '';
        $envFile = $this->projectDir . '/.env';

        foreach ($configuration as $key => $value) {
            $envVars .= $key . '="' . str_replace('"', '\\"', $value) . '"' . PHP_EOL;
        }

        $output->text($envFile);
        $output->writeln('');
        $output->writeln($envVars);

        if ($input->isInteractive() && !$input->getOption("cli") && !$output->confirm('Check if everything is ok. Write into "' . $envFile . '"?', false)) {
            throw new \RuntimeException('abort');
        }

        $output->note('Writing into ' . $envFile);

        file_put_contents($envFile, $envVars);
    }

    // TODO: refactor into separate command
    private function generateJwt(InputInterface $input, OutputStyle $io): int
    {
        $jwtDir = $this->projectDir . '/config/jwt';

        if (!file_exists($jwtDir) && !mkdir($jwtDir, 0700, true) && !is_dir($jwtDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $jwtDir));
        }

        // TODO: make it regenerate the public key if only private exists
        if (file_exists($jwtDir . '/private.pem') && !$input->getOption('force')) {
            $io->note('Private/Public key already exists. Skipping');

            return 0;
        }

        if (!$input->getOption('generate-jwt-keys') && !$input->hasOption('jwt-passphrase')) {
            return 0;
        }

        $application = $this->getApplication();
        if ($application === null) {
            throw new \RuntimeException('No application initialised');
        }
        $command = $application->find('system:generate-jwt-secret');
        $parameters = [
            '--private-key-path' => $jwtDir . '/private.pem',
            '--public-key-path' => $jwtDir . '/public.pem',
        ];

        if ($input->getOption('force')) {
            $parameters['--force'] = true;
        }
        if ($input->getOption('jwt-passphrase')) {
            $parameters['--jwt-passphrase'] = $input->getOption('jwt-passphrase');
        }

        $ret = $command->run(new ArrayInput($parameters, $command->getDefinition()), $io);

        return $ret;
    }

    private function generateInstanceId(): string
    {
        $length = 32;
        $keySpace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

        $str = '';
        $max = mb_strlen($keySpace, '8bit') - 1;
        for ($i = 0; $i < $length; ++$i) {
            $str .= $keySpace[random_int(0, $max)];
        }

        return $str;
    }
}
