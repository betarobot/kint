<?php

namespace Kint\Renderer;

use Kint;
use Kint\Object\BasicObject;
use Kint\Object\BlobObject;
use Kint\Object\Representation\Representation;

class RichRenderer extends Renderer
{
    /**
     * RichRenderer object plugins should implement Kint\Renderer\Rich\ObjectPluginInterface.
     */
    public static $object_plugins = array(
        'blacklist' => 'Kint\\Renderer\\Rich\\BlacklistPlugin',
        'callable' => 'Kint\\Renderer\\Rich\\CallablePlugin',
        'closure' => 'Kint\\Renderer\\Rich\\ClosurePlugin',
        'color' => 'Kint\\Renderer\\Rich\\ColorPlugin',
        'depth_limit' => 'Kint\\Renderer\\Rich\\DepthLimitPlugin',
        'nothing' => 'Kint\\Renderer\\Rich\\NothingPlugin',
        'recursion' => 'Kint\\Renderer\\Rich\\RecursionPlugin',
        'simplexml_element' => 'Kint\\Renderer\\Rich\\SimpleXMLElementPlugin',
        'trace_frame' => 'Kint\\Renderer\\Rich\\TraceFramePlugin',
    );

    /**
     * RichRenderer tab plugins should implement Kint\Renderer\Rich\TabPluginInterface.
     */
    public static $tab_plugins = array(
        'binary' => 'Kint\\Renderer\\Rich\\BinaryPlugin',
        'color' => 'Kint\\Renderer\\Rich\\ColorPlugin',
        'docstring' => 'Kint\\Renderer\\Rich\\DocstringPlugin',
        'microtime' => 'Kint\\Renderer\\Rich\\MicrotimePlugin',
        'source' => 'Kint\\Renderer\\Rich\\SourcePlugin',
        'table' => 'Kint\\Renderer\\Rich\\TablePlugin',
        'timestamp' => 'Kint\\Renderer\\Rich\\TimestampPlugin',
    );

    public static $pre_render_sources = array(
        'script' => array(
            array('Kint\\Renderer\\RichRenderer', 'renderJs'),
            array('Kint\\Renderer\\Rich\\MicrotimePlugin', 'renderJs'),
        ),
        'style' => array(
            array('Kint\\Renderer\\RichRenderer', 'renderCss'),
        ),
        'raw' => array(
            array('Kint\\Renderer\\RichRenderer', 'renderFolder'),
        ),
    );

    /**
     * Whether or not to render access paths.
     *
     * Access paths can become incredibly heavy with very deep and wide
     * structures. Given mostly public variables it will typically make
     * up one quarter of the output HTML size.
     *
     * If this is an unacceptably large amount and your browser is groaning
     * under the weight of the access paths - your first order of buisiness
     * should be to get a new browser. Failing that, use this to turn them off.
     *
     * @var bool
     */
    public static $access_paths = true;

    /**
     * The maximum length of a string before it is truncated.
     *
     * Falsey to disable
     *
     * @var int
     */
    public static $strlen_max = 80;

    /**
     * Path to the CSS file to load by default.
     *
     * @var string
     */
    public static $theme = 'original.css';

    /**
     * Assume types and sizes don't need to be escaped.
     *
     * Turn this off if you use anything but ascii in your class names,
     * but it'll cause a slowdown of around 10%
     *
     * @var bool
     */
    public static $escape_types = false;

    /**
     * Move all dumps to a folder at the bottom of the body.
     *
     * @var bool
     */
    public static $folder = true;

    protected static $been_run = false;

    protected $plugin_objs = array();
    protected $mod_return = false;
    protected $callee;
    protected $mini_trace;
    protected $previous_caller;
    protected $file_link_format = false;
    protected $show_minitrace = true;
    protected $auto_expand = false;

    public function __construct(array $params = array())
    {
        parent::__construct($params);

        $params += array(
            'modifiers' => array(),
            'minitrace' => array(),
            'callee' => null,
            'caller' => null,
        );

        $this->callee = $params['callee'];
        $this->mini_trace = $params['minitrace'];
        $this->previous_caller = $params['caller'];

        if (isset($params['settings']['return'])) {
            $this->mod_return = $params['settings']['return'];
        }

        if (isset($params['settings']['file_link_format'])) {
            $this->file_link_format = $params['settings']['file_link_format'];
        }

        if (empty($params['settings']['display_called_from'])) {
            $this->show_minitrace = false;
        }

        if (!empty($params['settings']['expanded'])) {
            $this->auto_expand = true;
        }
    }

