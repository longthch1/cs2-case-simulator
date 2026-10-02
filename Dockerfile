FROM php:8.2-apache

# Install PDO MySQL extension
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Enable Apache modules: rewrite, headers, deflate, expires
RUN a2enmod rewrite headers deflate expires

# Configure Apache DocumentRoot and AllowOverride All
RUN sed -ri -e 's!/var/www/html!/var/www/html!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!/var/www/html/!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && echo '<Directory /var/www/html>\n    Options -Indexes +FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>' >> /etc/apache2/apache2.conf

# Copy application source code
WORKDIR /var/www/html
COPY . /var/www/html/

# Create logs directory and fix permissions
RUN mkdir -p /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/logs

EXPOSE 80

CMD ["apache2-foreground"]
