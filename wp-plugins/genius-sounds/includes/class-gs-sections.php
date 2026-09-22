<?php
/**
 * Разделы каталога: средний уровень между каталогом и 945 подборками.
 *
 * Каталог был плоским: с одной страницы вело 945 ссылок на подборки, и все
 * они лежали на одной глубине. Для поиска это значит две вещи, обе плохие.
 * Робот обходит сайт по 10–70 страниц в сутки (цифра из Вебмастера) и до
 * дальних подборок доходит месяцами. А вес страницы делится на 945 ссылок
 * сразу, поэтому ни одна не выглядит важной.
 *
 * Раздел решает обе: ссылок с каталога становится дюжина, внутри раздела —
 * несколько десятков, и у каждой подборки появляется соседство по смыслу,
 * из которого поиск понимает, о чём она.
 *
 * Раздел вычисляется по слагу и названию, а не хранится отдельным полем в
 * каждой из 945 подборок: иначе после каждого импорта пришлось бы
 * вспоминать, что где-то есть ещё одна таблица соответствий. Правила лежат
 * здесь, и новая подборка попадает в раздел сама.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Sections {

    /** Раздел для всего, что не опознали. Без него подборка выпадает из иерархии. */
    const FALLBACK = 'raznoe';

    /**
     * Разделы в порядке показа.
     *
     * Порядок правил важен: подборка попадает в первый подошедший раздел.
     * Поэтому узкие темы («звуки для игр») стоят раньше широких («предметы»).
     *
     * @return array<string,array{title:string,menu:string,lead:string,words:string[]}>
     */
    public static function all() {
        static $all = null;
        if ($all !== null) {
            return $all;
        }

        $all = array(
            'kino-i-citaty' => array(
                'title' => 'Цитаты из фильмов и известные голоса',
                'menu'  => 'Кино и цитаты',
                'lead'  => 'Реплики из фильмов, мультфильмов и передач — то, что узнают с первой секунды.',
                'ru'    => array(
                    'из кино', 'из фильм', 'мультфильм', 'персонаж', 'реплик', 'сериал', 'цитат'
                ),
                'lat'   => array(
                    'citat', 'kinocitat', 'multfilm', 'serial'
                ),
            ),
            'strashnye-zvuki' => array(
                'title' => 'Страшные звуки и хоррор',
                'menu'  => 'Страшное',
                'lead'  => 'Скримеры, шорохи и потусторонние звуки для хоррор-роликов, игр и розыгрышей.',
                'ru'    => array(
                    'вампир', 'ведьм', 'демон', 'жутк', 'зомби', 'кладбищ', 'крипи', 'мистич', 'монстр',
                    'паранорм', 'потусторон', 'призрак', 'пугающ', 'скример', 'страшн', 'ужас', 'хоррор'
                ),
                'lat'   => array(
                    'demon', 'horror', 'kladbisch', 'kripi', 'mistik', 'monstr', 'prizrak', 'pugaush',
                    'skrimer', 'strashn', 'uzhas', 'vampir', 'vedm', 'zhutk', 'zombie'
                ),
            ),
            'dlya-strimov-i-donatov' => array(
                'title' => 'Звуки для стримов и донатов',
                'menu'  => 'Стримы и донаты',
                'lead'  => 'Оповещения о донатах, подписках и сообщениях: короткие звуки, которые слышно поверх игры и речи.',
                'ru'    => array(
                    'twitch', 'алерт', 'донат', 'оповещен для', 'подписк', 'стрим', 'чат'
                ),
                'lat'   => array(
                    'alert', 'donat', 'donationalerts', 'podpisk', 'stream', 'strim', 'twitch'
                ),
            ),
            'zvuki-dlya-igr' => array(
                'title' => 'Звуки для игр',
                'menu'  => 'Игры',
                'lead'  => 'Эффекты для игровых сцен: удары, подбор предметов, победы, проигрыши и интерфейсные сигналы.',
                'ru'    => array(
                    '8 бит', '8-бит', 'counter', 'dota', 'fortnite', 'game', 'minecraft', 'mortal', 'roblox',
                    'аркад', 'бонус', 'босс', 'геймер', 'дота', 'заклинан', 'игр', 'квест', 'кс', 'магия',
                    'майнкрафт', 'монет', 'мортал', 'перезаряд', 'пиксел', 'победа', 'портал', 'проигрыш',
                    'роблокс', 'телепорт', 'уровен', 'фортнайт', 'хедшот'
                ),
                'lat'   => array(
                    'arkad', 'bonus', 'boss', 'dota', 'fortnite', 'game', 'geym', 'igr', 'kvest', 'magi',
                    'minecraft', 'monet', 'mortal', 'pikseln', 'pobed', 'portal', 'proigr', 'roblox',
                    'teleport', 'uroven', 'zaklinan'
                ),
            ),
            'oruzhie-i-voyna' => array(
                'title' => 'Оружие, взрывы и война',
                'menu'  => 'Оружие и война',
                'lead'  => 'Выстрелы, взрывы, техника и голоса боя — дорожки для военных сцен и игровых роликов.',
                'ru'    => array(
                    'автомат', 'арбалет', 'артиллер', 'атаки', 'базук', 'бомб', 'броне', 'бумеранг', 'взрыв',
                    'воен', 'войн', 'выстрел', 'гранат', 'доспех', 'дубинк', 'истребител', 'калашник',
                    'лук и стрел', 'мачете', 'меч', 'миномёт', 'нож', 'ночного видения', 'нунчак', 'оруж',
                    'пистолет', 'пулем', 'пуль', 'пуля', 'разрубан', 'ракет', 'рассечен', 'сабл',
                    'сирена воздуш', 'снайпер', 'снаряд', 'солдат', 'танк', 'тепловизор', 'торпед', 'удар',
                    'щит', 'электрошокер'
                ),
                'lat'   => array(
                    'arbalet', 'artiller', 'ataki', 'avtomat', 'bazuk', 'bomb', 'brone', 'bumeranga', 'dospeh',
                    'dubinka', 'elektroshokera', 'granat', 'istrebitel', 'kalashnik', 'machete', 'mech',
                    'minomet', 'nochnogo-videniya', 'nunchak', 'oruzh', 'pistolet', 'pulem', 'pulya', 'raket',
                    'razrubanie', 'sabl', 'snaryad', 'snayper', 'soldat', 'tank', 'torped', 'udara', 'voen',
                    'voyn', 'vystrel', 'vzryv'
                ),
            ),
            'transport-i-tehnika' => array(
                'title' => 'Транспорт и техника',
                'menu'  => 'Транспорт',
                'lead'  => 'Машины, поезда, самолёты и моторы: записи движения и работы двигателей для монтажа.',
                'ru'    => array(
                    'авто', 'автобус', 'бензопил', 'велосипед', 'вертолет', 'вертолёт', 'грузовик', 'гудок',
                    'дверь машин', 'двигател', 'катер', 'квадроцикл', 'клаксон', 'колес', 'корабл', 'лодк',
                    'машин', 'метро', 'мотор', 'мотоцикл', 'паром', 'поезд', 'поезда', 'руль', 'самолет',
                    'самолёт', 'сигнал авто', 'скутер', 'снегоход', 'такси', 'тормоз', 'трактор', 'трамва',
                    'троллейбус', 'шин', 'шиномонтаж', 'экскаватор', 'электричк', 'яхт'
                ),
                'lat'   => array(
                    'avto', 'avtobus', 'benzopil', 'dvigat', 'ekskavator', 'elektrichk', 'gruzovik', 'kater',
                    'klakson', 'korabl', 'kvadrocikl', 'lodk', 'mashin', 'metro', 'motocikl', 'motor', 'parom',
                    'poezd', 'samolet', 'shinomontaj', 'skuter', 'snegohod', 'taksi', 'tormoz', 'traktor',
                    'tramva', 'trolleybus', 'velosiped', 'vertolet', 'yaht'
                ),
            ),
            'zvuki-zhivotnyh' => array(
                'title' => 'Звуки животных и птиц',
                'menu'  => 'Животные',
                'lead'  => 'Голоса зверей, птиц и насекомых: от домашней кошки до рёва хищника — записи для монтажа, игр и детских занятий.',
                'ru'    => array(
                    'акул', 'альпак', 'бабочк', 'барсук', 'белк', 'блеян', 'бобр', 'буйвол', 'бульдог',
                    'верблюд', 'волк', 'ворон', 'гепард', 'гиен', 'голуб', 'горилл', 'грызун', 'гус',
                    'гусениц', 'дельфин', 'динозавр', 'дракон', 'ежа', 'енот', 'жеребён', 'животн', 'жираф',
                    'жук', 'заяц', 'зебр', 'змеи', 'змей', 'индюк', 'кабан', 'кенгуру', 'кит', 'козл',
                    'козлён', 'комар', 'конь', 'коров', 'кот', 'кота', 'котён', 'кош', 'кошк', 'крокодил',
                    'кролик', 'крыс', 'кур', 'лает', 'лама', 'ламы', 'лев', 'леопард', 'лис', 'лось', 'лошад',
                    'льв', 'лягуш', 'медвед', 'муравь', 'мурлык', 'мух', 'мычан', 'мышь', 'мяука', 'носорог',
                    'обезьян', 'овц', 'олен', 'оса', 'осы', 'павлин', 'панд', 'пантер', 'паук', 'пес', 'петух',
                    'пингвин', 'питон', 'пони', 'попуг', 'птиц', 'пчел', 'пёс', 'ржан', 'рыб', 'рысь', 'рычит',
                    'саранч', 'сверчок', 'светлячк', 'свин', 'скунс', 'слон', 'собак', 'сов', 'страус',
                    'стрекоз', 'таракан', 'телён', 'тигр', 'утк', 'фламинго', 'хомяк', 'хорьк', 'цикад',
                    'цыплят', 'чайк', 'червяк', 'черепах', 'шакал', 'шиншилл', 'шмел', 'щенк', 'щенят',
                    'ягнён', 'ягуар', 'як', 'ящериц', 'ёж'
                ),
                'lat'   => array(
                    'akul', 'alpak', 'babochek', 'barsuk', 'belk', 'bobr', 'buldog', 'buyvol', 'chayk',
                    'cherepah', 'chervyakov', 'cikad', 'cyplyat', 'delfin', 'dinozavr', 'drakon', 'enot',
                    'flamingo', 'gepard', 'gien', 'golub', 'gorill', 'gryzun', 'homyak', 'hork', 'indyuk',
                    'jika', 'jiraf', 'juka', 'kaban', 'kenguru', 'kit-', 'komar', 'korov', 'kosh', 'kot-',
                    'krokodil', 'krolik', 'krys', 'kuric', 'leopard', 'lev-', 'lis', 'los', 'loshad',
                    'lyagush', 'medved', 'muh', 'muravy', 'murlyk', 'mysh', 'nosorog', 'obezyan', 'olen',
                    'osyi', 'ovc', 'pand', 'panter', 'pauk', 'pavlin', 'pchel', 'petuh', 'pingvin', 'poni',
                    'popug', 'ptic', 'ryb', 'rys', 'saranch', 'schenk', 'shakal', 'shinshill', 'shmel',
                    'skuns', 'slon', 'sobak', 'sov', 'strau', 'strekoz', 'sverch', 'svetlyachkov', 'svin',
                    'tarakana', 'tigr', 'utk', 'verblyud', 'volk', 'voron', 'yaguar', 'yascherit', 'zayac',
                    'zebr', 'zhivotn', 'zmei', 'zmey'
                ),
            ),
            'zvuki-prirody' => array(
                'title' => 'Звуки природы',
                'menu'  => 'Природа',
                'lead'  => 'Дождь, ветер, море, лес и гроза: длинные записи для фона, сна и медитации.',
                'ru'    => array(
                    'болот', 'бур', 'ветер', 'ветр', 'вода', 'водопад', 'воды', 'волн', 'вулкан', 'гейзер',
                    'горн', 'град', 'гроз', 'гром', 'дожд', 'закат', 'землетряс', 'извержен', 'иней', 'капл',
                    'костер', 'костёр', 'лав', 'лед', 'лес', 'лив', 'листь', 'лёд', 'магм', 'метел', 'море',
                    'морск', 'ночн лес', 'огон', 'озер', 'пещер', 'прибой', 'природ', 'рассвет', 'река',
                    'ручей', 'смерч', 'снег', 'сосульк', 'торнадо', 'трав', 'туман', 'ураган', 'шторм'
                ),
                'lat'   => array(
                    'bolot', 'dozhd', 'geyzer', 'grad', 'grom', 'groz', 'kapl', 'koster', 'lavyi', 'les-',
                    'listy', 'liven', 'magm', 'metel', 'more', 'ogon', 'ozer', 'pescher', 'priboy', 'prirod',
                    'reka', 'ruchey', 'shtorm', 'smerch', 'sneg', 'sosulk', 'tornado', 'trav', 'tuman',
                    'uragan', 'veter', 'vetra', 'vodopad', 'voln', 'vulkan', 'zemletryas'
                ),
            ),
            'fonovaya-muzyka' => array(
                'title' => 'Фоновая музыка без авторских прав',
                'menu'  => 'Фоновая музыка',
                'lead'  => 'Инструментальные треки и мелодии без слов для видео, роликов, презентаций и подкастов: слушайте онлайн и скачивайте в MP3.',
                'ru'    => array(
                    '528', 'агого', 'аккорд', 'аккордеон', 'арф', 'балалайк', 'банджо', 'барабан', 'белый шум',
                    'бит', 'битов', 'блюз', 'бонг', 'бубен', 'вальс', 'винил', 'виолончел', 'волынк', 'гимн',
                    'гитар', 'гонг', 'граммофон', 'дабстеп', 'джаз', 'диджеридо', 'диджериду', 'дискотек',
                    'домбр', 'дрилл', 'жанр', 'инструментал', 'каджон', 'калимб', 'кантри', 'кастаньет',
                    'классическ', 'колокольчик', 'колыбельн', 'конг', 'контрабас', 'ксилофон', 'латино',
                    'лаунж', 'литавр', 'лоуфай', 'маракас', 'марш', 'медленн', 'мелоди', 'минус', 'музык',
                    'музыкальн инструмент', 'низкочастотн', 'ноты', 'орган', 'оркестр', 'перкусс', 'пианино',
                    'пластинк', 'поющ', 'регги', 'ремикс', 'ритм', 'розовый шум', 'рок-', 'рока', 'романтичн',
                    'рэп', 'саксофон', 'саундтрек', 'семпл', 'синтезатор', 'скрипк', 'там-там', 'тамбурин',
                    'танцевальн', 'тарелк', 'терменвокс', 'техно', 'трек', 'треугольник', 'укулеле', 'фагот',
                    'флейт', 'фолк', 'фонк', 'фортепиан', 'ханг', 'хаус', 'хип-хоп', 'хор', 'хора', 'чилл',
                    'эмбиент', 'эпичн'
                ),
                'lat'   => array(
                    'agogo', 'akkordeon', 'ambient', 'arfa', 'balalayk', 'bandjo', 'baraban', 'bely-shum',
                    'bit-', 'blyuz', 'bongo', 'buben', 'chill', 'dabstep', 'didjeridu', 'diskotek', 'drill',
                    'drob', 'epich', 'fagot', 'fleyt', 'folk', 'fonk', 'gimn', 'gitar', 'gong', 'grammofon',
                    'hang', 'haus', 'hora', 'instrumental', 'jazz', 'kadjon', 'kalimb', 'kantri', 'kastanet',
                    'klassik', 'kolybeln', 'konga', 'kontrabas', 'ksilofon', 'latino', 'litavr', 'lofi',
                    'lounge', 'marakas', 'marsh', 'medlennaya', 'melodi', 'muz', 'muz-', 'nizkogo-zvuchaniya',
                    'noty', 'organ', 'orkestr', 'perkuss', 'pianino', 'plastinki', 'poyusch', 'regge',
                    'remiks', 'rep-', 'ritm', 'rok-', 'rozovyiy-shum', 'saksofon', 'saundtrek', 'sempl',
                    'sintezator', 'skripk', 'tamburin', 'tancevaln', 'tarelk', 'tehno', 'tishinyi', 'trans',
                    'treugolnik', 'ukulele', 'vals', 'vinil', 'violonchel', 'volink'
                ),
            ),
            'interfeys-i-uvedomleniya' => array(
                'title' => 'Интерфейс, уведомления и кнопки',
                'menu'  => 'Интерфейс и уведомления',
                'lead'  => 'Клики, свайпы, оповещения и системные сигналы: звуки для приложений, сайтов и монтажа.',
                'ru'    => array(
                    'бип', 'будильник', 'входящ', 'гудк', 'домофон', 'загрузк', 'звонок', 'интерфейс', 'касс',
                    'клик', 'кнопк', 'лифт', 'набор номер', 'нотифик', 'оплат', 'ошибк', 'пищал', 'подтвержд',
                    'рингтон', 'секундомер', 'сигнал', 'сирен', 'сканер', 'смс', 'сообщен', 'списани',
                    'таймер', 'телефон', 'терминал', 'тикан', 'турникет', 'уведомл', 'успешн', 'часы'
                ),
                'lat'   => array(
                    'bip', 'budilnik', 'chasy', 'domofon', 'gudk', 'interfejs', 'interfeys', 'kassa', 'klik',
                    'knopk', 'lift', 'notifikac', 'oplat', 'oshibk', 'rington', 'sekundomer', 'signal',
                    'siren', 'skaner', 'sms', 'soobscheni', 'spisani', 'taymer', 'telefon', 'terminal',
                    'tikan', 'turniket', 'uvedoml', 'zagruzk', 'zvonok'
                ),
            ),
            'memy-i-prikoly' => array(
                'title' => 'Мемы, приколы и звуки из роликов',
                'menu'  => 'Мемы и приколы',
                'lead'  => 'Узнаваемые звуки из мемов, шуток и коротких видео — то, что ставят в монтаж ради реакции зрителя.',
                'ru'    => array(
                    'among us', 'tiktok', 'бойнг', 'вайн', 'гренни', 'кринж', 'мем', 'мультяшн', 'навальн',
                    'пранк', 'прикол', 'розыгрыш', 'рофл', 'сигма', 'скибиди', 'смешн', 'тикток', 'треш',
                    'тролл', 'шутк'
                ),
                'lat'   => array(
                    'grenni', 'krinj', 'kринж', 'mem', 'multyashn', 'navalny', 'prank', 'prikol', 'rofl',
                    'rozygrysh', 'shutk', 'sigma', 'skibidi', 'smeshn', 'tiktok', 'troll', 'vayn'
                ),
            ),
            'golosa-i-frazy' => array(
                'title' => 'Голоса, фразы и озвучка',
                'menu'  => 'Голоса и фразы',
                'lead'  => 'Реплики, крики, смех и голосовые сообщения: готовые дорожки для роликов, розыгрышей и монтажа.',
                'ru'    => array(
                    'акцент', 'аплодисмент', 'бормот', 'вздох', 'голос', 'девуш', 'детск голос', 'диктор',
                    'дыхан', 'женск', 'зево', 'икот', 'кашл', 'крик', 'люблю', 'малыш', 'мужск', 'озвуч',
                    'плач', 'поцелу', 'признан', 'разговор', 'речи', 'речь', 'руган', 'свист чело', 'смех',
                    'стон', 'толп', 'тянк', 'фраз', 'хор', 'храп', 'чих', 'шаги люд', 'шепот', 'шёпот'
                ),
                'lat'   => array(
                    'akcent', 'aplodisment', 'bormot', 'chih', 'devush', 'diktor', 'dyhan', 'fraz', 'golos',
                    'hrap', 'kashl', 'krik', 'love', 'malysh', 'molotov', 'muzhsk', 'ozvuch', 'plach',
                    'pocelu', 'razgovor', 'rebenk', 'rech', 'rugan', 'shepot', 'smeh', 'ston', 'tolp', 'tyank',
                    'vzdoh', 'zevo', 'zhensk'
                ),
            ),
            'atmosfera-i-lokacii' => array(
                'title' => 'Атмосфера и локации',
                'menu'  => 'Атмосфера и места',
                'lead'  => 'Интершум помещений и улиц: кафе, больница, стадион, вокзал — фон, на котором происходит сцена.',
                'ru'    => array(
                    'атмосфер', 'аэропорт', 'баня', 'бирж', 'больниц', 'вокзал', 'город', 'деревн', 'завод',
                    'интерьер', 'кафе', 'комнат', 'космос', 'лаборатор', 'локац', 'магазин', 'обстановк',
                    'офис', 'парк', 'пляж', 'помещен', 'ресторан', 'рынок', 'спорт', 'стадион', 'стройк',
                    'толп', 'тюрьм', 'улиц', 'ферм', 'храм', 'церк', 'школ'
                ),
                'lat'   => array(
                    'aeroport', 'atmosfern', 'banya', 'bara', 'birji', 'bolnic', 'cerk', 'derevn', 'ferm',
                    'gorod', 'hram', 'interier', 'kafe', 'komnat', 'kosmos', 'laborator', 'magazin',
                    'obstanovka', 'ofis', 'parka', 'plyazh', 'pomechenie', 'restoran', 'rynok', 'shkol',
                    'sport', 'stadion', 'stroy', 'tyurm', 'ulic', 'vokzal', 'zavod'
                ),
            ),
            'bytovye-zvuki' => array(
                'title' => 'Бытовые звуки и предметы',
                'menu'  => 'Быт и предметы',
                'lead'  => 'Двери, шаги, посуда, инструменты и техника: звуки обычных действий для озвучки сцен.',
                'ru'    => array(
                    'аэрозол', 'бритв', 'бумаг', 'бутыл', 'вилк', 'вязан', 'готовк', 'двер', 'дерев', 'диван',
                    'дрел', 'духовк', 'еда', 'зажигалк', 'замок', 'земл', 'зонт', 'кальян', 'камен',
                    'карандаш', 'картон', 'кастрюл', 'качел', 'кипят', 'клавиатур', 'ключ', 'книг', 'кнопок',
                    'компьютер', 'консерв', 'коробк', 'кран', 'крем', 'кроват', 'крышк', 'лампа', 'летящ',
                    'ложк', 'лопат', 'мебел', 'метал', 'метл', 'микроволнов', 'микрофон', 'молни', 'молоко',
                    'молоток', 'молоток судьи', 'мусор', 'мышеловк', 'мышк', 'нож', 'ножниц', 'огнетушител',
                    'одежд', 'отмычк', 'пакет', 'песок', 'печат', 'пила', 'пилы', 'пластик', 'пленк', 'плёнк',
                    'подарок', 'портфел', 'посуд', 'предмет', 'принтер', 'проектор', 'пузырьк', 'пылесос',
                    'ремонт', 'ручк', 'салют', 'свеч', 'скакалк', 'сковород', 'снаряжен', 'спичк', 'стакан',
                    'стекл', 'степлер', 'стирал', 'стол', 'страниц', 'строит', 'стул', 'сундук', 'таблетк',
                    'тарелк посуд', 'ткан', 'топор', 'точилк', 'трубочк', 'тряпк', 'уборк', 'удочк', 'украшен',
                    'факел', 'фейерверк', 'флаг', 'хлопушк', 'холодильник', 'хрустал', 'чайник', 'часы настен',
                    'чемодан', 'шаг', 'шарик', 'шприц', 'штор', 'шуруп', 'щетк', 'щётк', 'эффект'
                ),
                'lat'   => array(
                    'aerozol', 'audio-effektyi', 'britva', 'bumag', 'butyl', 'chaynik', 'chemodan', 'dereva',
                    'divan', 'drel', 'duhovk', 'dver', 'fakel', 'feyverka', 'flag', 'hlopushk', 'holodilnik',
                    'hrustal', 'jestkih-knopok', 'kachel', 'kalyan', 'kamen', 'karandash', 'karton',
                    'kastryul', 'klaviatur', 'klyuch', 'knig', 'kompyuter', 'konserv', 'korobk', 'kran',
                    'krema', 'krovat', 'kryishk', 'lamp', 'letayuschego-obyekta', 'lopat', 'lozhk', 'mebel',
                    'metall', 'metlyi', 'mikrofona', 'mikrovolnov', 'molnii', 'molotok', 'musor',
                    'myishelovki', 'myshk', 'nozhnic', 'odezhd', 'ognetushitel', 'otmyichka', 'paket', 'pesok',
                    'plastik', 'plenka', 'podark', 'portfel', 'posud', 'predmetyi', 'printer', 'proektor',
                    'puzyirk', 'pylesos', 'remont', 'ruchk', 'schetki', 'shag', 'sharik', 'shprits', 'shtor',
                    'skakalk', 'skovorodki', 'snaryajenie', 'solominki', 'spichk', 'stakan', 'stekl',
                    'steplera', 'stiral', 'stol', 'stranic', 'stroit', 'stul', 'sunduk', 'svech', 'tabletok',
                    'tkani', 'tochilk', 'topor', 'tryapk', 'uborki', 'udochki', 'ukrashen', 'vilk',
                    'vyazaniya', 'zamok', 'zazhigalk', 'zonta'
                ),
            ),
        );

        return $all;
    }

    public static function exists($slug) {
        $all = self::all();
        return isset($all[$slug]) || $slug === self::FALLBACK;
    }

    /**
     * @return array{slug:string,title:string,menu:string,lead:string}|null
     */
    public static function get($slug) {
        $slug = sanitize_title((string) $slug);
        $all = self::all();
        if (isset($all[$slug])) {
            return array_merge(array('slug' => $slug), $all[$slug]);
        }
        if ($slug === self::FALLBACK) {
            return array(
                'slug'  => self::FALLBACK,
                'title' => 'Остальные звуки',
                'menu'  => 'Разное',
                'lead'  => 'Подборки, которые не попали в основные разделы: редкие эффекты, '
                         . 'узкие темы и всё, что встречается по одному разу.',
            );
        }
        return null;
    }

    /** Ручные привязки: слаг подборки → раздел. */
    const OPT_MAP = 'gs_sections_map';

    /**
     * @return array<string,string>
     */
    public static function map() {
        $map = get_option(self::OPT_MAP, array());
        return is_array($map) ? $map : array();
    }

    /**
     * Привязать подборки к разделу вручную.
     *
     * Правила по словам угадывают тему верно почти всегда, но «почти» —
     * это десятки подборок из тысячи. Ручная привязка хранится одной
     * опцией, а не полем в каждом файле: иначе её пришлось бы переносить
     * при каждой перезаписи подборки импортом.
     *
     * @param string[] $slugs
     */
    public static function assign(array $slugs, $section) {
        $section = sanitize_title((string) $section);
        if (!self::exists($section)) {
            return 0;
        }
        $map = self::map();
        $n = 0;
        foreach ($slugs as $slug) {
            $slug = sanitize_title((string) $slug);
            if ($slug === '') {
                continue;
            }
            $map[$slug] = $section;
            $n++;
        }
        update_option(self::OPT_MAP, $map, false);
        return $n;
    }

    /**
     * Раздел подборки. Считаем по слагу и названию: слаг латиницей и почти
     * всегда говорит о теме, название добавляет русские слова.
     */
    public static function guess($slug, $title = '') {
        $map = self::map();
        $key = sanitize_title((string) $slug);
        if ($key !== '' && isset($map[$key]) && self::exists($map[$key])) {
            return (string) $map[$key];
        }

        $slug_l  = mb_strtolower((string) $slug);
        $title_l = mb_strtolower((string) $title);

        foreach (self::all() as $section => $data) {
            if (self::hit($title_l, $data['ru'], 'а-яёa-z0-9')
                || self::hit($slug_l, $data['lat'], 'a-z0-9')) {
                return $section;
            }
        }
        return self::FALLBACK;
    }

    /**
     * Совпадение корня с началом слова.
     *
     * Простое вхождение подстроки здесь не годится: «рок» находится в
     * «створок», «мат» — в «автомате», «транс» — в «транспорте». Каталог
     * из-за этого разъезжался по разделам совершенно неожиданно, поэтому
     * корень обязан стоять в начале слова.
     *
     * @param string[] $words
     */
    private static function hit($haystack, $words, $letters) {
        if ($haystack === '' || empty($words)) {
            return false;
        }
        $parts = array();
        foreach ($words as $word) {
            $word = trim((string) $word);
            if ($word !== '') {
                $parts[] = preg_quote($word, '~');
            }
        }
        if (!$parts) {
            return false;
        }
        // Длинные корни вперёд: иначе короткий совпадёт первым и спрячет
        // более точный.
        usort($parts, function ($a, $b) {
            return mb_strlen($b) <=> mb_strlen($a);
        });
        $pattern = '~(?<![' . $letters . '])(?:' . implode('|', $parts) . ')~u';
        return (bool) preg_match($pattern, $haystack);
    }

    public static function url($slug) {
        return trailingslashit(GS_Catalog::base_url() . rawurlencode($slug));
    }

    /**
     * Подборки раздела, по убыванию наполненности.
     *
     * @return array<int,array>
     */
    public static function categories($slug, $limit = 0) {
        $out = array();
        foreach (GS_Catalog::load_index() as $row) {
            if (!is_array($row) || empty($row['slug'])) {
                continue;
            }
            $section = isset($row['section']) && $row['section'] !== ''
                ? (string) $row['section']
                : self::guess($row['slug'], $row['title'] ?? '');
            if ($section === $slug) {
                $out[] = $row;
            }
        }
        usort($out, function ($a, $b) {
            return (int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0);
        });
        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    /**
     * Сколько подборок и звуков в каждом разделе — для витрины каталога.
     *
     * @return array<int,array{slug:string,title:string,menu:string,lead:string,cats:int,sounds:int}>
     */
    public static function overview() {
        $counts = array();
        foreach (GS_Catalog::load_index() as $row) {
            if (!is_array($row) || empty($row['slug'])) {
                continue;
            }
            $section = isset($row['section']) && $row['section'] !== ''
                ? (string) $row['section']
                : self::guess($row['slug'], $row['title'] ?? '');
            if (!isset($counts[$section])) {
                $counts[$section] = array('cats' => 0, 'sounds' => 0);
            }
            $counts[$section]['cats']++;
            $counts[$section]['sounds'] += (int) ($row['count'] ?? 0);
        }

        $out = array();
        foreach (array_keys(self::all()) as $slug) {
            if (empty($counts[$slug]['cats'])) {
                continue;
            }
            $out[] = array_merge(self::get($slug), $counts[$slug]);
        }
        if (!empty($counts[self::FALLBACK]['cats'])) {
            $out[] = array_merge(self::get(self::FALLBACK), $counts[self::FALLBACK]);
        }
        return $out;
    }
}
