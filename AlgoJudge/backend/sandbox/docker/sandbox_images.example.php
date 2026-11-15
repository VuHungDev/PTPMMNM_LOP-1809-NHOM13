<?php
/**
 * Copy to backend/config/sandbox_images.php after building/reviewing images.
 * This file contains NO secrets. Only a trusted administrator may modify it.
 * Each value must be a full immutable sha256 image ID or repo@sha256 digest.
 * Unconfigured entries intentionally fail closed as SYS_ERR.
 */
return [
    // Optional absolute CLI executable for Apache services with a short PATH.
    'docker_binary' => '',
    'c' => '',
    'cpp' => '',
    'java' => '',
    'python' => '',
    'php' => '',
    'js' => '',
];
