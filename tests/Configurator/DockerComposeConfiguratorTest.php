<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Tests\Configurator;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\RootPackage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Flex\Configurator\DockerComposeConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

/**
 * @author Kévin Dunglas <kevin@dunglas.dev>
 */
class DockerComposeConfiguratorTest extends TestCase
{
    public const ORIGINAL_CONTENT = <<<'YAML'
version: '3.4'

services:
  app:
    build:
      context: .
      target: symfony_docker_php
      args:
        SYMFONY_VERSION: ${SYMFONY_VERSION:-}
        STABILITY: ${STABILITY:-stable}
    volumes:
      # Comment out the next line in production
      - ./:/srv/app:rw,cached
      # If you develop on Linux, comment out the following volumes to just use bind-mounted project directory from host
      - /srv/app/var/
      - /srv/app/var/cache/
      - /srv/app/var/logs/
      - /srv/app/var/sessions/
    environment:
      - SYMFONY_VERSION

  nginx:
    build:
      context: .
      target: symfony_docker_nginx
    depends_on:
      - app
    volumes:
      # Comment out the next line in production
      - ./docker/nginx/conf.d:/etc/nginx/conf.d:ro
      - ./public:/srv/app/public:ro
    ports:
      - '80:80'

  # This HTTP/2 proxy is not secure: it should only be used in dev
  h2-proxy:
    build:
      context: .
      target: symfony_docker_h2-proxy
    depends_on:
      - nginx
    volumes:
      - ./docker/h2-proxy/default.conf:/etc/nginx/conf.d/default.conf:ro
    ports:
      - '443:443'

YAML;

    public const CONFIG_DB = [
        'services' => [
            'db:',
            '  image: mariadb:10.3',
            '  environment:',
            '    - MYSQL_DATABASE=symfony',
            '    # You should definitely change the password in production',
            '    - MYSQL_PASSWORD=password',
            '    - MYSQL_RANDOM_ROOT_PASSWORD=true',
            '    - MYSQL_USER=symfony',
            '  volumes:',
            '    - db-data:/var/lib/mysql:rw',
            '    # You may use a bind-mounted host directory instead, so that it is harder to accidentally remove the volume and lose all your data!',
            '    # - ./docker/db/data:/var/lib/mysql:rw',
        ],
        'volumes' => ['db-data: {}'],
    ];

    public const CONFIG_DB_MULTIPLE_FILES = [
        'docker-compose.yml' => self::CONFIG_DB,
        'docker-compose.override.yml' => self::CONFIG_DB,
    ];

    /** @var Recipe|\PHPUnit\Framework\MockObject\MockObject */
    private $recipeDb;

    /** @var Lock|\PHPUnit\Framework\MockObject\MockObject */
    private $lock;

    /** @var Composer|\PHPUnit\Framework\MockObject\MockObject */
    private $composer;

    /** @var IOInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $io;

    /** @var DockerComposeConfigurator */
    private $configurator;

    /** @var RootPackage */
    private $package;

    private $originalEnvComposer;

    protected function setUp(): void
    {
        @mkdir(FLEX_TEST_DIR);

        $this->originalEnvComposer = $_SERVER['COMPOSER'] ?? null;
        $_SERVER['COMPOSER'] = FLEX_TEST_DIR.'/composer.json';
        // composer 2.1 and lower support
        putenv('COMPOSER='.FLEX_TEST_DIR.'/composer.json');

        // reset state
        DockerComposeConfigurator::$configureDockerRecipes = null;

        // Recipe
        $this->recipeDb = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $this->recipeDb->method('getName')->willReturn('doctrine/doctrine-bundle');

        // Lock
        $this->lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();

        // Configurator
        $this->package = new RootPackage('dummy/dummy', '1.0.0', '1.0.0');
        $this->package->setExtra(['symfony' => ['docker' => true]]);

        $this->composer = $this->getMockBuilder(Composer::class)->getMock();
        $this->composer->method('getPackage')->willReturn($this->package);

        $this->io = $this->getMockBuilder(IOInterface::class)->getMock();

        $this->configurator = new DockerComposeConfigurator(
            $this->composer,
            $this->io,
            new Options(['config-dir' => 'config', 'root-dir' => FLEX_TEST_DIR])
        );
    }

