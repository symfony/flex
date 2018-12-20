FROM php:7.1-fpm
MAINTAINER David Sanchez <david38sanchez@gmail.com>

# Bashrc
RUN echo "export LS_OPTIONS='--color=auto'" >> /root/.bashrc
RUN echo 'eval "`dircolors`"' >> /root/.bashrc
RUN echo "alias ls='ls $LS_OPTIONS'" >> /root/.bashrc
RUN echo "alias ll='ls $LS_OPTIONS -l'" >> /root/.bashrc
RUN echo "alias l='ls $LS_OPTIONS -lA'" >> /root/.bashrc
RUN echo "alias rm='rm -i'" >> /root/.bashrc
RUN echo "alias cp='cp -i'" >> /root/.bashrc
RUN echo "alias mv='mv -i'" >> /root/.bashrc

# Install lib dependencies
RUN apt-get update && apt-get install -y git wget subversion curl zlib1g-dev sqlite3 libsqlite3-dev

# Docker PHP extension install
RUN docker-php-ext-install pdo_sqlite zip

# Install Composer and make it available in the PATH
RUN wget https://getcomposer.org/composer.phar && chmod +x composer.phar && mv composer.phar /usr/local/bin/composer

EXPOSE 9000
CMD ["php-fpm"]