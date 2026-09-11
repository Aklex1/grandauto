<?php
/**
 * Блок «Частые задачи» на страницах сервисов.
 *
 * Под каждый сервис собрана семантика, и по каждому запросу написан
 * отдельный материал. Здесь эти запросы выводятся ссылками с посадочной:
 * читателю — быстрый ответ на конкретный вопрос, а страницам блога —
 * вес с самой сильной страницы раздела.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Keywords {

    /**
     * Запрос => адрес материала. Порядок — от частотных к редким.
     */
    public static function map() {
        return array(
            'sfx' => array(
                'title' => 'Частые задачи со звуками',
                'items' => array(
                    'звук шагов для игры'            => 'zvuk-shagov-dlya-igry',
                    'звуковые эффекты нейросетью'    => 'zvukovye-effekty-neyroset',
                    'фоновый звук для видео'         => 'fonovyy-zvuk-dlya-video',
                    'звук для тиктока'               => 'zvuk-dlya-tiktoka',
                    'сделать звук взрыва'            => 'zvuk-vzryva-onlayn',
                    'нейросеть генерирует звуки'     => 'neyroset-generiruet-zvuki',
                    'звук грома для монтажа'         => 'zvuk-groma-dlya-montazha',
                    'бесшовный луп звука'            => 'besshovnyy-lup-zvuka',
                    'звук магии для игры'            => 'zvuk-magii-dlya-igry',
                    'звук воды для медитации'        => 'zvuk-vody-dlya-meditacii',
                ),
            ),
            'avatar' => array(
                'title' => 'Частые задачи с говорящим аватаром',
                'items' => array(
                    'говорящий аватар нейросетью'       => 'govoryashchiy-avatar-neyroset',
                    'видео из фото с озвучкой'          => 'video-iz-foto-s-ozvuchkoy',
                    'оживить фото с речью'              => 'ozhivit-foto-s-rechyu',
                    'заставить фото говорить'           => 'neyroset-zastavlyaet-foto-govorit',
                    'озвучить фото своим голосом'       => 'ozvuchit-foto-svoim-golosom',
                    'анимировать лицо под голос'        => 'animirovat-lico-pod-golos',
                    'цифровой аватар для видео'         => 'cifrovoy-avatar-dlya-video',
                    'аватар из фотографии за 5 минут'   => 'govoryashchiy-avatar-iz-fotografii',
                    'аватар для канала из фото'         => 'avatar-dlya-kanala-iz-foto',
                    'синхронизация губ с аудио'         => 'sinhronizaciya-gub-s-audio',
                ),
            ),
            'vocal' => array(
                'title' => 'Частые задачи с дорожками',
                'items' => array(
                    'сделать инструментал из песни'   => 'sdelat-instrumental-iz-pesni',
                    'извлечь инструментал'            => 'izvlech-instrumental-iz-pesni',
                    'убрать бэк-вокал'                => 'ubrat-bek-vokal-iz-pesni',
                    'разделить трек на вокал и музыку'=> 'razdelit-trek-na-vokal-i-muzyku',
                    'караоке версия песни'            => 'karaoke-versiya-pesni',
                    'минусовка из mp3'                => 'sdelat-minusovku-iz-mp3',
                    'минусовка нейросетью'            => 'minusovka-iz-pesni-neyroset',
                    'минус для караоке онлайн'        => 'minus-dlya-karaoke-onlayn',
                    'нейросеть для разделения дорожек'=> 'neyroset-dlya-razdeleniya-dorozhek',
                    'минус без потери качества'       => 'minus-bez-poteri-kachestva',
                ),
            ),
            'denoise' => array(
                'title' => 'Частые задачи с очисткой записи',
                'items' => array(
                    'убрать фоновый шум из видео'     => 'ubrat-fonovyy-shum-iz-video',
                    'убрать эхо из аудио'             => 'ubrat-eho-iz-audio',
                    'очистить голос от лишних звуков' => 'ochistit-golos-ot-postoronnih-zvukov',
                    'улучшить качество записи голоса' => 'uluchshit-kachestvo-zapisi-golosa',
                    'почистить аудиозапись онлайн'    => 'pochistit-audiozapis-onlayn',
                    'реставрация звука'               => 'restavraciya-zvuka-neyrosetyu',
                    'убрать фон из аудиозаписи'       => 'ubrat-fon-iz-audiozapisi',
                    'очистить запись диктофона'       => 'ochistit-zapis-diktofona',
                    'шумоподавление для голоса'       => 'shumopodavlenie-dlya-zapisi-golosa',
                    'убрать гул микрофона'            => 'ubrat-gul-mikrofona',
                ),
            ),
            'photo' => array(
                'title' => 'Частые задачи с фотографиями',
                'items' => array(
                    'фото в видео нейросетью'       => 'foto-v-video-neyroset',
                    'сделать движущееся фото'       => 'sdelat-dvizhusheesya-foto',
                    'гифка из фото нейросетью'      => 'gifka-iz-foto-neyrosetyu',
                    'оживить чёрно-белое фото'      => 'ozhivit-cherno-beloe-foto',
                    'анимация фото онлайн'          => 'animaciya-foto-onlayn',
                    'анимировать старую фотографию' => 'animirovat-staruyu-fotografiyu',
                    'анимация лица на фотографии'   => 'animaciya-lica-na-fotografii',
                    'видео из фотографии за минуту' => 'video-iz-fotografii-za-minutu',
                    'видео из старой фотографии'    => 'video-iz-staroy-fotografii',
                    'анимировать портрет'           => 'animirovat-portret-neyrosetyu',
                ),
            ),
        );
    }

    /**
     * Блок ссылок для страницы сервиса.
     */
    public static function render($group) {
        $map = self::map();
        if (empty($map[$group]['items'])) {
            return '';
        }
        $data = $map[$group];

        ob_start();
        ?>
        <section class="gs-keys">
            <h2 class="gs-section-title"><?php echo esc_html($data['title']); ?></h2>
            <p class="gs-keys__lead">Короткие разборы под конкретные задачи — с примерами формулировок и ценами.</p>
            <ul class="gs-keys__list">
                <?php foreach ($data['items'] as $anchor => $slug): ?>
                    <li>
                        <a href="<?php echo esc_url(home_url('/' . $slug . '/')); ?>"><?php echo esc_html($anchor); ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
        return ob_get_clean();
    }

    /** Какой набор запросов относится к сервису лаборатории. */
    public static function group_for_lab($service_id) {
        $known = array('avatar', 'vocal', 'denoise');
        return in_array($service_id, $known, true) ? $service_id : '';
    }
}
