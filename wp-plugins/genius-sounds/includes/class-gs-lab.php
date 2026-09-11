<?php
/**
 * Микросервисы обработки аудио и видео поверх того же агрегатора и баланса,
 * что и студия звуков: оживление фото (липсинк), удаление вокала и очистка записи.
 *
 * Каждый сервис — отдельная посадочная страница под собранную семантику,
 * с перелинковкой на соседние сервисы.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Lab {

    const API_JOBS        = 'https://api.kie.ai/api/v1/jobs/createTask';
    const API_JOBS_INFO   = 'https://api.kie.ai/api/v1/jobs/recordInfo';
    const API_MUSIC      = 'https://api.kie.ai/api/v1/generate';
    const API_MUSIC_INFO = 'https://api.kie.ai/api/v1/generate/record-info';
    const API_VOCAL       = 'https://api.kie.ai/api/v1/vocal-removal/generate';
    const API_VOCAL_INFO  = 'https://api.kie.ai/api/v1/vocal-removal/record-info';

    const UPLOAD_DIR = 'uploads';

    /** Максимальные размеры загрузки: ограничения самого агрегатора. */
    const MAX_IMAGE_BYTES = 10485760;  // 10 МБ
    const MAX_AUDIO_BYTES = 10485760;  // 10 МБ (audio-isolation), для вокала — 20 МБ
    const MAX_AUDIO_BYTES_VOCAL = 20971520;

    public static function boot() {
        add_filter('body_class', array(__CLASS__, 'body_class'));
    }

    /* ---------------------------------------------------------------------
     * Реестр сервисов
     * ------------------------------------------------------------------ */

    /**
     * Ключевые слова в текстах взяты из подбора по базе Mutagen:
     * в скобках — конкуренция / частота.
     */
    public static function services() {
        return array(
            'avatar' => array(
                'id'          => 'avatar',
                'slug'        => 'govoryashchiy-avatar',
                'page_option' => 'gs_lab_page_avatar',
                'menu'        => 'Говорящий аватар',
                'nav'         => 'Говорящий аватар',
                'h1'          => 'Говорящий аватар из фото: видео, где человек со снимка говорит',
                'seo_title'   => 'Говорящий аватар из фото — сделать видео из фото с озвучкой',
                'seo_desc'    => 'Говорящий аватар из фото за пару минут: загрузите фотографию и запись голоса — нейросеть синхронизирует губы с речью и отдаст видео в MP4. Без установки программ и монтажа.',
                'lead'        => 'Загрузите фотографию и аудио с речью — нейросеть синхронизирует губы с голосом. На выходе видео, где человек со снимка говорит вашим текстом: для аватара канала, приветствия на сайте или поздравления.',
                'badge'       => 'Липсинк по фото и голосу',
                'cost_option' => 'gs_lab_cost_avatar',
                'cost'        => 120,
                'pricing'     => array('unit' => 'second', 'rate' => 12, 'min' => 120, 'max_seconds' => 60),
                'available'   => true,
                'inputs'      => array('image', 'audio'),
                'accept'      => array(
                    'image' => 'image/jpeg,image/png',
                    'audio' => 'audio/mpeg,audio/wav,audio/x-wav,audio/aac,audio/mp4,audio/ogg',
                ),
                'prompt'      => true,
                'prompt_hint' => 'Необязательно: опишите манеру речи или план кадра — например «спокойно рассказывает, крупный план».',
                'result_kind' => 'video',
                'poll_seconds'=> 600,
                'steps'       => array(
                    'Загрузите портрет: лицо крупно, анфас, без очков и головных уборов.',
                    'Добавьте аудио с речью — своё или сгенерированное в озвучке.',
                    'Нажмите «Сделать видео» и подождите: ролик собирается несколько минут.',
                ),
                'faq'         => array(
                    array('Какое фото подойдёт, чтобы оживить его нейросетью?',
                          'Лучше всего работает портрет анфас, где лицо занимает заметную часть кадра и хорошо освещено. Форматы JPEG и PNG, до 10 МБ. Снимки в профиль, в тёмных очках или с перекрытым ртом дают заметно хуже результат.'),
                    array('Можно ли сделать говорящее видео из старого фото?',
                          'Да, старые и отсканированные снимки работают, если лицо различимо. Архивную фотографию лучше сначала восстановить и повысить резкость в разделе «Нейросети», а уже потом загружать сюда для озвучки.'),
                    array('Где взять голос для видео?',
                          'Подойдёт любая запись речи до 5 минут: диктофон, кружок из мессенджера или файл из нашей озвучки текста. Если запись шумная, прогоните её через очистку звука — липсинк будет точнее.'),
                    array('Сколько длится генерация?',
                          'Обычно от одной до нескольких минут, в зависимости от длины аудио. Страницу можно не держать открытой: готовый ролик остаётся в истории.'),
                ),
            ),

            'vocal' => array(
                'id'          => 'vocal',
                'slug'        => 'ubrat-vokal',
                'page_option' => 'gs_lab_page_vocal',
                'menu'        => 'Убрать вокал',
                'nav'         => 'Убрать вокал',
                'h1'          => 'Убрать вокал из песни онлайн: минусовка за пару минут',
                'seo_title'   => 'Убрать вокал из песни онлайн — сделать минусовку бесплатно',
                'seo_desc'    => 'Уберите вокал из песни онлайн и получите минусовку: нейросеть отделит голос от музыки и отдаст две дорожки — инструментал и вокал. Загрузите трек и скачайте результат в MP3.',
                'lead'        => 'Загрузите трек — нейросеть отделит вокал от музыки и вернёт две дорожки: чистый инструментал для караоке и отдельно голос. Ничего устанавливать не нужно.',
                'badge'       => 'Разделение дорожек',
                'cost_option' => 'gs_lab_cost_vocal',
                'cost'        => 45,
                'pricing'     => array('unit' => 'minute', 'rate' => 15, 'min' => 45, 'max_seconds' => 600),
                'available'   => true,
                'blocked_note'=> 'Разделение дорожек подключается: настраиваем студию обработки звука. Загляните через пару дней — инструмент появится здесь же.',
                'inputs'      => array('audio'),
                'accept'      => array(
                    'audio' => 'audio/mpeg,audio/wav,audio/x-wav,audio/aac,audio/mp4,audio/ogg',
                ),
                'prompt'      => false,
                'result_kind' => 'stems',
                'poll_seconds'=> 600,
                'steps'       => array(
                    'Загрузите песню в MP3 или WAV — до 20 МБ.',
                    'Нажмите «Разделить дорожки» и подождите пару минут.',
                    'Скачайте минусовку и отдельно вокал.',
                ),
                'faq'         => array(
                    array('Как убрать вокал из песни без потери качества?',
                          'Нейросеть не вырезает частоты, как старые «инверсия фазы» и эквалайзеры, а заново собирает дорожки. Поэтому инструментал остаётся полным, а не глухим. Качество исходника всё же важно: из 128 kbps результат будет хуже, чем из 320 kbps или WAV.'),
                    array('Чем это отличается от минусовки, скачанной в интернете?',
                          'Готовые минусовки есть только у популярных песен и часто в плохом качестве. Здесь минусовка делается из вашего файла — из любой песни, включая редкие и авторские.'),
                    array('Можно ли наоборот оставить только голос?',
                          'Да, сервис отдаёт обе дорожки сразу: и инструментал, и вокал. Отдельный файл с голосом удобен для ремиксов, разборов и караоке-бэков.'),
                    array('Подойдёт ли результат для караоке?',
                          'Да, инструментальная дорожка — это и есть готовый минус для караоке. Если в песне плотный бэк-вокал, его частично может унести вместе с основным голосом.'),
                ),
            ),

            'stt' => array(
                'id'          => 'stt',
                'slug'        => 'rasshifrovka-audio',
                'page_option' => 'gs_lab_page_stt',
                'menu'        => 'Расшифровка записи',
                'nav'         => 'Расшифровка записи',
                'h1'          => 'Расшифровка аудио в текст онлайн: с таймкодами и по говорящим',
                'seo_title'   => 'Расшифровка аудио в текст онлайн — перевести запись в текст',
                'seo_desc'    => 'Расшифровка аудио в текст онлайн: загрузите запись или дайте ссылку на ролик — нейросеть вернёт текст с отметками времени и разделением по говорящим. Выгрузка в TXT, SRT и VTT.',
                'lead'        => 'Загрузите запись или вставьте ссылку на ролик — нейросеть вернёт текст с пунктуацией, отметками времени и разделением по говорящим. Готовый результат скачивается текстом или субтитрами.',
                'badge'       => 'Речь в текст',
                'cost_option' => 'gs_lab_cost_stt',
                'cost'        => 0,
                'pricing'     => array('unit' => 'fixed', 'rate' => 0, 'min' => 0, 'max_seconds' => 0),
                'available'   => true,
                'inputs'      => array('audio'),
                'input_optional' => array('audio'),
                'accept'      => array(
                    'audio' => 'audio/mpeg,audio/wav,audio/x-wav,audio/aac,audio/mp4,audio/ogg,video/mp4,video/webm',
                ),
                'prompt'      => false,
                'fields'      => array(
                    'source_url' => array(
                        'type'  => 'text',
                        'label' => 'Или ссылка на запись либо ролик',
                        'place' => 'https://www.youtube.com/watch?v=… или ссылка на MP3',
                        'max'   => 500,
                        'hint'  => 'Годится и прямая ссылка на файл, и ссылка на ролик — дорожку достанем сами.',
                    ),
                    'language' => array(
                        'type'  => 'text',
                        'label' => 'Язык записи (необязательно)',
                        'place' => 'ru, en, de…',
                        'max'   => 20,
                        'hint'  => 'На коротких записях явный язык заметно повышает точность.',
                    ),
                    'diarize' => array(
                        'type'    => 'checkbox',
                        'label'   => 'Разделить по говорящим',
                        'default' => true,
                        'hint'    => 'Для интервью, совещаний и подкастов.',
                    ),
                    'events' => array(
                        'type'    => 'checkbox',
                        'label'   => 'Отмечать неречевые звуки',
                        'default' => false,
                        'hint'    => 'Смех, музыка, аплодисменты — пометками в тексте.',
                    ),
                ),
                'result_kind' => 'text',
                'poll_seconds'=> 900,
                'steps'       => array(
                    'Загрузите запись или вставьте ссылку на ролик.',
                    'Укажите язык и включите разделение по говорящим, если в записи несколько человек.',
                    'Через пару минут заберите текст, субтитры SRT или VTT.',
                ),
                'faq'         => array(
                    array('Какие файлы принимаются?',
                          'MP3, WAV, M4A, OGG и дорожка из видео — MP4 и WebM. Можно не загружать файл вовсе, а дать ссылку на ролик: звук из него будет извлечён автоматически.'),
                    array('Насколько точная расшифровка?',
                          'На разборчивой записи — близко к дословной, со знаками препинания и заглавными буквами. Слабое место — имена собственные и узкие термины: их стоит проверить поиском по тексту. Если запись шумная, сначала прогоните её через очистку звука.'),
                    array('Как работает разделение по говорящим?',
                          'Голоса сравниваются по тембру и манере речи, каждому присваивается номер: «Говорящий 1», «Говорящий 2». Имён модель не знает — их подставляют заменой по тексту. Двух-трёх человек различает уверенно, в записи на пятерых часть реплик придётся поправить.'),
                    array('Что можно скачать?',
                          'Текст с отметками времени и говорящими, субтитры SRT и VTT. Субтитры сразу подхватываются видеоредактором и плеером.'),
                    array('Сколько это стоит?',
                          'Сейчас расшифровка бесплатна: платить нужно только за сервисы с тяжёлой генерацией.'),
                ),
            ),

            'ytaudio' => array(
                'id'          => 'ytaudio',
                'slug'        => 'zvuk-iz-video',
                'page_option' => 'gs_lab_page_ytaudio',
                'menu'        => 'Звук из видео',
                'nav'         => 'Звук из видео',
                'h1'          => 'Извлечь звук из видео онлайн: дорожка по ссылке за полминуты',
                'seo_title'   => 'Извлечь звук из видео онлайн — скачать аудиодорожку в MP3',
                'seo_desc'    => 'Извлеките звук из видео онлайн по ссылке: вставьте адрес ролика и получите готовую дорожку в MP3, WAV или M4A. Без установки программ и без скачивания самого видео.',
                'lead'        => 'Вставьте ссылку на ролик — и заберите звуковую дорожку отдельным файлом. Само видео скачивать не нужно: дорожка снимается на нашей стороне и приходит готовым файлом.',
                'badge'       => 'Дорожка из ролика',
                'cost_option' => 'gs_lab_cost_ytaudio',
                'cost'        => 0,
                'pricing'     => array('unit' => 'fixed', 'rate' => 0, 'min' => 0, 'max_seconds' => 0),
                'available'   => true,
                'inputs'      => array(),
                'accept'      => array(),
                'prompt'      => false,
                'fields'      => array(
                    'url' => array(
                        'type'  => 'text',
                        'label' => 'Ссылка на ролик',
                        'place' => 'https://www.youtube.com/watch?v=…',
                        'max'   => 500,
                        'hint'  => 'Ролик должен открываться без входа и без ограничения по возрасту.',
                    ),
                    'format' => array(
                        'type'    => 'select',
                        'label'   => 'Формат дорожки',
                        'options' => array(
                            'mp3' => 'MP3 — слушать и отправлять',
                            'wav' => 'WAV — для обработки и монтажа',
                            'm4a' => 'M4A — без потерь, формат из ролика',
                        ),
                        'default' => 'mp3',
                        'hint'    => 'Для расшифровки и монтажа лучше WAV, для прослушивания — MP3.',
                    ),
                ),
                'result_kind' => 'audio',
                'poll_seconds'=> 600,
                'steps'       => array(
                    'Вставьте ссылку на ролик.',
                    'Выберите формат: MP3 для прослушивания, WAV для обработки.',
                    'Скачайте готовую дорожку.',
                ),
                'faq'         => array(
                    array('Нужно ли скачивать само видео?',
                          'Нет. Достаточно ссылки: дорожка снимается на нашей стороне и приходит готовым файлом.'),
                    array('Теряется ли качество?',
                          'В M4A дорожка отдаётся ровно такой, какой была в ролике. MP3 и WAV получаются перекодированием — на слух разница незаметна, но для дальнейшей обработки лучше брать WAV.'),
                    array('Какой длины ролик можно обработать?',
                          'До полутора часов. Более длинные записи стоит разрезать: обработка занимает дольше, а файл получается громоздким.'),
                    array('Что делать с дорожкой дальше?',
                          'Её можно расшифровать в текст, очистить от шума или разделить на голос и музыку — все инструменты рядом.'),
                    array('А если ролик с ограничением по возрасту?',
                          'Такие ролики требуют входа в аккаунт, и дорожку снять не получится. То же касается приватных и удалённых видео.'),
                ),
            ),

            'music' => array(
                'id'          => 'music',
                'slug'        => 'sozdat-muzyku',
                'page_option' => 'gs_lab_page_music',
                'menu'        => 'Создать музыку',
                'nav'         => 'Создать музыку',
                'h1'          => 'Создать музыку нейросетью: трек по описанию за пару минут',
                'seo_title'   => 'Создать музыку нейросетью онлайн — генератор песен и треков',
                'seo_desc'    => 'Создайте музыку нейросетью онлайн: опишите настроение и стиль — и получите два готовых трека по три минуты. Инструментал для видео или песня с вокалом и вашим текстом, скачивание в MP3.',
                'lead'        => 'Опишите словами, какая нужна музыка — стиль, настроение, инструменты. Нейросеть напишет и сыграет трек: инструментал для фона видео или песню с вокалом на ваш текст. За одну генерацию приходит два разных варианта.',
                'badge'       => 'Музыка по описанию',
                'cost_option' => 'gs_lab_cost_music',
                'cost'        => 59,
                'pricing'     => array('unit' => 'fixed', 'rate' => 0, 'min' => 59, 'max_seconds' => 0),
                'available'   => true,
                'inputs'      => array(),
                'accept'      => array(),
                'prompt'      => true,
                'prompt_label'=> 'Какая нужна музыка',
                'prompt_hint' => 'Опишите стиль, настроение и инструменты: «спокойная акустическая гитара, тёплое настроение, фон для видео о путешествии».',
                'prompt_place'=> 'Например: энергичный поп-рок с электрогитарой, припев с женским вокалом',
                'fields'      => array(
                    'instrumental' => array(
                        'type'    => 'checkbox',
                        'label'   => 'Без вокала — только музыка',
                        'default' => true,
                        'hint'    => 'Снимите галочку, если нужна песня с голосом.',
                    ),
                    'style' => array(
                        'type'  => 'text',
                        'label' => 'Стиль и жанр (необязательно)',
                        'place' => 'поп, лоу-фай, рок, эмбиент, шансон',
                        'max'   => 200,
                        'hint'  => 'Можно перечислить через запятую — так точнее попадает в нужное звучание.',
                    ),
                    'title' => array(
                        'type'  => 'text',
                        'label' => 'Название трека (необязательно)',
                        'place' => 'Тёплый вечер',
                        'max'   => 80,
                    ),
                    'lyrics' => array(
                        'type'  => 'textarea',
                        'label' => 'Текст песни (необязательно)',
                        'place' => "Куплет...\nПрипев...",
                        'rows'  => 6,
                        'max'   => 2500,
                        'hint'  => 'Если вписать свой текст, нейросеть споёт именно его. Оставьте пустым — придумает сама.',
                    ),
                ),
                'result_kind' => 'audio',
                'poll_seconds'=> 600,
                'steps'       => array(
                    'Опишите словами нужную музыку: стиль, настроение, инструменты.',
                    'Решите, нужен ли вокал, и при желании впишите свой текст песни.',
                    'Запустите генерацию и через пару минут скачайте два готовых варианта.',
                ),
                'faq'         => array(
                    array('Как нейросеть создаёт музыку по описанию?',
                          'Она обучена на связке «описание — звучание», поэтому понимает и жанр, и настроение, и набор инструментов. Чем конкретнее описание, тем ближе результат: «спокойная гитара, медленный темп, тёплое настроение» сработает лучше, чем просто «красивая музыка».'),
                    array('Можно ли сделать песню со своим текстом?',
                          'Да. Снимите галочку «без вокала» и вставьте свой текст — нейросеть споёт именно его. Если текста нет, она напишет его сама по описанию.'),
                    array('Сколько длится трек и сколько их приходит?',
                          'За одну генерацию приходит два разных варианта примерно по три минуты каждый. Можно выбрать тот, что ближе, или запустить ещё раз с уточнённым описанием.'),
                    array('Можно ли использовать музыку в своих роликах?',
                          'Да, сгенерированные треки вы используете в своих проектах — в видео, подкастах, рекламе. Это удобнее готовых библиотек: площадки не предъявляют претензий по авторским правам к музыке, которой раньше не существовало.'),
                    array('Чем это отличается от генератора звуков?',
                          'Генератор звуков делает короткие эффекты и атмосферу — шаги, взрыв, дождь. Здесь получается полноценный музыкальный трек со структурой: вступление, развитие, финал.'),
                ),
            ),

            'denoise' => array(
                'id'          => 'denoise',
                'slug'        => 'ubrat-shum',
                'page_option' => 'gs_lab_page_denoise',
                'menu'        => 'Убрать шум',
                'nav'         => 'Убрать шум',
                'h1'          => 'Убрать шум из аудио онлайн: очистка записи голоса',
                'seo_title'   => 'Убрать шум из аудио онлайн — очистить запись голоса от шума',
                'seo_desc'    => 'Уберите шум из аудио онлайн: нейросеть отделит голос от фонового гула, эха и уличного шума. Загрузите запись или дорожку из видео и скачайте чистый голос в MP3.',
                'lead'        => 'Загрузите запись — нейросеть уберёт фоновый гул, шум улицы, шипение микрофона и оставит чистый голос. Подходит для интервью, созвонов, голосовых и звука из видео.',
                'badge'       => 'Шумоподавление',
                'cost_option' => 'gs_lab_cost_denoise',
                'cost'        => 36,
                'pricing'     => array('unit' => 'minute', 'rate' => 12, 'min' => 36, 'max_seconds' => 600),
                'available'   => true,
                'blocked_note'=> 'Очистка записи подключается: настраиваем студию обработки звука. Загляните через пару дней — инструмент появится здесь же.',
                'inputs'      => array('audio'),
                'accept'      => array(
                    'audio' => 'audio/mpeg,audio/wav,audio/x-wav,audio/aac,audio/mp4,audio/ogg',
                ),
                'prompt'      => false,
                'result_kind' => 'audio',
                'poll_seconds'=> 420,
                'steps'       => array(
                    'Загрузите запись в MP3 или WAV — до 10 МБ.',
                    'Нажмите «Очистить запись».',
                    'Послушайте результат и скачайте чистый голос.',
                ),
                'faq'         => array(
                    array('Какой шум убирает нейросеть?',
                          'Ровный фон — гул техники, кондиционер, шум улицы и трафика, шипение дешёвого микрофона, ветер. Отделяет речь от фона целиком, а не режет частоты, поэтому голос не становится «подводным».'),
                    array('Можно ли убрать эхо из записи?',
                          'Сильную реверберацию комнаты нейросеть заметно уменьшает, но полностью «сухим» голос из гулкого помещения не сделает. Чем ближе микрофон был ко рту, тем лучше результат.'),
                    array('Как почистить звук в видео?',
                          'Извлеките аудиодорожку любым конвертером, очистите её здесь и подставьте обратно в монтаже. Форматы MP4 и OGG тоже принимаются напрямую.'),
                    array('Останется ли качество записи?',
                          'На выходе MP3 с исходной длительностью. Речь становится разборчивее, но нейросеть не добавляет того, чего в записи не было: полностью заглушенные шумом слова не восстановятся.'),
                ),
            ),
        );
    }

    public static function get_service($id) {
        $services = self::services();
        return isset($services[$id]) ? $services[$id] : null;
    }

    /**
     * Доступность сервиса. Дефолт берётся из реестра, но переключается в админке:
     * часть моделей у поставщика то появляется, то отваливается.
     */
    public static function is_available($id) {
        $service = self::get_service($id);
        if (!$service) {
            return false;
        }
        // Вокал и шумоподавление живут на отдельном поставщике. Если его ключа
        // нет, сервис всё равно можно открыть во временном ручном режиме.
        if (class_exists('GS_MusicAI') && GS_MusicAI::handles($id)
            && !GS_MusicAI::ready($id) && !GS_Manual::enabled($id)) {
            return false;
        }
        $option = get_option('gs_lab_enabled_' . $id, null);
        if ($option === null || $option === '') {
            return !empty($service['available']);
        }
        return (string) $option === '1';
    }

    /**
     * Только рабочие сервисы — для меню, подвала и карты сайта.
     */
    public static function available_services() {
        $out = array();
        foreach (self::services() as $id => $service) {
            if (self::is_available($id)) {
                $out[$id] = $service;
            }
        }
        return $out;
    }

    /**
     * Минимальная цена — она же цена короткого файла и то, что показываем
     * на посадочной странице до загрузки.
     */
    /** Сервис выполняется руками — об этом надо честно писать на странице. */
    public static function is_manual($id) {
        return class_exists('GS_Manual') && GS_Manual::enabled($id)
            && !(class_exists('GS_MusicAI') && GS_MusicAI::ready($id));
    }

    public static function get_cost($id) {
        $service = self::get_service($id);
        if (!$service) {
            return 0.0;
        }
        $min = (float) get_option('gs_lab_min_' . $id, self::pricing($id, 'min'));
        if ($min > 0) {
            return round($min, 2);
        }
        $cost = (float) get_option($service['cost_option'], $service['cost']);
        return $cost > 0 ? round($cost, 2) : (float) $service['cost'];
    }

    /* ---------------------------------------------------------------------
     * Цена от длительности
     *
     * Поставщики берут деньги за секунды и минуты обработки, поэтому и здесь
     * цена считается от длины файла: короткий ролик стоит минимум, длинный
     * не уводит сервис в минус.
     * ------------------------------------------------------------------ */

    public static function pricing($id, $key) {
        $service = self::get_service($id);
        $pricing = ($service && !empty($service['pricing'])) ? $service['pricing'] : array();
        $defaults = array('unit' => 'fixed', 'rate' => 0, 'min' => 0, 'max_seconds' => 0);
        $pricing = array_merge($defaults, $pricing);
        return $pricing[$key];
    }

    public static function rate($id) {
        $rate = (float) get_option('gs_lab_rate_' . $id, self::pricing($id, 'rate'));
        return $rate > 0 ? $rate : (float) self::pricing($id, 'rate');
    }

    public static function max_seconds($id) {
        $max = (int) get_option('gs_lab_max_seconds_' . $id, self::pricing($id, 'max_seconds'));
        return $max > 0 ? $max : (int) self::pricing($id, 'max_seconds');
    }

    /**
     * Итоговая цена обработки файла длительностью $seconds.
     */
    public static function price($id, $seconds) {
        $min  = self::get_cost($id);
        $unit = (string) self::pricing($id, 'unit');
        $seconds = (float) $seconds;
        if ($unit === 'fixed' || $seconds <= 0) {
            return $min;
        }
        $units = $unit === 'second' ? ceil($seconds) : ceil($seconds / 60);
        $price = self::rate($id) * $units;
        return round(max($min, $price), 2);
    }

    /**
     * Понятная подпись цены для страницы сервиса.
     */
    public static function price_hint($id) {
        $unit = (string) self::pricing($id, 'unit');
        $min  = number_format_i18n(self::get_cost($id), 0);
        if ($unit === 'fixed') {
            return $min . ' ₽ за обработку';
        }
        $rate = number_format_i18n(self::rate($id), 0);
        $word = $unit === 'second' ? 'секунду видео' : 'минуту записи';
        return $rate . ' ₽ за ' . $word . ', минимум ' . $min . ' ₽';
    }

    /**
     * Длительность аудио или видео из нашей же папки загрузок.
     * Нужна и для цены, и для ограничения длины файла.
     */
    public static function media_duration($path) {
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            return 0.0;
        }
        if (!function_exists('wp_read_audio_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        if (!function_exists('wp_read_audio_metadata')) {
            return 0.0;
        }
        $meta = wp_read_audio_metadata($path);
        if (is_array($meta) && !empty($meta['length'])) {
            return (float) $meta['length'];
        }
        return 0.0;
    }

    /**
     * Обратное преобразование адреса загрузки в путь на диске.
     * Чужие адреса не принимаем: длительность считаем только по своим файлам.
     */
    public static function local_path($url) {
        $url = (string) $url;
        $base = self::uploads_url();
        if ($url === '' || strpos($url, $base) !== 0) {
            return '';
        }
        $rel = ltrim(substr($url, strlen($base)), '/');
        $rel = str_replace(array('..', "\\"), '', $rel);
        $path = self::uploads_dir() . '/' . $rel;
        return file_exists($path) ? $path : '';
    }

    /* ---------------------------------------------------------------------
     * Страницы
     * ------------------------------------------------------------------ */

    public static function ensure_pages() {
        foreach (self::services() as $service) {
            $page_id = (int) get_option($service['page_option']);
            if ($page_id > 0 && get_post($page_id)) {
                continue;
            }
            $shortcode = '[genius_lab id="' . $service['id'] . '"]';
            $existing = get_page_by_path($service['slug']);
            if ($existing) {
                $page_id = (int) $existing->ID;
                wp_update_post(array(
                    'ID'           => $page_id,
                    'post_title'   => $service['seo_title'],
                    'post_content' => $shortcode,
                    'post_status'  => 'publish',
                ));
            } else {
                $page_id = (int) wp_insert_post(array(
                    'post_title'   => $service['seo_title'],
                    'post_content' => $shortcode,
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_name'    => $service['slug'],
                ));
            }
            if ($page_id > 0) {
                update_option($service['page_option'], $page_id);
            }
        }
    }

    public static function get_url($id) {
        $service = self::get_service($id);
        if (!$service) {
            return '';
        }
        $page_id = (int) get_option($service['page_option']);
        $url = $page_id > 0 ? get_permalink($page_id) : '';
        return $url ? $url : home_url('/' . $service['slug'] . '/');
    }

    /**
     * Сервис текущего запроса, если открыта его посадочная страница.
     *
     * @return array|null
     */
    public static function current_service() {
        if (is_admin()) {
            return null;
        }
        foreach (self::services() as $service) {
            $page_id = (int) get_option($service['page_option']);
            if ($page_id > 0 && is_page($page_id)) {
                return $service;
            }
        }
        global $post;
        if ($post instanceof WP_Post && has_shortcode((string) $post->post_content, 'genius_lab')) {
            foreach (self::services() as $service) {
                if (strpos((string) $post->post_content, 'id="' . $service['id'] . '"') !== false) {
                    return $service;
                }
            }
        }
        return null;
    }

    public static function body_class($classes) {
        if (self::current_service()) {
            $classes[] = 'gs-lab-page';
            $classes[] = 'gs-studio-page';
            $classes[] = 'gs-chrome';
        }
        return $classes;
    }

    /* ---------------------------------------------------------------------
     * Загрузка файлов
     * ------------------------------------------------------------------ */

    public static function uploads_dir() {
        return GS_Storage::base_dir() . '/' . self::UPLOAD_DIR;
    }

    public static function uploads_url() {
        return GS_Storage::base_url() . '/' . self::UPLOAD_DIR;
    }

    /**
     * Сохраняет присланный файл и возвращает публичный URL:
     * агрегатору нужен именно адрес, а не содержимое.
     *
     * @return array{ok:bool,url:string,message:string}
     */
    public static function store_upload($file, $kind, $max_bytes) {
        $fail = function ($message) {
            return array('ok' => false, 'url' => '', 'message' => $message);
        };

        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return $fail('Файл не получен');
        }
        if ((int) $file['size'] > $max_bytes) {
            return $fail('Файл больше ' . round($max_bytes / 1048576) . ' МБ');
        }

        $check = wp_check_filetype_and_ext($file['tmp_name'], (string) $file['name']);
        $ext = $check['ext'] ? $check['ext'] : strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = $kind === 'image'
            ? array('jpg', 'jpeg', 'png')
            : array('mp3', 'wav', 'aac', 'm4a', 'mp4', 'ogg', 'oga');
        if (!in_array($ext, $allowed, true)) {
            return $fail('Неподдерживаемый формат: ' . $ext);
        }

        $dir = self::uploads_dir() . '/' . gmdate('Y-m');
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $name = $kind . '-' . wp_generate_password(18, false, false) . '.' . $ext;
        $target = $dir . '/' . $name;

        if (!@move_uploaded_file($file['tmp_name'], $target)) {
            return $fail('Не удалось сохранить файл');
        }
        @chmod($target, 0644);

        return array(
            'ok'      => true,
            'url'     => self::uploads_url() . '/' . gmdate('Y-m') . '/' . $name,
            'message' => '',
        );
    }

    /* ---------------------------------------------------------------------
     * Вызовы агрегатора
     * ------------------------------------------------------------------ */

    private static function api_key() {
        return trim((string) get_option('kie_tts_api_key', ''));
    }

    private static function post_json($url, $payload) {
        $key = self::api_key();
        if ($key === '') {
            return array('ok' => false, 'message' => 'Генерация временно недоступна: не настроен доступ к сервису', 'body' => array());
        }
        $response = wp_remote_post($url, array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode($payload),
        ));
        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => $response->get_error_message(), 'body' => array());
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return array('ok' => false, 'message' => 'Некорректный ответ сервиса генерации', 'body' => array());
        }
        if ((int) ($body['code'] ?? 0) !== 200) {
            return array('ok' => false, 'message' => (string) ($body['msg'] ?? 'Сервис генерации вернул ошибку'), 'body' => $body);
        }
        return array('ok' => true, 'message' => '', 'body' => $body);
    }

    private static function get_json($url, $args) {
        $key = self::api_key();
        if ($key === '') {
            return array('ok' => false, 'message' => 'Генерация временно недоступна', 'body' => array());
        }
        $response = wp_remote_get(add_query_arg($args, $url), array(
            'timeout' => 45,
            'headers' => array('Authorization' => 'Bearer ' . $key),
        ));
        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => $response->get_error_message(), 'body' => array());
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !isset($body['data'])) {
            return array('ok' => false, 'message' => 'Некорректный ответ сервиса генерации', 'body' => array());
        }
        return array('ok' => true, 'message' => '', 'body' => $body);
    }

    /**
     * Ставит задачу сервиса.
     *
     * @return array{ok:bool,task_id:string,message:string}
     */
    /** Отказ службы извлечения — на языке пользователя. */
    private static function ytaudio_error($message) {
        $low = mb_strtolower((string) $message);
        if (strpos($low, 'ключ') !== false || strpos($low, 'key') !== false) {
            return 'Служба извлечения звука не настроена — сообщите нам, починим.';
        }
        if (strpos($low, 'timeout') !== false || strpos($low, 'таймаут') !== false || strpos($low, 'timed out') !== false) {
            return 'Служба извлечения звука не ответила вовремя. Попробуйте ещё раз через минуту.';
        }
        if (strpos($low, 'sign in') !== false || strpos($low, 'age') !== false || strpos($low, 'private') !== false) {
            return 'Ролик требует входа в аккаунт или закрыт — дорожку снять нельзя.';
        }
        if (strpos($low, 'duration') !== false) {
            return 'Ролик слишком длинный. Разрежьте запись или возьмите фрагмент покороче.';
        }
        return (string) $message;
    }

    /** Короткое название трека из описания — когда пользователь его не задал. */
    private static function music_title($prompt) {
        $prompt = trim(preg_replace('~\s+~u', ' ', (string) $prompt));
        if ($prompt === '') {
            return 'Трек';
        }
        $words = preg_split('~\s+~u', $prompt);
        $words = array_slice($words, 0, 5);
        // Обрывок вида «бит для» выглядит небрежно: отбрасываем хвостовые
        // предлоги и союзы, на которых название повисает.
        $tail = array('для', 'под', 'из', 'в', 'на', 'с', 'и', 'о', 'по', 'к', 'от', 'до', 'при', 'без');
        while ($words && in_array(mb_strtolower(rtrim(end($words), ',.')), $tail, true)) {
            array_pop($words);
        }
        $title = trim(implode(' ', $words), " ,.;:-");
        if ($title === '') {
            return 'Трек';
        }
        return function_exists('mb_convert_case')
            ? mb_convert_case(mb_substr($title, 0, 1), MB_CASE_UPPER) . mb_substr($title, 1)
            : $title;
    }

    /** «3 мин 24 с» — подпись длительности трека. */
    private static function human_length($seconds) {
        $seconds = max(0, (int) $seconds);
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return $m > 0 ? $m . ' мин ' . $s . ' с' : $s . ' с';
    }

    public static function create_task($id, $params) {
        $callback = add_query_arg('token', GS_SFX::callback_token(), rest_url(GS_Rest::NS . '/lab/callback'));

        // Разделение дорожек и шумоподавление — на отдельном поставщике,
        // а пока его нет — заказом в ручную очередь.
        if (class_exists('GS_MusicAI') && GS_MusicAI::handles($id)) {
            if (GS_MusicAI::ready($id)) {
                return GS_MusicAI::create_job($id, (string) $params['audio_url']);
            }
            if (GS_Manual::enabled($id)) {
                return GS_Manual::create_order($id, $params);
            }
            return array('ok' => false, 'task_id' => '', 'message' => 'Инструмент сейчас недоступен');
        }

        if ($id === 'avatar') {
            $res = self::post_json(self::API_JOBS, array(
                'model'       => 'kling/ai-avatar-pro',
                'callBackUrl' => $callback,
                'input'       => array(
                    'image_url' => (string) $params['image_url'],
                    'audio_url' => (string) $params['audio_url'],
                    'prompt'    => (string) ($params['prompt'] ?? ''),
                ),
            ));
            $task = $res['ok'] ? (string) ($res['body']['data']['taskId'] ?? '') : '';
            return array('ok' => $res['ok'] && $task !== '', 'task_id' => $task, 'message' => $res['message']);
        }

        if ($id === 'denoise') {
            $res = self::post_json(self::API_JOBS, array(
                'model'       => 'elevenlabs/audio-isolation',
                'callBackUrl' => $callback,
                'input'       => array('audio_url' => (string) $params['audio_url']),
            ));
            $task = $res['ok'] ? (string) ($res['body']['data']['taskId'] ?? '') : '';
            return array('ok' => $res['ok'] && $task !== '', 'task_id' => $task, 'message' => $res['message']);
        }

        if ($id === 'stt') {
            $fields = isset($params['fields']) && is_array($params['fields']) ? $params['fields'] : array();
            $link = trim((string) ($fields['source_url'] ?? ''));
            $audio = (string) ($params['audio_url'] ?? '');
            if ($audio === '' && $link === '') {
                return array('ok' => false, 'task_id' => '', 'message' => 'Загрузите запись или вставьте ссылку');
            }

            $source = array(
                'language_code'    => trim((string) ($fields['language'] ?? '')),
                'tag_audio_events' => !empty($fields['events']),
                'diarize'          => !empty($fields['diarize']),
            );
            // Прямую ссылку на файл отдаём как есть, ссылку на ролик — через
            // извлечение дорожки: разбирать её здесь незачем, это умеет очередь.
            if ($audio !== '') {
                $source['audio_url'] = $audio;
            } elseif (preg_match('~\.(mp3|wav|m4a|ogg|opus|aac|mp4|webm)(\?|$)~i', $link)) {
                $source['audio_url'] = $link;
            } else {
                $source['youtube_url'] = $link;
            }

            $created = GS_Transcribe::create($source, (int) ($params['user_id'] ?? 0));
            if (is_wp_error($created)) {
                return array('ok' => false, 'task_id' => '', 'message' => $created->get_error_message());
            }
            return array('ok' => true, 'task_id' => (string) $created['id'], 'message' => '');
        }

        if ($id === 'ytaudio') {
            $fields = isset($params['fields']) && is_array($params['fields']) ? $params['fields'] : array();
            $url = esc_url_raw(trim((string) ($fields['url'] ?? '')));
            if ($url === '') {
                return array('ok' => false, 'task_id' => '', 'message' => 'Вставьте ссылку на ролик');
            }
            $format = strtolower(trim((string) ($fields['format'] ?? 'mp3')));
            if (!in_array($format, array('mp3', 'wav', 'm4a'), true)) {
                $format = 'mp3';
            }
            if (!class_exists('KIE_TTS_API')) {
                return array('ok' => false, 'task_id' => '', 'message' => 'Извлечение звука сейчас недоступно');
            }

            // Служба отвечает сразу, очереди у неё нет: складываем готовый
            // ответ под своим номером, чтобы страница работала как обычно.
            $data = KIE_TTS_API::create_youtube_audio_task($url, $format);
            $audio = is_array($data) && !empty($data['audio_url']) ? esc_url_raw((string) $data['audio_url']) : '';
            if ($audio === '') {
                $message = is_array($data) && !empty($data['message'])
                    ? (string) $data['message']
                    : 'Не удалось получить дорожку по этой ссылке';
                return array('ok' => false, 'task_id' => '', 'message' => self::ytaudio_error($message));
            }

            $task_id = 'yta-' . wp_generate_password(20, false, false);
            $title = is_array($data) && !empty($data['title']) ? (string) $data['title'] : 'Звуковая дорожка';
            set_transient('gs_lab_yta_' . $task_id, array(
                'url'   => $audio,
                'title' => $title,
            ), DAY_IN_SECONDS);
            return array('ok' => true, 'task_id' => $task_id, 'message' => '');
        }

        if ($id === 'music') {
            $fields = isset($params['fields']) && is_array($params['fields']) ? $params['fields'] : array();
            $instrumental = !isset($fields['instrumental']) || !empty($fields['instrumental']);
            $lyrics = trim((string) ($fields['lyrics'] ?? ''));
            $style  = trim((string) ($fields['style'] ?? ''));
            $title  = trim((string) ($fields['title'] ?? ''));
            $prompt = trim((string) ($params['prompt'] ?? ''));

            // Свой текст песни поставщик принимает только в «своём» режиме,
            // где описание и стиль задаются отдельными полями.
            $custom = (!$instrumental && $lyrics !== '') || $style !== '' || $title !== '';

            $payload = array(
                'model'        => 'V5',
                'customMode'   => $custom,
                'instrumental' => $instrumental,
                'callBackUrl'  => $callback,
            );
            if ($custom) {
                // В своём режиме prompt — это текст песни, а описание уходит в стиль.
                $payload['style'] = mb_substr($style !== '' ? $style : $prompt, 0, 200);
                $payload['title'] = mb_substr($title !== '' ? $title : self::music_title($prompt), 0, 80);
                $payload['prompt'] = $instrumental ? '' : mb_substr($lyrics !== '' ? $lyrics : $prompt, 0, 2500);
                if ($instrumental) {
                    unset($payload['prompt']);
                }
            } else {
                $payload['prompt'] = mb_substr($prompt, 0, 1000);
            }

            $res = self::post_json(self::API_MUSIC, $payload);
            $task = $res['ok'] ? (string) ($res['body']['data']['taskId'] ?? '') : '';
            return array('ok' => $res['ok'] && $task !== '', 'task_id' => $task, 'message' => $res['message']);
        }

        if ($id === 'vocal') {
            $res = self::post_json(self::API_VOCAL, array(
                'audioUrl'    => (string) $params['audio_url'],
                'audioId'     => 'gs-' . wp_generate_password(16, false, false),
                'type'        => 'separate_vocal',
                'stemName'    => 'Vocals',
                'callBackUrl' => $callback,
            ));
            $task = $res['ok'] ? (string) ($res['body']['data']['taskId'] ?? '') : '';
            return array('ok' => $res['ok'] && $task !== '', 'task_id' => $task, 'message' => $res['message']);
        }

        return array('ok' => false, 'task_id' => '', 'message' => 'Неизвестный сервис');
    }

    /**
     * Статус задачи.
     *
     * @return array{ok:bool,status:string,files:array,message:string}
     */
    public static function fetch_task($id, $task_id) {
        $out = array('ok' => false, 'status' => 'pending', 'files' => array(), 'text' => '', 'message' => '');

        if (class_exists('GS_Manual') && GS_Manual::is_own_task($task_id)) {
            return GS_Manual::state($task_id);
        }

        if (class_exists('GS_MusicAI') && GS_MusicAI::is_own_task($task_id)) {
            return GS_MusicAI::fetch_job($id, $task_id);
        }

        if ($id === 'stt') {
            $state = GS_Transcribe::state($task_id);
            $out['ok']      = true;
            $out['status']  = (string) $state['status'];
            $out['files']   = is_array($state['files']) ? $state['files'] : array();
            $out['text']    = (string) $state['text'];
            $out['message'] = (string) $state['message'];
            return $out;
        }

        if ($id === 'ytaudio') {
            $saved = get_transient('gs_lab_yta_' . $task_id);
            $out['ok'] = true;
            if (!is_array($saved) || empty($saved['url'])) {
                $out['status'] = 'failed';
                $out['message'] = 'Дорожка не найдена — попробуйте ещё раз';
                return $out;
            }
            $out['status'] = 'completed';
            $out['files'][] = array(
                'label' => (string) $saved['title'],
                'url'   => (string) $saved['url'],
                'kind'  => 'audio',
            );
            return $out;
        }

        if ($id === 'music') {
            $res = self::get_json(self::API_MUSIC_INFO, array('taskId' => $task_id));
            if (!$res['ok']) {
                $out['message'] = $res['message'];
                return $out;
            }
            $data = is_array($res['body']['data']) ? $res['body']['data'] : array();
            $out['ok'] = true;
            $status = (string) ($data['status'] ?? '');

            if (in_array($status, array('CREATE_TASK_FAILED', 'GENERATE_AUDIO_FAILED', 'CALLBACK_EXCEPTION', 'SENSITIVE_WORD_ERROR'), true)) {
                $out['status'] = 'failed';
                $out['message'] = $status === 'SENSITIVE_WORD_ERROR'
                    ? 'Описание не прошло проверку — переформулируйте запрос'
                    : 'Не удалось создать трек';
                return $out;
            }

            $items = array();
            if (!empty($data['response']['sunoData']) && is_array($data['response']['sunoData'])) {
                $items = $data['response']['sunoData'];
            }
            // Поставщик отдаёт первый вариант раньше второго: показываем готовое
            // только когда пришли оба и у них есть окончательные ссылки.
            $files = array();
            foreach ($items as $index => $item) {
                $url = '';
                foreach (array('audioUrl', 'audio_url', 'sourceAudioUrl', 'source_audio_url') as $field) {
                    if (!empty($item[$field])) {
                        $url = (string) $item[$field];
                        break;
                    }
                }
                if ($url === '') {
                    continue;
                }
                $name = trim((string) ($item['title'] ?? ''));
                $seconds = isset($item['duration']) ? (int) round((float) $item['duration']) : 0;
                $label = ($name !== '' ? $name : 'Вариант ' . ((int) $index + 1));
                if ($seconds > 0) {
                    $label .= ' — ' . self::human_length($seconds);
                }
                $files[] = array('label' => $label, 'url' => $url, 'kind' => 'audio');
            }
            if ($status === 'SUCCESS' && $files) {
                $out['status'] = 'completed';
                $out['files'] = $files;
            }
            return $out;
        }

        if ($id === 'vocal') {
            $res = self::get_json(self::API_VOCAL_INFO, array('taskId' => $task_id));
            if (!$res['ok']) {
                $out['message'] = $res['message'];
                return $out;
            }
            $data = $res['body']['data'];
            $flag = (string) ($data['successFlag'] ?? '');
            $out['ok'] = true;

            if (in_array($flag, array('CREATE_TASK_FAILED', 'GENERATE_AUDIO_FAILED', 'CALLBACK_EXCEPTION'), true)) {
                $out['status'] = 'failed';
                $out['message'] = 'Не удалось разделить дорожки';
                return $out;
            }
            $resp = isset($data['response']) && is_array($data['response']) ? $data['response'] : array();
            if (!empty($resp['instrumentalUrl']) || !empty($resp['vocalUrl'])) {
                $out['status'] = 'completed';
                if (!empty($resp['instrumentalUrl'])) {
                    $out['files'][] = array('label' => 'Минусовка (инструментал)', 'url' => (string) $resp['instrumentalUrl'], 'kind' => 'audio');
                }
                if (!empty($resp['vocalUrl'])) {
                    $out['files'][] = array('label' => 'Вокал без музыки', 'url' => (string) $resp['vocalUrl'], 'kind' => 'audio');
                }
            }
            return $out;
        }

        $res = self::get_json(self::API_JOBS_INFO, array('taskId' => $task_id));
        if (!$res['ok']) {
            $out['message'] = $res['message'];
            return $out;
        }
        $data = $res['body']['data'];
        $out['ok'] = true;
        $state = (string) ($data['state'] ?? 'waiting');

        if ($state === 'fail') {
            $out['status'] = 'failed';
            $out['message'] = (string) ($data['failMsg'] ?? 'Генерация не удалась');
            return $out;
        }
        if ($state !== 'success') {
            return $out;
        }

        $result = isset($data['resultJson']) ? $data['resultJson'] : array();
        if (is_string($result)) {
            $decoded = json_decode($result, true);
            $result = is_array($decoded) ? $decoded : array();
        }
        $urls = array();
        if (!empty($result['resultUrls'])) {
            $urls = is_array($result['resultUrls']) ? $result['resultUrls'] : array($result['resultUrls']);
        }
        if (empty($urls)) {
            return $out;
        }

        $service = self::get_service($id);
        $kind = ($service && $service['result_kind'] === 'video') ? 'video' : 'audio';
        $label = $kind === 'video' ? 'Готовое видео' : 'Очищенная запись';
        foreach ($urls as $url) {
            $out['files'][] = array('label' => $label, 'url' => (string) $url, 'kind' => $kind);
        }
        $out['status'] = 'completed';
        return $out;
    }

    /**
     * Разбор форматов запроса на разделение дорожек: документация поставщика
     * противоречива, поэтому пробуем варианты и смотрим на живой ответ.
     */
    public static function vocal_probe($audio_url, $variant = 1) {
        $callback = add_query_arg('token', GS_SFX::callback_token(), rest_url(GS_Rest::NS . '/lab/callback'));

        $payloads = array(
            1 => array('audioUrl' => $audio_url, 'type' => 'separate_vocal', 'callBackUrl' => $callback),
            2 => array('audioUrl' => $audio_url, 'type' => 'separate_vocal', 'stemName' => 'Vocals', 'callBackUrl' => $callback),
            3 => array('uploadUrl' => $audio_url, 'type' => 'separate_vocal', 'callBackUrl' => $callback),
            4 => array('audioUrl' => $audio_url, 'type' => 'split_stem', 'stemName' => 'Vocals', 'callBackUrl' => $callback),
        );
        // Вариант 0 — не постановка задачи, а сырой ответ о её состоянии:
        // по нему видно настоящую причину отказа, а не нашу трактовку.
        if ($variant === 0) {
            $key = self::api_key();
            $response = wp_remote_get(
                add_query_arg('taskId', $audio_url, self::API_VOCAL_INFO),
                array('timeout' => 45, 'headers' => array('Authorization' => 'Bearer ' . $key))
            );
            if (is_wp_error($response)) {
                return array('variant' => 0, 'ok' => false, 'message' => $response->get_error_message(), 'body' => array());
            }
            return array(
                'variant' => 0,
                'ok'      => true,
                'http'    => (int) wp_remote_retrieve_response_code($response),
                'raw'     => mb_substr((string) wp_remote_retrieve_body($response), 0, 1500),
            );
        }

        $payload = isset($payloads[$variant]) ? $payloads[$variant] : $payloads[1];

        $res = self::post_json(self::API_VOCAL, $payload);
        return array(
            'variant' => $variant,
            'sent'    => array_keys($payload),
            'ok'      => !empty($res['ok']),
            'message' => (string) $res['message'],
            'body'    => $res['body'],
        );
    }

    /**
     * Остаток кредитов у поставщика — без этого нельзя считать себестоимость
     * операции и осмысленно назначать цену для пользователя.
     *
     * @return array{ok:bool,credits:float,message:string}
     */
    public static function provider_credits() {
        $res = self::get_json('https://api.kie.ai/api/v1/chat/credit', array());
        if (empty($res['ok'])) {
            return array('ok' => false, 'credits' => 0.0, 'message' => $res['message']);
        }
        $data = $res['body']['data'];
        $credits = is_array($data) ? (float) ($data['credit'] ?? $data['credits'] ?? 0) : (float) $data;
        return array('ok' => true, 'credits' => $credits, 'message' => '');
    }

    /**
     * Сколько кредитов реально съела задача — из этого считается себестоимость.
     *
     * @return array{ok:bool,credits:float,state:string,message:string}
     */
    public static function task_credits($task_id) {
        $res = self::get_json(self::API_JOBS_INFO, array('taskId' => (string) $task_id));
        if (empty($res['ok'])) {
            return array('ok' => false, 'credits' => 0.0, 'state' => '', 'message' => $res['message']);
        }
        $data = is_array($res['body']['data']) ? $res['body']['data'] : array();
        $credits = 0.0;
        foreach (array('creditsConsumed', 'credits_consumed', 'costCredits', 'credits') as $field) {
            if (isset($data[$field])) {
                $credits = (float) $data[$field];
                break;
            }
        }
        return array(
            'ok'      => true,
            'credits' => $credits,
            'state'   => (string) ($data['state'] ?? ''),
            'message' => '',
        );
    }

    /**
     * Расширение файла по типу содержимого, с запасным вариантом по виду задачи.
     */
    private static function ext_from_type($content_type, $kind) {
        $map = array(
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'video/mp4'  => 'mp4',
            'audio/mpeg' => 'mp3',
            'audio/wav'  => 'wav',
            'audio/x-wav'=> 'wav',
            'audio/ogg'  => 'ogg',
            'audio/mp4'  => 'm4a',
        );
        $type = strtolower(trim(explode(';', (string) $content_type)[0]));
        if (isset($map[$type])) {
            return $map[$type];
        }
        if ($kind === 'video') {
            return 'mp4';
        }
        return $kind === 'image' ? 'png' : 'mp3';
    }

    /**
     * Копируем результат к себе: ссылки агрегатора живут ограниченное время.
     */
    public static function store_result($task_id, $url, $kind) {
        $task_id = preg_replace('~[^a-zA-Z0-9_-]~', '', (string) $task_id);
        if ($task_id === '' || $url === '') {
            return '';
        }
        // Файл уже лежит у нас (например, приложен вручную) — копировать нечего.
        if (strpos((string) $url, GS_Storage::generated_url()) === 0) {
            return (string) $url;
        }
        GS_Storage::ensure_dirs();

        $known = array('mp3', 'wav', 'mp4', 'ogg', 'm4a', 'png', 'jpg', 'jpeg', 'webp');
        $ext = strtolower((string) pathinfo(wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($ext, $known, true)) {
            $ext = '';
        }

        // Имя уже известно — отдаём готовый файл, не скачивая заново.
        if ($ext !== '') {
            $name = $task_id . '-' . substr(md5($url), 0, 8) . '.' . $ext;
            $target = GS_Storage::generated_dir() . '/' . $name;
            if (file_exists($target) && filesize($target) > 1024) {
                return GS_Storage::generated_url() . '/' . $name;
            }
        }

        $response = wp_remote_get($url, array('timeout' => 180));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        $body = wp_remote_retrieve_body($response);

        // Ссылки моделей часто без расширения: тип берём из ответа,
        // иначе картинка ляжет на диск как mp3 и не откроется у клиента.
        if ($ext === '') {
            $ext = self::ext_from_type((string) wp_remote_retrieve_header($response, 'content-type'), $kind);
        }
        $name = $task_id . '-' . substr(md5($url), 0, 8) . '.' . $ext;
        $target = GS_Storage::generated_dir() . '/' . $name;
        if (file_exists($target) && filesize($target) > 1024) {
            return GS_Storage::generated_url() . '/' . $name;
        }
        if (strlen((string) $body) < 1024) {
            return '';
        }
        if (!GS_Storage::atomic_put($target, $body)) {
            return '';
        }
        return GS_Storage::generated_url() . '/' . $name;
    }
}
