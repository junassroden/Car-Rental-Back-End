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

while (ob_get_level() > 0) {
    ob_end_clean();
}

function get_code_excerpt($file, $errorLine, $padding = 5) {
    if (!is_readable($file)) return [[], 0];
    $lines = file($file);
    $start = max($errorLine - $padding - 1, 0);
    $end = min($errorLine + $padding - 1, count($lines) - 1);
    $excerpt = array_slice($lines, $start, $end - $start + 1, true);
    return [$excerpt, $start + 1];
}

$ex = isset($exception) && $exception instanceof Throwable ? $exception : null;
$filePath = $ex ? $ex->getFile() : 'Unknown';
$lineNum = $ex ? $ex->getLine() : 0;

list($codeExcerpt, $excerptStart) = get_code_excerpt($filePath, $lineNum);

// Prepare trace for interactive display (array of frames)
$traceFrames = $ex ? $ex->getTrace() : [];
if (!empty($trace) && is_string($trace)) {
    // Fallback: if a plain text trace is provided, keep it as fallback
    $plainTrace = $trace;
} else {
    $plainTrace = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LavaLust · Database Error</title>
    <style nonce="<?= defined('CSP_NONCE') ? CSP_NONCE : '' ?>">
        /* modern dark mode */
        :root {
            --bg-body: #0a0c10;
            --bg-surface: #14161f;
            --bg-elevated: #1a1d2b;
            --bg-code: #0d0f15;
            --border-subtle: #2a2e3d;
            --text-primary: #eef2ff;
            --text-secondary: #9ca3c7;
            --text-muted: #6b728c;
            --accent-error: #f97583;
            --accent-warning: #ffb86b;
            --accent-info: #6bc5ff;
            --accent-success: #8be9b4;
            --shadow-md: 0 10px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.3);
            --font-mono: 'SF Mono', 'Fira Code', 'Cascadia Code', 'JetBrains Mono', monospace;
            --font-sans: system-ui, -apple-system, 'Segoe UI', 'Inter', 'Roboto', sans-serif;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--bg-body);
            font-family: var(--font-sans);
            color: var(--text-primary);
            padding: 2rem 1rem;
            line-height: 1.5;
        }

        .error-container {
            max-width: 1280px;
            margin: 0 auto;
        }

        .error-card {
            background: var(--bg-surface);
            border-radius: 28px;
            border: 1px solid var(--border-subtle);
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }

        .card-header {
            padding: 1.75rem 2rem;
            background: rgba(249, 117, 131, 0.08);
            border-bottom: 1px solid var(--border-subtle);
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: space-between;
            gap: 1rem;
        }

        .error-badge {
            font-family: var(--font-mono);
            font-size: 0.85rem;
            background: rgba(249, 117, 131, 0.2);
            color: var(--accent-error);
            padding: 0.3rem 0.9rem;
            border-radius: 40px;
            font-weight: 500;
            border: 1px solid rgba(249, 117, 131, 0.3);
        }

        .error-title {
            font-size: 1.6rem;
            font-weight: 600;
            background: linear-gradient(135deg, #f9a8b4, #ffb86b);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            margin-top: 0.5rem;
        }

        .section {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border-subtle);
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 1.25rem;
        }

        .section-header h3 {
            font-weight: 600;
            font-size: 1.25rem;
        }

        .section-icon {
            font-size: 1.4rem;
        }

        .message-card, .location-card {
            background: var(--bg-elevated);
            border-radius: 20px;
            padding: 1rem 1.4rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--accent-error);
        }

        .location-card {
            border-left-color: var(--accent-info);
        }

        .label-sm {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 600;
            color: var(--text-muted);
            display: block;
            margin-bottom: 0.5rem;
        }

        .error-message {
            font-family: var(--font-mono);
            font-size: 0.95rem;
            word-break: break-word;
        }

        /* SQL & bindings */
        .sql-card, .bindings-card {
            background: var(--bg-elevated);
            border-radius: 20px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid var(--border-subtle);
        }

        .sql-card pre, .bindings-card pre {
            background: var(--bg-code);
            padding: 0.8rem;
            border-radius: 14px;
            overflow-x: auto;
            font-family: var(--font-mono);
            font-size: 0.85rem;
            margin-top: 0.5rem;
            color: #b9c3e6;
        }

        /* code preview */
        .code-preview-wrapper {
            background: var(--bg-code);
            border-radius: 20px;
            overflow: auto;
            border: 1px solid var(--border-subtle);
        }

        .code-header {
            background: rgba(0, 0, 0, 0.3);
            padding: 0.7rem 1rem;
            font-size: 0.8rem;
            font-family: var(--font-mono);
            border-bottom: 1px solid var(--border-subtle);
            color: var(--text-secondary);
        }

        .code-preview {
            font-family: var(--font-mono);
            font-size: 0.85rem;
            line-height: 1.5;
        }

        .code-line {
            display: flex;
            padding: 0 1rem;
            transition: background 0.1s ease;
        }

        .code-line:hover {
            background: rgba(107, 197, 255, 0.05);
        }

        .line-number {
            width: 3.5rem;
            text-align: right;
            padding-right: 1rem;
            color: var(--text-muted);
            user-select: none;
            opacity: 0.7;
        }

        .line-content {
            flex: 1;
            white-space: pre;
            overflow-x: auto;
        }

        .highlight-line {
            background: rgba(255, 184, 107, 0.12);
            border-left: 3px solid var(--accent-warning);
        }

        .highlight-line .line-number {
            color: var(--accent-warning);
            font-weight: 600;
        }

        /* stack trace */
        .trace-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .trace-item {
            background: var(--bg-elevated);
            border-radius: 18px;
            border: 1px solid var(--border-subtle);
            overflow: hidden;
        }

        .trace-summary {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.9rem 1.2rem;
            cursor: pointer;
            font-family: var(--font-mono);
            font-size: 0.85rem;
            flex-wrap: wrap;
            background: rgba(0, 0, 0, 0.2);
        }

        .trace-summary:hover {
            background: rgba(107, 197, 255, 0.05);
        }

        .trace-icon {
            font-size: 1.1rem;
            color: var(--accent-info);
        }

        .trace-file {
            color: var(--accent-info);
            word-break: break-all;
            font-weight: 500;
        }

        .trace-function {
            color: var(--text-secondary);
            background: rgba(156, 163, 199, 0.15);
            padding: 0.2rem 0.6rem;
            border-radius: 24px;
            font-size: 0.75rem;
        }

        .trace-details {
            display: none;
            padding: 1rem 1.2rem;
            border-top: 1px solid var(--border-subtle);
            background: var(--bg-code);
            font-size: 0.8rem;
        }

        .trace-details pre {
            background: #0a0c10;
            padding: 0.8rem;
            border-radius: 14px;
            overflow-x: auto;
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: #cbd5f0;
        }

        /* grids */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .info-card {
            background: var(--bg-elevated);
            border-radius: 20px;
            padding: 1rem;
            border: 1px solid var(--border-subtle);
        }

        .info-card h4 {
            font-size: 0.9rem;
            margin-bottom: 0.75rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-card pre {
            background: var(--bg-code);
            padding: 0.8rem;
            border-radius: 14px;
            overflow-x: auto;
            font-size: 0.75rem;
            font-family: var(--font-mono);
            max-height: 200px;
            color: #b9c3e6;
        }

        .simple-table {
            width: 100%;
            font-size: 0.85rem;
        }

        .simple-table td {
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-subtle);
            vertical-align: top;
        }

        .simple-table td:first-child {
            font-weight: 600;
            width: 140px;
            color: var(--text-secondary);
        }

        .tip-badge {
            background: #2a2f3e;
            border-radius: 14px;
            padding: 0.9rem 1.2rem;
            font-family: var(--font-mono);
            font-size: 0.8rem;
            border-left: 3px solid var(--accent-warning);
        }

        .footer-note {
            text-align: center;
            padding: 1.2rem;
            background: rgba(0, 0, 0, 0.3);
            color: var(--text-muted);
            font-size: 0.75rem;
            border-top: 1px solid var(--border-subtle);
        }

        @media (max-width: 680px) {
            .section {
                padding: 1.2rem;
            }
            .card-header {
                padding: 1.2rem;
            }
            .trace-summary {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="error-container">
    <div class="error-card">
        <div class="card-header">
            <div>
                <span class="error-badge">Database Error</span>
                <div class="error-title">SQL query failed</div>
            </div>
            <div class="error-badge" style="background:#1e293b;">Debug mode active</div>
        </div>

        <!-- ERROR MESSAGE & LOCATION -->
        <div class="section">
            <div class="message-card">
                <span class="label-sm">Error message</span>
                <div class="error-message"><?= htmlspecialchars($exception_message ?? $message ?? 'Unknown database error', ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="location-card">
                <span class="label-sm">Thrown in</span>
                <div class="error-message"><?= htmlspecialchars($filePath); ?> : <?= $lineNum; ?></div>
            </div>
        </div>

        <!-- SQL QUERY & BINDINGS (database specific) -->
        <?php if (!empty($query)): ?>
        <div class="section">
            <div class="section-header">
                <h3>SQL Query</h3>
            </div>
            <div class="sql-card">
                <pre><?= htmlspecialchars($query); ?></pre>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($bindings_data)): ?>
        <div class="section">
            <div class="section-header">
                <h3>Query Bindings</h3>
            </div>
            <div class="bindings-card">
                <pre><?= htmlspecialchars($bindings_data); ?></pre>
            </div>
        </div>
        <?php endif; ?>

        <!-- CODE PREVIEW -->
        <?php if (!empty($codeExcerpt)): ?>
        <div class="section">
            <div class="section-header">
                <h3>Source context</h3>
            </div>
            <div class="code-preview-wrapper">
                <div class="code-header">
                    <?= htmlspecialchars($filePath); ?>
                </div>
                <div class="code-preview">
                    <?php foreach ($codeExcerpt as $lineNumLocal => $codeLine): ?>
                        <?php $currentLineNumber = $lineNumLocal + 1; ?>
                        <div class="code-line <?= ($currentLineNumber === $lineNum) ? 'highlight-line' : ''; ?>">
                            <span class="line-number"><?= str_pad($currentLineNumber, 3, ' ', STR_PAD_LEFT); ?></span>
                            <span class="line-content"><?= htmlspecialchars(rtrim($codeLine)); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- STACK TRACE (interactive) -->
        <div class="section">
            <div class="section-header">
                <h3>Stack trace</h3>
            </div>
            <?php if (!empty($traceFrames)): ?>
            <div class="trace-list">
                <?php foreach ($traceFrames as $frame): ?>
                    <?php if (isset($frame['file'])): ?>
                    <div class="trace-item">
                        <div class="trace-summary" onclick="toggleTraceDetails(this)">
                            <span class="trace-file"><?= htmlspecialchars($frame['file']); ?>:<?= $frame['line'] ?? '?'; ?></span>
                            <span class="trace-function">
                                <?= htmlspecialchars(($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function']); ?>()
                            </span>
                            <span style="margin-left: auto; font-size: 12px;">▼</span>
                        </div>
                        <div class="trace-details">
                            <div><strong>⚙️ Function:</strong> <?= htmlspecialchars(($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function']); ?>()</div>
                            <?php if (!empty($frame['args'])): ?>
                                <div style="margin-top: 10px;"><strong>Arguments:</strong></div>
                                <pre><?= htmlspecialchars(print_r($frame['args'], true)); ?></pre>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php elseif (!empty($plainTrace)): ?>
                <div class="code" style="background: var(--bg-elevated);"><?= nl2br(htmlspecialchars($plainTrace)); ?></div>
            <?php else: ?>
                <div class="tip-badge">No stack trace available.</div>
            <?php endif; ?>
        </div>

        <!-- REQUEST & SERVER INFO -->
        <div class="section">
            <div class="section-header">
                <h3>Request & Server</h3>
            </div>
            <div class="info-grid">
                <div class="info-card">
                    <h4>Request</h4>
                    <table class="simple-table">
                        <tr><td>Method</td><td><?= htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? 'CLI'); ?></td></tr>
                        <tr><td>URI</td><td><?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/'); ?></td></tr>
                        <tr><td>Query String</td><td><?= htmlspecialchars($_SERVER['QUERY_STRING'] ?? 'none'); ?></td></tr>
                    </table>
                </div>
                <div class="info-card">
                    <h4>Server environment</h4>
                    <table class="simple-table">
                        <tr><td>PHP</td><td><?= phpversion(); ?></td></tr>
                        <tr><td>LavaLust</td><td><?= htmlspecialchars(config_item('VERSION')); ?></td></tr>
                        <tr><td>Software</td><td><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Built-in'); ?></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- ENVIRONMENT SNAPSHOT -->
        <div class="section">
            <div class="section-header">
                <h3>Environment snapshot</h3>
            </div>
            <div class="info-grid">
                <div class="info-card">
                    <h4>$_GET</h4>
                    <pre><?= htmlspecialchars(print_r($_GET, true)); ?></pre>
                </div>
                <div class="info-card">
                    <h4>$_POST</h4>
                    <pre><?= htmlspecialchars(print_r($_POST, true)); ?></pre>
                </div>
                <div class="info-card">
                    <h4>$_SESSION</h4>
                    <pre><?= isset($_SESSION) ? htmlspecialchars(print_r($_SESSION, true)) : 'No active session'; ?></pre>
                </div>
                <div class="info-card">
                    <h4>$_COOKIE</h4>
                    <pre><?= htmlspecialchars(print_r($_COOKIE, true)); ?></pre>
                </div>
            </div>
        </div>

        <!-- TIPS -->
        <div class="section">
            <div class="section-header">
                <h3>Pro tip</h3>
            </div>
            <div class="tip-badge">
                This detailed error page is shown because debug mode is enabled.<br>
                In production, set <code>$config['ENVIRONMENT'] = 'production'</code> to hide sensitive traces.
            </div>
        </div>

        <div class="footer-note">
            LavaLust Framework — PHP <?= phpversion(); ?> • <?= date('Y'); ?>
        </div>
    </div>
</div>

<script nonce="<?= defined('CSP_NONCE') ? CSP_NONCE : '' ?>">
    function toggleTraceDetails(element) {
        const detailsDiv = element.nextElementSibling;
        if (detailsDiv && detailsDiv.classList.contains('trace-details')) {
            const isVisible = detailsDiv.style.display === 'block';
            detailsDiv.style.display = isVisible ? 'none' : 'block';
            const arrowSpan = element.querySelector('span:last-child');
            if (arrowSpan) {
                arrowSpan.textContent = isVisible ? '▼' : '▲';
            }
        }
    }
    // Initially hide all trace details
    document.querySelectorAll('.trace-details').forEach(detail => detail.style.display = 'none');
</script>
</body>
</html>