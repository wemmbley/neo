<?php

declare(strict_types=1);

namespace Neo\Debug;

/**
 * VarDump — один файл, один класс: аналог Symfony VarDumper + рекурсивное раскрытие как в Kint.
 *
 * Подключение:   require_once 'VarDump.php';
 * Использование: dump($a, $b);   // выводит и возвращает значение (как в Symfony)
 *                dd($a, $b);     // выводит и завершает скрипт (exit 1)
 *
 * Работает сразу «из коробки»: HTML в браузере, ANSI-цвета в консоли, чистый текст для AJAX/JSON.
 * Клик по стрелке ▼ — свернуть/развернуть. Ctrl/Cmd/Alt + клик — свернуть/развернуть всё вложенное.
 *
 * Настройки — ниже в self::$config (или в коде: VarDump::configure([...]), VarDump::setTheme('light')).
 * Свои темы — в self::$themes (или VarDump::registerTheme('name', [...])).
 */
final class Dumper
{
    /* ====================== НАСТРОЙКИ (кастом живёт здесь) ====================== */
    private static array $config = [
        'theme'         => 'dark',   // dark | light | monokai | solarized | ваша тема
        'expand_depth'  => 3,        // до какой глубины раскрыто сразу; true = раскрыть всё рекурсивно
        'max_depth'     => 20,       // жёсткий предел вложенности
        'max_items'     => 2500,     // максимум элементов массива на уровень
        'max_string'    => 10000,    // обрезка длинных строк (0 = не резать)
        'show_source'   => true,     // показывать файл:строка вызова
        'base_path'     => null,     // что отрезать от пути (null = авто: base_path() Laravel, иначе полный путь)
        'cli_colors'    => 'auto',   // auto | true | false
        'plain_for_ajax'=> true,     // для AJAX/JSON-запросов выводить простой текст вместо HTML
        'font_family'   => 'Menlo, Monaco, Consolas, monospace',
        'font_size'     => 12,
        'max_height'    => 0,        // px, 0 = без ограничения (иначе у блока появится прокрутка)
    ];

    /** Токены цвета. 'cli' — какая ANSI-палитра ближе (dark|light). */
    private static array $themes = [
        'dark' => [ // точная копия Symfony VarDumper (тёмная)
            'bg' => '#18171B', 'fg' => '#FF8400', 'str' => '#56DB3A', 'num' => '#1299DA', 'note' => '#1299DA',
            'ref' => '#A0A0A0', 'vis' => '#FFFFFF', 'meta' => '#B729D9', 'key' => '#56DB3A', 'index' => '#1299DA',
            'src' => '#A0A0A0', 'cli' => 'dark',
        ],
        'light' => [
            'bg' => '#FFFFFF', 'fg' => '#CC7832', 'str' => '#448C27', 'num' => '#0072BD', 'note' => '#0072BD',
            'ref' => '#7B7B7B', 'vis' => '#000000', 'meta' => '#B729D9', 'key' => '#448C27', 'index' => '#0072BD',
            'src' => '#7B7B7B', 'cli' => 'light',
        ],
        'monokai' => [
            'bg' => '#272822', 'fg' => '#F8F8F2', 'str' => '#E6DB74', 'num' => '#AE81FF', 'note' => '#66D9EF',
            'ref' => '#75715E', 'vis' => '#F8F8F2', 'meta' => '#F92672', 'key' => '#A6E22E', 'index' => '#AE81FF',
            'src' => '#75715E', 'cli' => 'dark',
        ],
        'solarized' => [
            'bg' => '#002B36', 'fg' => '#839496', 'str' => '#2AA198', 'num' => '#D33682', 'note' => '#268BD2',
            'ref' => '#586E75', 'vis' => '#93A1A1', 'meta' => '#CB4B16', 'key' => '#859900', 'index' => '#B58900',
            'src' => '#586E75', 'cli' => 'dark',
        ],
    ];

