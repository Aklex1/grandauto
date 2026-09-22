<?php
/**
 * Черновик статьи по короткому заданию.
 *
 * Статьи про API пишутся не «о нейросетях вообще», а о нашем: с живыми
 * адресами, ценами и ответами. Модель этого знать не может, поэтому
 * справка о сервисе собирается здесь, из того же реестра операций, по
 * которому API и работает. Меняются цены — меняются и тексты в следующих
 * статьях, без правки заданий.
 *
 * Задание (что писать) остаётся снаружи: план статьи, её ключевая фраза и
 * угол — это работа человека, и подставлять их из головы модели значит
 * получить шестьдесят одинаковых обзоров.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Longread {

    /** Разумные границы: короче — не лонгрид, длиннее — не дочитают. */
    const MIN_CHARS = 4000;
    const MAX_CHARS = 22000;

    /**
     * Справка о нашем API для задания.
     *
     * Пишем именно фактами, а не рекламой: модель перескажет их своими
     * словами, и если факт неверен, ошибка разойдётся по всем статьям.
     */
    public static function facts() {
        $lines = array();
        $lines[] = 'Адрес API: ' . untrailingslashit(rest_url(GS_Api::NS)) . ' (например, POST '
            . untrailingslashit(rest_url(GS_Api::NS)) . '/generate).';
        $lines[] = 'Авторизация: заголовок Authorization: Bearer <ключ>. Ключ выдаётся в разделе '
            . GS_Api_Page::get_url() . ' после входа, показывается один раз.';
        $lines[] = 'Маршруты: GET /services — список операций и цен; GET /balance — остаток; '
            . 'POST /uploads — загрузка файла и получение ссылки; POST /generate — запуск операции; '
            . 'GET /tasks/{id} — состояние и результат. Плюс необязательный webhook: '
            . 'параметр callback_url, на него приходит POST, когда задача готова.';
        $lines[] = 'Совместимость с OpenAI: POST /chat/completions и GET /models работают в том же '
            . 'формате, что у OpenAI, — в клиентской библиотеке достаточно поменять base_url и ключ. '
            . 'Тариф на чат: ' . GS_OpenAI::price_hint() . '.';
        $lines[] = 'Оплата: за запуск, без абонплаты и без минимального платежа. '
            . 'При выпуске первого ключа на баланс начисляется ' . GS_Api_Keys::trial_amount()
            . ' ₽ на пробу.';
        $lines[] = 'Ограничение частоты: не больше ' . GS_Api::RATE_PER_MINUTE . ' запросов в минуту на ключ.';

        $ops = array();
        foreach (GS_Api::services() as $id => $service) {
            $price = GS_Api::price($id);
            $ops[] = sprintf('%s (%s) — %s ₽', $service['title'], $id,
                $price > 0 ? rtrim(rtrim(number_format($price, 2, ',', ' '), '0'), ',') : '0');
        }
        $lines[] = 'Операции и цены: ' . implode('; ', $ops) . '.';
        $lines[] = 'Цены указаны в рублях за один запуск; у озвучки — за 1000 знаков.';

        return implode("\n", $lines);
    }

    /**
     * @param array $brief slug, title, key, tails[], angle, outline[]
     * @return array{ok:bool,html:string,message:string,route:string}
     */
    public static function write(array $brief) {
        $out = array('ok' => false, 'html' => '', 'message' => '', 'route' => '');

        $key = trim((string) ($brief['key'] ?? ''));
        $title = trim((string) ($brief['title'] ?? ''));
        if ($key === '' || $title === '') {
            $out['message'] = 'нужны ключевая фраза и заголовок';
            return $out;
        }

        $res = GS_Provider::chat_messages(self::messages($brief), array(
            'temperature' => 0.8,
            'max_tokens'  => 7000,
            'timeout'     => 300,
        ));
        if (empty($res['ok'])) {
            $out['message'] = (string) $res['message'];
            $out['detail'] = (string) ($res['detail'] ?? '');
            return $out;
        }

        $html = self::clean((string) $res['content']);
        $len = mb_strlen(wp_strip_all_tags($html));
        if ($len < self::MIN_CHARS) {
            $out['message'] = sprintf('текст короткий: %d знаков', $len);
            return $out;
        }
        if ($len > self::MAX_CHARS) {
            $out['message'] = sprintf('текст длинный: %d знаков', $len);
            return $out;
        }
        if (stripos($html, 'kie') !== false || stripos($html, 'zvukogram') !== false) {
            $out['message'] = 'в тексте упомянут поставщик';
            return $out;
        }

        $out['ok'] = true;
        $out['html'] = $html;
        $out['chars'] = $len;
        $out['route'] = (string) ($res['route'] ?? '');
        return $out;
    }

    private static function messages($brief) {
        $key = (string) $brief['key'];
        $tails = array_filter(array_map('trim', (array) ($brief['tails'] ?? array())));
        $outline = array_filter(array_map('trim', (array) ($brief['outline'] ?? array())));
        $angle = trim((string) ($brief['angle'] ?? ''));
        $title = trim((string) ($brief['title'] ?? ''));

        // Голос зависит от читателя. Разработчику нужен разработчик с
        // числами и кодом; человеку, который пишет песню, такой голос
        // читается как чужая инструкция.
        $voice = (!array_key_exists('code', $brief) || !empty($brief['code']))
            ? 'Пишешь как разработчик, который сам подключал такие API: конкретно, с числами и '
              . 'примерами кода, '
            : 'Пишешь как практик, который сам это делал руками: конкретно, с примерами и '
              . 'числами, но без программного кода, ';
        $system = 'Ты пишешь статьи для блога сервиса нейросетей на русском языке. '
            . $voice
            . 'без «в современном мире», без «широкого спектра возможностей», '
            . 'без восклицаний и без обещаний. Не выдумываешь фактов о сервисе: всё, что можно '
            . 'сказать о нём, дано в справке ниже. Никогда не упоминаешь сторонние сервисы как '
            . 'поставщиков нашей работы. Отвечаешь готовым HTML без <html>, <head> и <h1>.';

        $user = "Ключевая фраза статьи: «{$key}».\n";
        if ($tails) {
            $user .= "Дополнительные формулировки, которые должны встретиться естественно: "
                . implode('; ', $tails) . ".\n";
        }
        $user .= "Заголовок статьи: {$title}\n";
        if ($angle !== '') {
            $user .= "О чём именно эта статья (её угол, не повторять соседние): {$angle}\n";
        }
        $user .= "\nСправка о нашем сервисе — только эти факты можно утверждать:\n" . self::facts() . "\n";

        $user .= "\nСтруктура: вступление на 2–3 абзаца без заголовка, дальше разделы <h2> по плану:\n";
        foreach ($outline as $i => $h2) {
            $user .= ($i + 1) . ') ' . $h2 . "\n";
        }
        // Куда вести читателя и нужен ли код — зависит от кластера. Для
        // статей про API это раздел API и примеры на curl; для статьи о
        // словах к песне примеры на curl выглядят как чужой текст, а вести
        // надо на сам сервис. Раньше и то, и другое было прибито гвоздями.
        $promo_url = trim((string) ($brief['promo_url'] ?? ''));
        if ($promo_url === '') {
            $promo_url = GS_Api_Page::get_url();
        }
        $promo_name = trim((string) ($brief['promo_name'] ?? 'раздел API'));
        $want_code = array_key_exists('code', $brief) ? !empty($brief['code']) : true;

        $user .= "\nТребования:\n"
            . "— объём 7000–11000 знаков, абзацы по 2–5 предложений;\n"
            . ($want_code
                ? "— хотя бы один рабочий пример запроса на curl и один на Python "
                  . "(requests или openai — смотря о чём статья), в <pre><code>…</code></pre>;\n"
                : "— никакого программного кода: читатель этой статьи не разработчик, "
                  . "примеры давай словами и разметкой текста, а не запросами;\n")
            . "— хотя бы одна таблица <table> с ценами или сравнением, если это уместно по теме;\n"
            . "— ключевая фраза в первом абзаце и ещё 2–4 раза по тексту, без повторов подряд;\n"
            . "— ссылка на " . $promo_name . ": <a href=\"" . $promo_url . "\">…</a> "
            . "в первом экране и ещё раз в конце, текст ссылки разный;\n"
            . "— в конце раздел <h2>Частые вопросы</h2> с 4–6 вопросами: вопрос в <p><strong>, "
            . "ответ отдельным <p>;\n"
            . "— никаких <h1>, никаких markdown-звёздочек, только HTML.\n";

        return array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        );
    }

    /**
     * Модель иногда оборачивает ответ в ```html, иногда добавляет <h1>.
     * Ни то, ни другое в запись класть нельзя: заголовок у записи свой.
     */
    private static function clean($html) {
        $html = trim($html);
        $html = preg_replace('~^```(?:html)?\s*|\s*```$~u', '', $html);
        // Модель иногда заворачивает ответ в собственную обёртку вида
        // «:::writing{...}» с закрывающим «:::». В запись это попасть не
        // должно ни в каком виде.
        $html = preg_replace('~^:::[a-z]*\{[^}]*\}\s*~iu', '', $html);
        $html = preg_replace('~^:::\s*$~mu', '', $html);
        $html = preg_replace('~\s*:::\s*$~u', '', $html);
        $html = preg_replace('~<h1[^>]*>.*?</h1>~isu', '', $html);
        $html = preg_replace('~\n{3,}~u', "\n\n", $html);
        return trim($html);
    }
}
