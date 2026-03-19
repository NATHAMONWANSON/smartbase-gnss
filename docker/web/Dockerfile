FROM drupal:10-apache

# เปิดใช้งาน Apache Modules สำหรับ Reverse Proxy และ WebSocket
RUN a2enmod proxy proxy_http proxy_wstunnel rewrite headers

# คัดลอกไฟล์ตั้งค่า Apache
COPY ./000-default.conf /etc/apache2/sites-available/000-default.conf

# ติดตั้ง nano ไว้เผื่อเข้าไปแก้ไฟล์ใน Container
RUN apt-get update && apt-get install -y nano

WORKDIR /var/www/html
