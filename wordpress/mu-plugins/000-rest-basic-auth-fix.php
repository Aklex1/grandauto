<?php
/**
 * Plugin Name: REST: Authorization для nginx + php-fpm
 * Description: Восстанавливает PHP_AUTH_USER/PHP_AUTH_PW из заголовка Authorization. Без этого пароли приложений WordPress не работают на nginx, и REST API отвечает rest_not_logged_in даже с верным паролем.
 * Version: 1.0.0
 *
 * Куда положить: wp-content/mu-plugins/000-rest-basic-auth-fix.php
 * Активировать не нужно — mu-плагины подключаются сами и раньше обычных.
 *
 * Почему это нужно именно здесь: под Apache PHP сам раскладывает Basic-заголовок
 * в PHP_AUTH_USER и PHP_AUTH_PW, а под nginx с php-fpm — нет. Ядро WordPress читает
 * именно эти две переменные, поэтому пароль приложения молча игнорируется.
 * Проверено на genius-bot.ru: заголовок до PHP доходит (его читает genius/v1),
 * но wp/v2 всё равно отдаёт «Вы не авторизованы».
 */

if (!defined('ABSPATH')) {
    exit;
}

(static function (): void {
    if (!empty($_SERVER['PHP_AUTH_USER'])) {
        return;
    }
    $header = '';
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (!empty($_SERVER[$key])) {
            $header = (string) $_SERVER[$key];
            break;
        }
    }
    if ($header === '' && function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }
    if (stripos($header, 'basic ') !== 0) {
        return;
    }
    $decoded = base64_decode(substr($header, 6), true);
    if ($decoded === false || !str_contains($decoded, ':')) {
        return;
    }
    [$user, $password] = explode(':', $decoded, 2);
    $_SERVER['PHP_AUTH_USER'] = $user;
    $_SERVER['PHP_AUTH_PW'] = $password;
})();
