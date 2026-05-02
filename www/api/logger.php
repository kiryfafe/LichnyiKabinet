<?php
/**
 * Централизованная система логирования
 * Поддерживает разные уровни логирования и запись в файл
 * 
 * Использование:
 *   Logger::info('User logged in', array('user_id' => 123));
 *   Logger::error('Database connection failed', array('error' => $e->getMessage()));
 *   Logger::warning('Rate limit exceeded', array('ip' => $_SERVER['REMOTE_ADDR']));
 *   Logger::security('Failed login attempt', array('identifier' => $email, 'ip' => $ip));
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
    private static $minLevel = null;
    private static $securityLogFile = null;
    
    private static $levelPriority = null;
    
    /**
     * Инициализация системы логирования
     */
    private static function init() {
        if (self::$initialized) {
            return;
        }
        
        // Инициализируем приоритеты уровней
        self::$levelPriority = array(
            self::LEVEL_DEBUG => 0,
            self::LEVEL_INFO => 1,
            self::LEVEL_WARNING => 2,
            self::LEVEL_ERROR => 3,
            self::LEVEL_CRITICAL => 4,
            self::LEVEL_SECURITY => 5
        );
        
        // Устанавливаем минимальный уровень по умолчанию
        self::$minLevel = self::LEVEL_INFO;
        
        // Определяем директорию для логов
        $baseDir = dirname(__DIR__);
        self::$logDir = $baseDir . '/logs';
        
        // Создаем директорию если не существует
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0750, true);
            // Добавляем .htaccess для защиты директории логов
            $htaccessContent = "Deny from all\n";
            @file_put_contents(self::$logDir . '/.htaccess', $htaccessContent);
        }
        
        // Формируем имя файла лога с датой
        $date = date('Y-m-d');
        self::$logFile = self::$logDir . "/app_{$date}.log";
        
        // Отдельный файл для security событий
        self::$securityLogFile = self::$logDir . "/security_{$date}.log";
        
        self::$initialized = true;
    }
    
    /**
     * Установка минимального уровня логирования
     */
    public static function setMinLevel($level) {
        self::$minLevel = $level;
    }
    
    /**
     * Проверка достаточности уровня важности
     */
    private static function shouldLog($level) {
        return self::$levelPriority[$level] >= self::$levelPriority[self::$minLevel];
    }
    
    /**
     * Основная функция логирования
     */
    private static function log($level, $message, $context = array()) {
        self::init();
        
        if (!self::shouldLog($level)) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $microtime = microtime(true);
        $micro = sprintf('%03d', ($microtime - floor($microtime)) * 1000);
        
        // Собираем контекст
        $contextStr = '';
        if (!empty($context)) {
            // Маскируем чувствительные данные
            $sensitiveKeys = array('password', 'pass', 'secret', 'token', 'key', 'auth');
            $safeContext = array();
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
            // JSON_THROW_ON_ERROR доступен только с PHP 7.3, убираем для совместимости
            $contextStr = ' ' . json_encode($safeContext, JSON_UNESCAPED_UNICODE);
        }
        
        // Получаем информацию о вызове
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = isset($backtrace[2]) ? 
            basename(isset($backtrace[2]['file']) ? $backtrace[2]['file'] : 'unknown') . ':' . (isset($backtrace[2]['line']) ? $backtrace[2]['line'] : '?') : 
            'unknown';
        
        // Форматируем сообщение
        $logEntry = "[{$timestamp}.{$micro}] [{$level}] [{$caller}] {$message}{$contextStr}\n";
        
        // Определяем файл для записи
        $targetFile = ($level === self::LEVEL_SECURITY) ? self::$securityLogFile : self::$logFile;
        
        // Записываем в файл
        @file_put_contents($targetFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        // Для критических ошибок также пишем в error_log PHP
        if (in_array($level, array(self::LEVEL_CRITICAL, self::LEVEL_ERROR, self::LEVEL_SECURITY))) {
            error_log($logEntry);
        }
    }
    
    /**
     * Логирование информационного сообщения
     */
    public static function info($message, $context = array()) {
        self::log(self::LEVEL_INFO, $message, $context);
    }
    
    /**
     * Логирование предупреждения
     */
    public static function warning($message, $context = array()) {
        self::log(self::LEVEL_WARNING, $message, $context);
    }
    
    /**
     * Логирование ошибки
     */
    public static function error($message, $context = array()) {
        self::log(self::LEVEL_ERROR, $message, $context);
    }
    
    /**
     * Логирование критической ошибки
     */
    public static function critical($message, $context = array()) {
        self::log(self::LEVEL_CRITICAL, $message, $context);
    }
    
    /**
     * Логирование события безопасности
     */
    public static function security($message, $context = array()) {
        self::log(self::LEVEL_SECURITY, $message, $context);
    }
    
    /**
     * Логирование отладочной информации
     */
    public static function debug($message, $context = array()) {
        self::log(self::LEVEL_DEBUG, $message, $context);
    }
    
    /**
     * Логирование аудита (для compliance)
     */
    public static function audit($action, $userId, $details = array()) {
        self::log(self::LEVEL_SECURITY, "AUDIT: {$action}", array_merge(
            array(
                'user_id' => $userId,
                'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown',
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown'
            ),
            $details
        ));
    }
}

// Helper functions for backward compatibility with existing code
if (!function_exists('logError')) {
    /**
     * @deprecated Используйте Logger::error() вместо этой функции
     */
    function logError($message, $level = 'ERROR') {
        $context = array();
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

// Автоматический перехват фатальных ошибок
register_shutdown_function(function() {
    $error = error_get_last();
    // Для PHP 5.6 используем числовые значения вместо констант в in_array
    $fatalTypes = array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE);
    if ($error && in_array($error['type'], $fatalTypes)) {
        Logger::critical("Fatal error: {$error['message']}", array(
            'file' => $error['file'],
            'line' => $error['line'],
            'type' => $error['type']
        ));
    }
});

// Перехват исключений
set_exception_handler(function($exception) {
    Logger::critical("Uncaught exception: {$exception->getMessage()}", array(
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => substr($exception->getTraceAsString(), 0, 500)
    ));
    
    // Передаем обработку дальше стандартному обработчику
    throw $exception;
});
?>