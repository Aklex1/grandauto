<?php
/**
 * Объявления в группу курса.
 *
 * Новости и анонсы живут в отдельной теме группы, а писать их
 * приходится с телефона руками. Бот, который ведёт эту тему по
 * расписанию, стоит на своём сервере и к сайту отношения не имеет —
 * поэтому здесь свой короткий путь: взять текст и отправить его в
 * нужную тему.
 *
 * Получатель не приходит из запроса: и группа, и тема берутся из
 * настроек курса. Иначе маршрут превращался бы в «отправь что угодно
 * куда угодно от имени нашего бота», а это совсем другая вещь по
 * последствиям.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Announce {

    /**
     * Ключи ботов, которые есть у сайта.
     *
     * Имя рядом с ключом нужно только для ответа администратору: по
     * нему видно, кем ушло объявление, и кого добавлять в группу, если
     * не ушло никем.
     *
     * @return array<string,string>
     */
    private static function tokens() {
        $out = array();
        if (class_exists('GS_Leads') && GS_Leads::token() !== '') {
            $out['бот заявок'] = GS_Leads::token();
        }
        $known = array(
            'kie_tts_telegram_bot_token'      => 'бот озвучки',
            'kie_neurohub_telegram_bot_token' => 'бот нейрохаба',
        );
        foreach ($known as $option => $label) {
            $token = trim((string) get_option($option, ''));
            if ($token !== '' && !in_array($token, $out, true)) {
                $out[$label] = $token;
            }
        }
        return $out;
    }

    /**
     * Куда писать: из ссылки вида https://t.me/c/4407841852/2.
     *
     * Внутренний адрес группы в API отличается от ссылки приставкой
     * «-100» — её и дописываем, а число после косой черты и есть тема.
     *
     * @return array{chat:string,thread:int}|null
     */
    public static function target($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (preg_match('~t\.me/c/(\d+)(?:/(\d+))?~', $url, $m)) {
            return array(
                'chat'   => '-100' . $m[1],
                'thread' => isset($m[2]) ? (int) $m[2] : 0,
            );
        }
        /*
         * Открытая группа зовётся по имени, а не по номеру: ссылка на
         * неё выглядит как t.me/имя, и Телеграм понимает «@имя» всюду,
         * где ждёт номер чата. Без этого проверить, та ли это группа,
         * можно было только по номеру, которого у человека под рукой нет.
         */
        if (preg_match('~(?:t\.me/|@)([A-Za-z][A-Za-z0-9_]{3,})(?:/(\d+))?~', $url, $m)) {
            return array(
                'chat'   => '@' . $m[1],
                'thread' => isset($m[2]) ? (int) $m[2] : 0,
            );
        }
        return null;
    }

    /**
     * Кто из ботов видит эту группу.
     *
     * «Chat not found» звучит одинаково и когда бота в группе нет, и
     * когда у группы другой внутренний номер. Спрашиваем у Телеграма
     * саму группу — ответ сразу говорит, что чинить.
     *
     * @return array<string,string>
     */
    public static function probe($url) {
        $to = self::target($url);
        if (!$to) {
            return array('адрес' => 'не разобран: ' . $url);
        }
        $out = array('чат' => $to['chat'], 'тема' => (string) $to['thread']);
        /*
         * Ссылка вида t.me/c/<номер> почти всегда означает супергруппу,
         * и её внутренний номер — это «-100» плюс то, что в ссылке. Но
         * у старых групп приставки нет, а ошибка Телеграма в обоих
         * случаях одна и та же — поэтому пробуем оба варианта.
         */
        $ids = strpos($to['chat'], '@') === 0
            ? array($to['chat'])
            : array($to['chat'], '-' . ltrim($to['chat'], '-100'));
        $pairs = array();
        foreach (self::tokens() as $name => $token) {
            foreach (array_unique($ids) as $id) {
                $pairs[$name . ' / ' . $id] = array($token, $id);
            }
        }
        foreach ($pairs as $name => $pair) {
            list($token, $id) = $pair;
            $response = wp_remote_get(
                'https://api.telegram.org/bot' . $token . '/getChat?chat_id=' . rawurlencode($id),
                array('timeout' => 25)
            );
            if (is_wp_error($response)) {
                $out[$name] = 'связь: ' . $response->get_error_message();
                continue;
            }
            $answer = json_decode((string) wp_remote_retrieve_body($response), true);
            if (is_array($answer) && !empty($answer['ok'])) {
                $chat = (array) ($answer['result'] ?? array());
                $out[$name] = 'видит: ' . (string) ($chat['title'] ?? '—')
                            . ' (' . (string) ($chat['type'] ?? '?') . ')'
                            . ', номер ' . (string) ($chat['id'] ?? '?');
            } else {
                $out[$name] = 'нет: ' . (is_array($answer)
                    ? (string) ($answer['description'] ?? 'отказ')
                    : 'некорректный ответ');
            }
        }
        return $out;
    }

    /**
     * Какие чаты бот видел последними.
     *
     * Если у бота не стоит вебхук, Телеграм отдаёт последние события —
     * по ним видно настоящий номер группы, в которую его добавили. Если
     * вебхук стоит, он так и скажет, и это тоже ответ.
     *
     * @return array<string,mixed>
     */
    public static function updates() {
        $out = array();
        foreach (self::tokens() as $name => $token) {
            $response = wp_remote_get(
                'https://api.telegram.org/bot' . $token . '/getUpdates?limit=50&timeout=0',
                array('timeout' => 30)
            );
            if (is_wp_error($response)) {
                $out[$name] = 'связь: ' . $response->get_error_message();
                continue;
            }
            $answer = json_decode((string) wp_remote_retrieve_body($response), true);
            if (!is_array($answer) || empty($answer['ok'])) {
                $out[$name] = 'нет: ' . (is_array($answer)
                    ? (string) ($answer['description'] ?? 'отказ')
                    : 'некорректный ответ');
                continue;
            }
            $chats = array();
            foreach ((array) ($answer['result'] ?? array()) as $update) {
                foreach (array('message', 'channel_post', 'my_chat_member', 'edited_message') as $key) {
                    $chat = $update[$key]['chat'] ?? null;
                    if (is_array($chat) && !empty($chat['id'])) {
                        $chats[(string) $chat['id']] = (string) ($chat['title'] ?? $chat['username'] ?? 'без названия');
                    }
                }
            }
            $out[$name] = $chats ? $chats : 'событий нет';
        }
        return $out;
    }

    /**
     * Отправить объявление.
     *
     * @return array{ok:bool,message:string}
     */
    public static function post($text, $url) {
        $text = trim((string) $text);
        if (mb_strlen($text) < 10) {
            return array('ok' => false, 'message' => 'Слишком короткий текст');
        }
        if (mb_strlen($text) > 4000) {
            return array('ok' => false, 'message' => 'Телеграм не примет больше 4000 знаков');
        }
        $to = self::target($url);
        if (!$to) {
            return array('ok' => false, 'message' => 'Не указана тема группы курса');
        }
        $tokens = self::tokens();
        if (!$tokens) {
            return array('ok' => false, 'message' => 'Бот не настроен');
        }

        $body = array(
            'chat_id'                  => $to['chat'],
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => 'true',
        );
        if ($to['thread'] > 0) {
            $body['message_thread_id'] = $to['thread'];
        }

        /*
         * Ботов у хозяйства несколько, и в группу курса добавлен не
         * обязательно тот, что шлёт заявки. Перебираем тех, чьи ключи
         * уже есть на сайте, и останавливаемся на первом, кого группа
         * признала своим: «chat not found» значит ровно одно — этот бот
         * в группе не состоит.
         */
        $last = 'Бот не настроен';
        foreach ($tokens as $name => $token) {
            $response = wp_remote_post(
                'https://api.telegram.org/bot' . $token . '/sendMessage',
                array('timeout' => 25, 'body' => $body)
            );
            if (is_wp_error($response)) {
                $last = $response->get_error_message();
                continue;
            }
            $answer = json_decode((string) wp_remote_retrieve_body($response), true);
            if (is_array($answer) && !empty($answer['ok'])) {
                return array(
                    'ok'      => true,
                    'message' => 'Опубликовано ботом ' . $name,
                    'bot'     => $name,
                    'id'      => (int) ($answer['result']['message_id'] ?? 0),
                );
            }
            $last = is_array($answer)
                ? $name . ': ' . (string) ($answer['description'] ?? 'Телеграм отклонил сообщение')
                : $name . ': некорректный ответ Телеграма';
        }
        return array('ok' => false, 'message' => $last);
    }
}
