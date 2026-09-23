<?php
/**
 * Партнёрская программа: доля с трат приглашённых.
 *
 * Механика приглашений уже есть в плагине озвучки — код, ссылка, запись
 * «кто кого привёл» и таблица начислений. Не хватало главного: доля
 * считалась только с озвучки, а траты на остальные сервисы проходили мимо
 * партнёра. Здесь это и чинится.
 *
 * Считаем не по каждой операции, а по чистой сумме: сколько человек
 * потратил минус сколько ему вернули за неудачи. Иначе партнёр получал бы
 * долю и с денег, которые вернулись на баланс, — то есть из нашего
 * кармана. Разница между «потрачено» и «уже оплачено партнёру»
 * закрывается при следующей же трате.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Referral {

    /** Сколько человек потратил у нас всего, за вычетом возвратов. */
    const META_SPENT = 'gs_ref_spent';

    /** С какой суммы партнёру уже начислено. */
    const META_PAID  = 'gs_ref_paid';

    /** Мельче рубля не платим: копейки только засоряют историю. */
    const MIN_PAYOUT = 1.0;

    public static function ready() {
        return class_exists('KIE_TTS_Referral')
            && method_exists('KIE_TTS_Referral', 'get_commission_rate');
    }

    /** Доля партнёра, 0.15 — это 15%. */
    public static function rate() {
        return self::ready() ? (float) KIE_TTS_Referral::get_commission_rate() : 0.0;
    }

    /** Кого записали пригласившим для этого человека. */
    public static function referrer_of($user_id) {
        return (int) get_user_meta((int) $user_id, 'kie_tts_referred_by', true);
    }

    /* ---------------------------------------------------------------------
     * Учёт трат
     * ------------------------------------------------------------------ */

    public static function note_spend($user_id, $amount) {
        $amount = (float) $amount;
        if ($amount <= 0 || (int) $user_id <= 0) {
            return;
        }
        $spent = (float) get_user_meta((int) $user_id, self::META_SPENT, true);
        update_user_meta((int) $user_id, self::META_SPENT, round($spent + $amount, 2));
        self::settle((int) $user_id);
    }

    /**
     * Возврат за неудачу уменьшает сумму трат.
     *
     * Ниже уже оплаченного не опускаем: отбирать у партнёра начисленное —
     * худший способ объяснить, как работает программа. Просто следующая
     * доля начнётся с этой отметки.
     */
    public static function note_refund($user_id, $amount) {
        $amount = (float) $amount;
        if ($amount <= 0 || (int) $user_id <= 0) {
            return;
        }
        $spent = (float) get_user_meta((int) $user_id, self::META_SPENT, true);
        $paid  = (float) get_user_meta((int) $user_id, self::META_PAID, true);
        update_user_meta((int) $user_id, self::META_SPENT, round(max($paid, $spent - $amount), 2));
    }

    /**
     * Доплатить партнёру за то, что ещё не оплачено.
     */
    public static function settle($user_id) {
        if (!self::ready()) {
            return 0.0;
        }
        $user_id = (int) $user_id;
        $referrer = self::referrer_of($user_id);
        if ($referrer <= 0 || $referrer === $user_id) {
            return 0.0;
        }

        $spent = (float) get_user_meta($user_id, self::META_SPENT, true);
        $paid  = (float) get_user_meta($user_id, self::META_PAID, true);
        $base  = round($spent - $paid, 2);
        if ($base < self::MIN_PAYOUT) {
            return 0.0;
        }

        // Отметку двигаем до начисления: если начисление сорвётся, партнёр
        // потеряет одну долю, а не получит её дважды при каждом обращении.
        update_user_meta($user_id, self::META_PAID, round($spent, 2));

        $source_id = 'gs-' . $user_id . '-' . time() . '-' . wp_generate_password(6, false, false);
        $ok = KIE_TTS_Referral::process_spend_commission($user_id, $base, 'gs_service', $source_id);
        if (!$ok) {
            update_user_meta($user_id, self::META_PAID, round($paid, 2));
            return 0.0;
        }
        return round($base * self::rate(), 2);
    }

    /* ---------------------------------------------------------------------
     * Данные для страницы
     * ------------------------------------------------------------------ */

    /**
     * Ссылка, код и заработок — в одном месте.
     */
    public static function summary($user_id) {
        $out = array(
            'ready'   => self::ready(),
            'rate'    => round(self::rate() * 100),
            'link'    => '',
            'code'    => '',
            'invited' => 0,
            'earned'  => 0.0,
        );
        $user_id = (int) $user_id;
        if (!$out['ready'] || $user_id <= 0) {
            return $out;
        }
        if (method_exists('KIE_TTS_Referral', 'get_referral_link')) {
            $out['link'] = (string) KIE_TTS_Referral::get_referral_link($user_id);
        }
        if (method_exists('KIE_TTS_Referral', 'get_or_create_referral_code')) {
            $out['code'] = (string) KIE_TTS_Referral::get_or_create_referral_code($user_id);
        }
        if (method_exists('KIE_TTS_Referral', 'get_stats')) {
            $stats = (array) KIE_TTS_Referral::get_stats($user_id);
            $out['invited'] = (int) ($stats['invited_count'] ?? 0);
            $out['earned']  = (float) ($stats['total_earned'] ?? 0);
            $out['rows']    = is_array($stats['recent_earnings'] ?? null)
                ? array_slice($stats['recent_earnings'], 0, 10)
                : array();
        }
        return $out;
    }
}
