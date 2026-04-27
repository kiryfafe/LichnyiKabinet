<?php
/**
 * Простая система логирования для Pyrus-only версии
 * Без зависимости от базы данных
 */

class Logger {
    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_CRITICAL = 'CRITICAL';
    const LEVEL_SECURITY = 'SECURITY';
    
    private static $logFile = null;
    private static $logDir = null;
    private static $initialized = false;
    private static $minLevel = self::LEVEL_INFO;
    private static $securityLogFile = null;
    
    private static $levelPriority = [
        self::LEVEL_DEBUG => 0,
        self::LEVEL_INFO => 1,
        self::LEVEL_WARNING => 2,
        self::LEVEL_ERROR => 3,
        self::LEVEL_CRITICAL => 4,
        self::LEVEL_SECURITY => 5
    ];
    
    private static function init() {
        if (self::$initialized) {
            return;
        }
        
        $baseDir = dirname(__DIR__);
        self::$logDir = $baseDir . '/logs';
        
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0750, true);
            $htaccessContent = "Deny from all\n";
            @file_put_contents(self::$logDir . '/.htaccess', $htaccessContent);
        }
        
        $date = date('Y-m-d');
        self::$logFile = self::$logDir . "/app_{$date}.log";
        self::$securityLogFile = self::$logDir . "/security_{$date}.log";
        
        self::$initialized = true;
    }
    
    public static function setMinLevel($level) {
        self::$minLevel = $level;
    }
    
    private static function shouldLog($level) {
        return self::$levelPriority[$level] >= self::$levelPriority[self::$minLevel];
    }
    
    private static function log($level, $message, $context = []) {
        self::init();
        
        if (!self::shouldLog($level)) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $microtime = microtime(true);
        $micro = sprintf('%03d', ($microtime - floor($microtime)) * 1000);
        
        $contextStr = '';
        if (!empty($context)) {
            $sensitiveKeys = ['password', 'pass', 'secret', 'token', 'key', 'auth'];
            $safeContext = [];
            foreach ($context as $key => $value) {
                $isSensitive = false;
                foreach ($sensitiveKeys as $sensitive) {
                    if (stripos($key, $sensitive) !== false) {
                        $isSensitive = true;
                        break;
                    }
                }
                $safeContext[$key] = $isSensitive ? '***REDACTED***' : $value;
            }
            $contextStr = ' ' . json_encode($safeContext, JSON_UNESCAPED_UNICODE);
        }
        
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = isset($backtrace[2]) ? 
            basename(isset($backtrace[2]['file']) ? $backtrace[2]['file'] : 'unknown') . ':' . 
            (isset($backtrace[2]['line']) ? $backtrace[2]['line'] : '?') : 
            'unknown';
        
        $logEntry = "[{$timestamp}.{$micro}] [{$level}] [{$caller}] {$message}{$contextStr}\n";
        
        $targetFile = ($level === self::LEVEL_SECURITY) ? self::$securityLogFile : self::$logFile;
        
        @file_put_contents($targetFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        if (in_array($level, [self::LEVEL_CRITICAL, self::LEVEL_ERROR, self::LEVEL_SECURITY])) {
            error_log($logEntry);
        }
    }
    
    public static function info($message, $context = []) {
        self::log(self::LEVEL_INFO, $message, $context);
    }
    
    public static function warning($message, $context = []) {
        self::log(self::LEVEL_WARNING, $message, $context);
    }
    
    public static function error($message, $context = []) {
        self::log(self::LEVEL_ERROR, $message, $context);
    }
    
    public static function critical($message, $context = []) {
        self::log(self::LEVEL_CRITICAL, $message, $context);
    }
    
    public static function security($message, $context = []) {
        self::log(self::LEVEL_SECURITY, $message, $context);
    }
    
    public static function debug($message, $context = []) {
        self::log(self::LEVEL_DEBUG, $message, $context);
    }
}

if (!function_exists('logError')) {
    function logError($message, $level = 'ERROR') {
        $context = [];
        if ($level === 'INFO') {
            Logger::info($message, $context);
        } elseif ($level === 'WARNING') {
            Logger::warning($message, $context);
        } elseif ($level === 'SECURITY') {
            Logger::security($message, $context);
        } else {
            Logger::error($message, $context);
        }
    }
}
?>
