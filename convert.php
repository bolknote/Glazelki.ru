<?php
declare(strict_types=1);

const BASE_DATE = '20170923015827';
const BASE_URL = 'https://web.archive.org/web/'.BASE_DATE.'/http://glazelki.ru:80/';

/**
 * Функия кеширования ответа
 * @param callable $fn Функц
 * @param string $key Ключ кеширования
 * @return mixed
 */
function c(callable $fn, string $key) /*:mixed*/
{
    $cache_dir = __DIR__.'/cache/';
    $cache_file = $cache_dir . $key;

    if (!file_exists($cache_dir)) {
        mkdir($cache_dir);
    }

    $key = strtr($key, ['/' => '_']);

    if (file_exists($cache_file)) {
        printf("Got cache: %s\n", $key);
        return unserialize(file_get_contents($cache_file));
    } else {
        $content = $fn();
        file_put_contents($cache_file, serialize($content));
        return $content;
    }
}

/**
 * Берём урлы заметок
 * @param string $url Урл с которого начинаем
 * @return array []string Урлы заметок
 */
function getNoteLinks(string $url): array
{
    $page_pat = '!https://web.archive.org/web/\d+/http://glazelki.ru/page/(\d+)!s';
    $page_fmt = 'https://web.archive.org/web/%s/http://glazelki.ru/page/%d';
    $note_pat = '!https://web.archive.org/web/\d+/http://glazelki.ru/\d+/\d+/\d+/[^"/]+!s';

    // тут будут страницы, которые надо посетить, первую мы уже посетили
    $pages = [1 => true];
    $notes = [];

    do {
        printf("Getting %s\n", $url);
        $content = file_get_contents($url);
        if ($content === false) {
            throw new RuntimeException();
        }

        // Ищем переходы на страницы
        if (preg_match_all($page_pat, $content, $m)) {
            $pages += array_fill_keys($m[1],false);
        }

        // ищем ссылки на заметки
        if (preg_match_all($note_pat, $content, $m)) {
            $notes = array_unique([...$notes, ...$m[0]]);
        }

        // есть ли непосещённые урлы?
        $urls = array_keys($pages, false);
        if ($urls) {
            $url = sprintf($page_fmt, BASE_DATE, $urls[0]);
            $pages[$urls[0]] = true;
        } else {
            $url = false;
        }

    } while ($url !== false);

    printf("Received %d urls.\n", count($notes));

    return array_values($notes);
}

/**
 * Получаем заметку
 * @param  string $url Урл заметки
 * @return string Текстовое содержимое
 */
function getNote(string $url):string
{
    printf("Getting %s\n", $url);
    $content = file_get_contents($url);

    if ($content === false) {
        throw new RuntimeException();
    }

    return $content;
}

/**
 * Скачивает картинки
 * @param string $url
 * @param string $date_str
 * @param int $n
 * @return string Новое имя
 */
function downloadPicture(string $url, string $date_str, int $n):string
{
    $cache_dir = __DIR__.'/pictures/';

    if (!file_exists($cache_dir)) {
        mkdir($cache_dir);
    }

    $cache_mask = sprintf("%s%s.%s*", $cache_dir, $date_str, $n);
    $files = glob($cache_mask);

    if ($files && filesize($files[0]) > 0) {
        $new_name = basename($files[0]);
        printf("Got cache: %s\n", $new_name);

        return $new_name;
    }

    $tmp_name = tempnam(sys_get_temp_dir(), 'glaz');
    $url = preg_replace('/_[a-z]+(?:\.[.a-z]+)?$/i', '_orig', $url);

    printf("Downloading %s\n", $url);
    copy($url, $tmp_name);

    $width = getimagesize($tmp_name)[0];
    if ($width >= 2000) {
        $new_name = sprintf("%s%s.%s@2x.jpg", $cache_dir, $date_str, $n);
    } else {
        $new_name = sprintf("%s%s.%s.jpg", $cache_dir, $date_str, $n);
    }

    rename($tmp_name, $new_name);

    return basename($new_name);
}

/**
 * Исправление ссылок на картинки
 * @param string $content Текст, где встречаются ссылки
 * @param array $info Информация о заметке
 * @return string
 */
function fixPictures(string $content, array $info):string
{
    $images = [];

    if (preg_match_all('!<img[^>]+>!s', $content, $m)) {
        foreach ($m[0] as $tag) {
            $t = tidy_parse_string(tidy_repair_string($tag));
            // после починки куска HTML у нас получается HEAD → BODY → IMG
            // двигаемся по этому пути и получаем атрибуты картинки
            $attrs = $t->html()->child[1]->child[0]->attribute;

            // убираем приставку веб-архива из картинки
            $src = preg_replace('!https://web.archive.org/web/[^/]+/!s', '', $attrs['src']);

            if (strpos($src, 'simple-smile.png') !== false) {
                $images[$tag] = '🙂';
            } else {
                $newname = downloadPicture($src, $info['ctime']->format('Y.m.d.H.i'), count($images) + 1);
                $images[$tag] = sprintf("%s\n%s\n", $newname, rtrim($attrs['alt'] ?? $attrs['title'] ?? '', '.'));
            }
        }
    }

    $content = strtr($content, $images);

    return $content;
}

