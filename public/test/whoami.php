<?php
header("Content-Type: text/plain; charset=utf-8");
echo "SCRIPT_FILENAME: " . ($_SERVER['SCRIPT_FILENAME'] ?? '') . "\n";
echo "DOCUMENT_ROOT:   " . ($_SERVER['DOCUMENT_ROOT'] ?? '') . "\n";
echo "PWD:             " . getcwd() . "\n";
echo "__FILE__:         " . __FILE__ . "\n";
