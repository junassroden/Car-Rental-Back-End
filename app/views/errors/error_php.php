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
 * @copyright Copyright 2020 (https://ronmarasigan.github.io)
 * @since Version 1
 * @link https://lavalust.pinoywap.org
 * @license https://opensource.org/licenses/MIT MIT License
 */
?>
<?php
function get_code_excerpt($file, $line, $padding = 5) {
    if (!is_readable($file)) return [[], 0];
    $lines = file($file);
    $start = max($line - $padding - 1, 0);
    $end = min($line + $padding - 1, count($lines) - 1);
    $excerpt = array_slice($lines, $start, $end - $start + 1, true);
    return [$excerpt, $start + 1];
}

list($codeExcerpt, $excerptStart) = get_code_excerpt($filepath, $line);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Whoops! Something went wrong.</title>
    <style>
        body {
            margin: 0;
            padding: 2rem;
            background-color: #f0f0f0;
            font-family: Consolas, Menlo, monospace;
            color: #333;
        }

        .container {
            max-width: 960px;
            margin: auto;
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 6px;
            padding: 2rem;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }

        .title {
            font-size: 1.5rem;
            font-weight: bold;
            color: #a94442;
            margin-bottom: 1.5rem;
        }

        .label {
            font-weight: bold;
            margin-top: 1rem;
            display: block;
        }

        .code-preview {
            background: #2e2e2e;
            color: #eaeaea;
            padding: 1rem;
            font-size: 14px;
            border-radius: 6px;
            overflow-x: auto;
        }

        .line {
            display: flex;
        }

        .line-number {
            width: 3em;
            color: #888;
            text-align: right;
            margin-right: 1em;
            user-select: none;
        }

        .code-line {
            white-space: pre;
            flex-grow: 1;
        }

        .highlight {
            background: #444;
            color: #fff;
            font-weight: bold;
        }

        .stack-trace, .env {
            background: #fafafa;
            border: 1px solid #ddd;
            padding: 1rem;
            margin-top: 1rem;
            font-size: 14px;
            border-radius: 6px;
            white-space: pre-wrap;
        }

        .footer {
            text-align: center;
            font-size: 13px;
            margin-top: 2rem;
            color: #888;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.5rem;
        }

        table td {
            padding: 0.4rem 0.6rem;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: top;
            font-family: monospace;
        }

        table td:first-child {
            font-weight: bold;
            color: #666;
            width: 25%;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="title">🔥 PHP Error: <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>

    <div><strong>Severity:</strong> <?php echo $severity; ?></div>
    <div><strong>File:</strong> <?php echo $filepath; ?></div>
    <div><strong>Line:</strong> <?php echo $line; ?></div>

    <?php if (!empty($codeExcerpt)): ?>
        <div class="label">Code Preview</div>
        <div class="code-preview">
            <?php foreach ($codeExcerpt as $i => $codeLine): ?>
                <div class="line<?php echo ($excerptStart + $i) == $line ? ' highlight' : ''; ?>">
                    <div class="line-number"><?php echo str_pad($excerptStart + $i, 3, ' ', STR_PAD_LEFT); ?></div>
                    <div class="code-line"><?php echo htmlspecialchars(rtrim($codeLine)); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="label">Stack Trace</div>
    <div class="stack-trace">
        <?php foreach (debug_backtrace() as $trace): ?>
            <?php if (isset($trace['file']) && strpos($trace['file'], realpath(SYSTEM_DIR)) !== 0): ?>
                • <?php echo $trace['file']; ?>:<?php echo $trace['line'] ?? '?'; ?> → 
                <?php echo (isset($trace['class']) ? $trace['class'] . $trace['type'] : '') . $trace['function']; ?>()<br>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="label">Environment</div>
    <div class="env">
        <table>
            <tr><td>Method</td><td><?php echo $_SERVER['REQUEST_METHOD'] ?? 'N/A'; ?></td></tr>
            <tr><td>URI</td><td><?php echo $_SERVER['REQUEST_URI'] ?? 'N/A'; ?></td></tr>
            <tr><td>Query String</td><td><?php echo $_SERVER['QUERY_STRING'] ?? 'N/A'; ?></td></tr>
            <tr><td>GET</td><td><pre><?php print_r($_GET); ?></pre></td></tr>
            <tr><td>POST</td><td><pre><?php print_r($_POST); ?></pre></td></tr>
            <tr><td>COOKIE</td><td><pre><?php print_r($_COOKIE); ?></pre></td></tr>
            <tr><td>SESSION</td><td><pre><?php echo isset($_SESSION) ? print_r($_SESSION, true) : 'No session'; ?></pre></td></tr>
        </table>
    </div>

    <div class="footer">
        LavaLust <?php echo config_item('VERSION'); ?> — PHP <?php echo PHP_VERSION; ?>  
    </div>
</div>
</body>
</html>
