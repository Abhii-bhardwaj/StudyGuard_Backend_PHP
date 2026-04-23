# Use the official PHP image with Apache
FROM php:8.2-apache

# Enable Apache mod_rewrite for .htaccess support
RUN a2enmod rewrite

# Install required PHP extensions (StudyGuard uses mysqli)
RUN docker-php-ext-install mysqli pdo_mysql

# Set the working directory inside the container
WORKDIR /var/www/html

# Copy your local PHP files into the container
COPY . /var/www/html/

# Update RewriteBase for root deployment on Render
# The local XAMPP setup uses /studyguard/ but Render serves from /
RUN sed -i 's|RewriteBase /studyguard/|RewriteBase /|g' .htaccess

# Configure Apache to allow .htaccess overrides
RUN echo '<Directory /var/www/html/>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/allow-override.conf && \
    a2enconf allow-override

# Expose port 80 for web traffic
EXPOSE 80

# Start Apache in the foreground
CMD ["apache2-foreground"]