    protected function tearDown(): void
    {
        unset($_SERVER['COMPOSE_FILE']);
        if ($this->originalEnvComposer) {
            $_SERVER['COMPOSER'] = $this->originalEnvComposer;
        } else {
            unset($_SERVER['COMPOSER']);
        }
        // composer 2.1 and lower support
        putenv('COMPOSER='.$this->originalEnvComposer);

        (new Filesystem())->remove([
            FLEX_TEST_DIR.'/compose.yml',
            FLEX_TEST_DIR.'/docker-compose.yml',
            FLEX_TEST_DIR.'/compose.override.yml',
            FLEX_TEST_DIR.'/docker-compose.override.yml',
            FLEX_TEST_DIR.'/compose.yaml',
            FLEX_TEST_DIR.'/docker-compose.yaml',
            FLEX_TEST_DIR.'/composer.json',
            FLEX_TEST_DIR.'/child/compose.override.yaml',
            FLEX_TEST_DIR.'/child/docker-compose.override.yaml',
            FLEX_TEST_DIR.'/child',
        ]);
    }

    public static function dockerComposerFileProvider(): iterable
    {
        yield ['compose.yaml'];
        yield ['compose.yml'];
        yield ['docker-compose.yaml'];
        yield ['docker-compose.yml'];
    }

    /**
     * @dataProvider dockerComposerFileProvider
     */
    public function testConfigure(string $fileName)
    {
        $dockerComposeFile = FLEX_TEST_DIR."/$fileName";
        file_put_contents($dockerComposeFile, self::ORIGINAL_CONTENT);

        $this->configurator->configure($this->recipeDb, self::CONFIG_DB, $this->lock);

        $this->assertStringEqualsFile($dockerComposeFile, self::ORIGINAL_CONTENT.<<<'YAML'

###> doctrine/doctrine-bundle ###
  db:
    image: mariadb:10.3
    environment:
      - MYSQL_DATABASE=symfony
      # You should definitely change the password in production
      - MYSQL_PASSWORD=password
      - MYSQL_RANDOM_ROOT_PASSWORD=true
      - MYSQL_USER=symfony
    volumes:
      - db-data:/var/lib/mysql:rw
      # You may use a bind-mounted host directory instead, so that it is harder to accidentally remove the volume and lose all your data!
      # - ./docker/db/data:/var/lib/mysql:rw
###< doctrine/doctrine-bundle ###

volumes:
###> doctrine/doctrine-bundle ###
  db-data: {}
###< doctrine/doctrine-bundle ###

YAML
        );

        $this->configurator->unconfigure($this->recipeDb, self::CONFIG_DB, $this->lock);
        $this->assertEquals(self::ORIGINAL_CONTENT, file_get_contents($dockerComposeFile));
    }

    public function testNotConfiguredIfConfigSet()
    {
        $this->package->setExtra(['symfony' => ['docker' => false]]);
        $this->configurator->configure($this->recipeDb, self::CONFIG_DB, $this->lock);

        $this->assertFileDoesNotExist(FLEX_TEST_DIR.'/docker-compose.yaml');
    }

    /**
     * @dataProvider getInteractiveDockerPreferenceTests
     */
    public function testPreferenceAskedInteractively(string $userInput, bool $expectedIsConfigured, bool $expectedIsComposerJsonUpdated)
    {
        $composerJsonPath = FLEX_TEST_DIR.'/composer.json';
        file_put_contents($composerJsonPath, json_encode(['name' => 'test/app']));

        $this->package->setExtra(['symfony' => []]);
        $this->recipeDb->method('getJob')->willReturn('install');
        $this->io->method('isInteractive')->willReturn(true);
        $this->io->expects($this->once())->method('askAndValidate')->willReturn($userInput);

        $this->configurator->configure($this->recipeDb, self::CONFIG_DB, $this->lock);

        if ($expectedIsConfigured) {
            $this->assertFileExists(FLEX_TEST_DIR.'/compose.yaml');
        } else {
            $this->assertFileDoesNotExist(FLEX_TEST_DIR.'/compose.yaml');
        }

        $composerJsonData = json_decode(file_get_contents($composerJsonPath), true);
        if ($expectedIsComposerJsonUpdated) {
            $this->assertArrayHasKey('extra', $composerJsonData);
            $this->assertSame($expectedIsConfigured, $composerJsonData['extra']['symfony']['docker']);
        } else {
            $this->assertArrayNotHasKey('extra', $composerJsonData);
        }
    }

