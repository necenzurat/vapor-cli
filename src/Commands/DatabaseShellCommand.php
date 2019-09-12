<?php

namespace Laravel\VaporCli\Commands;

use Laravel\VaporCli\Helpers;
use Symfony\Component\Console\Input\InputArgument;

class DatabaseShellCommand extends Command
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('database:shell')
            ->addArgument('database', InputArgument::REQUIRED, 'The name of the database')
            ->addArgument('user', InputArgument::OPTIONAL, 'The username of the database user to connect as')
            ->setDescription('Start a MySQL shell for the given database');
    }

    /**
     * Execute the command.
     *
     * @return void
     */
    public function handle()
    {
        Helpers::ensure_api_token_is_available();

        $databases = $this->vapor->databases();

        if (! is_numeric($databaseId = $this->argument('database'))) {
            $databaseId = $this->findIdByName($databases, $databaseId);
        }

        if (is_null($databaseId)) {
            Helpers::abort('Unable to find a database with that name / ID.');
        }

        $jumpBox = $this->findCompatibleJumpBox(
            $database = collect($databases)->firstWhere('id', $databaseId)
        );

        $user = $this->findDatabaseUser($database);

        passthru(sprintf('ssh -t ec2-user@%s -i %s -o LogLevel=error "mysql -u %s -p%s -h %s vapor"',
            $jumpBox['endpoint'],
            $this->storeJumpBoxKey($jumpBox),
            $user['username'],
            $this->vapor->databaseUserPassword($user['id'])['password'],
            $database['endpoint']
        ));
    }

    /**
     * Find a jump-box compatible with the database.
     *
     * @param  array  $database
     * @return array
     */
    protected function findCompatibleJumpBox(array $database)
    {
        $jumpBoxes = $this->vapor->jumpBoxes();

        $jumpBox = collect($jumpBoxes)->firstWhere(
            'network_id', $database['network_id']
        );

        if (is_null($jumpBox)) {
            Helpers::abort('A jumpbox is required in order to start a shell session.');
        }

        return $jumpBox;
    }

    /**
     * Find the database user for the shell session.
     *
     * @param  array  $database
     * @return array
     */
    protected function findDatabaseUser(array $database)
    {
        $users = $this->vapor->databaseUsers($database['id']);

        if (empty($users)) {
            Helpers::abort('An additional database user is required in order to start a shell session.');
        }

        if ($this->argument('user')) {
            $user = collect($users)->firstWhere('username', $this->argument('user'));
        } else {
            $user = $users[0];
        }

        if (is_null($user)) {
            Helpers::abort('Unable to find a database user with that username.');
        }

        return $user;
    }

    /**
     * Store the private SSH key for the jump-box.
     *
     * @param  array  $jumpBox
     * @return string
     */
    protected function storeJumpBoxKey(array $jumpBox)
    {
        file_put_contents(
            $path = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'].'/.ssh/vapor-database-shell',
            $this->vapor->jumpBoxKey($jumpBox['id'])['private_key']
        );

        chmod($path, 0600);

        return $path;
    }
}
