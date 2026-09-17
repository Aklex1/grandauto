<?php
/**
 * Сборка файла .pptx из готовой структуры слайдов.
 *
 * Презентацию отдаём именно в PPTX, а не картинками и не PDF: человек
 * почти всегда хочет её дописать — поменять заголовок, переставить пункт,
 * подставить свои цифры. Картинку и PDF не поправишь, а pptx открывается
 * и в PowerPoint, и в Google Slides, и в бесплатных редакторах.
 *
 * Формат — это zip с набором XML. Почти весь набор одинаков для любой
 * презентации, меняются только слайды, поэтому постоянная часть лежит
 * здесь константами, а собирается по слайду за раз.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GS_Pptx {

    /** Размер слайда 16:9 в EMU — единицах, в которых считает PowerPoint. */
    const W = 12192000;
    const H = 6858000;

    /** 1 см = 360000 EMU. Поля и блоки считаем от этого. */
    const CM = 360000;

    public static function available() {
        return class_exists('ZipArchive');
    }

    /**
     * Собирает презентацию.
     *
     * @param array  $slides Каждый слайд: title, bullets[], image (путь к файлу или '')
     * @param string $path   Куда записать .pptx
     * @param array  $meta   title, author — для свойств файла
     * @return array{ok:bool,message:string}
     */
    public static function build($slides, $path, $meta = array()) {
        if (!self::available()) {
            return array('ok' => false, 'message' => 'На сервере нет расширения zip — сборка файла недоступна');
        }
        $slides = array_values(array_filter((array) $slides));
        if (!$slides) {
            return array('ok' => false, 'message' => 'Нечего собирать: список слайдов пуст');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return array('ok' => false, 'message' => 'Не удалось создать файл презентации');
        }

        // Картинки нумеруем заранее: на них ссылаются и слайд, и его rels,
        // и общий список типов содержимого. На слайде их может быть две —
        // фон и иллюстрация рядом с текстом, — поэтому имена сквозные.
        $media = array();
        $n = 0;
        foreach ($slides as $i => $slide) {
            $media[$i] = array();
            foreach (array('image', 'illustration') as $role) {
                $file = (string) ($slide[$role] ?? '');
                if ($file !== '' && is_readable($file)) {
                    $n++;
                    $media[$i][$role] = array('name' => 'image' . $n . '.jpg', 'file' => $file);
                }
            }
        }

        $zip->addFromString('[Content_Types].xml', self::content_types(count($slides), (bool) array_filter($media)));
        $zip->addFromString('_rels/.rels', self::root_rels());
        $zip->addFromString('docProps/core.xml', self::core($meta));
        $zip->addFromString('docProps/app.xml', self::app(count($slides)));
        $zip->addFromString('ppt/presentation.xml', self::presentation(count($slides)));
        $zip->addFromString('ppt/_rels/presentation.xml.rels', self::presentation_rels(count($slides)));
        $zip->addFromString('ppt/presProps.xml', self::pres_props());
        $zip->addFromString('ppt/theme/theme1.xml', self::theme());
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', self::master());
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', self::master_rels());
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', self::layout());
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', self::layout_rels());

        foreach ($slides as $i => $slide) {
            $num = $i + 1;
            $bg = $media[$i]['image']['name'] ?? '';
            $il = $media[$i]['illustration']['name'] ?? '';
            $zip->addFromString('ppt/slides/slide' . $num . '.xml',
                self::slide($slide, $i === 0, $bg !== '', $il !== ''));
            $zip->addFromString('ppt/slides/_rels/slide' . $num . '.xml.rels',
                self::slide_rels($bg, $il));
            foreach ($media[$i] as $item) {
                $zip->addFile($item['file'], 'ppt/media/' . $item['name']);
            }
        }

        if (!$zip->close()) {
            return array('ok' => false, 'message' => 'Не удалось записать файл презентации');
        }
        return array('ok' => true, 'message' => '');
    }

    /* ---------------------------------------------------------------------
     * Слайд
     * ------------------------------------------------------------------ */

    /**
     * Титульный слайд отличается от обычного только размером и положением
     * текста, поэтому отдельного макета под него не заводим.
     */
    private static function slide($slide, $is_title, $has_image, $has_illustration = false) {
        $title = self::esc((string) ($slide['title'] ?? ''));
        $bullets = array_values(array_filter(array_map('strval', (array) ($slide['bullets'] ?? array())))); 

        $shapes = '';
        $id = 2;

        if ($has_image) {
            // Фон на весь слайд, поверх — затемнение, иначе текст не читается
            // на светлых участках картинки.
            $shapes .= '<p:pic><p:nvPicPr><p:cNvPr id="' . $id . '" name="Фон"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr>'
                . '<p:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
                . '<p:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . self::W . '" cy="' . self::H . '"/></a:xfrm>'
                . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
            $id++;
            $shapes .= self::rect($id, 0, 0, self::W, self::H, '080C14', 62);
            $id++;
        } else {
            $shapes .= self::rect($id, 0, 0, self::W, self::H, '080C14', 100);
            $id++;
        }

        $pad = round(self::CM * 2.2);
        $width = self::W - $pad * 2;

        // Иллюстрация занимает правую часть слайда, текст ужимается влево.
        if ($has_illustration && !$is_title) {
            $ill_w = round(self::W * 0.36);
            $ill_x = self::W - $pad - $ill_w;
            $ill_y = round(self::CM * 4.6);
            $ill_h = round(self::H - $ill_y - self::CM * 2.2);
            $shapes .= '<p:pic><p:nvPicPr><p:cNvPr id="' . $id . '" name="Иллюстрация"/>'
                . '<p:cNvPicPr/><p:nvPr/></p:nvPicPr>'
                . '<p:blipFill><a:blip r:embed="' . ($has_image ? 'rId3' : 'rId2') . '"/>'
                . '<a:srcRect l="8000" r="8000"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
                . '<p:spPr><a:xfrm><a:off x="' . $ill_x . '" y="' . $ill_y . '"/>'
                . '<a:ext cx="' . $ill_w . '" cy="' . $ill_h . '"/></a:xfrm>'
                . '<a:prstGeom prst="roundRect"><a:avLst><a:gd name="adj" fmla="val 6000"/></a:avLst></a:prstGeom>'
                . '<a:ln w="12700"><a:solidFill><a:srgbClr val="6366F1"><a:alpha val="45000"/></a:srgbClr></a:solidFill></a:ln>'
                . '</p:spPr></p:pic>';
            $id++;
            $width = $ill_x - $pad - round(self::CM * 0.8);
        }

        if ($is_title) {
            $shapes .= self::text($id, $pad, round(self::CM * 5.4), $width, round(self::CM * 4),
                $title, 4400, true, 'ctr');
            $id++;
            if ($bullets) {
                $shapes .= self::text($id, $pad, round(self::CM * 9.6), $width, round(self::CM * 3),
                    implode('  ·  ', $bullets), 1800, false, 'ctr', '94A3B8');
                $id++;
            }
        } else {
            $shapes .= self::text($id, $pad, round(self::CM * 1.6), $width, round(self::CM * 2.6),
                $title, 3200, true, 'l');
            $id++;
            if ($bullets) {
                $shapes .= self::bullets($id, $pad, round(self::CM * 5), $width, round(self::CM * 11), $bullets);
                $id++;
            }
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld><p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            . '<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . $shapes
            . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
    }

    /** Заливка прямоугольником; $alpha — прозрачность в процентах. */
    private static function rect($id, $x, $y, $cx, $cy, $color, $alpha) {
        $fill = '<a:srgbClr val="' . $color . '">'
            . ($alpha < 100 ? '<a:alpha val="' . ((int) $alpha * 1000) . '"/>' : '')
            . '</a:srgbClr>';
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Подложка"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:solidFill>' . $fill . '</a:solidFill>'
            . '<a:ln><a:noFill/></a:ln></p:spPr></p:sp>';
    }

    private static function text($id, $x, $y, $cx, $cy, $text, $size, $bold, $align, $color = 'F1F5F9') {
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Текст"/>'
            . '<p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
            . '<p:txBody><a:bodyPr wrap="square" anchor="t"><a:normAutofit/></a:bodyPr><a:lstStyle/>'
            . '<a:p><a:pPr algn="' . $align . '"/><a:r><a:rPr lang="ru-RU" sz="' . $size . '"'
            . ($bold ? ' b="1"' : '') . ' dirty="0"><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill>'
            . '<a:latin typeface="+mn-lt"/></a:rPr><a:t>' . self::esc($text) . '</a:t></a:r></a:p>'
            . '</p:txBody></p:sp>';
    }

    private static function bullets($id, $x, $y, $cx, $cy, $items) {
        $paras = '';
        foreach ($items as $item) {
            $paras .= '<a:p><a:pPr marL="285750" indent="-285750">'
                . '<a:lnSpc><a:spcPct val="115000"/></a:lnSpc>'
                . '<a:spcBef><a:spcPts val="900"/></a:spcBef>'
                . '<a:buClr><a:srgbClr val="22D3EE"/></a:buClr><a:buChar char="—"/></a:pPr>'
                . '<a:r><a:rPr lang="ru-RU" sz="2000" dirty="0">'
                . '<a:solidFill><a:srgbClr val="CBD5E1"/></a:solidFill>'
                . '<a:latin typeface="+mn-lt"/></a:rPr><a:t>' . self::esc($item) . '</a:t></a:r></a:p>';
        }
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Пункты"/>'
            . '<p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
            . '<p:txBody><a:bodyPr wrap="square"><a:normAutofit/></a:bodyPr><a:lstStyle/>'
            . $paras . '</p:txBody></p:sp>';
    }

    private static function slide_rels($background, $illustration = '') {
        $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>';
        $next = 2;
        foreach (array($background, $illustration) as $image) {
            if ($image === '') {
                continue;
            }
            $rels .= '<Relationship Id="rId' . $next . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/' . $image . '"/>';
            $next++;
        }
        return self::rels($rels);
    }

    /* ---------------------------------------------------------------------
     * Постоянная часть пакета
     * ------------------------------------------------------------------ */

    private static function rels($inner) {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $inner . '</Relationships>';
    }

    private static function content_types($count, $has_media) {
        $slides = '';
        for ($i = 1; $i <= $count; $i++) {
            $slides .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . ($has_media ? '<Default Extension="jpg" ContentType="image/jpeg"/>' : '')
            . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            . '<Override PartName="/ppt/presProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presProps+xml"/>'
            . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
            . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            . $slides
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private static function root_rels() {
        return self::rels(
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        );
    }

    private static function core($meta) {
        $title = self::esc((string) ($meta['title'] ?? 'Презентация'));
        $author = self::esc((string) ($meta['author'] ?? 'Genius-bot'));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $title . '</dc:title><dc:creator>' . $author . '</dc:creator>'
            . '<cp:lastModifiedBy>' . $author . '</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private static function app($count) {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
            . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Slides>' . (int) $count . '</Slides>'
            . '<Application>Genius-bot</Application>'
            . '</Properties>';
    }

    private static function presentation($count) {
        $ids = '';
        for ($i = 1; $i <= $count; $i++) {
            $ids .= '<p:sldId id="' . (255 + $i) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" saveSubsetFonts="1">'
            . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
            . '<p:sldIdLst>' . $ids . '</p:sldIdLst>'
            . '<p:sldSz cx="' . self::W . '" cy="' . self::H . '"/>'
            . '<p:notesSz cx="' . self::H . '" cy="' . self::W . '"/>'
            . '</p:presentation>';
    }

    private static function presentation_rels($count) {
        $inner = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>';
        for ($i = 1; $i <= $count; $i++) {
            $inner .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $i . '.xml"/>';
        }
        $n = $count + 2;
        $inner .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/presProps" Target="presProps.xml"/>';
        $inner .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>';
        return self::rels($inner);
    }

    private static function pres_props() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:presentationPr xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>';
    }

    private static function master() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld><p:bg><p:bgPr><a:solidFill><a:srgbClr val="080C14"/></a:solidFill>'
            . '<a:effectLst/></p:bgPr></p:bg><p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            . '<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . '</p:spTree></p:cSld>'
            . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2"'
            . ' accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6"'
            . ' hlink="hlink" folHlink="folHlink"/>'
            . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
            . '<p:txStyles><p:titleStyle/><p:bodyStyle/><p:otherStyle/></p:txStyles>'
            . '</p:sldMaster>';
    }

    private static function master_rels() {
        return self::rels(
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>'
        );
    }

    private static function layout() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank" preserve="1">'
            . '<p:cSld name="Пустой"><p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            . '<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>';
    }

    private static function layout_rels() {
        return self::rels(
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
        );
    }

    /** Тема обязательна: без неё PowerPoint считает пакет повреждённым. */
    private static function theme() {
        $scheme = '';
        foreach (array('dk1' => '000000', 'lt1' => 'FFFFFF', 'dk2' => '080C14', 'lt2' => 'F1F5F9',
                       'accent1' => '6366F1', 'accent2' => '22D3EE', 'accent3' => '8B5CF6',
                       'accent4' => '34D399', 'accent5' => '818CF8', 'accent6' => '94A3B8',
                       'hlink' => '6366F1', 'folHlink' => '8B5CF6') as $name => $value) {
            $scheme .= '<a:' . $name . '><a:srgbClr val="' . $value . '"/></a:' . $name . '>';
        }
        $fill = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';
        $line = '<a:ln w="9525" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
            . '<a:prstDash val="solid"/></a:ln>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Genius">'
            . '<a:themeElements>'
            . '<a:clrScheme name="Genius">' . $scheme . '</a:clrScheme>'
            . '<a:fontScheme name="Genius">'
            . '<a:majorFont><a:latin typeface="Arial"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
            . '<a:minorFont><a:latin typeface="Arial"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>'
            . '</a:fontScheme>'
            . '<a:fmtScheme name="Genius">'
            . '<a:fillStyleLst>' . $fill . $fill . $fill . '</a:fillStyleLst>'
            . '<a:lnStyleLst>' . $line . $line . $line . '</a:lnStyleLst>'
            . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle>'
            . '<a:effectStyle><a:effectLst/></a:effectStyle>'
            . '<a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            . '<a:bgFillStyleLst>' . $fill . $fill . $fill . '</a:bgFillStyleLst>'
            . '</a:fmtScheme></a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/></a:theme>';
    }

    private static function esc($text) {
        // В XML нельзя управляющие символы — модель иногда присылает их в тексте.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $text);
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
