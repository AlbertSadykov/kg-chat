<?php

namespace App\Blog;

/** Сохраняем только проверенный Delta; произвольный HTML никогда не выводим. */
class Content
{
    public static function filename(string $value): ?string
    {
        $prefix = url('blog/image/');
        if (strpos($value, $prefix) === 0) {
            $value = substr($value, strlen($prefix));
        }
        return preg_match('/^[a-f0-9]{48}\.(jpg|png|webp|gif)$/D', $value) ? $value : null;
    }

    public static function link(string $value): ?string
    {
        $value = trim($value);
        if (strlen($value) > 2000 || preg_match('/[\x00-\x20\x7f]/', $value)) {
            return null;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || !filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        return $value;
    }

    public static function normalize(string $json): array
    {
        if (strlen($json) > 262144) {
            throw new \InvalidArgumentException('Текст слишком большой: максимум 256 КБ.');
        }
        $data = json_decode($json, true, 32);
        if (!is_array($data) || !isset($data['ops']) || !is_array($data['ops']) || count($data['ops']) > 5000) {
            throw new \InvalidArgumentException('Некорректное содержимое редактора.');
        }
        $ops = [];
        $plain = '';
        $media = [];
        foreach ($data['ops'] as $op) {
            if (!is_array($op) || !array_key_exists('insert', $op) || isset($op['retain']) || isset($op['delete'])) {
                throw new \InvalidArgumentException('Разрешён только полный текст записи.');
            }
            $insert = $op['insert'];
            if (is_string($insert)) {
                if (!mb_check_encoding($insert, 'UTF-8')) {
                    throw new \InvalidArgumentException('Некорректная кодировка текста.');
                }
                $insert = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $insert);
                $plain .= $insert;
            } elseif (is_array($insert) && count($insert) === 1 && isset($insert['image']) && is_string($insert['image'])) {
                $filename = self::filename($insert['image']);
                if (!$filename) {
                    throw new \InvalidArgumentException('Картинки нужно загружать кнопкой редактора.');
                }
                $insert = ['image' => $filename];
                $media[$filename] = $filename;
            } else {
                throw new \InvalidArgumentException('Неподдерживаемый элемент текста.');
            }
            $attrs = $op['attributes'] ?? [];
            if (!is_array($attrs)) {
                throw new \InvalidArgumentException('Некорректное форматирование.');
            }
            $safe = [];
            foreach (['bold', 'italic', 'underline', 'strike', 'blockquote'] as $key) {
                if (($attrs[$key] ?? false) === true) {
                    $safe[$key] = true;
                }
            }
            if (in_array($attrs['header'] ?? null, [2, 3], true)) {
                $safe['header'] = $attrs['header'];
            }
            if (in_array($attrs['list'] ?? null, ['ordered', 'bullet'], true)) {
                $safe['list'] = $attrs['list'];
            }
            if (in_array($attrs['align'] ?? null, ['center', 'right', 'justify'], true)) {
                $safe['align'] = $attrs['align'];
            }
            if (isset($attrs['link'])) {
                if (!is_string($attrs['link']) || !($href = self::link($attrs['link']))) {
                    throw new \InvalidArgumentException('Ссылка должна начинаться с https:// или http://.');
                }
                $safe['link'] = $href;
            }
            $item = ['insert' => $insert];
            if ($safe) {
                $item['attributes'] = $safe;
            }
            $ops[] = $item;
        }
        if (mb_strlen($plain) > 100000 || trim($plain) === '') {
            throw new \InvalidArgumentException('Введите текст записи, максимум 100 000 символов.');
        }
        $last = $ops[count($ops) - 1]['insert'];
        if (!is_string($last) || substr($last, -1) !== "\n") {
            $ops[] = ['insert' => "\n"];
        }
        return ['delta' => ['ops' => $ops], 'plain' => trim($plain), 'media' => array_values($media)];
    }

    public static function editorDelta(string $json): array
    {
        $data = json_decode($json, true) ?: ['ops' => [['insert' => "\n"]]];
        foreach ($data['ops'] as &$op) {
            if (is_array($op['insert']) && isset($op['insert']['image'])) {
                $op['insert']['image'] = url('blog/image/' . $op['insert']['image']);
            }
        }
        unset($op);
        return $data;
    }

    public static function html(string $json): string
    {
        $data = self::normalize($json)['delta'];
        $html = '';
        $line = '';
        $list = '';
        foreach ($data['ops'] as $op) {
            $attrs = $op['attributes'] ?? [];
            if (is_array($op['insert'])) {
                $line .= '<img src="' . e(url('blog/image/' . $op['insert']['image'])) . '" alt="" loading="lazy" decoding="async">';
                continue;
            }
            $parts = explode("\n", $op['insert']);
            foreach ($parts as $index => $part) {
                $text = e($part);
                foreach (['bold' => 'strong', 'italic' => 'em', 'underline' => 'u', 'strike' => 's'] as $key => $tag) {
                    if (!empty($attrs[$key])) {
                        $text = '<' . $tag . '>' . $text . '</' . $tag . '>';
                    }
                }
                if (isset($attrs['link'])) {
                    $text = '<a href="' . e($attrs['link']) . '" rel="nofollow noopener noreferrer" target="_blank">' . $text . '</a>';
                }
                $line .= $text;
                if ($index === count($parts) - 1) {
                    continue;
                }
                $nextList = isset($attrs['list']) ? ($attrs['list'] === 'ordered' ? 'ol' : 'ul') : '';
                if ($list !== $nextList) {
                    if ($list !== '') {
                        $html .= '</' . $list . '>';
                    }
                    if ($nextList !== '') {
                        $html .= '<' . $nextList . '>';
                    }
                    $list = $nextList;
                }
                $tag = $list !== '' ? 'li' : (isset($attrs['header']) ? 'h' . $attrs['header'] : (!empty($attrs['blockquote']) ? 'blockquote' : 'p'));
                $class = isset($attrs['align']) ? ' class="blog-align-' . $attrs['align'] . '"' : '';
                $html .= '<' . $tag . $class . '>' . ($line === '' ? '<br>' : $line) . '</' . $tag . '>';
                $line = '';
            }
        }
        if ($list !== '') {
            $html .= '</' . $list . '>';
        }
        return $html;
    }
}