    private const ANSI = [
        'dark' => [
            'default' => '0;38;5;208', 'num' => '1;38;5;38', 'const' => '1;38;5;208', 'str' => '1;38;5;113',
            'note' => '38;5;38', 'ref' => '38;5;247', 'public' => '', 'protected' => '', 'private' => '',
            'meta' => '38;5;170', 'key' => '38;5;113', 'index' => '38;5;38', 'src' => '38;5;247',
        ],
        'light' => [
            'default' => '38;5;166', 'num' => '1;38;5;26', 'const' => '1;38;5;166', 'str' => '1;38;5;28',
            'note' => '38;5;26', 'ref' => '38;5;243', 'public' => '', 'protected' => '', 'private' => '',
            'meta' => '38;5;127', 'key' => '38;5;28', 'index' => '38;5;26', 'src' => '38;5;243',
        ],
    ];

    /* ============================== ВНУТРЕННЕЕ ============================== */
    private static array $sent = [];
    private bool $html;
    private bool $color = false;
    private array $cfg;
    private array $ansi;
    private array $stack = [];
    private array $refStack = [];
    private bool $refApi;
    private float|int $expand;

    public static function configure(array $config): void
    {
        self::$config = array_replace(self::$config, $config);
    }

    public static function setTheme(string $name): void
    {
        self::$config['theme'] = $name;
    }

    public static function registerTheme(string $name, array $tokens): void
    {
        self::$themes[$name] = array_replace(self::$themes['dark'], $tokens);
    }

    public static function dump(mixed ...$vars): mixed
    {
        if (!$vars) {
            return null;
        }
        $src = self::source();
        foreach ($vars as $v) {
            echo (new self())->render($v, $src);
        }
        return count($vars) === 1 ? reset($vars) : $vars;
    }

    public static function dd(mixed ...$vars)
    {
        self::dump(...$vars);
        exit(1);
    }

