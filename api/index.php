<?php

// api/index.php

// Vercel serverless function entry point
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/../public/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

// Change to project root
chdir(__DIR__.'/..');

// Load public/index.php
require __DIR__.'/../public/index.php';
