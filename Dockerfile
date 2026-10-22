FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends \
    libreoffice-writer libreoffice-calc libreoffice-impress libreoffice-draw \
    python3-pip fonts-dejavu fonts-liberation \
 && pip3 install --break-system-packages pdf2docx \
 && rm -rf /var/lib/apt/lists/*
RUN printf "upload_max_filesize=50M\npost_max_size=50M\nmax_execution_time=300\nmemory_limit=512M\n" > /usr/local/etc/php/conf.d/up.ini
COPY index.php /var/www/html/
