FROM php:8.1.30-apache

WORKDIR /var/www/html

COPY . .

RUN rm -r .git && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod -R 777 /var/www/html/tmp && \
    chmod -R 777 /var/www/html/upload && \
    chmod -R 777 /var/www/html/application/config

RUN apt update && \
    apt install -y libpng-dev libjpeg-dev libfreetype6-dev libicu-dev libldap2-dev libzip-dev libc-client-dev libkrb5-dev && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-configure ldap && \
    docker-php-ext-configure imap --with-kerberos --with-imap-ssl && \
    docker-php-ext-install pdo pdo_mysql gd intl ldap zip imap && \
    apt clean && \
    rm -rf /var/lib/apt/lists/*

EXPOSE 80

RUN echo "memory_limit = 4G" >> $PHP_INI_DIR/php.ini && \
    echo "upload_tmp_dir = /var/www/html/tmp" >>  $PHP_INI_DIR/php.ini && \
    echo "upload_max_filesize = 500M" >> $PHP_INI_DIR/php.ini && \
    echo "post_max_size = 500M" >> $PHP_INI_DIR/php.ini && \
    echo "session.save_path = /var/www/html/tmp" >> $PHP_INI_DIR/php.ini && \
    echo "output_buffering = On" >> $PHP_INI_DIR/php.ini

# Create a script to set ServerName dynamically
RUN echo '#!/bin/bash\n\
if [ -z "$SERVER_NAME" ]; then\n\
  echo "ServerName not set. Using localhost."\n\
  SERVER_NAME="localhost"\n\
fi\n\
echo "Setting ServerName to $SERVER_NAME"\n\
echo "ServerName $SERVER_NAME" >> /etc/apache2/conf-available/servername.conf\n\
a2enconf servername\n\
apache2-foreground' > /usr/local/bin/start-apache

RUN chmod +x /usr/local/bin/start-apache

# Add custom Apache configuration for KeepAlive
RUN echo 'KeepAlive On\n\
MaxKeepAliveRequests 100\n\
KeepAliveTimeout 1' >> /etc/apache2/apache2.conf \

# Add custom Apache configuration
RUN echo '<IfModule mpm_event_module>\n\
    StartServers 2\n\
    MinSpareThreads 25\n\
    MaxSpareThreads 75\n\
    ThreadLimit 64\n\
    ThreadsPerChild 25\n\
    MaxRequestWorkers 150\n\
    MaxConnectionsPerChild 10000\n\
</IfModule>' > /etc/apache2/mods-available/mpm_event.conf


CMD ["/usr/local/bin/start-apache"]