    public function getInteractiveDockerPreferenceTests()
    {
        yield 'yes_once' => ['y', true, false];
        yield 'no_once' => ['n', false, false];
        yield 'yes_forever' => ['p', true, true];
        yield 'no_forever' => ['x', false, true];
    }

    public function testEnvVarUsedForDockerConfirmation()
    {
        $composerJsonPath = FLEX_TEST_DIR.'/composer.json';
        file_put_contents($composerJsonPath, json_encode(['name' => 'test/app']));

        $this->package->setExtra(['symfony' => []]);
        $this->recipeDb->method('getJob')->willReturn('install');
        $this->io->expects($this->never())->method('askAndValidate');

        $_SERVER['SYMFONY_DOCKER'] = 1;
        $this->configurator->configure($this->recipeDb, self::CONFIG_DB, $this->lock);
        unset($_SERVER['SYMFONY_DOCKER']);

        $this->assertFileExists(FLEX_TEST_DIR.'/compose.yaml');

        $composerJsonData = json_decode(file_get_contents($composerJsonPath), true);
        $this->assertArrayHasKey('extra', $composerJsonData);
        $this->assertTrue($composerJsonData['extra']['symfony']['docker']);
    }

    public function testConfigureFileWithExistingVolumes()
    {
        $originalContent = self::ORIGINAL_CONTENT.<<<'YAML'

volumes:
  my-data: {}

YAML;

        $dockerComposeFile = FLEX_TEST_DIR.'/docker-compose.yaml';
        file_put_contents($dockerComposeFile, $originalContent);

        $this->configurator->configure($this->recipeDb, self::CONFIG_DB, $this->lock);

        $this->assertStringEqualsFile($dockerComposeFile, self::ORIGINAL_CONTENT.<<<'YAML'

###> doctrine/doctrine-bundle ###
  db:
    image: mariadb:10.3
    environment:
      - MYSQL_DATABASE=symfony
      # You should definitely change the password in production
      - MYSQL_PASSWORD=password
      - MYSQL_RANDOM_ROOT_PASSWORD=true
      - MYSQL_USER=symfony
    volumes:
      - db-data:/var/lib/mysql:rw
      # You may use a bind-mounted host directory instead, so that it is harder to accidentally remove the volume and lose all your data!
      # - ./docker/db/data:/var/lib/mysql:rw
###< doctrine/doctrine-bundle ###

volumes:
  my-data: {}

###> doctrine/doctrine-bundle ###
  db-data: {}
###< doctrine/doctrine-bundle ###

YAML
        );

        $this->configurator->unconfigure($this->recipeDb, self::CONFIG_DB, $this->lock);
        // Not the same original, we have an extra breaks line
        $this->assertEquals($originalContent.<<<'YAML'


YAML
            , file_get_contents($dockerComposeFile));
    }

