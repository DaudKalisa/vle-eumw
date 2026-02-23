@echo off
echo Downloading PHPMailer files...

curl -o vendor\phpmailer\phpmailer\src\PHPMailer.php https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/PHPMailer.php
curl -o vendor\phpmailer\phpmailer\src\SMTP.php https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/SMTP.php
curl -o vendor\phpmailer\phpmailer\src\Exception.php https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/Exception.php
curl -o vendor\phpmailer\phpmailer\src\OAuth.php https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/OAuth.php
curl -o vendor\phpmailer\phpmailer\src\POP3.php https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/POP3.php
curl -o vendor\composer\ClassLoader.php https://raw.githubusercontent.com/composer/composer/main/src/Composer/Autoload/ClassLoader.php

echo.
echo PHPMailer files downloaded!
echo.
dir vendor\phpmailer\phpmailer\src
pause
