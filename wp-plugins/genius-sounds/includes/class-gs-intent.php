<?php
/**
 * Речь или звук: куда на самом деле шёл человек.
 *
 * В студию звуков регулярно приходят с описанием реплики — «голос девушки
 * томно говорит "…"». Suno такое соберёт как звуковой эффект: получится
 * бормотание на выдуманном языке, деньги спишутся, человек уйдёт. Озвучка
 * текста живёт в другом сервисе, и разводить эти два пути нужно до оплаты,
 * а не после.
 *
 * Класс отвечает на один вопрос — похоже ли описание на речь — и рисует
 * блок перехода в озвучку. Пользуются им и каталог, и студия.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Intent {

    /**
     * Приметы, которые сами по себе решают дело: так звук не описывают.
     */
    private static function strong() {
        return array(
            'закадровый голос', 'закадровым голосом', 'голос за кадром',
            'озвучить текст', 'озвучка текста', 'текст для озвучки',
            'озвучь текст', 'voiceover', 'voice over', 'войсовер',
        );
    }

    /**
     * Глаголы речи. Встретился один — это уже сильный признак: звук
     * описывают существительными и прилагательными, а не «скажи».
     */
    private static function verbs() {
        return array(
            'скажи', 'сказал', 'сказать', 'говорит', 'говорить', 'говорят',
            'произнес', 'произнёс', 'произнос', 'проговор', 'озвуч', 'наговор',
            'прочита', 'прочти', 'зачита', 'читает', 'читай', 'читать',
            'приветствует', 'представляется',
            ' say ', ' says ', 'speak', 'narrat',
        );
    }

    /**
     * Слова про голос. Сами по себе не приговор: «гул голосов в кафе» и
     * «голоса птиц» — законные звуки, поэтому вес у них меньше, а суммарный
     * вклад ограничен двумя баллами: без глагола речи этого не хватит.
     */
    private static function nouns() {
        return array(
            'голос', 'диктор', 'озвучк', 'речь', 'реплик', 'монолог', 'диалог',
            'интонац', 'тембр', 'фраз', 'текст', 'закадров',
            'voice', 'tts',
        );
    }

    /**
     * Прямая речь в кавычках: то, что человек хочет услышать дословно.
     *
     * @return string содержимое первой кавычки или ''
     */
    public static function quoted($text) {
        $text = (string) $text;
        if (preg_match('~[«"“„\']\s*([^«»"“”„\']{6,})\s*[»"”“\']~u', $text, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * Оценка «это речь» в баллах. Порог — 3.
     */
    public static function score($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return 0;
        }
        $low = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $score = 0;

        foreach (self::strong() as $needle) {
            if (strpos($low, $needle) !== false) {
                return 3;
            }
        }

        $quote = self::quoted($text);
        if ($quote !== '') {
            // Длинная цитата — почти наверняка текст под озвучку.
            $len = function_exists('mb_strlen') ? mb_strlen($quote, 'UTF-8') : strlen($quote);
            $score += $len >= 25 ? 3 : 2;
        }

        foreach (self::verbs() as $needle) {
            if (strpos($low, $needle) !== false) {
                $score += 2;
                break;
            }
        }

        $nouns = 0;
        foreach (self::nouns() as $needle) {
            if (strpos($low, $needle) !== false) {
                $nouns++;
                if ($nouns >= 2) {
                    break;
                }
            }
        }
        $score += $nouns;

        return $score;
    }

    public static function looks_like_speech($text) {
        return self::score($text) >= 3;
    }

    /**
     * Текст, который имеет смысл перенести в озвучку: если человек написал
     * реплику в кавычках — берём её, иначе описание целиком (пусть правит
     * на месте, это всё равно ближе к результату, чем пустое поле).
     */
    public static function speech_text($text) {
        $quote = self::quoted($text);
        return $quote !== '' ? $quote : trim((string) $text);
    }

    /**
     * Блок «вам нужна озвучка». Рисуется скрытым, если признаков речи нет:
     * скрипт студии показывает его прямо во время набора.
     *
     * @param string $text    описание, которое сейчас в поле
     * @param string $context 'studio' | 'catalog' | 'search'
     */
    public static function render_switch($text = '', $context = 'studio', $force = null) {
        $text = (string) $text;
        $show = $force === null ? self::looks_like_speech($text) : (bool) $force;
        $url  = GS_Pages::get_tts_url(self::speech_text($text));

        $titles = array(
            'studio'  => 'Похоже, вам нужна не звуковая дорожка, а голос',
            'catalog' => 'Ищете голос, а не звук?',
            'search'  => 'Похоже, вы ищете голос, а не звук',
        );
        $title = isset($titles[$context]) ? $titles[$context] : $titles['studio'];

        ob_start();
        ?>
        <div class="gs-switch" id="gs-speech-switch" data-gs-switch<?php echo $show ? '' : ' hidden'; ?>>
            <span class="gs-switch__icon" aria-hidden="true">🗣️</span>
            <div class="gs-switch__body">
                <strong class="gs-switch__title"><?php echo esc_html($title); ?></strong>
                <p class="gs-switch__text">
                    Генератор звуков делает шумы и эффекты: дождь, шаги, взрыв, сигнал интерфейса.
                    Слова он не выговаривает — вместо реплики получится бормотание.
                    Чтобы текст произнесли человеческим голосом, откройте <strong>озвучку текста</strong>:
                    там 60+ голосов, русский и ещё 30 языков, выбор скорости и интонации.
                </p>
            </div>
            <a class="gs-btn gs-btn--primary gs-switch__btn" href="<?php echo esc_url($url); ?>" data-gs-switch-link>
                Перейти в озвучку текста
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Две дорожки одной строкой: куда идти за звуком, а куда за голосом.
     * Стоит на видном месте и в каталоге, и в студии.
     */
    public static function render_router($active = 'sfx') {
        $sfx = GS_Pages::get_studio_url();
        $tts = GS_Pages::get_tts_url();

        ob_start();
        ?>
        <div class="gs-router" role="group" aria-label="Что нужно создать">
            <a class="gs-router__lane<?php echo $active === 'sfx' ? ' is-active' : ''; ?>" href="<?php echo esc_url($sfx); ?>">
                <span class="gs-router__icon" aria-hidden="true">🎛️</span>
                <span class="gs-router__body">
                    <span class="gs-router__title">Звук и эффекты</span>
                    <span class="gs-router__text">Дождь, шаги, взрыв, сигнал, атмосфера — по описанию</span>
                </span>
                <span class="gs-router__mark"><?php echo $active === 'sfx' ? 'вы здесь' : 'открыть'; ?></span>
            </a>
            <a class="gs-router__lane<?php echo $active === 'tts' ? ' is-active' : ''; ?>" href="<?php echo esc_url($tts); ?>">
                <span class="gs-router__icon" aria-hidden="true">🗣️</span>
                <span class="gs-router__body">
                    <span class="gs-router__title">Голос и речь</span>
                    <span class="gs-router__text">Текст произносит живой голос — 60+ голосов, 30 языков</span>
                </span>
                <span class="gs-router__mark"><?php echo $active === 'tts' ? 'вы здесь' : 'открыть'; ?></span>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }
}
