<?php
/**
 * CS2 Case Opening Simulator — Main Entry Point
 * Serves the frontend SPA (single-page application)
 */
$indexFile = __DIR__ . '/static/index.html';
if (file_exists($indexFile)) {
    readfile($indexFile);
} else {
    http_response_code(503);
    echo '<h1>CS2 Case Opening Simulator</h1><p>Frontend not found. Please ensure static/index.html exists.</p>';
}