    public function render(BasicObject $o)
    {
        if ($plugin = $this->getPlugin(self::$object_plugins, $o->hints)) {
            if (strlen($output = $plugin->renderObject($o))) {
                return $output;
            }
        }

        $children = $this->renderChildren($o);
        $header = $this->renderHeaderWrapper($o, (bool) strlen($children), $this->renderHeader($o));

        return '<dl>'.$header.$children.'</dl>';
    }

    public function renderHeaderWrapper(BasicObject $o, $has_children, $contents)
    {
        $out = '<dt';

        if ($has_children) {
            $out .= ' class="kint-parent';

            if ($this->auto_expand) {
                $out .= ' kint-show';
            }

            $out .= '"';
        }

        $out .= '>';

        if (self::$access_paths && $o->depth > 0 && $ap = $o->getAccessPath()) {
            $out .= '<span class="kint-access-path-trigger" title="Show access path">&rlarr;</span>';
        }

        if ($has_children) {
            $out .= '<span class="kint-popup-trigger" title="Open in new window">&boxbox;</span><nav></nav>';
        }

        $out .= $contents;

        if (!empty($ap)) {
            $out .= '<div class="access-path">'.$this->escape($ap).'</div>';
        }

        return $out.'</dt>';
    }

    public function renderHeader(BasicObject $o)
    {
        $output = '';

        if (($s = $o->getModifiers()) !== null) {
            $output .= '<var>'.$s.'</var> ';
        }

        if (($s = $o->getName()) !== null) {
            $output .= '<dfn>'.$this->escape($s).'</dfn> ';

            if ($s = $o->getOperator()) {
                $output .= $this->escape($s, 'ASCII').' ';
            }
        }

        if (($s = $o->getType()) !== null) {
            if (self::$escape_types) {
                $s = $this->escape($s);
            }

            if ($o->reference) {
                $s = '&amp;'.$s;
            }

            $output .= '<var>'.$s.'</var> ';
        }

        if (($s = $o->getSize()) !== null) {
            if (self::$escape_types) {
                $s = $this->escape($s);
            }
            $output .= '('.$s.') ';
        }

        if (($s = $o->getValueShort()) !== null) {
            $s = preg_replace('/\s+/', ' ', $s);

            if (self::$strlen_max && BlobObject::strlen($s) > self::$strlen_max) {
                $s = substr($s, 0, self::$strlen_max).'...';
            }
            $output .= $this->escape($s);
        }

        return trim($output);
    }

    public function renderChildren(BasicObject $o)
    {
        $contents = array();
        $tabs = array();

        foreach ($o->getRepresentations() as $rep) {
            $result = $this->renderTab($o, $rep);
            if (strlen($result)) {
                $contents[] = $result;
                $tabs[] = $rep;
            }
        }

        if (empty($tabs)) {
            return '';
        }

        $output = '<dd>';

        if (count($tabs) === 1 && $tabs[0]->labelIsImplicit()) {
            $output .= reset($contents);
        } else {
            $output .= '<ul class="kint-tabs">';

            foreach ($tabs as $i => $tab) {
                if ($i === 0) {
                    $output .= '<li class="kint-active-tab">';
                } else {
                    $output .= '<li>';
                }

                $output .= $this->escape($tab->getLabel()).'</li>';
            }

            $output .= '</ul><ul>';

            foreach ($contents as $tab) {
                $output .= '<li>'.$tab.'</li>';
            }

            $output .= '</ul>';
        }

        return $output.'</dd>';
    }

    protected function renderTab(BasicObject $o, Representation $rep)
    {
        if ($plugin = $this->getPlugin(self::$tab_plugins, $rep->hints)) {
            if (strlen($output = $plugin->renderTab($rep))) {
                return $output;
            }
        }

        if (is_array($rep->contents)) {
            $output = '';
            foreach ($rep->contents as $obj) {
                $output .= $this->render($obj);
            }

            return $output;
        } elseif (is_string($rep->contents)) {
            $show_contents = false;

            // If it is the value representation of a string and its whitespace
            // was truncated in the header, always display the full string
            if ($o->type !== 'string' || $o->value !== $rep) {
                $show_contents = true;
            } elseif (preg_match('/(:?[\r\n\t\f\v]| {2})/', $rep->contents)) {
                $show_contents = true;
            } elseif (self::$strlen_max && BlobObject::strlen($rep->contents) > self::$strlen_max) {
                $show_contents = true;
            }

            if ($o->type === 'string' && $o->value === $rep && empty($o->encoding)) {
                $show_contents = false;
            }

            if ($show_contents) {
                return '<pre>'.$this->escape($rep->contents)."\n</pre>";
            }
        } elseif ($rep->contents instanceof BasicObject) {
            return $this->render($rep->contents);
        }

        return;
    }