    private function __construct()
    {
        $this->cfg = self::$config;
        $cli = in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true);
        $this->html = !$cli && !($this->cfg['plain_for_ajax'] && self::wantsPlain());
        $theme = self::$themes[$this->cfg['theme']] ?? self::$themes['dark'];
        $this->ansi = self::ANSI[$theme['cli'] ?? 'dark'];
        $this->refApi = class_exists('ReflectionReference');
        $e = $this->cfg['expand_depth'];
        $this->expand = $e === true ? INF : (int) $e;
        if (!$this->html && $cli) {
            $this->color = $this->cfg['cli_colors'] === 'auto' ? self::detectColors() : (bool) $this->cfg['cli_colors'];
        }
    }

    private static function wantsPlain(): bool
    {
        $xhr = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
        $acc = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        return $xhr || (str_contains($acc, 'json') && !str_contains($acc, 'html'));
    }

    private static function detectColors(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }
        if (getenv('FORCE_COLOR') !== false) {
            return true;
        }
        if (!defined('STDOUT')) {
            return false;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return (function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(STDOUT))
                || getenv('ANSICON') !== false || getenv('ConEmuANSI') === 'ON' || getenv('WT_SESSION') !== false;
        }
        return function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }

    private static function source(): ?string
    {
        if (!self::$config['show_source']) {
            return null;
        }
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10) as $f) {
            if (isset($f['file']) && $f['file'] !== __FILE__) {
                $file = $f['file'];
                $base = self::$config['base_path'] ?? (function_exists('base_path') ? @base_path() : null);
                if ($base && str_starts_with($file, rtrim((string) $base, '/\\'))) {
                    $file = ltrim(substr($file, strlen(rtrim((string) $base, '/\\'))), '/\\');
                }
                return $file . ':' . ($f['line'] ?? 0);
            }
        }
        return null;
    }

    /* ------------------------------- рендер ------------------------------- */

    private function render(mixed $v, ?string $src): string
    {
        $body = $this->val($v, 0);
        if (!$this->html) {
            return ($src !== null ? $this->s('src', $src) . "\n" : '') . $body . "\n";
        }
        $theme = $this->cfg['theme'];
        $out = $this->assets($theme);
        $h = (int) $this->cfg['max_height'];
        $style = $h > 0 ? ' style="max-height:' . $h . 'px"' : '';
        $out .= '<pre class="sf-dump sfd-' . htmlspecialchars((string) $theme, ENT_QUOTES) . '"' . $style . '>';
        if ($src !== null) {
            $out .= '<span class="sf-dump-src">' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</span>\n";
        }
        return $out . $body . "</pre>\n";
    }

    private function assets(string $theme): string
    {
        $out = '';
        if (empty(self::$sent['base'])) {
            self::$sent['base'] = true;
            $css = <<<'CSS'
.sf-dump{display:block;position:relative;z-index:99999;box-sizing:border-box;margin:0 0 6px;padding:8px 10px;overflow:auto;text-align:left;direction:ltr;white-space:pre-wrap;word-break:break-all;word-wrap:break-word;font:normal {SIZE}px/1.2em {FONT};background:var(--sfd-bg);color:var(--sfd-fg);border-radius:0}
.sf-dump samp{display:inline;font:inherit;margin:0;padding:0;background:none}
.sf-dump span,.sf-dump a{font:inherit}
.sf-dump a{text-decoration:none;cursor:pointer;border:0;outline:none;color:inherit}
.sf-dump-node.sf-dump-compact>samp{display:none}
.sf-dump-toggle{color:var(--sfd-ref);user-select:none;-webkit-user-select:none}
.sf-dump-toggle span{display:inline-block;font-size:.8em;transition:transform .1s}
.sf-dump-compact>.sf-dump-toggle span{transform:rotate(-90deg)}
.sf-dump-const{font-weight:bold}
.sf-dump-num{font-weight:bold;color:var(--sfd-num)}
.sf-dump-str{font-weight:bold;color:var(--sfd-str)}
.sf-dump-note{color:var(--sfd-note)}
.sf-dump-ref{color:var(--sfd-ref)}
.sf-dump-public,.sf-dump-protected,.sf-dump-private{color:var(--sfd-vis)}
.sf-dump-meta{color:var(--sfd-meta)}
.sf-dump-key{color:var(--sfd-key)}
.sf-dump-index{color:var(--sfd-index)}
.sf-dump-src{display:block;margin-bottom:4px;color:var(--sfd-src);font-size:.9em}
CSS;
            $css = str_replace(['{SIZE}', '{FONT}'], [(string) (int) $this->cfg['font_size'], (string) $this->cfg['font_family']], $css);
            $js = <<<'JS'
(function(){if(window.__sfDump)return;window.__sfDump=1;document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('.sf-dump-toggle');if(!a)return;e.preventDefault();var n=a.parentNode,c=!n.classList.contains('sf-dump-compact');n.classList[c?'add':'remove']('sf-dump-compact');if(e.ctrlKey||e.metaKey||e.altKey){n.querySelectorAll('.sf-dump-node').forEach(function(x){x.classList[c?'add':'remove']('sf-dump-compact')})}});})();
JS;
            $out .= '<style>' . $css . '</style><script>' . $js . '</script>';
        }
        if (empty(self::$sent['t:' . $theme])) {
            self::$sent['t:' . $theme] = true;
            $t = self::$themes[$theme] ?? self::$themes['dark'];
            $out .= '<style>.sfd-' . $theme . '{--sfd-bg:' . $t['bg'] . ';--sfd-fg:' . $t['fg'] . ';--sfd-str:' . $t['str']
                . ';--sfd-num:' . $t['num'] . ';--sfd-note:' . $t['note'] . ';--sfd-ref:' . $t['ref'] . ';--sfd-vis:' . $t['vis']
                . ';--sfd-meta:' . $t['meta'] . ';--sfd-key:' . $t['key'] . ';--sfd-index:' . $t['index'] . ';--sfd-src:' . $t['src'] . '}</style>';
        }
        return $out;
    }

    /* ----------------------------- форматирование ----------------------------- */

    private function s(string $kind, string $t, string $title = ''): string
    {
        if ($this->html) {
            $e = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return '<span class="sf-dump-' . $kind . '"' . ($title !== '' ? ' title="' . htmlspecialchars($title, ENT_QUOTES) . '"' : '') . '>' . $e . '</span>';
        }
        $t = str_replace("\x1b", '\e', $t);
        $code = $this->ansi[$kind] ?? '';
        return ($this->color && $code !== '') ? "\e[{$code}m{$t}\e[m" : $t;
    }

    /** Пунктуация: в HTML наследует основной цвет, в консоли — «default». */
    private function p(string $t): string
    {
        return $this->html ? htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $this->s('default', $t);
    }

    private function key(int|string $k): string
    {
        return is_int($k) ? $this->s('index', (string) $k) : $this->s('key', '"' . $k . '"');
    }

    private function node(string $open, string $body, string $close, int $d): string
    {
        $pad = str_repeat('  ', $d);
        if (!$this->html) {
            return rtrim($open) . "\n" . $body . $pad . $close;
        }
        $c = $d >= $this->expand ? ' sf-dump-compact' : '';
        return '<span class="sf-dump-node' . $c . '">' . $open . '<a class="sf-dump-toggle"><span>▼</span></a><samp>'
            . "\n" . $body . $pad . '</samp>' . $close . '</span>';
    }

    /* -------------------------------- значения -------------------------------- */

    private function val(mixed $v, int $d): string
    {
        if ($v === null) {
            return $this->s('const', 'null');
        }
        if (is_bool($v)) {
            return $this->s('const', $v ? 'true' : 'false');
        }
        if (is_int($v)) {
            return $this->s('num', (string) $v);
        }
        if (is_float($v)) {
            return $this->s('num', is_nan($v) ? 'NAN' : (is_infinite($v) ? ($v > 0 ? 'INF' : '-INF') : var_export($v, true)));
        }
        if (is_string($v)) {
            return $this->str($v);
        }
        if (is_array($v)) {
            return $this->arr($v, $d);
        }
        if (is_object($v)) {
            return $this->obj($v, $d);
        }
        $closed = gettype($v) === 'resource (closed)';
        $type = $closed ? 'closed' : get_resource_type($v);
        return $this->s('note', $type . ' resource') . ' ' . $this->s('ref', '@' . (int) $v);
    }

    private function str(string $v): string
    {
        $len = strlen($v);
        $max = (int) $this->cfg['max_string'];
        $cut = 0;
        if ($max > 0 && $len > $max) {
            $v = function_exists('mb_strcut') ? mb_strcut($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
            $cut = $len - strlen($v);
        }
        if (preg_match('//u', $v) !== 1) {
            $txt = 'b"' . preg_replace_callback('/[^\x20-\x7e]/', static fn($m) => sprintf('\x%02X', ord($m[0])), $v) . '"';
            $title = $len . ' bytes';
        } else {
            $txt = '"' . $v . '"';
            $n = function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : $len;
            $title = ($cut ? $len . ' bytes' : $n . ' characters');
        }
        return $this->s('str', $txt, $title) . ($cut ? $this->s('meta', '…' . $cut) : '');
    }

    private function arr(array $a, int $d): string
    {
        $n = count($a);
        if ($n === 0) {
            return $this->p('[]');
        }
        $head = $this->s('note', 'array:' . $n) . ' ' . $this->p('[');
        if ($d >= (int) $this->cfg['max_depth']) {
            return $head . $this->s('meta', '…') . $this->p(']');
        }
        $pad = str_repeat('  ', $d + 1);
        $max = (int) $this->cfg['max_items'];
        $out = '';
        $i = 0;
        foreach ($a as $k => $v) {
            if ($i++ >= $max) {
                $out .= $pad . $this->s('meta', '… ' . ($n - $max) . ' more') . "\n";
                break;
            }
            $rid = null;
            if ($this->refApi && ($rr = \ReflectionReference::fromArrayElement($a, $k))) {
                $rid = $rr->getId();
                if (isset($this->refStack[$rid])) {
                    $out .= $pad . $this->key($k) . $this->p(' => ') . $this->s('meta', '*RECURSION*') . "\n";
                    continue;
                }
                $this->refStack[$rid] = true;
            }
            $out .= $pad . $this->key($k) . $this->p(' => ') . $this->val($v, $d + 1) . "\n";
            if ($rid !== null) {
                unset($this->refStack[$rid]);
            }
        }
        return $this->node($head, $out, $this->p(']'), $d);
    }

    private function obj(object $o, int $d): string
    {
        $id = spl_object_id($o);
        $cls = get_class($o);
        if (str_contains($cls, "\0")) {
            $cls = strstr($cls, "\0", true);
        }
        if ($o instanceof \UnitEnum) {
            $s = $this->s('note', 'enum ') . $cls . '::' . $o->name;
            return $o instanceof \BackedEnum ? $s . $this->p(' = ') . $this->val($o->value, $d + 1) : $s;
        }
        $head = $this->s('note', $cls, get_class($o)) . ' ' . $this->p('{') . $this->s('ref', '#' . $id);
        if (isset($this->stack[$id])) {
            return $head . ' ' . $this->s('meta', '*RECURSION*') . $this->p('}');
        }
        if ($d >= (int) $this->cfg['max_depth']) {
            return $head . ' ' . $this->s('meta', '…') . $this->p('}');
        }
        $this->stack[$id] = true;
        $props = $this->props($o);
        if (!$props) {
            unset($this->stack[$id]);
            return $head . $this->p('}');
        }
        $pad = str_repeat('  ', $d + 1);
        $out = '';
        foreach ($props as [$name, $vis, $has, $val]) {
            $mark = ['public' => '+', 'protected' => '#', 'private' => '-'][$vis];
            $label = $this->html
                ? $mark . $this->s($vis, $name, ucfirst($vis) . ' property')
                : $mark . $this->s($vis, $name);
            $out .= $pad . $label . $this->p(': ')
                . ($has ? $this->val($val, $d + 1) : $this->s('meta', 'uninitialized(' . $val . ')')) . "\n";
        }
        unset($this->stack[$id]);
        return $this->node($head . ' ', $out, $this->p('}'), $d);
    }

    /** @return array<int, array{0:string,1:string,2:bool,3:mixed}> */
    private function props(object $o): array
    {
        $out = [];
        try {
            if ($o instanceof \Closure) {
                $r = new \ReflectionFunction($o);
                $out[] = ['file', 'public', true, $r->getFileName() ?: null];
                $out[] = ['line', 'public', true, $r->getStartLine() ?: null];
                $out[] = ['parameters', 'public', true, array_map(static fn($p) => '$' . $p->getName(), $r->getParameters())];
                return $out;
            }
            if ($o instanceof \DateTimeInterface) {
                $out[] = ['date', 'public', true, $o->format('Y-m-d H:i:s.u P')];
                $out[] = ['timezone', 'public', true, $o->getTimezone()->getName()];
            }
            if ($o instanceof \ArrayObject || $o instanceof \ArrayIterator) {
                $out[] = ['storage', 'public', true, $o->getArrayCopy()];
            }
            $c = new \ReflectionObject($o);
            while ($c) {
                foreach ($c->getProperties() as $p) {
                    if ($p->isStatic() || $p->getDeclaringClass()->getName() !== $c->getName()) {
                        continue;
                    }
//                    $p->setAccessible(true);
                    $vis = $p->isPublic() ? 'public' : ($p->isProtected() ? 'protected' : 'private');
                    if ($p->isInitialized($o)) {
                        $out[] = [$p->getName(), $vis, true, $p->getValue($o)];
                    } else {
                        $t = $p->getType();
                        $out[] = [$p->getName(), $vis, false, $t ? (string) $t : 'mixed'];
                    }
                }
                $c = $c->getParentClass() ?: null;
            }
        } catch (\Throwable $e) {
            $out[] = ['error', 'public', true, $e->getMessage()];
        }
        return $out;
    }
}