/**
 * Получить транслитератор, если получать несколько раз,
 * он перестаёт работать
 * @return Transliterator
 */
function getTrans():Transliterator
{
    static $trans = null;
    if ($trans === null) {
        $trans = Transliterator::create("Any-ru", Transliterator::REVERSE);
    }

    return $trans;
}

/**
 * Исправление ссылок на страницы
 * @param string $content
 * @return string
 */
function fixLinks(string $content):string
{
    $links = [];

    if (preg_match_all('!<a [^>]+>.*?</a>!s', $content, $m)) {
        foreach ($m[0] as $tag) {
            $t = tidy_parse_string(tidy_repair_string($tag));
            // после починки куска HTML у нас получается HEAD → BODY → IMG
            // двигаемся по этому пути и получаем атрибуты картинки
            $attrs = $t->html()->child[1]->child[0]->attribute;
            $text = trim(strip_tags($t->html()->child[1]->child[0]->child[0]->value));

            $href = preg_replace(
                '!https://web.archive.org/web/\d+/http://glazelki.ru/!s',
                '',
                $attrs['href'],
                -1,
                $cnt
            );

            if ($cnt === 0) {
                // внешние ссылки
                $href = preg_replace(
                    '!https://web.archive.org/web/\d+/!s',
                    '',
                    $href,
                    -1,
                    $cnt
                );

                if ($cnt === 0) {
                    var_dump($attrs);
                    throw new RuntimeException();
                }

                // Ссылка на подписку, убираем её
                if (strpos($href, 'feedburner.google.com/fb/a/mailverify') !== false) {
                    $links[$tag] = '';
                } else {
                    $links[$tag] = sprintf('[[%s %s]]', $href, $text);
                }

                continue;
            }

            switch (true) {
                case strpos($href, 'tag') === 0:
                    [$base, $path] = explode('/', urldecode($href));
                    $latin = toTranslit($path);
                    $links[$tag] = sprintf('[[/tags/%s %s]]', $latin, $text);
                    break;

                case preg_match('!\d+/\d+/\d+/!s', $href):
                    $path = explode('/', $href, 4)[3];
                    $links[$tag] = sprintf('[[/tags/%s %s]]', $path, $text);
                    break;

                default:
                    throw new RuntimeException();
            }
        }
    }

    $content = strtr($content, $links);

    return $content;
}

function toTranslit(string $cyr):string
{
    $latin = getTrans()->transliterate($cyr);
    $latin = iconv("UTF-8", "ASCII//TRANSLIT", $latin);
    $latin = preg_replace('/[^a-z-]/s', '', $latin);

    return $latin;
}

/**
 * Приведение разметки HTML к эгеевской
 * @param string $content
 * @param array $info Мета о заметке
 * @return string
 */
function fixMarkup(string $content, array $info):string
{
    // замена списков
    $content = preg_replace(
        [
            '!</?[uo]l>!',
            '!<li>!',
            '!</li>!',
        ],
        [
            '',
            ' - ',
            '',
        ],
        $content
    );

    $content = preg_replace(
        [
            // остатки от ката
            '!(?:<p>)?<strong><span id="more-\d+"></span></strong>(?:</p>)?!s',
            // преобразуем P в параграфы Эгеи
            '!<p>!',
            '!</p>!',
            // убираем виндовые переводы
            '/\r/',
            // кавычки
            '/&#171;|&laquo;/', '/&#187;|&raquo;/',
            // наклонные
            '!</?em>!s',
            // перевод строки
            '!<br\s*/?>\n*!s',
            // жирность
            '!</?(?:strong|b)>!s',
            // тире
            '/&#8212;/s',
            // многоточие
            '/\s*\.{3,}/s', '/\s*\.*\s*&#8230;/s',
            // кавычка
            '/&quot;/s',
            // короткое тире
            '/&ndash;/',
            // много пробелов подряд
            '/  +/',
        ],
        [
            "\n",
            '',
            "\n",
            '',
            '«', '»',
            '//',
            "\n\n",
            '**',
            '—',
            '…', '…',
            '"',
            '–',
            ' ',
        ],
    $content);

    $content = fixPictures($content, $info);

    $content  = preg_replace(
        '!([^\n]+)\n{2,}(https://img-fotki.yandex.ru[^\n]+)\n\1!s',
        "\$2\n\$1",
        $content
    );

    $content = preg_replace('/\n{3,}/', "\n\n", $content);
    $content = fixLinks($content);

    // цитата
    $content = preg_replace_callback(
        '!<blockquote>(.*?)</blockquote>!s',
        function (array $m):string {
            $content = preg_replace('/^/m', '> ', strip_tags($m[1]));
            return $content;
        },
        $content
    );

    return trim($content);
}

