#!/bin/bash

curl -o - 'tests/fpm/session_unset.php'
curl -o - 'tests/fpm/php-fpm1.php'
curl -o - 'tests/fpm/php-fpm2.php'

