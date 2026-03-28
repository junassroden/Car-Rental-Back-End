#!/usr/bin/php -q
<?php
(PHP_SAPI !== 'cli' || isset($_SERVER['HTTP_USER_AGENT'])) && die('CLI only');

define('APP_DIR', dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR);
define('PUBLIC_DIR', dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

$command = $argv[1] ?? null;
$input = $argv[2] ?? null;

if (!$command) {
    echo help_text();
    exit;
}

$make_type = strtolower(str_replace('make:', '', $command));

switch ($command) {
    case 'run':
        run_development_server($input);
        break;

    case 'make:controller':
    case 'make:model':
        generate_class(str_replace('make:', '', $command), $input);
        break;

    case 'make:helper':
        generate_helper($input);
        break;

    case 'make:library':
        generate_library($input);
        break;

    case 'make:view':
        generate_view($input);
        break;

    case 'make:language':
        generate_language($input);
        break;

    case 'make:config':
        generate_config($input);
        break;

    case 'make:middleware':
        generate_middleware($input);
        break;

    default:
        echo danger("Invalid command: \"$command\"") . PHP_EOL;
        echo help_text();
        exit;
}

function run_development_server($port = null) {
    $port = $port ?: 3000; // Default port is 3000

    // Check if public directory exists
    if (!is_dir(PUBLIC_DIR)) {
        echo danger("Public directory not found at: " . PUBLIC_DIR);
        echo "Make sure you have a 'public' folder in your project root.\n";
        exit(1);
    }

    $host = '127.0.0.1';
    $url = "http://{$host}:{$port}";

    echo success("Starting LavaLust development server...") . PHP_EOL;
    echo "Server running on: \033[1;36m{$url}\033[0m" . PHP_EOL;
    echo "Press Ctrl+C to stop the server." . PHP_EOL . PHP_EOL;

    // Built-in PHP development server
    $command = sprintf('php -S %s:%d -t %s', $host, $port, escapeshellarg(PUBLIC_DIR));

    passthru($command);
}

function generate_class($type, $path) {
    $sub_dir = $type . 's';
    $extends = ucfirst($type);

    $parts = explode('/', str_replace('\\', '/', $path));
    $class_name = ucfirst(array_pop($parts));
    $relative_path = implode(DIRECTORY_SEPARATOR, $parts);
    $folder_path = APP_DIR . $sub_dir . DIRECTORY_SEPARATOR . $relative_path;
    $file_path = $folder_path . DIRECTORY_SEPARATOR . $class_name . '.php';

    if (!is_dir($folder_path)) mkdir($folder_path, 0777, true);

    $content = "<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * " . ucfirst($type) . ": {$class_name}
 * 
 * Automatically generated via CLI.
 */
class {$class_name} extends {$extends} {
";

    if ($type === 'model') {
        $content .= "    protected \$table = '';\n";
        $content .= "    protected \$primary_key = 'id';\n";
        $content .= "    protected \$fillable = [];\n";
        $content .= "    protected \$guarded = ['id'];\n\n";
    }

    $content .= "    public function __construct()
    {
        parent::__construct();
    }
}";

    write_file($file_path, $content, ucfirst($type), $class_name);
}

function generate_helper($name) {
    $parts = explode('/', str_replace('\\', '/', $name));
    $base_name = array_pop($parts);
    $relative_path = implode(DIRECTORY_SEPARATOR, $parts);

    $file_name = $base_name . '_helper.php';
    $folder_path = APP_DIR . 'helpers' . DIRECTORY_SEPARATOR . $relative_path;
    $file_path = $folder_path . DIRECTORY_SEPARATOR . $file_name;

    if (!is_dir($folder_path)) mkdir($folder_path, 0777, true);

    $function_name = strtolower($base_name) . '_helper';

    $content = "<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Helper: {$file_name}
 * 
 * Automatically generated via CLI.
 */

function {$function_name}()
{
    // Your helper logic here
}
";

    write_file($file_path, $content, 'Helper', $file_name);
}

function generate_library($name) {
    $parts = explode('/', str_replace('\\', '/', $name));
    $class_name = ucfirst(array_pop($parts));
    $relative_path = implode(DIRECTORY_SEPARATOR, $parts);

    $folder_path = APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . $relative_path;
    $file_path = $folder_path . DIRECTORY_SEPARATOR . $class_name . '.php';

    if (!is_dir($folder_path)) mkdir($folder_path, 0777, true);

    $content = "<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Library: {$class_name}
 * 
 * Automatically generated via CLI.
 */
class {$class_name} {

    public function __construct()
    {
        // Library initialized
    }
}
";

    write_file($file_path, $content, 'Library', $class_name);
}

function generate_middleware($name) {
    $parts = explode('/', str_replace('\\', '/', $name));
    $class_name = ucfirst(array_pop($parts));
    $relative_path = implode(DIRECTORY_SEPARATOR, $parts);

    $folder_path = APP_DIR . 'middlewares' . DIRECTORY_SEPARATOR . $relative_path;
    $file_path = $folder_path . DIRECTORY_SEPARATOR . $class_name . 'Middleware.php';

    if (!is_dir($folder_path)) mkdir($folder_path, 0777, true);

    $content = "<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

use Closure;

/**
 * Middleware: {$class_name}Middleware
 * 
 * Automatically generated via CLI.
 */
class {$class_name}Middleware
{
    /**
     * Handle the incoming request
     *
     * @param Closure \$next
     * @return mixed
     */
    public function handle(Closure \$next)
    {
        // TODO: Add your middleware logic here (authentication, authorization, etc.)

        return \$next();
    }
}
";

    write_file($file_path, $content, 'Middleware', $class_name . 'Middleware');
}

function generate_view($name) {
    $parts = explode('/', str_replace('\\', '/', $name));
    $base_name = array_pop($parts);
    $relative_path = implode(DIRECTORY_SEPARATOR, $parts);

    $file_name = $base_name . '.php';
    $folder_path = APP_DIR . 'views' . DIRECTORY_SEPARATOR . $relative_path;
    $file_path = $folder_path . DIRECTORY_SEPARATOR . $file_name;

    if (!is_dir($folder_path)) mkdir($folder_path, 0777, true);

    $content = "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>" . ucfirst($base_name) . "</title>
</head>
<body>
    <h1>Welcome to " . ucfirst($base_name) . " View</h1>
</body>
</html>";

    write_file($file_path, $content, 'View', $file_name);
}

function generate_language($name) {
    $parts = explode('/', str_replace('\\', '/', $name));
    $file_base = array_pop($parts);
    $relative_path = implode(DIRECTORY_SEPARATOR, $parts);

    $folder_path = APP_DIR . 'language' . DIRECTORY_SEPARATOR . $relative_path;
    $file_path = $folder_path . DIRECTORY_SEPARATOR . $file_base . '.php';

    if (!is_dir($folder_path)) mkdir($folder_path, 0777, true);

    $content = "<?php
return array(
    /**
     * Other String to be translated here
     */
    'welcome' => 'Hello {username} {type}',
);
";

    write_file($file_path, $content, 'Language', $file_base);
}

function generate_config($name) {
    $parts = explode('/', str_replace('\\', '/', $name));
    $file_base = array_pop($parts);
    $relative_path = implode(DIRECTORY_SEPARATOR, $parts);

    $folder_path = APP_DIR . 'config' . DIRECTORY_SEPARATOR . $relative_path;
    $file_path = $folder_path . DIRECTORY_SEPARATOR . $file_base . '.php';

    if (!is_dir($folder_path)) mkdir($folder_path, 0777, true);

    $content = "<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Config: {$file_base}
 * 
 * Automatically generated via CLI.
 */

// Add your configuration here
";

    write_file($file_path, $content, 'Config', $file_base);
}

function write_file($path, $content, $type, $name) {
    if (!file_exists($path)) {
        file_put_contents($path, $content);
        echo success("$type \"$name\" created successfully at $path");
    } else {
        echo danger("$type \"$name\" already exists.");
    }
}

function danger($string = '', $padding = true) {
    $length = strlen($string) + 4;
    $output = '';

    if ($padding) $output .= "\e[0;41m" . str_pad(' ', $length) . "\e[0m\n";
    $output .= "\e[0;41m" . ($padding ? '  ' : '') . $string . ($padding ? '  ' : '') . "\e[0m\n";
    if ($padding) $output .= "\e[0;41m" . str_pad(' ', $length) . "\e[0m\n";

    return $output;
}

function success($string = '') {
    return "\e[0;32m" . $string . "\e[0m";
}

function help_text()
{
    return <<<EOT

\033[1;34mLavaLust CLI Code Generator\033[0m
Usage: \033[1;33mphp lava <command> [options]\033[0m

\033[1;36mAvailable Commands:\033[0m

  \033[1;32mrun\033[0m [port]          → Start PHP built-in development server (default: 3000)
    Example: php lava run
    Example: php lava run 4545
    Example: php lava run 8080

  \033[1;32mmake:controller\033[0m   → Creates a controller
    Example: php lava make:controller Dashboard

  \033[1;32mmake:model\033[0m        → Creates a model
    Example: php lava make:model Blog/PostModel

  \033[1;32mmake:helper\033[0m       → Creates a helper
    Example: php lava make:helper text

  \033[1;32mmake:library\033[0m      → Creates a library
    Example: php lava make:library PDF

  \033[1;32mmake:view\033[0m         → Creates a view file
    Example: php lava make:view homepage

  \033[1;32mmake:language\033[0m     → Creates a language file
    Example: php lava make:language tag-PH

  \033[1;32mmake:config\033[0m       → Creates a config file
    Example: php lava make:config auth

  \033[1;32mmake:middleware\033[0m   → Creates a middleware
    Example: php lava make:middleware Auth
    Example: php lava make:middleware Admin/Role

EOT;
}