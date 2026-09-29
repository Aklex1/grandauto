<?php
/**
 * Документ по описанию ситуации: бесплатный разбор, оплата, готовый файл.
 *
 * Порядок тот же, что в подарочных песнях, и по той же причине: человек из
 * рекламы не станет регистрироваться и пополнять баланс, чтобы «посмотреть,
 * что выйдет». Поэтому сначала он бесплатно видит разбор своей ситуации —
 * кому адресовать, что требовать, на что ссылаться и чего в его рассказе не
 * хватает, — и только потом платит за сам документ.
 *
 *   заявка → разбор (бесплатно) → оплата через ЮMoney → документ в Word
 *
 * Оплата — той же ссылкой, что и в остальных сервисах сайта: метка заказа
 * legal_<id>, приёмник уведомлений разбирает её и зовёт сюда paid().
 *
 * Документ собирается по нормам, перечисленным в задании модели, и только по
 * фактам из рассказа: недостающее остаётся полем в квадратных скобках. Это не
 * перестраховка — документ с выдуманной датой покупки хуже, чем документ с
 * пропуском, потому что пропуск человек заметит, а выдумку нет.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Legal_Doc {

    const ORDER_PREFIX = 'gs_legal_order_';
    const LABEL_PREFIX = 'legal_';

    /** Сколько разборов в сутки с одного адреса: модель стоит денег. */
    const FREE_PER_DAY = 5;
    const FREE_KEY = 'gs_legal_free_';

    /** Сколько живёт заказ: дальше документ нужно запрашивать заново. */
    const TTL = 2592000;   // 30 дней

    /* ---------------------------------------------------------------------
     * Заказы
     * ------------------------------------------------------------------ */

    public static function order($id) {
        $id = preg_replace('~[^A-Za-z0-9]~', '', (string) $id);
        if ($id === '') {
            return null;
        }
        $row = get_option(self::ORDER_PREFIX . $id, null);
        return is_array($row) ? $row : null;
    }

    private static function save($id, $row) {
        update_option(self::ORDER_PREFIX . preg_replace('~[^A-Za-z0-9]~', '', (string) $id), $row, false);
    }

    private static function allow_free() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0';
        return (int) get_transient(self::FREE_KEY . md5($ip)) < self::FREE_PER_DAY;
    }

    private static function note_free() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0';
        $key = self::FREE_KEY . md5($ip);
        set_transient($key, (int) get_transient($key) + 1, DAY_IN_SECONDS);
    }

    /* ---------------------------------------------------------------------
     * Шаг 1: бесплатный разбор ситуации
     * ------------------------------------------------------------------ */

    public static function start($fields) {
        $out = array('ok' => false, 'order' => '', 'review' => '', 'message' => '');

        $story = trim((string) ($fields['story'] ?? ''));
        if (mb_strlen($story) < 30) {
            $out['message'] = 'Опишите ситуацию подробнее: что произошло, когда и чего вы хотите.';
            return $out;
        }
        if (!self::allow_free()) {
            $out['message'] = 'Сегодня с этого адреса уже было пять разборов. '
                . 'Попробуйте завтра или напишите нам в поддержку.';
            return $out;
        }

        $page = sanitize_title((string) ($fields['page'] ?? 'claim'));
        // Приводим к идентификатору дерева: снаружи приходит и слаг тоже, а
        // от страницы зависит, какой набор документов человек получит.
        $resolved = class_exists('GS_Legal') ? GS_Legal::resolve($page) : '';
        $page = $resolved !== '' ? $resolved : 'claim';
        $kind = self::kind_of($page);

        $res = GS_Provider::chat(self::review_system($kind), self::review_task($fields, $page), array(
            'temperature' => 0.3,
            'max_tokens'  => 1400,
            'timeout'     => 150,
        ));
        if (empty($res['ok'])) {
            $out['message'] = 'Не получилось разобрать ситуацию: ' . (string) $res['message'];
            return $out;
        }

        $review = self::clean_html((string) $res['content']);
        if (mb_strlen(wp_strip_all_tags($review)) < 200) {
            $out['message'] = 'Разбор вышел слишком коротким — добавьте деталей в описание.';
            return $out;
        }

        $order = wp_generate_password(16, false, false);
        self::save($order, array(
            'created' => time(),
            'status'  => 'review',
            'kind'    => $kind,
            'page'    => $page,
            'story'   => mb_substr($story, 0, 6000),
            'contact' => mb_substr(trim((string) ($fields['contact'] ?? '')), 0, 160),
            'plan'    => (int) ($fields['plan'] ?? GS_Legal::PRICE_BASE) === GS_Legal::PRICE_FULL
                         ? GS_Legal::PRICE_FULL : GS_Legal::PRICE_BASE,
            'review'  => $review,
            'parts'   => array(),
            'tries'   => 0,
        ));
        self::note_free();

        $out['ok'] = true;
        $out['order'] = $order;
        $out['review'] = $review;
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Шаг 2: оплата
     * ------------------------------------------------------------------ */

    public static function pay_link($id, $plan) {
        $row = self::order($id);
        if (!$row) {
            return '';
        }
        $price = (int) $plan === GS_Legal::PRICE_FULL ? GS_Legal::PRICE_FULL : GS_Legal::PRICE_BASE;
        $row['plan'] = $price;
        self::save($id, $row);

        $label = self::LABEL_PREFIX . preg_replace('~[^A-Za-z0-9]~', '', (string) $id);
        $receiver = class_exists('KIE_TTS_Payment')
            ? KIE_TTS_Payment::get_yoomoney_receiver()
            : (string) get_option('kie_tts_yoomoney_receiver', '');
        if ($receiver === '') {
            return '';
        }
        $success = class_exists('KIE_TTS_Payment')
            ? KIE_TTS_Payment::get_yoomoney_success_url()
            : home_url('/success');

        // Ссылку собираем сами — ровно теми же параметрами, что и остальные
        // платежи сайта, но с человеческим назначением. У готового
        // построителя назначение прибито как «TTS Balance Topup», и человек,
        // который платит за претензию, видел бы на странице оплаты его.
        // Уведомление приходит на адрес из настроек кошелька, а не из ссылки,
        // поэтому приёмник остаётся тот же.
        return add_query_arg(array(
            'receiver'      => $receiver,
            'quickpay-form' => 'shop',
            'targets'       => self::payment_target($row, $price, $id),
            'paymentType'   => 'AC',
            'sum'           => number_format((float) $price, 2, '.', ''),
            'label'         => $label,
            'successURL'    => urlencode(add_query_arg('order', $id, GS_Legal::get_url(
                GS_Legal::resolve($row['page']) !== '' ? $row['page'] : 'claim'
            ))),
        ), 'https://yoomoney.ru/quickpay/confirm.xml');
    }

    /**
     * Назначение платежа: человек видит его на странице оплаты.
     *
     * Номер заказа в назначении нужен поддержке: по нему платёж находится и в
     * истории кошелька, и в журнале уведомлений.
     */
    private static function payment_target($row, $price, $id) {
        $full = (int) $price >= GS_Legal::PRICE_FULL;
        if (($row['kind'] ?? '') === 'order') {
            $what = $full
                ? 'Возражение на судебный приказ и поворот исполнения'
                : 'Возражение на судебный приказ';
        } else {
            $what = $full
                ? 'Претензия, жалоба в надзор и черновик иска'
                : 'Подготовка претензии';
        }
        return $what . ' (заказ ' . preg_replace('~[^A-Za-z0-9]~', '', (string) $id) . ')';
    }

    /**
     * Деньги пришли — собираем документ.
     *
     * Зовётся из приёмника уведомлений ЮMoney по метке заказа. Сборку не
     * ждём молча: если модель в этот момент не ответила, следующий опрос
     * состояния повторит попытку, а заказ не зависнет оплаченным и пустым.
     */
    public static function paid($label) {
        $id = substr((string) $label, strlen(self::LABEL_PREFIX));
        $row = self::order($id);
        if (!$row) {
            return array('ok' => false, 'message' => 'заказ не найден');
        }
        if (in_array(($row['status'] ?? ''), array('writing', 'done'), true)) {
            return array('ok' => true, 'message' => 'уже в работе');
        }
        $row['status'] = 'paid';
        $row['paid_at'] = time();
        self::save($id, $row);

        $made = self::make($id);
        return array('ok' => !empty($made['ok']), 'message' => (string) ($made['message'] ?? ''));
    }

    /* ---------------------------------------------------------------------
     * Шаг 3: сам документ
     * ------------------------------------------------------------------ */

    /**
     * Собрать документы заказа.
     *
     * В базовом тарифе один документ, в полном — ещё два: надзор и черновик
     * иска для претензий, поворот исполнения и заявление приставам для
     * судебного приказа. Каждый документ — отдельное обращение к модели:
     * одним запросом на три документа модель неизбежно смешивает адресатов.
     */
    public static function make($id) {
        $row = self::order($id);
        if (!$row) {
            return array('ok' => false, 'message' => 'заказ не найден');
        }
        if (($row['status'] ?? '') === 'done' && !empty($row['parts'])) {
            return array('ok' => true, 'message' => 'готово');
        }
        if ((int) ($row['tries'] ?? 0) >= 3) {
            return array('ok' => false, 'message' => 'не удалось собрать документ, нужен разбор вручную');
        }

        $row['status'] = 'writing';
        $row['tries'] = (int) ($row['tries'] ?? 0) + 1;
        self::save($id, $row);

        $parts = array();
        foreach (self::plan_parts($row) as $part) {
            $res = GS_Provider::chat(
                self::doc_system($row['kind'], $part['type']),
                self::doc_task($row, $part),
                array('temperature' => 0.2, 'max_tokens' => 4000, 'timeout' => 300)
            );
            if (empty($res['ok'])) {
                $row['status'] = 'paid';
                $row['error'] = (string) $res['message'];
                self::save($id, $row);
                return array('ok' => false, 'message' => (string) $res['message']);
            }
            $text = self::clean_html((string) $res['content']);
            if (mb_strlen(wp_strip_all_tags($text)) < 600) {
                $row['status'] = 'paid';
                $row['error'] = 'документ вышел слишком коротким';
                self::save($id, $row);
                return array('ok' => false, 'message' => 'документ вышел слишком коротким');
            }
            $parts[] = array('title' => $part['title'], 'html' => $text);
        }

        $row['parts'] = $parts;
        $row['status'] = 'done';
        $row['done_at'] = time();
        $row['error'] = '';
        self::save($id, $row);

        self::notify($id, $row);
        return array('ok' => true, 'message' => '', 'parts' => count($parts));
    }

    /** Что входит в заказ по выбранному тарифу. */
    private static function plan_parts($row) {
        $full = (int) ($row['plan'] ?? 0) === GS_Legal::PRICE_FULL;
        if (($row['kind'] ?? '') === 'order') {
            $parts = array(array('type' => 'objection', 'title' => 'Возражение на судебный приказ'));
            if ($full) {
                $parts[] = array('type' => 'turnaround', 'title' => 'Заявление о повороте исполнения');
                $parts[] = array('type' => 'bailiff', 'title' => 'Заявление судебному приставу');
            }
            return $parts;
        }
        $first = ($row['page'] ?? '') === 'zhaloba-na-upravlyayushchuyu-kompaniyu'
            ? array('type' => 'complaint', 'title' => 'Жалоба в управляющую организацию')
            : array('type' => 'claim', 'title' => 'Претензия');
        $parts = array($first);
        if ($full) {
            $parts[] = array('type' => 'supervision', 'title' => 'Жалоба в надзорный орган');
            $parts[] = array('type' => 'lawsuit', 'title' => 'Черновик искового заявления');
        }
        return $parts;
    }

    /* ---------------------------------------------------------------------
     * Состояние и выдача
     * ------------------------------------------------------------------ */

    public static function state($id) {
        $row = self::order($id);
        if (!$row) {
            return array('status' => 'none');
        }
        // Оплачен, но документа нет: значит в момент оплаты модель молчала.
        // Повторяем здесь, а не ждём вмешательства человека.
        if (($row['status'] ?? '') === 'paid') {
            self::make($id);
            $row = self::order($id);
        }

        $files = array();
        foreach ((array) ($row['parts'] ?? array()) as $index => $part) {
            $files[] = array(
                'title' => (string) $part['title'],
                'html'  => (string) $part['html'],
                'doc'   => add_query_arg(
                    array('order' => $id, 'part' => $index),
                    rest_url(GS_Rest::NS . '/legal/doc')
                ),
            );
        }

        return array(
            'status'  => (string) ($row['status'] ?? ''),
            'review'  => (string) ($row['review'] ?? ''),
            'plan'    => (int) ($row['plan'] ?? 0),
            'parts'   => $files,
            'message' => (string) ($row['error'] ?? ''),
        );
    }

    /**
     * Файл документа для скачивания.
     *
     * Отдаём .doc в виде HTML: Word и Google Документы такой файл открывают
     * и дают править, а собирать настоящий DOCX на сервере — значит тащить
     * библиотеку ради одного файла. PDF человек делает печатью из документа,
     * и об этом на странице написано прямо.
     */
    public static function serve_doc($id, $index) {
        $row = self::order($id);
        $parts = (array) ($row['parts'] ?? array());
        if (!$row || !isset($parts[$index])) {
            status_header(404);
            exit('Документ не найден');
        }
        $part = $parts[$index];
        $name = sanitize_title($part['title']) . '.doc';

        nocache_headers();
        header('Content-Type: application/msword; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        echo "<html xmlns:o='urn:schemas-microsoft-com:office:office' "
            . "xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'>";
        echo '<head><meta charset="utf-8"><title>' . esc_html($part['title']) . '</title>';
        echo '<style>body{font-family:"Times New Roman",serif;font-size:14pt;line-height:1.5}'
            . 'h1,h2{font-size:14pt;text-align:center}p{margin:0 0 10pt;text-align:justify}</style>';
        echo '</head><body>' . $part['html'] . '</body></html>';
        exit;
    }

    /** Уведомление о готовом заказе: в Telegram и на почту, если она указана. */
    private static function notify($id, $row) {
        $contact = (string) ($row['contact'] ?? '');
        $titles = array();
        foreach ((array) $row['parts'] as $part) {
            $titles[] = (string) $part['title'];
        }
        $link = add_query_arg('order', $id, GS_Legal::get_url(
            GS_Legal::page($row['page']) ? $row['page'] : ($row['kind'] === 'order' ? 'order' : 'claim')
        ));

        if (class_exists('GS_Leads')) {
            GS_Leads::accept(array(
                'name'    => 'Заказ ' . $id,
                'contact' => $contact !== '' ? $contact : 'контакт не указан',
                'comment' => 'Документ готов (' . (int) $row['plan'] . ' ₽): ' . implode(', ', $titles)
                             . '. Ссылка: ' . $link,
                'source'  => 'Юрдокументы: оплачено',
            ));
        }

        if (is_email($contact)) {
            $subject = ($row['kind'] ?? '') === 'order'
                ? 'Ваше возражение на судебный приказ готово'
                : 'Ваша претензия готова';
            $body = '<p>Документ собран по вашему описанию. Откройте страницу заказа, '
                . 'проверьте даты и реквизиты, скачайте файл в Word:</p>'
                . '<p><a href="' . esc_url($link) . '">' . esc_html($link) . '</a></p>'
                . '<p>В документе могут остаться поля в квадратных скобках — это то, '
                . 'чего не было в описании: адрес, номер чека, реквизиты счёта. '
                . 'Их нужно заполнить перед отправкой.</p>';
            wp_mail($contact, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
        }
    }

    /* ---------------------------------------------------------------------
     * Задания модели
     * ------------------------------------------------------------------ */

    private static function kind_of($page) {
        $root = class_exists('GS_Legal') ? GS_Legal::root_of($page) : '';
        return $root === 'order' ? 'order' : 'claim';
    }

    /** Общие правила: на них держится вся достоверность документов. */
    private static function rules() {
        return "Правила, обязательные для любого документа:\n"
            . "1. Используй только факты из описания. Не выдумывай даты, суммы, номера чеков и "
            . "договоров, названия и адреса организаций — вместо недостающего ставь поле в "
            . "квадратных скобках: [дата покупки], [адрес продавца], [номер дела].\n"
            . "2. Ссылайся только на нормы, которые точно применимы к описанной ситуации. Если "
            . "не уверен в номере статьи — не указывай номер, напиши общую формулировку.\n"
            . "3. Не давай гарантий результата и не обещай сроков рассмотрения, которых нет в законе.\n"
            . "4. Пиши официально-деловым стилем от первого лица заявителя, без эмоций и без воды.\n"
            . "5. Отвечай готовым HTML: абзацы <p>, заголовок документа <h2>, списки <ol>/<ul>. "
            . "Без <html>, <head>, <h1> и без markdown.\n"
            . "6. Не обсуждай задание и не предлагай вместо него другой документ: составь "
            . "именно тот, который заказан. Если каких-то сведений нет — оставь поле в "
            . "квадратных скобках, но документ доведи до конца.\n";
    }

    private static function review_system($kind) {
        $what = $kind === 'order'
            ? "возражения на судебный приказ и сопутствующих заявлений"
            : "претензии или жалобы";
        return "Ты юридический помощник сервиса, который готовит проекты {$what} для граждан РФ.\n"
            . "Сейчас твоя задача — БЕСПЛАТНЫЙ РАЗБОР ситуации, а не документ. Разбор должен "
            . "показать человеку, что мы поняли его случай, и чего в описании не хватает.\n\n"
            . "Структура разбора (HTML, без заголовка первого уровня):\n"
            . "<p><strong>Кому адресуем.</strong> …</p>\n"
            . "<p><strong>Что потребуем.</strong> … — конкретные требования списком <ol>.</p>\n"
            . "<p><strong>На что ссылаемся.</strong> Нормы, применимые к этой ситуации.</p>\n"
            . "<p><strong>Срок.</strong> Срок ответа или процессуальный срок с оговоркой, откуда он считается.</p>\n"
            . "<p><strong>Чего не хватает в описании.</strong> Список <ul> из двух-пяти пунктов: "
            . "что человеку нужно будет подставить в документ.</p>\n\n"
            . "Объём — 1200–2500 знаков. Сам документ не пиши: его человек получает после оплаты. "
            . "Не обещай исхода дела.\n" . self::rules();
    }

    private static function review_task($fields, $page) {
        $title = class_exists('GS_Legal') && GS_Legal::page($page)
            ? GS_Legal::page($page)['h1'] : '';
        $task = "Раздел сайта, откуда пришёл человек: {$title}\n";
        $task .= "Описание ситуации: " . trim((string) ($fields['story'] ?? '')) . "\n";
        $task .= 'Выбранный тариф: ' . (int) ($fields['plan'] ?? GS_Legal::PRICE_BASE) . " ₽\n";
        $task .= 'Сегодня: ' . date_i18n('d.m.Y') . "\n";
        return $task;
    }

    private static function doc_system($kind, $type) {
        $head = "Ты юридический помощник сервиса, который готовит проекты документов для граждан РФ.\n";

        if ($kind === 'order') {
            $norms = "Нормы по судебному приказу:\n"
                . "— возражение относительно исполнения: ст. 128–129 ГПК РФ (по налоговым — ст. 123.7 КАС РФ);\n"
                . "— восстановление процессуального срока: ст. 112 ГПК РФ, постановление Пленума ВС РФ № 62;\n"
                . "— поворот исполнения: ст. 443–444 ГПК РФ;\n"
                . "— прекращение исполнительного производства: ст. 43 закона № 229-ФЗ;\n"
                . "— сохранение прожиточного минимума: ст. 446 ГПК РФ и ст. 69 закона № 229-ФЗ.\n";
            $bodies = array(
                'objection' => "Составь ВОЗРАЖЕНИЕ относительно исполнения судебного приказа. "
                    . "Мотивировать несогласие с долгом не требуется — достаточно заявить несогласие. "
                    . "Если из описания видно, что десятидневный срок пропущен, добавь в тот же "
                    . "документ отдельным разделом ходатайство о восстановлении срока с указанием "
                    . "причины из описания и перечнем подтверждающих документов.",
                'turnaround' => "Составь ЗАЯВЛЕНИЕ О ПОВОРОТЕ ИСПОЛНЕНИЯ судебного приказа: "
                    . "требование вернуть взысканное после отмены приказа, с указанием сумм из "
                    . "описания и просьбой выдать исполнительный лист.",
                'bailiff' => "Составь ЗАЯВЛЕНИЕ СУДЕБНОМУ ПРИСТАВУ-ИСПОЛНИТЕЛЮ: прекратить "
                    . "исполнительное производство в связи с отменой судебного приказа, снять "
                    . "аресты со счетов и вернуть удержанное.",
            );
            $body = $bodies[$type] ?? $bodies['objection'];
            $structure = "Структура: шапка (в какой суд или кому, от кого — ФИО, адрес, телефон), "
                . "заголовок документа, ссылка на дело и приказ, существо обращения, "
                . "«На основании изложенного ПРОШУ:» нумерованным списком, приложения, дата, подпись.";
        } else {
            $norms = "Нормы по потребительским спорам:\n"
                . "— Закон РФ «О защите прав потребителей»: ст. 4, 13 (штраф 50%), 15 (моральный вред), "
                . "18 (недостатки товара), 20–22 (сроки), 23 (неустойка 1% в день), 25 (обмен товара "
                . "надлежащего качества), 26.1 (дистанционная продажа), 27–29 (услуги), "
                . "28 п. 5 (неустойка 3% в день), 32 (отказ от услуги), 35 (утрата вещи);\n"
                . "— ГК РФ: ст. 309, 310, 393, 450.1, 782, 1064;\n"
                . "— авиа: Воздушный кодекс ст. 119–120; УК и ЖКХ: ЖК РФ ст. 161–162, "
                . "постановления Правительства № 491, 354, 416;\n"
                . "— банки и страхование: закон № 353-ФЗ, закон о финансовом уполномоченном; "
                . "застройщики: закон № 214-ФЗ ст. 6–7.\n";
            $bodies = array(
                'claim' => "Составь ПРЕТЕНЗИЮ. Если в описании есть сумма и дата — посчитай "
                    . "неустойку с указанием периода и оговоркой «на дату составления, начисление "
                    . "продолжается». Требования формулируй конкретно: сумма, способ и срок "
                    . "исполнения. Предупреди о намерении обратиться в суд с требованием неустойки, "
                    . "штрафа 50% и компенсации морального вреда — только если спор потребительский.",
                'complaint' => "Составь ЖАЛОБУ в управляющую организацию: требования устранить "
                    . "нарушения, произвести перерасчёт и дать письменный ответ в установленный "
                    . "законодательством срок. Ссылайся на ЖК РФ и применимые пункты правил "
                    . "№ 491, 354 и 416.",
                'supervision' => "Составь ЖАЛОБУ В НАДЗОРНЫЙ ОРГАН, выбрав его по ситуации: "
                    . "Роспотребнадзор, Банк России, государственная жилищная инспекция, "
                    . "Росавиация или прокуратура. Опиши нарушение и попроси провести проверку.",
                'lawsuit' => "Составь ЧЕРНОВИК ИСКОВОГО ЗАЯВЛЕНИЯ в суд: цена иска с расчётом, "
                    . "требования (основная сумма, неустойка, штраф 50%, моральный вред, расходы), "
                    . "ссылка на досудебную претензию и оговорка о госпошлине по потребительским искам.",
            );
            $body = $bodies[$type] ?? $bodies['claim'];
            $structure = "Структура: шапка (кому — должность, организация, адрес; от кого — ФИО, "
                . "адрес, телефон), заголовок документа, обстоятельства по датам, правовое "
                . "обоснование, «На основании изложенного ТРЕБУЮ:» нумерованным списком, срок и "
                . "способ ответа, приложения, дата, подпись.";
        }

        return $head . $norms . "\n" . $body . "\n" . $structure . "\n\n"
            . "После документа добавь раздел <h2>Как отправить</h2>: вручение под подпись на втором "
            . "экземпляре или заказное письмо с описью и уведомлением, что сохранить и что делать, "
            . "если ответа нет.\n\n" . self::rules();
    }

    private static function doc_task($row, $part) {
        $title = class_exists('GS_Legal') && GS_Legal::page($row['page'])
            ? GS_Legal::page($row['page'])['h1'] : '';
        $task = "Раздел сайта: {$title}\n";
        $task .= "Готовим документ: {$part['title']}\n";
        $task .= "Описание ситуации от человека:\n" . (string) $row['story'] . "\n\n";
        if (!empty($row['review'])) {
            $task .= "Разбор, который человек уже видел (документ должен ему соответствовать):\n"
                . wp_strip_all_tags((string) $row['review']) . "\n\n";
        }
        $task .= 'Дата составления: ' . date_i18n('d.m.Y') . "\n";
        return $task;
    }

    private static function clean_html($html) {
        $html = trim((string) $html);
        $html = preg_replace('~^```(?:html)?\s*|\s*```$~u', '', $html);
        $html = preg_replace('~<h1[^>]*>.*?</h1>~isu', '', $html);
        return trim(wp_kses_post($html));
    }
}
