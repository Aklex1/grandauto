<?php
/**
 * Новые посты канала — сами в каталог.
 *
 * Канал пополняется каждый день, а каталог собирали один раз. Через
 * месяц он отстанет, и человек с рекламы увидит вчерашнюю витрину,
 * тогда как в Телеграме уже новое. Поэтому раз в несколько часов
 * заглядываем в канал и заводим карточки по тому, что появилось.
 *
 * Читаем открытую веб-версию канала: подписка и ключи для этого не
 * нужны, а структура у неё простая и давно не меняется. Промт пишем по
 * самому кадру — в постах его почти никогда нет, а если и есть, то
 * ссылкой в бота.
 *
 * За один заход берём немного: каждая карточка — обращение к модели,
 * то есть деньги и время. Накопившееся разберётся за несколько заходов,
 * торопиться некуда.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Prompts_Sync {

    const CRON   = 'gs_prompts_sync';
    const OPT_AT = 'gs_prompts_sync_last';

    /** Сколько новых постов разбираем за один заход. */
    const BATCH = 6;

    /**
     * Метка канала -> рубрика каталога.
     *
     * Метки в канале ставят руками, поэтому они не всегда точные —
     * рубрику всё равно уточняем по тексту промта. Здесь только
     * подсказка на случай, когда по тексту ничего не распозналось.
     */
    private static function tag_map() {
        return array(
            'женский' => array('zhenskie'), 'подруги' => array('zhenskie'),
            'беременность' => array('zhenskie'),
            'мужской' => array('muzhskie'),
            'парный' => array('parnye'),
            'семейный' => array('semejnye'), 'семеный' => array('semejnye'),
            'мамаиребенок' => array('semejnye', 'detskie'),
            'папаиребенок' => array('semejnye', 'detskie'),
            'детский' => array('detskie'), 'дети' => array('detskie'),
            'дляподростка' => array('detskie'), 'подростки' => array('detskie'),
            'новогодний' => array('novogodnie'), 'ноговогодний' => array('novogodnie'),
            'свадебный' => array('svadebnye'),
            'наденьрождения' => array('prazdniki'), 'наденьрождния' => array('prazdniki'),
            'автомобиль' => array('avto'), 'машина' => array('avto'),
            'студийная' => array('studiya'),
            'портрет' => array('portret'), 'селфи' => array('portret'), 'зеркало' => array('portret'),
        );
    }

    public static function boot() {
        // Фильтр ставим первым: wp_schedule_event сверяет имя
        // расписания со списком и без фильтра своего имени не найдёт.
        add_filter('cron_schedules', array(__CLASS__, 'schedule'));
        add_action(self::CRON, array(__CLASS__, 'run'));
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 900, 'gs_six_hours', self::CRON);
        }
    }

    public static function schedule($list) {
        if (empty($list['gs_six_hours'])) {
            $list['gs_six_hours'] = array('interval' => 6 * HOUR_IN_SECONDS, 'display' => 'Каждые шесть часов');
        }
        return $list;
    }

    /* ---------------------------------------------------------------------
     * Чтение канала
     * ------------------------------------------------------------------ */

    /** Самый свежий пост, который уже в каталоге. */
    public static function last_seen() {
        $saved = (int) get_option(self::OPT_AT, 0);
        if ($saved > 0) {
            return $saved;
        }
        $max = 0;
        foreach (GS_Prompts::load() as $item) {
            $max = max($max, (int) ($item['msg'] ?? 0));
        }
        return $max;
    }

    /**
     * Посты из открытой веб-версии канала.
     *
     * @return array<int,array{msg:int,photo:string,tags:string[]}>
     */
    /**
     * Запрос с повтором.
     *
     * До Телеграма с хостинга иногда не доходит соединение — обрывается
     * на подключении за десять секунд, а следующая попытка проходит.
     * Без повтора такой случай стоил бы целого захода: раз в шесть
     * часов — это полдня отставания от канала на пустом месте.
     */
    private static function fetch($url, $timeout = 45) {
        $last = null;
        for ($try = 0; $try < 3; $try++) {
            if ($try > 0) {
                sleep(2);
            }
            $res = wp_remote_get($url, array(
                'timeout' => $timeout,
                'headers' => array('User-Agent' => 'Mozilla/5.0 (compatible; genius-bot/1.0)'),
            ));
            if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200) {
                return $res;
            }
            $last = $res;
        }
        return $last;
    }

    public static function read_channel() {
        $url = 'https://t.me/s/' . GS_Prompts::TG_CHANNEL;
        $res = self::fetch($url);
        if (is_wp_error($res)) {
            return array('ok' => false, 'posts' => array(), 'message' => $res->get_error_message());
        }
        if ((int) wp_remote_retrieve_response_code($res) !== 200) {
            return array('ok' => false, 'posts' => array(),
                         'message' => 'Телеграм ответил ' . wp_remote_retrieve_response_code($res));
        }
        $html = (string) wp_remote_retrieve_body($res);
        $channel = preg_quote(GS_Prompts::TG_CHANNEL, '~');

        // Режем по началу поста: внутри куска и картинка, и метки.
        $chunks = preg_split('~(?=data-post="' . $channel . '/\d+")~', $html);
        $posts = array();
        foreach ($chunks as $chunk) {
            if (!preg_match('~^data-post="' . $channel . '/(\d+)"~', $chunk, $m)) {
                continue;
            }
            $msg = (int) $m[1];
            if (!preg_match_all("~background-image:url\\('([^']+)'\\)~", $chunk, $img)) {
                continue;
            }
            $photo = '';
            foreach ($img[1] as $candidate) {
                if (strpos($candidate, 'telesco.pe') !== false) {
                    $photo = $candidate;
                    break;
                }
            }
            if ($photo === '') {
                continue;
            }
            $tags = array();
            if (preg_match_all('~\?q=%23([^"&]+)~', $chunk, $t)) {
                foreach ($t[1] as $raw) {
                    $tag = mb_strtolower(rawurldecode($raw), 'UTF-8');
                    if ($tag !== '') {
                        $tags[] = $tag;
                    }
                }
            }
            $posts[$msg] = array('msg' => $msg, 'photo' => $photo, 'tags' => array_unique($tags));
        }
        ksort($posts);
        return array('ok' => true, 'posts' => $posts, 'message' => '');
    }

    /* ---------------------------------------------------------------------
     * Разбор
     * ------------------------------------------------------------------ */

    public static function run($limit = 0) {
        $limit = $limit > 0 ? (int) $limit : self::BATCH;
        $read = self::read_channel();
        if (empty($read['ok'])) {
            return array('ok' => false, 'сообщение' => $read['message'], 'заведено' => 0);
        }
        $last = self::last_seen();
        $fresh = array();
        foreach ($read['posts'] as $msg => $post) {
            if ($msg > $last) {
                $fresh[] = $post;
            }
        }
        if (!$fresh) {
            return array('ok' => true, 'сообщение' => 'нового нет', 'заведено' => 0,
                         'последний' => $last);
        }

        $made = 0;
        $skipped = 0;
        $errors = array();
        $seen = $last;
        foreach (array_slice($fresh, 0, $limit) as $post) {
            $res = self::ingest($post);
            if (!empty($res['ok'])) {
                if (!empty($res['skipped'])) {
                    $skipped++;
                } else {
                    $made++;
                }
                // Пост засчитан в любом случае: отброшенный кадр второй
                // раз разбирать незачем, это те же деньги за тот же ответ.
                $seen = max($seen, (int) $post['msg']);
            } else {
                $errors[] = $post['msg'] . ': ' . (string) ($res['message'] ?? '');
                // На сбое останавливаемся: если поставщик отвечает
                // ошибкой, следующие посты ответят тем же, а метку
                // двигать нельзя — пост ещё не разобран.
                break;
            }
        }
        if ($seen > $last) {
            update_option(self::OPT_AT, $seen, false);
        }
        return array(
            'ok' => true, 'заведено' => $made, 'отброшено' => $skipped,
            'осталось' => max(0, count($fresh) - $limit),
            'последний' => $seen, 'ошибки' => $errors,
        );
    }

    /** Один пост: скачать кадр, описать, завести карточку. */
    public static function ingest($post) {
        $photo = (string) ($post['photo'] ?? '');
        $msg = (int) ($post['msg'] ?? 0);
        if ($photo === '' || $msg <= 0) {
            return array('ok' => false, 'message' => 'нет кадра');
        }
        $res = self::fetch($photo, 60);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return array('ok' => false, 'message' => 'кадр не скачался');
        }
        $bytes = (string) wp_remote_retrieve_body($res);
        if (strlen($bytes) < 2000 || !@getimagesizefromstring($bytes)) {
            return array('ok' => false, 'message' => 'кадр не разобрать');
        }

        $rubrics = array();
        $map = self::tag_map();
        foreach ((array) ($post['tags'] ?? array()) as $tag) {
            if (isset($map[$tag])) {
                $rubrics = array_merge($rubrics, $map[$tag]);
            }
        }
        $hint = (array) ($post['tags'] ?? array());

        return GS_Rest::prompts_ingest_bytes(
            $bytes,
            'tg-' . $msg . '.jpg',
            $hint ? implode(', ', $hint) : '',
            array_values(array_unique($rubrics)),
            $msg
        );
    }
}
