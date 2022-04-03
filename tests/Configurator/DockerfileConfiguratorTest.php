<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Configurator;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\RootPackage;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

class DockerfileConfiguratorTest extends TestCase
{
    protected function setUp(): void
    {
        @mkdir(FLEX_TEST_DIR);
    }

    protected function tearDown(): void
    {
        @unlink(FLEX_TEST_DIR.'/Dockerfile');
    }

    public function testConfigure()
    {
        $originalContent = <<<'EOF'
FROM php:7.1-fpm-alpine

RUN apk add --no-cache --virtual .persistent-deps \
		git \
		icu-libs \
		make \
		zlib

ENV APCU_VERSION 5.1.8

RUN set -xe \
	&& apk add --no-cache --virtual .build-deps \
		$PHPIZE_DEPS \
		icu-dev \
		zlib-dev \
	&& docker-php-ext-install \
		intl \
		zip \
	&& pecl install \
		apcu-${APCU_VERSION} \
	&& docker-php-ext-enable --ini-name 20-apcu.ini apcu \
	&& docker-php-ext-enable --ini-name 05-opcache.ini opcache \
	&& apk del .build-deps

###> recipes ###
###< recipes ##

COPY docker/app/php.ini /usr/local/etc/php/php.ini

COPY docker/app/install-composer.sh /usr/local/bin/docker-app-install-composer
RUN chmod +x /usr/local/bin/docker-app-install-composer

RUN set -xe \
	&& docker-app-install-composer \
	&& mv composer.phar /usr/local/bin/composer

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER 1

RUN composer global require "hirak/prestissimo:^0.3" --prefer-dist --no-progress --no-suggest --optimize-autoloader --classmap-authoritative \
	&& composer clear-cache

WORKDIR /srv/app

COPY . .
# Cleanup unneeded files
RUN rm -Rf docker/

# Download the Symfony skeleton
ENV SKELETON_COMPOSER_JSON https://raw.githubusercontent.com/symfony/skeleton/v3.3.2/composer.json
RUN [ -f composer.json ] || php -r "copy('$SKELETON_COMPOSER_JSON', 'composer.json');"

RUN mkdir -p var/cache var/logs var/sessions \
    && composer install --prefer-dist --no-dev --no-progress --no-suggest --optimize-autoloader --classmap-authoritative --no-interaction \
	&& composer clear-cache \
# Permissions hack because setfacl does not work on Mac and Windows
	&& chown -R www-data var

COPY docker/app/docker-entrypoint.sh /usr/local/bin/docker-app-entrypoint
RUN chmod +x /usr/local/bin/docker-app-entrypoint

ENTRYPOINT ["docker-app-entrypoint"]
CMD ["php-fpm"]

EOF;

        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $recipe->method('getName')->willReturn('doctrine/doctrine-bundle');

        $config = FLEX_TEST_DIR.'/Dockerfile';
        file_put_contents($config, $originalContent);

        $configurator = $this->createConfigurator();
        $configurator->configure($recipe, ['RUN docker-php-ext-install pdo_mysql'], $lock);
        $this->assertEquals(<<<'EOF'
FROM php:7.1-fpm-alpine

RUN apk add --no-cache --virtual .persistent-deps \
		git \
		icu-libs \
		make \
		zlib

ENV APCU_VERSION 5.1.8

RUN set -xe \
	&& apk add --no-cache --virtual .build-deps \
		$PHPIZE_DEPS \
		icu-dev \
		zlib-dev \
	&& docker-php-ext-install \
		intl \
		zip \
	&& pecl install \
		apcu-${APCU_VERSION} \
	&& docker-php-ext-enable --ini-name 20-apcu.ini apcu \
	&& docker-php-ext-enable --ini-name 05-opcache.ini opcache \
	&& apk del .build-deps

###> recipes ###
###> doctrine/doctrine-bundle ###
RUN docker-php-ext-install pdo_mysql
###< doctrine/doctrine-bundle ###
###< recipes ##

COPY docker/app/php.ini /usr/local/etc/php/php.ini

COPY docker/app/install-composer.sh /usr/local/bin/docker-app-install-composer
RUN chmod +x /usr/local/bin/docker-app-install-composer

RUN set -xe \
	&& docker-app-install-composer \
	&& mv composer.phar /usr/local/bin/composer

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER 1

RUN composer global require "hirak/prestissimo:^0.3" --prefer-dist --no-progress --no-suggest --optimize-autoloader --classmap-authoritative \
	&& composer clear-cache

WORKDIR /srv/app

COPY . .
# Cleanup unneeded files
RUN rm -Rf docker/

# Download the Symfony skeleton
ENV SKELETON_COMPOSER_JSON https://raw.githubusercontent.com/symfony/skeleton/v3.3.2/composer.json
RUN [ -f composer.json ] || php -r "copy('$SKELETON_COMPOSER_JSON', 'composer.json');"

RUN mkdir -p var/cache var/logs var/sessions \
    && composer install --prefer-dist --no-dev --no-progress --no-suggest --optimize-autoloader --classmap-authoritative --no-interaction \
	&& composer clear-cache \
# Permissions hack because setfacl does not work on Mac and Windows
	&& chown -R www-data var

COPY docker/app/docker-entrypoint.sh /usr/local/bin/docker-app-entrypoint
RUN chmod +x /usr/local/bin/docker-app-entrypoint

ENTRYPOINT ["docker-app-entrypoint"]
CMD ["php-fpm"]

EOF
            , file_get_contents($config));

        $configurator->unconfigure($recipe, [], $lock);
        $this->assertEquals($originalContent, file_get_contents($config));
    }

