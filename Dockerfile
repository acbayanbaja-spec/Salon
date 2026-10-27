FROM php:8.2-apache

# Install PostgreSQL client dev libraries and PDO extensions for MySQL and PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite module
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . /var/www/html/

# Create uploads directory and set permissions
RUN mkdir -p /var/www/html/uploads && chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