    public function testConfigureFileWithExistingMarks()
    {
        $originalContent = self::ORIGINAL_CONTENT.<<<'YAML'

###> doctrine/doctrine-bundle ###
  db:
    image: postgres:11-alpine
    environment:
      - POSTGRES_DB=symfony
      - POSTGRES_USER=symfony
      # You should definitely change the password in production
      - POSTGRES_PASSWORD=!ChangeMe!
    volumes:
      - db-data:/var/lib/postgresql/data:rw
      # You may use a bind-mounted host directory instead, so that it is harder to accidentally remove the volume and lose all your data!
      # - ./docker/db/data:/var/lib/postgresql/data:rw
###< doctrine/doctrine-bundle ###

volumes:
###> doctrine/doctrine-bundle ###
  db-data: {}
###< doctrine/doctrine-bundle ###

YAML;

        $dockerComposeFile = FLEX_TEST_DIR.'/docker-compose.yml';
        file_put_contents($dockerComposeFile, $originalContent);

        /** @var Recipe|\PHPUnit\Framework\MockObject\MockObject $recipe */
        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $recipe->method('getName')->willReturn('symfony/mercure-bundle');

        $config = [
            'services' => [
                'mercure:',
                '  # In production, you may want to use the managed version of Mercure, https://mercure.rocks',
                '  image: dunglas/mercure',
                '  environment:',
                '    # You should definitely change all these values in production',
                '    - JWT_KEY=!ChangeMe!',
                '    - ALLOW_ANONYMOUS=1',
                '    - CORS_ALLOWED_ORIGINS=*',
                '    - PUBLISH_ALLOWED_ORIGINS=http://localhost:1337',
                '    - DEMO=1',
            ],
        ];

        $this->configurator->configure($recipe, $config, $this->lock);

        $this->assertStringEqualsFile($dockerComposeFile, self::ORIGINAL_CONTENT.<<<'YAML'

###> doctrine/doctrine-bundle ###
  db:
    image: postgres:11-alpine
    environment:
      - POSTGRES_DB=symfony
      - POSTGRES_USER=symfony
      # You should definitely change the password in production
      - POSTGRES_PASSWORD=!ChangeMe!
    volumes:
      - db-data:/var/lib/postgresql/data:rw
      # You may use a bind-mounted host directory instead, so that it is harder to accidentally remove the volume and lose all your data!
      # - ./docker/db/data:/var/lib/postgresql/data:rw
###< doctrine/doctrine-bundle ###

###> symfony/mercure-bundle ###
  mercure:
    # In production, you may want to use the managed version of Mercure, https://mercure.rocks
    image: dunglas/mercure
    environment:
      # You should definitely change all these values in production
      - JWT_KEY=!ChangeMe!
      - ALLOW_ANONYMOUS=1
      - CORS_ALLOWED_ORIGINS=*
      - PUBLISH_ALLOWED_ORIGINS=http://localhost:1337
      - DEMO=1
###< symfony/mercure-bundle ###

volumes:
###> doctrine/doctrine-bundle ###
  db-data: {}
###< doctrine/doctrine-bundle ###

YAML
        );

        $this->configurator->unconfigure($recipe, $config, $this->lock);
        $this->assertEquals($originalContent, file_get_contents($dockerComposeFile));

        // Unconfigure doctrine
        $this->configurator->unconfigure($this->recipeDb, self::CONFIG_DB, $this->lock);
        $this->assertEquals(self::ORIGINAL_CONTENT, file_get_contents($dockerComposeFile));
    }

    public function testUnconfigureFileWithManyMarks()
    {
        $originalContent = self::ORIGINAL_CONTENT.<<<'YAML'

###> symfony/messenger ###
  rabbitmq:
    image: rabbitmq:management-alpine
    environment:
      # You should definitely change the password in production
      - RABBITMQ_DEFAULT_USER=guest
      - RABBITMQ_DEFAULT_PASS=guest
    volumes:
      - rabbitmq-data:/var/lib/rabbitmq
###< symfony/messenger ###

###> doctrine/doctrine-bundle ###
  db:
    image: postgres:11-alpine
    environment:
      - POSTGRES_DB=symfony
      - POSTGRES_USER=symfony
      # You should definitely change the password in production
      - POSTGRES_PASSWORD=!ChangeMe!
    volumes:
      - db-data:/var/lib/postgresql/data:rw
      # You may use a bind-mounted host directory instead, so that it is harder to accidentally remove the volume and lose all your data!
      # - ./docker/db/data:/var/lib/postgresql/data:rw
###< doctrine/doctrine-bundle ###

volumes:
###> symfony/messenger ###
  rabbitmq-data: {}
###< symfony/messenger ###

###> doctrine/doctrine-bundle ###
  db-data: {}
###< doctrine/doctrine-bundle ###

YAML;

        $contentWithoutDoctrine = self::ORIGINAL_CONTENT.<<<'YAML'

###> symfony/messenger ###
  rabbitmq:
    image: rabbitmq:management-alpine
    environment:
      # You should definitely change the password in production
      - RABBITMQ_DEFAULT_USER=guest
      - RABBITMQ_DEFAULT_PASS=guest
    volumes:
      - rabbitmq-data:/var/lib/rabbitmq
###< symfony/messenger ###

volumes:
###> symfony/messenger ###
  rabbitmq-data: {}
###< symfony/messenger ###


YAML;

        $dockerComposeFile = FLEX_TEST_DIR.'/docker-compose.yml';
        file_put_contents($dockerComposeFile, $originalContent);

        /** @var Recipe|\PHPUnit\Framework\MockObject\MockObject $recipe */
        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $recipe->method('getName')->willReturn('symfony/messenger');

        $this->configurator->unconfigure($this->recipeDb, self::CONFIG_DB, $this->lock);
        $this->assertStringEqualsFile($dockerComposeFile, $contentWithoutDoctrine);
    }

