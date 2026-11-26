<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
    require dirname(__DIR__).'/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Create the test database and build its schema once per run, and reset the fixtures before
// each test.
//
// With process isolation this file runs once in the main process and then again in the process
// of every test. The main process creates the database and runs the migrations, and says so in
// the environment, which the test processes inherit. They only load the fixtures again: that
// is what gives every test the same data to start from. No test changes the schema, so there
// is nothing for the migrations to do a second time, and starting the console twice more
// before every test only to find that out is time the suite does not need.
//
// Note: `--quiet` is needed here for each step so that PHPUnit doesn't fail.
$schemaIsBuilt = '1' === getenv('DAVIS_TEST_SCHEMA_BUILT');

$actions = $schemaIsBuilt ? [] : [
    // No `--if-not-exists`: it asks the platform to list its databases, which SQLite cannot do.
    // Creating an existing database is a no-op here either way, the exit code is ignored below.
    'doctrine:database:create',
    // The schema is built by the migrations, not by `doctrine:schema:update`, so the tests run
    // against the schema an install actually has. The two are not the same on PostgreSQL: DBAL
    // models an identity column without its `DEFAULT nextval(...)`, and the sabre/dav backends
    // INSERT without an id, so every DAV write would fail against a schema:update database.
    'doctrine:migrations:migrate --no-interaction --allow-no-migration',
];
$actions[] = 'doctrine:fixtures:load --no-interaction';

foreach ($actions as $action) {
    passthru(sprintf(
        'APP_ENV=%s php "%s/../bin/console" %s --quiet',
        $_ENV['APP_ENV'],
        __DIR__,
        $action,
    ));
}

// For the test processes, whichever way PHPUnit hands them their environment
putenv('DAVIS_TEST_SCHEMA_BUILT=1');
$_SERVER['DAVIS_TEST_SCHEMA_BUILT'] = $_ENV['DAVIS_TEST_SCHEMA_BUILT'] = '1';
