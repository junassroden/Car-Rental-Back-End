<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 4
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/**
* ------------------------------------------------------
*  Class Ember
* ------------------------------------------------------
 */
class Ember
{
    /**
     * Templates path
     *
     * @var string
     */
    public string $templates_path;

    /**
     * Cache path
     *
     * @var string
     */
    public string $cache_path;

    /** Registered globals
     *
     * @var array
     */
    public array $globals = [];

    /** Registered functions
     *
     * @var array
     */
    public array $functions = [];

    /** Registered filters
     *
     * @var array
     */
    public array $filters = [];

    /** Auto-escape output
     *
     * @var bool
     */
    public bool $auto_escape;
    
    /**
     * Constructor
     *
     * @param array $options
     */
    public function __construct()
    {
        $_lava = lava_instance();

        $_lava->config->load('ember');

        if(! config_item('ember_helper_enabled')) {
            show_error('Ember Helper is disabled or set up incorrectly.');
        }
       
        $options = ['auto_escape' => config_item('auto_escape')];
        
        $this->templates_path = config_item('templates_path');

        $this->cache_path = config_item('cache_path');
        
        if (!is_dir($this->cache_path)) mkdir($this->cache_path, 0755, true);
        
        if (isset($options['auto_escape'])) $this->auto_escape = (bool)$options['auto_escape'];

        // Register built-in filters and functions
        $registrar = load_class('Ember_registrar', 'libraries/Ember');
        $registrar->register($this);
        
    }

    /**
     * Escape output if auto_escape is enabled
     *
     * @param string $value
     * @return string
     */
    public function escape($value)
    {
        return $this->auto_escape
            ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
            : $value;
    }

    /**
     * Add a global variable
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function add_global($name, $value)
    {
        $this->globals[$name] = $value;
    }

    /**
     * Add a function
     *
     * @param string $name
     * @param callable $callable
     * @return void
     */
    public function add_function($name, $callable)
    {
        $this->functions[$name] = $callable;
    }

    /**
     * Apply a filter to a value
     *
     * @param string $name
     * @param mixed $value
     * @return mixed
     */
    public function add_filter($name, $callable)
    {
        $this->filters[$name] = $callable;
    }

    /**
     * Apply a filter to a value
     *
     * @param string $name
     * @param mixed $value
     * @return mixed
     */
    public function apply_filter($name, $value)
    {
        if (isset($this->filters[$name])) {
            return ($this->filters[$name])($value);
        }
        throw new \RuntimeException("Filter not defined: $name");
    }