function getTags(string $content):array
{
    $xhtml = tidy_repair_string($content, ['output-xml' => true,]);

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML($xhtml);
    $xpath = new DOMXPath($doc);

    $links = [];

    foreach ($xpath->query('//a[@rel="tag"]') as $tag) {
        $text = mb_strtolower($tag->nodeValue, 'UTF-8');
        $href = $tag->attributes->getNamedItem('href')->value;
        $href = urldecode(preg_replace('!^.*?/tag/([^/]+)/?!s', '$1', $href));

        $latin = toTranslit($href);
        $links[] = [$latin, $text];
    }

    return $links;
}

/**
 * Получаем различные данные из текста замети
 * @param string $content Текст заметки
 * @return array Описание
 * @throws Exception
 */
function parseNote(string $content):array
{
    $info = [];
    $date_pat = '!<span class="entry-date" title="(\d+):(\d+)">(\d+)\.(\d+)\.(\d+)</span>!s';
    $head_pat = '!<h1 class="art-postheader">(.*?)</h1>!s';
    $cont_pat = '@<!-- article-content -->(.*?)<!--(?:Start Share Buttons| /article-content)@s';
    $knob_pat = '<div style="clear:both;"></div><div class="header_text" style="text-align:"><h3>Поделиться в соц. сетях';

    // пытаемся получить дату
    if (!preg_match($date_pat, $content, $m)) {
        throw new RuntimeException();
    }

    $date_str = sprintf('%d/%d/%d %d:%d:00', $m[5], $m[4], $m[3], $m[1], $m[2]);
    $info['ctime'] = new DateTimeImmutable($date_str, new DateTimeZone('Europe/Moscow'));

    // пытаемся получить заголовок
    if (!preg_match($head_pat, $content, $m)) {
        throw new RuntimeException();
    }

    $info['title'] = html_entity_decode(trim($m[1]), ENT_COMPAT | ENT_HTML401, "UTF-8");

    // пытаемся получить контент
    if (!preg_match($cont_pat, $content, $m)) {
        throw new RuntimeException();
    }

    $text = trim($m[1]);
    
    // пытаемся отрезать социокнопки
    $idx = strpos($text, $knob_pat);

    if ($idx !== false) {
        $text = substr($text, 0, $idx);
    }

    $info['text'] = fixMarkup($text, $info);
    $info['tags'] = getTags($content);

    return $info;
}

// Сюда записываем дамп с заметками
$fp = fopen('dump.sql', 'wb');
// Сюда записываем дамп с тегами
$tp = fopen('dump2.sql', 'wb');

fwrite($fp, "TRUNCATE TABLE e2BlogNotes;\n");

fwrite($tp, "TRUNCATE TABLE e2BlogKeywords;\n");
fwrite($tp, "TRUNCATE TABLE e2BlogNotesKeywords;\n");

foreach (c(fn() => getNoteLinks(BASE_URL), 'note_links') as $url) {
    $content = c(fn() => getNote($url), 'note_'.sha1($url));
    $info = parseNote($content);

    fprintf($fp, <<<'SQL'
    INSERT INTO e2BlogNotes
    (
        Title, Text, FormatterID, Uploads, IsPublished, IsCommentable, IsVisible,
        IsFavourite, Stamp, LastModified, Offset, IsDST, IsIndexed, IsExternal,
        SourceID, SourceNoteURL
    ) VALUES (
        '%s', '%s', 'neasden', 'a:0:{}', 1, 1, 1, 0,
        %3$d, %3$d, 3 * 60 * 60, 0, 0, 0, 0, 0
    );

    SQL,
    addcslashes($info['title'], "\n\r\0'"),
    addcslashes($info['text'], "\n\r\0'"),
    $info['ctime']->format('U'),
    );

    foreach ($info['tags'] as [$tag, $name]) {
        $etag = addcslashes($tag, "\n\r\0'");

        fprintf($tp, <<<'SQL'
            INSERT INTO e2BlogKeywords (Keyword, OriginalAlias, Uploads, IsFavourite)
            SELECT '%s', '%2$s', 'a:0:{}', 0
            FROM DUAL
            WHERE NOT EXISTS (
                SELECT * FROM e2BlogKeywords
                WHERE OriginalAlias='%2$s' LIMIT 1
            );

            INSERT INTO e2BlogNotesKeywords(SubsetID, NoteID, KeywordID)
            SELECT 0, n.id, (SELECT id FROM e2BlogKeywords WHERE OriginalAlias='%2$s' LIMIT 1)
            FROM e2BlogNotes n
            WHERE Stamp=%d;

            SQL,
            addcslashes($name, "\n\r\0'"),
            $etag,
            $info['ctime']->format('U'),
        );
    }
}

fclose($fp);
fclose($tp);
