#!/usr/bin/php -q
<?php
(PHP_SAPI !== 'cli' || isset($_SERVER['HTTP_USER_AGENT'])) && die('CLI only');

define('APP_DIR', dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR);
define('PUBLIC_DIR', dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

// Command Registry
$commands = [];

// Register built-in commands
register_command('run', 'handle_run_command', 'Start PHP built-in development server', [
    '[port]' => 'Port number (default: 3000)'
]);

register_command('make:controller', 'handle_make_controller', 'Creates a controller', [
    'name' => 'Controller name (e.g., Dashboard or User/ProfileController)'
]);

register_command('make:model', 'handle_make_model', 'Creates a model', [
    'name' => 'Model name (e.g., User or Blog/PostModel)'
]);

register_command('make:helper', 'handle_make_helper', 'Creates a helper', [
    'name' => 'Helper name (e.g., text)'
]);

register_command('make:library', 'handle_make_library', 'Creates a library', [
    'name' => 'Library name (e.g., PDF)'
]);

register_command('make:view', 'handle_make_view', 'Creates a view file', [
    'name' => 'View name (e.g., homepage or admin/dashboard)'
]);

register_command('make:language', 'handle_make_language', 'Creates a language file', [
    'name' => 'Language file name (e.g., tag-PH)'
]);

register_command('make:config', 'handle_make_config', 'Creates a config file', [
    'name' => 'Config name (e.g., auth)'
]);

register_command('make:middleware', 'handle_make_middleware', 'Creates a middleware', [
    'name' => 'Middleware name (e.g., Auth or Admin/Role)'
]);

// Execute command
$command = $argv[1] ?? null;
$input = $argv[2] ?? null;

if (!$command) {
    echo help_text($commands);
    exit;
}

if (!isset($commands[$command])) {
    echo danger("Invalid command: \"$command\"") . PHP_EOL;
    echo help_text($commands);
    exit;
}

// Execute the registered command handler
call_user_func($commands[$command]['handler'], $input);

// ========== COMMAND HANDLERS ==========

function handle_run_command($port = null) {
    $port = $port ?: 3000;

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

    $command = sprintf('php -S %s:%d -t %s', $host, $port, escapeshellarg(PUBLIC_DIR));
    passthru($command);
}

function handle_make_controller($name) {
    generate_class_file('controller', $name, 'Controllers', 'Controller', null, 'extends BaseController');
}

function handle_make_model($name) {
    generate_class_file('model', $name, 'Models', 'Model', null, 'extends Model');
}

function handle_make_helper($name) {
    generate_helper_file($name);
}

function handle_make_library($name) {
    generate_class_file('library', $name, 'Libraries', 'Library');
}

function handle_make_middleware($name) {
    generate_middleware_file($name);
}

function handle_make_view($name) {
    generate_view_file($name);
}

function handle_make_language($name) {
    generate_language_file($name);
}

function handle_make_config($name) {
    generate_config_file($name);
}

// ========== GENERATOR FUNCTIONS ==========

function generate_class_file($type, $path, $sub_dir, $class_type, $interface = null, $extends = null) {
    $parts = explode('/', str_replace('\\', '/', $path));
    $class_name = ucfirst(array_pop($parts));
    $relative_path = implode(DIRECTORY_SEPARATOR, $parts);
    $folder_path = APP_DIR . $sub_dir . DIRECTORY_SEPARATOR . $relative_path;
    $file_path = $folder_path . DIRECTORY_SEPARATOR . $class_name . '.php';

    if (!is_dir($folder_path)) mkdir($folder_path, 0777, true);

    $extends_declaration = $extends ? " extends {$extends}" : '';
    $interface_declaration = $interface ? " implements {$interface}" : '';

    $content = "<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * {$class_type}: {$class_name}
 * 
 * Automatically generated via CLI.
 */
class {$class_name}{$extends_declaration}{$interface_declaration} {
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

    write_file($file_path, $content, $class_type, $class_name);
}

function generate_helper_file($name) {
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

function generate_middleware_file($name) {
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

function generate_view_file($name) {
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

function generate_language_file($name) {
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

function generate_config_file($name) {
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

// ========== UTILITY FUNCTIONS ==========

function register_command($command, $handler, $description = '', $arguments = []) {
    global $commands;
    $commands[$command] = [
        'handler' => $handler,
        'description' => $description,
        'arguments' => $arguments
    ];
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

function help_text($commands) {
    $help = "\033[1;34mLavaLust CLI Code Generator\033[0m\n";
    $help .= "Usage: \033[1;33mphp lava <command> [options]\033[0m\n\n";
    $help .= "\033[1;36mAvailable Commands:\033[0m\n\n";

    foreach ($commands as $command => $details) {
        $help .= "  \033[1;32m" . str_pad($command, 20) . "\033[0m → {$details['description']}\n";
        
        if (!empty($details['arguments'])) {
            foreach ($details['arguments'] as $arg => $desc) {
                $help .= "    \033[1;33m" . str_pad($arg, 18) . "\033[0m {$desc}\n";
            }
        }
        
        $help .= "\n";
    }

    // Add examples
    $help .= "\033[1;36mExamples:\033[0m\n";
    $help .= "  php lava run\n";
    $help .= "  php lava run 8080\n";
    $help .= "  php lava make:controller Dashboard\n";
    $help .= "  php lava make:model User\n";
    $help .= "  php lava make:helper text\n\n";

    return $help;
}