    public function testConfigureMultipleFiles()
    {
        $dockerComposeFile = FLEX_TEST_DIR.'/docker-compose.yml';
        file_put_contents($dockerComposeFile, self::ORIGINAL_CONTENT);
        $dockerComposeOverrideFile = FLEX_TEST_DIR.'/docker-compose.override.yml';
        file_put_contents($dockerComposeOverrideFile, self::ORIGINAL_CONTENT);

        $this->configurator->configure($this->recipeDb, self::CONFIG_DB_MULTIPLE_FILES, $this->lock);

        foreach ([$dockerComposeFile, $dockerComposeOverrideFile] as $file) {
            $this->assertStringEqualsFile($file, self::ORIGINAL_CONTENT.<<<'YAML'

###> doctrine/doctrine-bundle ###
  db:
    image: mariadb:10.3
    environment:
      - MYSQL_DATABASE=symfony
      # You should definitely change the password in production
      - MYSQL_PASSWORD=password
      - MYSQL_RANDOM_ROOT_PASSWORD=true
      - MYSQL_USER=symfony
    volumes:
      - db-data:/var/lib/mysql:rw
      # You may use a bind-mounted host directory instead, so that it is harder to accidentally remove the volume and lose all your data!
      # - ./docker/db/data:/var/lib/mysql:rw
###< doctrine/doctrine-bundle ###

volumes:
###> doctrine/doctrine-bundle ###
  db-data: {}
###< doctrine/doctrine-bundle ###

YAML
            );
        }

        $this->configurator->unconfigure($this->recipeDb, self::CONFIG_DB_MULTIPLE_FILES, $this->lock);
        $this->assertStringEqualsFile($dockerComposeFile, self::ORIGINAL_CONTENT);
        $this->assertStringEqualsFile($dockerComposeOverrideFile, self::ORIGINAL_CONTENT);
    }

    public function testConfigureEnvVar()
    {
        @mkdir(FLEX_TEST_DIR.'/child/');
        $dockerComposeFile = FLEX_TEST_DIR.'/child/docker-compose.yml';
        file_put_contents($dockerComposeFile, self::ORIGINAL_CONTENT);
        $dockerComposeOverrideFile = FLEX_TEST_DIR.'/child/docker-compose.override.yml';
        file_put_contents($dockerComposeOverrideFile, self::ORIGINAL_CONTENT);

        $sep = '\\' === \DIRECTORY_SEPARATOR ? ';' : ':';
        $_SERVER['COMPOSE_FILE'] = $dockerComposeFile.$sep.$dockerComposeOverrideFile;

        $this->configurator->configure($this->recipeDb, self::CONFIG_DB_MULTIPLE_FILES, $this->lock);

        foreach ([$dockerComposeFile, $dockerComposeOverrideFile] as $file) {
            $this->assertStringEqualsFile($file, self::ORIGINAL_CONTENT.<<<'YAML'

###> doctrine/doctrine-bundle ###
  db:
    image: mariadb:10.3
    environment:
      - MYSQL_DATABASE=symfony
      # You should definitely change the password in production
      - MYSQL_PASSWORD=password
      - MYSQL_RANDOM_ROOT_PASSWORD=true
      - MYSQL_USER=symfony
    volumes:
      - db-data:/var/lib/mysql:rw
      # You may use a bind-mounted host directory instead, so that it is harder to accidentally remove the volume and lose all your data!
      # - ./docker/db/data:/var/lib/mysql:rw
###< doctrine/doctrine-bundle ###

volumes:
###> doctrine/doctrine-bundle ###
  db-data: {}
###< doctrine/doctrine-bundle ###

YAML
            );
        }

        $this->configurator->unconfigure($this->recipeDb, self::CONFIG_DB_MULTIPLE_FILES, $this->lock);
        $this->assertStringEqualsFile($dockerComposeFile, self::ORIGINAL_CONTENT);
        $this->assertStringEqualsFile($dockerComposeOverrideFile, self::ORIGINAL_CONTENT);
    }