    public function testUpdate()
    {
        $configurator = $this->createConfigurator();
        $recipe = $this->createMock(Recipe::class);
        $recipe->method('getName')
            ->willReturn('dummy/dummy');

        $recipeUpdate = new RecipeUpdate(
            $recipe,
            $recipe,
            $this->createMock(Lock::class),
            FLEX_TEST_DIR
        );

        @mkdir(FLEX_TEST_DIR);
        file_put_contents(
            FLEX_TEST_DIR.'/Dockerfile',
            <<<EOF
FROM php:7.1-fpm-alpine

RUN apk add --no-cache --virtual .persistent-deps \
		git \
		zlib

###> recipes ###
###> dummy/dummy ###
RUN docker-php-ext-install pdo_dummyv1
# my custom line
###< dummy/dummy ###
###> doctrine/doctrine-bundle ###
RUN docker-php-ext-install pdo_mysql
###< doctrine/doctrine-bundle ###
###< recipes ##

COPY docker/app/install-composer.sh /usr/local/bin/docker-app-install-composer
RUN chmod +x /usr/local/bin/docker-app-install-composer

EOF
        );

        $configurator->update(
            $recipeUpdate,
            ['RUN docker-php-ext-install pdo_dummyv1'],
            ['RUN docker-php-ext-install pdo_dummyv2']
        );

        $this->assertSame(['Dockerfile' => <<<EOF
FROM php:7.1-fpm-alpine

RUN apk add --no-cache --virtual .persistent-deps \
		git \
		zlib

###> recipes ###
###> dummy/dummy ###
RUN docker-php-ext-install pdo_dummyv1
###< dummy/dummy ###
###> doctrine/doctrine-bundle ###
RUN docker-php-ext-install pdo_mysql
###< doctrine/doctrine-bundle ###
###< recipes ##

COPY docker/app/install-composer.sh /usr/local/bin/docker-app-install-composer
RUN chmod +x /usr/local/bin/docker-app-install-composer

EOF
        ], $recipeUpdate->getOriginalFiles());

        $this->assertSame(['Dockerfile' => <<<EOF
FROM php:7.1-fpm-alpine

RUN apk add --no-cache --virtual .persistent-deps \
		git \
		zlib

###> recipes ###
###> dummy/dummy ###
RUN docker-php-ext-install pdo_dummyv2
###< dummy/dummy ###
###> doctrine/doctrine-bundle ###
RUN docker-php-ext-install pdo_mysql
###< doctrine/doctrine-bundle ###
###< recipes ##

COPY docker/app/install-composer.sh /usr/local/bin/docker-app-install-composer
RUN chmod +x /usr/local/bin/docker-app-install-composer

EOF
        ], $recipeUpdate->getNewFiles());
    }

    private function createConfigurator(): DockerfileConfigurator
    {
        $package = new RootPackage('dummy/dummy', '1.0.0', '1.0.0');
        $package->setExtra(['symfony' => ['docker' => true]]);

        $composer = $this->getMockBuilder(Composer::class)->getMock();
        $composer->method('getPackage')->willReturn($package);

        return new DockerfileConfigurator(
            $composer,
            $this->getMockBuilder(IOInterface::class)->getMock(),
            new Options(['config-dir' => 'config', 'root-dir' => FLEX_TEST_DIR])
        );
    }
}
