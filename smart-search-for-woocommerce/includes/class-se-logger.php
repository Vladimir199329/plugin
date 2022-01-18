<?php

defined('ABSPATH') || exit;

class SeLogger
{
    const DEBUG_VAR_NAME     = 'debug_module_searchanise';
    const DEBUG_LOG_VAR_NAME = 'log_module_searchanise';
    const DEBUG_KEY          = 'Y';

    const TYPE_DEBUG = 'debug';
    const TYPE_ERROR = 'error';

    private $log_files = array(
        self::TYPE_ERROR => 'error.log',
        self::TYPE_DEBUG => 'debug.log',
    );
    private $log_dir      = '';
    private $log_errors   = false;
    private $log_debug    = false;
    private $output_debug = false;

    private static $self = null;

    public function __construct($options = array())
    {
        foreach ($options as $option => $value) {
            if (property_exists($this, $option)) {
                $this->{$option} = $value;
            }
        }

        if (!empty($this->log_dir) && !file_exists($this->log_dir)) {
            mkdir($this->log_dir, 0777, true);
        }
    }

    public static function getInstance($options = array())
    {
        if (self::$self === null) {
            self::$self = new self($options);
        }

        return self::$self;
    }

    /**
     * Log error data
     * 
     * @param mixed $data
     */
    public function error($data)
    {
        $this->log($data, self::TYPE_ERROR);
        $this->output($data);
    }

    /**
     * Log debug data
     * 
     * @param mixed $data
     */
    public function debug($data)
    {
        $this->log($data, self::TYPE_DEBUG);
        $this->output($data);
    }

    /**
     * Clear log files
     */
    public function clearLogs()
    {
        if (!empty($this->log_dir) && file_exists($this->log_dir)) {
            foreach ($this->log_files as $file) {
                $file = rtrim($this->log_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file;

                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Log data
     * 
     * @param mixed $data
     * @param string $type
     */
    private function log($data, $type)
    {
        if (
            !$this->isLogErrorsEnabled() && $type == self::TYPE_ERROR
            || !$this->isLogDebugEnabled() && $type == self::TYPE_DEBUG
        ) {
            return;
        }

        $date = date('c');
        $message = "Searchanise: # {$type}: " . print_r($data, true);
        $file = $this->log_files[$type];

        if (!empty($this->log_dir) && file_exists($this->log_dir)) {
            $full_path = rtrim($this->log_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file;

            $f = fopen($full_path, 'a+');
            if ($f === false) {
                return;
            }

            @fwrite($f, "\n" . $date . "\n");
            @fwrite($f, $message);
            @fwrite($f, "\n");
            @fclose($f);
        }
    }

    /**
     * Output debug data
     * 
     * @param mixed $data
     */
    private function output($data)
    {
        if (
            !$this->isDebugEnabled()
            || (defined('DOING_CRON') && DOING_CRON)
        ) {
            return;
        }

        if (is_array($data)) {
            foreach ($data as $k => &$v) {
                if (!is_array($v) && preg_match('~[^\x20-\x7E\t\r\n]~', $v) > 0) {
                    $v = '=== BINARY DATA ===';
                }
            }
        }

        $this->printR($data);
    }

    /**
     * Checks if log errors is enabled
     * 
     * @return boolean
     */
    public function isLogErrorsEnabled()
    {
        return $this->log_errors || (isset($_REQUEST[self::DEBUG_LOG_VAR_NAME]) && $_REQUEST[self::DEBUG_LOG_VAR_NAME] == self::DEBUG_KEY);
    }

    /**
     * Checks if log debug is enabled
     * 
     * @return boolean
     */
    public function isLogDebugEnabled()
    {
        return $this->log_debug || (isset($_REQUEST[self::DEBUG_LOG_VAR_NAME]) && $_REQUEST[self::DEBUG_LOG_VAR_NAME] == self::DEBUG_KEY);
    }

    /**
     * Checks if debug output is enabled
     * 
     * @return boolean
     */
    private function isDebugEnabled()
    {
        return $this->output_debug || (isset($_REQUEST[self::DEBUG_VAR_NAME]) && $_REQUEST[self::DEBUG_VAR_NAME] == self::DEBUG_KEY);   
    }

    public function printR()
    {
        call_user_func_array(array(ApiSe::getInstance(), 'printR'), func_get_args());
    }
}

add_action('init', function() {
    $GLOBALS['SearchaniseLogger'] = SeLogger::getInstance(array(
        'log_dir'      => SE_LOG_DIR,
        'log_debug'    => SE_DEBUG_LOG,
        'log_errors'   => SE_ERROR_LOG,
        'output_debug' => SE_DEBUG,
    ));
});
