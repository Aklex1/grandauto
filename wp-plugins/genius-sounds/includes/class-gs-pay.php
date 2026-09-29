<?php
/**
 * Ссылка на оплату ЮMoney с человеческим назначением платежа.
 *
 * Готовый построитель в базовом плагине прибивает назначение как «TTS Balance
 * Topup»: для пополнения баланса это верно, а человек, который покупает песню
 * или претензию, видит на странице оплаты чужую техническую строку. Здесь
 * собираем ту же ссылку теми же параметрами, но назначение задаёт вызывающий.
 *
 * Кошелёк, адрес успеха и метка остаются прежними, и уведомление приходит на
 * адрес из настроек кошелька, а не из ссылки, — поэтому приёмник уведомлений
 * и разбор метки не меняются.
 *
 * Класс отдельный, потому что таких мест уже два: подарочные песни и
 * юридические документы. Третье появится — назначение снова разойдётся, если
 * каждый будет собирать ссылку сам.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Pay {

    const FORM_URL = 'https://yoomoney.ru/quickpay/confirm.xml';

    public static function receiver() {
        if (class_exists('KIE_TTS_Payment')) {
            return (string) KIE_TTS_Payment::get_yoomoney_receiver();
        }
        return (string) get_option('kie_tts_yoomoney_receiver', '');
    }

    public static function success_url() {
        if (class_exists('KIE_TTS_Payment')) {
            return (string) KIE_TTS_Payment::get_yoomoney_success_url();
        }
        return (string) get_option('kie_tts_yoomoney_success_url', home_url('/'));
    }

    /**
     * Ссылка на оплату.
     *
     * @param string $label   метка платежа: по ней приёмник узнаёт заказ
     * @param float  $amount  сумма в рублях
     * @param string $target  назначение платежа — его человек видит у ЮMoney
     * @param string $success куда вернуть после оплаты; пусто — общий адрес
     * @return string пустая строка, если кошелёк не настроен
     */
    public static function link($label, $amount, $target, $success = '') {
        $receiver = self::receiver();
        $label = preg_replace('~[^A-Za-z0-9_|.\-]~', '', (string) $label);
        if ($receiver === '' || $label === '' || (float) $amount <= 0) {
            return '';
        }
        $target = trim((string) $target);
        if ($target === '') {
            $target = 'Оплата заказа';
        }
        if ($success === '') {
            $success = self::success_url();
        }

        return add_query_arg(array(
            'receiver'      => $receiver,
            'quickpay-form' => 'shop',
            'targets'       => $target,
            'paymentType'   => 'AC',
            'sum'           => number_format((float) $amount, 2, '.', ''),
            'label'         => $label,
            'successURL'    => urlencode($success),
        ), self::FORM_URL);
    }
}
