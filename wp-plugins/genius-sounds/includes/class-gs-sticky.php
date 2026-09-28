<?php
/**
 * Липкая панель со ссылкой на сервис — в статьях блога.
 *
 * Врезка в начале статьи уходит за верхний край экрана через два абзаца, а
 * блок в конце видят только дочитавшие. Панель решает обе беды: она
 * появляется, когда человек начал читать всерьёз, и дальше едет вместе с
 * ним.
 *
 * Куда вести, решает сама статья, и порядок правил тут важнее списка адресов:
 *
 * 1. Подарочный кластер — на посадочную «Песня в подарок».
 * 2. Статьи про заработок на нейросетях — на обучение, а не на музыку:
 *    человек, который читает «сколько платят за ИИ-видео», пришёл не
 *    сочинять песню, и кнопка «создать музыку» для него мимо.
 * 3. Всё остальное — на тот ресурс, на который в статье больше всего
 *    ссылок. Не на первый по порядку: почти в каждом лонгриде есть блок
 *    «что ещё пригодится», и по первой ссылке статья «сгенерировать песню
 *    по тексту» уводила в удаление вокала. Главный инструмент автор
 *    упоминает несколько раз, сопутствующие — один.
 * 4. Если ссылок одинаково много или их нет вовсе — по теме заголовка.
 *
 * Панель закрывается крестиком и после этого не показывается сутки: реклама,
 * которую нельзя убрать, раздражает сильнее, чем помогает.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Sticky {

    /** После скольких пикселей прокрутки показывать. */
    const AFTER_PX = 600;

    public static function boot() {
        // Раньше скриптов подвала: они печатаются на двадцатом приоритете, и
        // панель, выведенная после них, скрипту уже не видна — он ищет её в
        // момент разбора страницы и молча выходит.
        add_action('wp_footer', array(__CLASS__, 'render'), 5);
    }

    /** Нужна ли панель на этой странице. */
    public static function needed() {
        return !is_admin() && is_singular('post') && self::target() !== null;
    }

    /**
     * Куда ведём с этой статьи.
     *
     * @return array{url:string,title:string,text:string,cta:string}|null
     */
    public static function target() {
        $post = get_queried_object();
        if (!($post instanceof WP_Post) || $post->post_type !== 'post') {
            return null;
        }

        // Подарочный кластер: у его статей своя метка потока публикаций.
        if (class_exists('GS_Gift')
            && (string) get_post_meta($post->ID, '_gs_queue_lane', true) === GS_Gift::LANE) {
            return array(
                'url'   => GS_Gift::get_url(GS_Gift::root()),
                'title' => 'Песня в подарок',
                'text'  => 'Анкета про человека — текст сразу и бесплатно, песня через 10–20 минут. От '
                           . (int) GS_Gift::PRICE_SONG . ' ₽.',
                'cta'   => 'Заполнить анкету',
            );
        }

        // Юридические кластеры: статья ведёт на свою страницу блока, а не на
        // нейрохаб. Ссылка на неё в тексте уже стоит — берём её, иначе
        // посадочную кластера.
        $lane = (string) get_post_meta($post->ID, '_gs_queue_lane', true);
        if (class_exists('GS_Legal') && ($lane === GS_Legal::LANE_CLAIM || $lane === GS_Legal::LANE_ORDER)) {
            $legal = self::legal_card($lane, (string) $post->post_content);
            if ($legal !== null) {
                return $legal;
            }
        }

        $topic = mb_strtolower($post->post_title . ' ' . $post->post_name);

        // Тема заработка перебивает ссылки в тексте: такая статья почти всегда
        // упоминает по дороге и озвучку, и музыку, но человеку нужно не это.
        if (self::about_money($topic)) {
            return self::card('course');
        }

        // Раздел промтов: ведём не в пустой нейрохаб, а в нейрохаб с промтом
        // из этой самой статьи. Человек пришёл за конкретным кадром — пусть
        // получит его нажатием одной кнопки, а не копированием текста.
        $promt = self::promt_card($post);
        if ($promt !== null) {
            return $promt;
        }

        return self::card(self::choose($topic, (string) $post->post_content));
    }

    /**
     * Панель статьи с промтом: ссылка с уже подставленным промтом.
     *
     * Адрес берём из самой статьи — её кнопка «Создать такое же фото» уже
     * собрана при публикации, и промт в ней тот, к которому нарисован
     * пример. Пересобирать его из меты — значит рискнуть разойтись с
     * картинкой, если промтов в статье несколько.
     *
     * @return array{url:string,title:string,text:string,cta:string}|null
     */
    private static function promt_card($post) {
        $url = '';
        if (preg_match('~href="([^"]*/neurohub/\?p=[^"]+)"~', (string) $post->post_content, $m)) {
            $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        } else {
            $prompt = (string) get_post_meta($post->ID, '_gs_promt', true);
            if ($prompt !== '' && class_exists('GS_Promt')) {
                $url = GS_Promt::try_url($prompt);
            }
        }
        if ($url === '') {
            return null;
        }
        return array(
            'url'   => $url,
            'title' => 'Повторить это фото',
            'text'  => 'Промт из статьи уже подставлен — останется нажать «Создать».',
            'cta'   => 'Создать фото',
        );
    }

    /**
     * Карточка юридического блока: страница из текста или посадочная кластера.
     */
    private static function legal_card($lane, $content) {
        $root = $lane === GS_Legal::LANE_ORDER ? 'order' : 'claim';
        $best = null;
        $at = PHP_INT_MAX;

        foreach (GS_Legal::children($root) as $id => $child) {
            $pos = strpos($content, '/' . $child['slug'] . '/');
            if ($pos !== false && $pos < $at) {
                $at = $pos;
                $best = $id;
            }
        }
        $id = $best !== null ? $best : $root;
        $page = GS_Legal::page($id);
        if (!$page) {
            return null;
        }
        return array(
            'url'   => GS_Legal::get_url($id),
            'title' => (string) $page['menu'],
            'text'  => $lane === GS_Legal::LANE_ORDER
                ? 'Опишите ситуацию — соберём возражение и посчитаем срок. От '
                  . (int) GS_Legal::PRICE_BASE . ' ₽.'
                : 'Опишите ситуацию — соберём претензию со статьями и расчётом. От '
                  . (int) GS_Legal::PRICE_BASE . ' ₽.',
            'cta'   => 'Составить документ',
        );
    }

    /**
     * Статья про то, как на нейросетях заработать.
     *
     * Список намеренно короткий и однозначный. «Клиент», «заказчик» и
     * «продавать» сюда не годятся: с ними под заработок попадают примерка
     * дисков и цены на озвучку для покупателя, то есть ровно те статьи, где
     * нужна кнопка сервиса.
     */
    private static function about_money($topic) {
        $words = array(
            'заработ', 'зарабат', 'подработ', 'монетиз', 'фриланс', 'доход',
            'сколько платят', 'zarabot', 'zarabat', 'podrabot', 'monetiz',
            'frilans', 'dohod',
        );
        foreach ($words as $word) {
            if (mb_strpos($topic, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ключ ресурса для этой статьи: сначала по ссылкам, потом по теме.
     *
     * @return string ключ из self::candidates()
     */
    private static function choose($topic, $content) {
        $counts = array();
        $first = array();

        foreach (self::candidates() as $key => $path) {
            $needle = '/' . $path . '/';
            $n = substr_count($content, $needle);
            if ($n > 0) {
                $counts[$key] = $n;
                $first[$key] = strpos($content, $needle);
            }
        }

        if (!$counts) {
            return self::by_topic($topic);
        }

        $top = max($counts);
        $tied = array_keys($counts, $top, true);
        if (count($tied) === 1) {
            return $tied[0];
        }

        // Ничья: столько же ссылок у нескольких ресурсов — обычно это блок
        // «что ещё пригодится» в конце. Спрашиваем заголовок, и только если
        // он молчит, берём того, кто упомянут раньше.
        $want = self::by_topic($topic);
        if (in_array($want, $tied, true)) {
            return $want;
        }
        usort($tied, function ($a, $b) use ($first) {
            return $first[$a] - $first[$b];
        });
        return $tied[0];
    }

    /**
     * Все адреса, по которым узнаём ресурс в тексте: ключ => путь.
     *
     * Микросервисы берём из GS_Lab, чтобы новый сервис попадал в панель сам,
     * остальное — из таблицы страниц.
     */
    private static function candidates() {
        $out = array();
        if (class_exists('GS_Lab')) {
            foreach (GS_Lab::available_services() as $service) {
                $slug = isset($service['slug']) ? (string) $service['slug'] : '';
                $id = isset($service['id']) ? (string) $service['id'] : '';
                if ($slug !== '' && $id !== '') {
                    $out['svc:' . $id] = $slug;
                }
            }
        }
        foreach (self::places() as $key => $place) {
            $out[$key] = $place['path'];
        }
        return $out;
    }

    /**
     * Страницы вне GS_Lab: обучение, чужие кабинеты, каталог, посадочные.
     *
     * Адрес держим относительным, а не полной ссылкой: домен подставит
     * home_url, и на копии сайта панель не уведёт на живой.
     */
    private static function places() {
        return array(
            'course' => array(
                'path'  => class_exists('GS_Course') ? GS_Course::SLUG : 'obuchenie-zarabotku-na-neirosetyah',
                'title' => 'Заработок на нейросетях',
                'text'  => 'Обучение: какие услуги покупают, сколько они стоят и где брать первых клиентов.',
                'cta'   => 'Открыть обучение',
            ),
            'neurohub' => array(
                'path'  => 'neurohub',
                'title' => 'Нейрохаб',
                'text'  => 'Промты, картинки и текст в одном окне — без подписок и зарубежных карт.',
                'cta'   => 'Открыть нейрохаб',
            ),
            'tts' => array(
                'path'  => 'tts-dashboard',
                'title' => 'Озвучка текста',
                'text'  => 'Живые голоса с паузами и интонацией. Первые минуты — бесплатно.',
                'cta'   => 'Озвучить текст',
            ),
            'api' => array(
                'path'  => 'api',
                'title' => 'API для разработчиков',
                'text'  => 'Озвучка, музыка, видео и картинки одним ключом. Пробный баланс при выпуске.',
                'cta'   => 'Получить ключ',
            ),
            'showwheel' => array(
                'path'  => 'showwheel',
                'title' => 'Примерка дисков',
                'text'  => 'Загрузите фото машины — покажем, как на ней сядут выбранные диски.',
                'cta'   => 'Примерить диски',
            ),
            'sounds' => array(
                'path'  => 'sound-generator',
                'title' => 'Генератор звуков',
                'text'  => 'Опишите звук словами — получите готовый файл для монтажа.',
                'cta'   => 'Создать звук',
            ),
            'catalog' => array(
                'path'  => 'sounds-catalog',
                'title' => 'Каталог звуков',
                'text'  => 'Тысячи готовых звуков и шумов: послушать и скачать без регистрации.',
                'cta'   => 'Открыть каталог',
            ),
            'slides' => array(
                'path'  => 'sozdat-prezentaciyu',
                'title' => 'Презентация за минуту',
                'text'  => 'Тема и пара тезисов — готовые слайды с текстом и картинками.',
                'cta'   => 'Собрать презентацию',
            ),
            'assistant' => array(
                'path'  => 'ai-pomoshnik',
                'title' => 'ИИ-помощник',
                'text'  => 'Отвечает на вопросы, пишет тексты и разбирает документы.',
                'cta'   => 'Спросить',
            ),
            'photo' => array(
                'path'  => 'ozhivit-foto',
                'title' => 'Оживить фото',
                'text'  => 'Снимок превращается в короткое видео: взгляд, улыбка, поворот головы.',
                'cta'   => 'Оживить фото',
            ),
            'gift' => array(
                'path'  => 'pesnya-v-podarok',
                'title' => 'Песня в подарок',
                'text'  => 'Анкета про человека — текст сразу и бесплатно, песня через 10–20 минут.',
                'cta'   => 'Заполнить анкету',
            ),
        );
    }

    /**
     * Тема заголовка — на случай ничьей по ссылкам и статей без ссылок.
     *
     * Список идёт от частного к общему: «убрать вокал из песни» должно
     * попасть в удаление вокала, а не в генерацию музыки по слову «песня».
     */
    private static function by_topic($topic) {
        $map = array(
            'svc:vocal'    => array('убрать вокал', 'вокал из', 'минусовк', 'караоке'),
            'svc:denoise'  => array('убрать шум', 'шумоподавл', 'очистить звук', 'убрать эхо', 'шум с записи'),
            'svc:stt'      => array('транскриб', 'расшифров', 'аудио в текст', 'субтитр', 'стенограмм'),
            'svc:ytaudio'  => array('звук из видео', 'аудио из видео', 'youtube', 'ютуб'),
            'svc:dub'      => array('дубляж', 'перевести видео', 'перевод видео'),
            'svc:vupscale' => array('качество видео', 'апскейл', 'разрешение видео'),
            'svc:cover'    => array('обложка'),
            'svc:lyrics'   => array('текст песни', 'слова песни', 'стихи'),
            'svc:avatar'   => array('говорящий аватар', 'аватар', 'фото заговорил', 'фото в видео'),
            'photo'        => array('оживить фото', 'анимация фото'),
            'svc:voicesong' => array('своим голосом', 'свой голос', 'клон голоса', 'копия голоса'),
            // «Поёт», «спеть», «напеть» — тоже про генерацию: статья
            // «нейросеть, которая поёт» иначе уходила в первую ссылку текста.
            'svc:music'    => array('песн', 'музык', 'трек', 'саундтрек', 'мелоди', 'припев',
                                    'поёт', 'поет', 'спеть', 'напеть', 'споёт', 'споет'),
            'slides'       => array('презентац', 'слайд'),
            'showwheel'    => array('диск', 'колёс', 'колес', 'шина'),
            'assistant'    => array('чат-бот', 'ии-помощник', 'ассистент'),
            'api'          => array('api', 'интеграц', 'телеграм-бот', 'бота', 'вебхук'),
            'tts'          => array('озвуч', 'голос', 'диктор', 'аудиокниг', 'подкаст'),
            'sounds'       => array('звук', 'шум', 'sfx'),
        );
        $known = self::candidates();
        foreach ($map as $key => $words) {
            if (!isset($known[$key])) {
                continue;   // сервис отключён — не предлагаем его
            }
            foreach ($words as $word) {
                if (mb_strpos($topic, $word) !== false) {
                    return $key;
                }
            }
        }
        // Промты, картинки, видеогенераторы и прочие «как сделать в
        // нейросети» — всё это делается в нейрохабе.
        return 'neurohub';
    }

    /**
     * Карточка панели по ключу ресурса.
     *
     * @return array{url:string,title:string,text:string,cta:string}
     */
    private static function card($key) {
        if (strpos($key, 'svc:') === 0 && class_exists('GS_Lab')) {
            $id = substr($key, 4);
            foreach (GS_Lab::available_services() as $service) {
                if ((string) ($service['id'] ?? '') === $id) {
                    $lead = (string) ($service['lead'] ?? '');
                    if (mb_strlen($lead) > 120) {
                        $lead = rtrim(mb_substr($lead, 0, 117), " ,.;:—-") . '…';
                    }
                    return array(
                        'url'   => GS_Lab::get_url($id),
                        'title' => (string) ($service['menu'] ?? 'Инструмент'),
                        'text'  => $lead,
                        'cta'   => 'Открыть',
                    );
                }
            }
            $key = 'neurohub';
        }

        $places = self::places();
        if (!isset($places[$key])) {
            $key = 'neurohub';
        }
        $place = $places[$key];

        if ($key === 'course' && class_exists('GS_Course')) {
            $url = GS_Course::get_url();
        } elseif ($key === 'gift' && class_exists('GS_Gift')) {
            $url = GS_Gift::get_url(GS_Gift::root());
        } else {
            $url = home_url('/' . $place['path'] . '/');
        }
        return array(
            'url'   => $url,
            'title' => $place['title'],
            'text'  => $place['text'],
            'cta'   => $place['cta'],
        );
    }

    public static function render() {
        if (!self::needed()) {
            return;
        }
        $target = self::target();
        $key = 'gs-sticky-' . sanitize_title($target['title']);
        ?>
        <aside class="gs-sticky" id="gs-sticky" data-gs-sticky-key="<?php echo esc_attr($key); ?>"
               data-gs-sticky-after="<?php echo (int) self::AFTER_PX; ?>" hidden>
            <div class="gs-sticky__in">
                <div class="gs-sticky__text">
                    <strong><?php echo esc_html($target['title']); ?></strong>
                    <span><?php echo esc_html($target['text']); ?></span>
                </div>
                <a class="gs-sticky__go" href="<?php echo esc_url($target['url']); ?>">
                    <?php echo esc_html($target['cta']); ?>
                </a>
                <button type="button" class="gs-sticky__x" aria-label="Скрыть">&times;</button>
            </div>
        </aside>
        <?php
    }
}