    public function testConfigureFileInParentDir()
    {
        $this->configurator = new DockerComposeConfigurator(
            $this->composer,
            $this->getMockBuilder(IOInterface::class)->getMock(),
            new Options(['config-dir' => 'config', 'root-dir' => FLEX_TEST_DIR.'/child'])
        );

        @mkdir(FLEX_TEST_DIR.'/child');
        $dockerComposeFile = FLEX_TEST_DIR.'/docker-compose.yaml';
        file_put_contents($dockerComposeFile, self::ORIGINAL_CONTENT);

        $this->configurator->configure($this->recipeDb, self::CONFIG_DB, $this->lock);

        $this->assertStringEqualsFile($dockerComposeFile, self::ORIGINAL_CONTENT.<<<'YAML'

###> doctrine/doctrine-bundle ###
  db:
    image: mariadb:10.3
    environment:
      - MYSQL_DATABASE=symfony
      # You should definitely change the password in production
      - MYSQL_PASSWORD=password
      - MYSQL_RANDOM_ROOT_PASSWORD=true
      - MYSQL_USER=symfony
    volumes:
      - db-data:/var/lib/mysql:rw
      # You may use a bind-mounted host directory instead, so that it is harder to accidentally remove the volume and lose all your data!
      # - ./docker/db/data:/var/lib/mysql:rw
###< doctrine/doctrine-bundle ###

volumes:
###> doctrine/doctrine-bundle ###
  db-data: {}
###< doctrine/doctrine-bundle ###

YAML
        );

        $this->configurator->unconfigure($this->recipeDb, self::CONFIG_DB, $this->lock);
        $this->assertEquals(self::ORIGINAL_CONTENT, file_get_contents($dockerComposeFile));
    }

    public function testConfigureWithoutExistingDockerComposeFiles()
    {
        $dockerComposeFile = FLEX_TEST_DIR.'/compose.yaml';
        $defaultContent = "version: '3'\n";

        $this->configurator->configure($this->recipeDb, self::CONFIG_DB, $this->lock);

        $this->assertStringEqualsFile($dockerComposeFile, $defaultContent.<<<'YAML'

services:
###> doctrine/doctrine-bundle ###
  db:
    image: mariadb:10.3
    environment:
      - MYSQL_DATABASE=symfony
      # You should definitely change the password in production
      - MYSQL_PASSWORD=password
      - MYSQL_RANDOM_ROOT_PASSWORD=true
      - MYSQL_USER=symfony
    volumes:
      - db-data:/var/lib/mysql:rw
      # You may use a bind-mounted host directory instead, so that it is harder to accidentally remove the volume and lose all your data!
      # - ./docker/db/data:/var/lib/mysql:rw
###< doctrine/doctrine-bundle ###

volumes:
###> doctrine/doctrine-bundle ###
  db-data: {}
###< doctrine/doctrine-bundle ###

YAML
        );

        $this->configurator->unconfigure($this->recipeDb, self::CONFIG_DB, $this->lock);
        $this->assertEquals(trim($defaultContent), file_get_contents($dockerComposeFile));
    }