    /**
     * Render a template with given context
     *
     * @param string $template
     * @param array $context
     * @return string
     */
    public function render($template, $context = [])
    {
        $compiled = $this->compile($template);
        $vars = array_merge($this->globals, $context);
        $funcs = $this->functions;
        $sections = [];
        $extends = null;

        ob_start();
        try {
            (function () use ($compiled, $vars, $funcs, &$sections, &$extends) {
                extract($vars, EXTR_SKIP);
                $__fn = $funcs;
                $__sections = &$sections;
                $__extends = &$extends;
                include $compiled;
            })();
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        if ($extends) {
            $parent_compiled = $this->compile($extends);
            ob_start();
            try {
                (function () use ($parent_compiled, $vars, $funcs, &$sections) {
                    extract($vars, EXTR_SKIP);
                    $__fn = $funcs;
                    $__sections = $sections;
                    include $parent_compiled;
                })();
                return ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        }

        return $output;
    }

    /**
     * Compile a template to a cached PHP file
     *
     * @param string $template
     * @return string Path to compiled PHP file
     */
    public function compile($template)
    {
        $tpl_path = $this->resolve_template_path($template);
        if (!file_exists($tpl_path)) throw new \RuntimeException("Template not found: $template");

        $cache_file = $this->cache_path . md5($tpl_path) . '.php';
        if (!file_exists($cache_file) || filemtime($cache_file) < filemtime($tpl_path)) {
            $source = file_get_contents($tpl_path);
            $compiled = $this->compile_string($source, $tpl_path);
            file_put_contents($cache_file, $compiled);
        }

        return $cache_file;
    }

    /**
     * Resolve template name to a file path
     *
     * @param string $template
     * @return string
     */
    public function resolve_template_path($template)
    {
        $tpl = str_replace('.', DIRECTORY_SEPARATOR, $template);
        $paths = [
            $this->templates_path . $tpl,
            $this->templates_path . $tpl . '.ember.php',
            $this->templates_path . $tpl . '.php',
            $this->templates_path . $tpl . '.html',
            $this->templates_path . $tpl . '.tpl',
        ];
        foreach ($paths as $p) {
            if (is_file($p)) {
                $real = realpath($p);
                if (strpos($real, realpath($this->templates_path)) !== 0) {
                    throw new \RuntimeException("Template outside allowed path: $template");
                }
                return $real;
            }
        }
        throw new \RuntimeException("Template not found: $template");
    }

    /**
     * Compile template source code to PHP code
     *
     * @param string $source
     * @param string $tpl_path
     * @return string
     */
    public function compile_string($source, $tpl_path = '')
    {
        // for extends and sections
        $source = preg_replace('/@extends\([\'"](.+?)[\'"]\)/', '<?php $__extends = \'$1\'; ?>', $source);

        // @section ... @endsection ... @show
        $source = preg_replace('/@section\([\'"](.+?)[\'"]\)/', '<?php $__sectionName = \'$1\'; ob_start(); ?>', $source);
        $source = preg_replace('/@endsection/', '<?php $__sections[$__sectionName] = ob_get_clean(); unset($__sectionName); ?>', $source);

        // @show (end section and display)
        $source = preg_replace('/@show/', '<?php if(isset($__sectionName)) { $__sections[$__sectionName] = ob_get_clean(); echo $__sections[$__sectionName]; unset($__sectionName); } ?>', $source);

        // @yield('section') - display section content
        $source = preg_replace('/@yield\([\'"](.+?)[\'"]\)/', '<?php echo $__sections[\'$1\'] ?? ""; ?>', $source);

        // @include - sanitize template name, pass defined vars
        $source = preg_replace('/@include\([\'"](.+?)[\'"]\)/', '<?php
            $tplName = preg_replace("/[^a-zA-Z0-9_\.\/\-]/", "", "$1");
            $vars = array_diff_key(get_defined_vars(), array_flip(["__sections","__fn","__extends","compiled"]));
            echo $this->render($tplName, $vars); ?>', $source);

        // Raw echo {!! !!}
        $source = preg_replace('/\{!!\s*(.*?)\s*!!\}/s', '<?php echo ($1); ?>', $source);

        // Escaped echo {{ var|filters }} OR {{ function(args) }}
        $source = preg_replace_callback('/\{\{\s*(.*?)\s*\}\}/s', function ($m) {
            $expr = trim($m[1]);

            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$/', $expr, $fnMatch)) {
                $fnName = $fnMatch[1];
                $args   = trim($fnMatch[2]);
                $args = array_map('trim', preg_split('/,(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/', $args));

                foreach ($args as &$a) {
                    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $a)) {
                        $a = '$' . $a;
                    }
                }
                $argsStr = implode(', ', $args);

                return "<?php echo \$this->escape((\$__fn['$fnName'])($argsStr)); ?>";
            }

            // Variable with filters
            $parts = preg_split('/\|(?=(?:[^\"\']*[\"\'][^\"\']*[\"\'])*[^\"\']*$)/', $expr);
            $var = '$' . trim(array_shift($parts));
            foreach ($parts as $filter) {
                $filter = trim($filter);
                if ($filter === 'upper') {
                    $var = "strtoupper($var)";
                } elseif ($filter === 'lower') {
                    $var = "strtolower($var)";
                } elseif ($filter === 'raw') {
                    // raw always disables escaping
                    return "<?php echo $var; ?>";
                } else {
                    $var = "\$this->apply_filter('$filter', $var)";
                }
            }

            return "<?php echo \$this->escape($var); ?>";
        }, $source);


        // If / elseif / else / endif
        $source = preg_replace('/@if\s*\((.*?)\)/', '<?php if ($1): ?>', $source);
        $source = preg_replace('/@elseif\s*\((.*?)\)/', '<?php elseif ($1): ?>', $source);
        $source = preg_replace('/@else/', '<?php else: ?>', $source);
        $source = preg_replace('/@endif/', '<?php endif; ?>', $source);

        // @foreach (require explicit $variables)
        $source = preg_replace_callback('/@foreach\s*\(\s*(.*?)\s*\)/', function($m) {
            return "<?php foreach ($m[1]): ?>";
        }, $source);
        $source = preg_replace('/@endforeach/', '<?php endforeach; ?>', $source);

        // @for
        $source = preg_replace_callback('/@for\s*\((.*?)\)/', fn($m) => "<?php for ($m[1]): ?>", $source);
        $source = preg_replace('/@endfor/', '<?php endfor; ?>', $source);

        // @while
        $source = preg_replace('/@while\s*\((.*?)\)/', '<?php while ($1): ?>', $source);
        $source = preg_replace('/@endwhile/', '<?php endwhile; ?>', $source);

        // @php ... @endphp
        $source = preg_replace('/@php/', '<?php ', $source);
        $source = preg_replace('/@endphp/', '?>', $source);

        return "<?php // Compiled: {$tpl_path} ?>\n" . $source;
    }
}
