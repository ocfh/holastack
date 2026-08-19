<?php





namespace holastack;



spl_autoload_register(function ($class) {
    $prefix = 'holastack\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $rel = substr($class, strlen($prefix));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

require_once __DIR__ . '/config/config.php';

if (!is_dir(ELW_LOG_DIR)) {
    @mkdir(ELW_LOG_DIR, 0777, true);
}



require_once __DIR__ . '/src/langHelpers.php';
