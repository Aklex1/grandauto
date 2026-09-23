<?php
/**
 * Личная база знаний пользователя.
 *
 * Оплативший клиент заполняет свою базу (текст + документы) в личном кабинете;
 * она подставляется в системный промпт его диалогов с теми ассистентами, где это
 * включено (по умолчанию — бизнес-консультант). Так владелец готовит и проверяет
 * свою «коробку» ещё до подключения собственного бота.
 *
 * Хранение — в мете пользователя: база привязана к аккаунту, общему с сайтом и ботом.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GA_KB
{
    public const META = 'ga_user_kb';
    public const META_COMPANY = 'ga_user_kb_company';
    public const MAX = 20000;

    /** @return array{company:string,content:string} */
    public static function get(int $user_id): array
    {
        if (!$user_id) {
            return ['company' => '', 'content' => ''];
        }
        return [
            'company' => (string) get_user_meta($user_id, self::META_COMPANY, true),
            'content' => (string) get_user_meta($user_id, self::META, true),
        ];
    }

    public static function save(int $user_id, string $company, string $content): void
    {
        update_user_meta($user_id, self::META_COMPANY,
            mb_substr(sanitize_text_field($company), 0, 120));
        update_user_meta($user_id, self::META,
            mb_substr(sanitize_textarea_field($content), 0, self::MAX));
    }

    /** Слаги ассистентов, для которых применяется личная база (настраивается). */
    public static function enabled_slugs(): array
    {
        $raw = (string) get_option('ga_kb_assistants', 'biznes');
        $out = array_filter(array_map('trim', explode(',', $raw)));
        return $out ?: ['biznes'];
    }

    public static function applies(array $assistant): bool
    {
        return in_array($assistant['slug'], self::enabled_slugs(), true);
    }

    /** Блок для системного промпта: база пользователя, если она есть и ассистент её поддерживает. */
    public static function for_prompt(int $user_id, array $assistant): string
    {
        if (!$user_id || !self::applies($assistant)) {
            return '';
        }
        $kb = self::get($user_id);
        $content = trim($kb['content']);
        if ($content === '') {
            return '';
        }
        $head = 'БАЗА ЗНАНИЙ КЛИЕНТА';
        if ($kb['company'] !== '') {
            $head .= ' (компания: ' . $kb['company'] . ')';
        }
        return "\n\n" . $head
            . "\nОтвечай строго по этим данным клиента. Чего здесь нет — не выдумывай, "
            . "предложи оставить контакт.\n" . $content;
    }
}
