# Image officielle PHP avec Apache
FROM php:8.2-apache

# Copier tous les fichiers du projet dans le serveur web
COPY . /var/www/html/

# Donner les permissions (important parfois)
RUN chown -R www-data:www-data /var/www/html

# Activer mod_rewrite (utile si tu fais des routes)
RUN a2enmod rewrite

# Exposer le port 80
EXPOSE 80