    protected static function renderJs()
    {
        return file_get_contents(KINT_DIR.'/resources/compiled/rich.js');
    }

    protected static function renderCss()
    {
        if (file_exists(KINT_DIR.'/resources/compiled/'.self::$theme)) {
            return file_get_contents(KINT_DIR.'/resources/compiled/'.self::$theme);
        } else {
            return file_get_contents(self::$theme);
        }
    }

    protected static function renderFolder()
    {
        if (self::$folder) {
            return '<div class="kint-rich kint-folder"><dl><dt class="kint-parent"><nav></nav>Kint</dt><dd class="kint-folder"></dd></dl></div>';
        } else {
            return '';
        }
    }

    public function preRender()
    {
        $output = '';

        if (!self::$been_run || $this->mod_return) {
            foreach (self::$pre_render_sources as $type => $values) {
                $contents = '';
                foreach ($values as $v) {
                    $contents .= call_user_func($v, $this);
                }

                if (!strlen($contents)) {
                    continue;
                }

                switch ($type) {
                    case 'script':
                        $output .= '<script class="kint-script">'.$contents.'</script>';
                        break;
                    case 'style':
                        $output .= '<style class="kint-style">'.$contents.'</style>';
                        break;
                    default:
                        $output .= $contents;
                }
            }

            if (!$this->mod_return) {
                self::$been_run = true;
            }
        }

        return $output.'<div class="kint-rich">';
    }

    public function postRender()
    {
        if (!$this->show_minitrace) {
            return '</div>';
        }

        $output = '<footer>';
        $output .= '<span class="kint-popup-trigger" title="Open in new window">&boxbox;</span> ';

        if (isset($this->callee['file'])) {
            if (!empty($this->mini_trace)) {
                $output .= '<nav></nav>';
            }

            $output .= 'Called from '.$this->ideLink($this->callee['file'], $this->callee['line']);
        }

        $caller = '';

        if (isset($this->previous_caller['class'])) {
            $caller .= $this->previous_caller['class'];
        }
        if (isset($this->previous_caller['type'])) {
            $caller .= $this->previous_caller['type'];
        }
        if (isset($this->previous_caller['function'])
            && !in_array(
                $this->previous_caller['function'],
                array('include', 'include_once', 'require', 'require_once')
            )
        ) {
            $caller .= $this->previous_caller['function'].'()';
        }

        if ($caller) {
            $output .= ' ['.$caller.']';
        }

        if (!empty($this->mini_trace)) {
            $output .= '<ol>';
            foreach ($this->mini_trace as $step) {
                $output .= '<li>'.$this->ideLink($step['file'], $step['line']); // closing tag not required
                if (isset($step['function'])
                    && !in_array($step['function'], array('include', 'include_once', 'require', 'require_once'))
                ) {
                    $output .= ' [';
                    if (isset($step['class'])) {
                        $output .= $step['class'];
                    }
                    if (isset($step['type'])) {
                        $output .= $step['type'];
                    }
                    $output .= $step['function'].'()]';
                }
            }
            $output .= '</ol>';
        }

        $output .= '</footer></div>';

        return $output;
    }

    public function escape($string, $encoding = false)
    {
        if ($encoding === false) {
            $encoding = BlobObject::detectEncoding($string);
        }

        $original_encoding = $encoding;

        if ($encoding === false || $encoding === 'ASCII') {
            $encoding = 'UTF-8';
        }

        $string = htmlspecialchars($string, ENT_NOQUOTES, $encoding);

        // this call converts all non-ASCII characters into numeirc htmlentities
        if (extension_loaded('mbstring') && $original_encoding !== 'ASCII') {
            $string = mb_encode_numericentity($string, array(0x80, 0xffff, 0, 0xffff), $encoding);
        }

        return $string;
    }

    protected function getPlugin(array $plugins, array $hints)
    {
        if ($plugins = $this->matchPlugins($plugins, $hints)) {
            $plugin = end($plugins);

            if (!isset($this->plugin_objs[$plugin])) {
                $this->plugin_objs[$plugin] = new $plugin($this);
            }

            return $this->plugin_objs[$plugin];
        }
    }

    protected function ideLink($file, $line)
    {
        $shortenedPath = $this->escape(Kint::shortenPath($file));
        if (!$this->file_link_format) {
            return $shortenedPath.':'.$line;
        }

        $ideLink = Kint::getIdeLink($file, $line);
        $class = (strpos($ideLink, 'http://') === 0) ? 'class="kint-ide-link" ' : '';

        return "<a {$class}href=\"{$ideLink}\">{$shortenedPath}:{$line}</a>";
    }
}