    public function testUpdate()
    {
        $recipe = $this->createMock(Recipe::class);
        $recipe->method('getName')
            ->willReturn('doctrine/doctrine-bundle');

        $recipeUpdate = new RecipeUpdate(
            $recipe,
            $recipe,
            $this->createMock(Lock::class),
            FLEX_TEST_DIR
        );

        @mkdir(FLEX_TEST_DIR);
        file_put_contents(
            FLEX_TEST_DIR.'/docker-compose.yml',
            <<<EOF
version: '3'

services:
###> doctrine/doctrine-bundle ###
  database:
    image: postgres:13-alpine
    environment:
      POSTGRES_DB: app
      POSTGRES_PASSWORD: CUSTOMIZED_PASSWORD
      POSTGRES_USER: CUSTOMIZED_USER
    volumes:
      - db-data:/var/lib/postgresql/data:rw
###< doctrine/doctrine-bundle ###

###> symfony/mercure-bundle ###
  mercure:
    image: dunglas/mercure
    restart: unless-stopped
    # Comment the following line to disable the development mode
    command: /usr/bin/caddy run -config /etc/caddy/Caddyfile.dev
    volumes:
      - mercure_data:/data
      - mercure_config:/config
###< symfony/mercure-bundle ###

volumes:
###> doctrine/doctrine-bundle ###
  db-data:
###< doctrine/doctrine-bundle ###

###> symfony/mercure-bundle ###
  mercure_data:
  mercure_config:
###< symfony/mercure-bundle ###

EOF
        );

        $this->configurator->update(
            $recipeUpdate,
            [
                'docker-compose.yml' => [
                    'services' => [
                        'database:',
                        '  image: postgres:13-alpine',
                        '  environment:',
                        '    POSTGRES_DB: app',
                        '    POSTGRES_PASSWORD: ChangeMe',
                        '    POSTGRES_USER: symfony',
                        '  volumes:',
                        '    - db-data:/var/lib/postgresql/data:rw',
                    ],
                    'volumes' => [
                        'db-data:',
                    ],
                ],
            ],
            [
                'docker-compose.yml' => [
                    'services' => [
                        'database:',
                        '  image: postgres:13-alpine',
                        '  environment:',
                        '    POSTGRES_DB: app',
                        '    POSTGRES_PASSWORD: ChangeMe',
                        '    POSTGRES_USER: symfony',
                        '  volumes:',
                        '    - MY_DATABASE-data:/var/lib/postgresql/data:rw',
                    ],
                    'volumes' => [
                        'MY_DATABASE:',
                    ],
                ],
            ]
        );

        $this->assertSame(['docker-compose.yml' => <<<EOF
version: '3'

services:
###> doctrine/doctrine-bundle ###
  database:
    image: postgres:13-alpine
    environment:
      POSTGRES_DB: app
      POSTGRES_PASSWORD: ChangeMe
      POSTGRES_USER: symfony
    volumes:
      - db-data:/var/lib/postgresql/data:rw
###< doctrine/doctrine-bundle ###

###> symfony/mercure-bundle ###
  mercure:
    image: dunglas/mercure
    restart: unless-stopped
    # Comment the following line to disable the development mode
    command: /usr/bin/caddy run -config /etc/caddy/Caddyfile.dev
    volumes:
      - mercure_data:/data
      - mercure_config:/config
###< symfony/mercure-bundle ###

volumes:
###> doctrine/doctrine-bundle ###
  db-data:
###< doctrine/doctrine-bundle ###

###> symfony/mercure-bundle ###
  mercure_data:
  mercure_config:
###< symfony/mercure-bundle ###

EOF
        ], $recipeUpdate->getOriginalFiles());

        $this->assertSame(['docker-compose.yml' => <<<EOF
version: '3'

services:
###> doctrine/doctrine-bundle ###
  database:
    image: postgres:13-alpine
    environment:
      POSTGRES_DB: app
      POSTGRES_PASSWORD: ChangeMe
      POSTGRES_USER: symfony
    volumes:
      - MY_DATABASE-data:/var/lib/postgresql/data:rw
###< doctrine/doctrine-bundle ###

###> symfony/mercure-bundle ###
  mercure:
    image: dunglas/mercure
    restart: unless-stopped
    # Comment the following line to disable the development mode
    command: /usr/bin/caddy run -config /etc/caddy/Caddyfile.dev
    volumes:
      - mercure_data:/data
      - mercure_config:/config
###< symfony/mercure-bundle ###

volumes:
###> doctrine/doctrine-bundle ###
  MY_DATABASE:
###< doctrine/doctrine-bundle ###

###> symfony/mercure-bundle ###
  mercure_data:
  mercure_config:
###< symfony/mercure-bundle ###

EOF
        ], $recipeUpdate->getNewFiles());
    }
}
