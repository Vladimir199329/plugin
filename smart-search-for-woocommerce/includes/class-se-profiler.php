<?php

defined('ABSPATH') || exit;

class SeProfiler
{
    private static $blocks = array();

    /**
     * Clear all blocks information
     */
    public static function clearBlocks()
    {
        self::$blocks = array();
    }

    /**
     * Start new profile block
     * 
     * @param string $block_name
     */
    public static function startBlock($block_name)
    {
        self::$blocks[$block_name] = array();
        self::$blocks[$block_name]['start']['time'] = microtime(true);
        self::$blocks[$block_name]['start']['memory_usage'] = memory_get_usage();
        self::$blocks[$block_name]['start']['memory_peak_usage'] = memory_get_peak_usage();
    }

    /**
     * End existed profile block
     * 
     * @param string $block_name
     */
    public static function endBlock($block_name)
    {
        if (!empty(self::$blocks[$block_name]['start'])) {
            self::$blocks[$block_name]['end']['time'] = microtime(true);
            self::$blocks[$block_name]['end']['memory_usage'] = memory_get_usage();
            self::$blocks[$block_name]['end']['memory_peak_usage'] = memory_get_peak_usage();
        }
    }

    /**
     * Returns block information if block is finished
     * 
     * @param string $block_name
     * @return array Block info
     */
    public static function getBlockInfo($block_name)
    {
        $info = array();

        if (!empty(self::$blocks[$block_name]) && !empty(self::$blocks[$block_name]['end'])) {
            $info['time'] = self::$blocks[$block_name]['end']['time'] - self::$blocks[$block_name]['start']['time'];
            $info['memory_increased'] = self::niceFileSize(self::$blocks[$block_name]['end']['memory_usage'] - self::$blocks[$block_name]['start']['memory_usage']);
            $info['memory_peak_increased'] = self::niceFileSize(self::$blocks[$block_name]['end']['memory_peak_usage'] - self::$blocks[$block_name]['start']['memory_peak_usage']);
        }

        return $info;
    }

    /**
     * Returns all blocks information
     * 
     * @return array Block infos
     */
    public static function getBlocksInfo()
    {
        $info = array();

        foreach (self::$blocks as $name => $block) {
            $info[$name] = self::getBlockInfo($name);
        }

        return $info;
    }

    /**
     * Convert bytes value to human format
     * 
     * @param int $bytes Bytes count
     * @param boolean $binaryPrefix
     * @return string
     */
    private static function niceFileSize($bytes, $binaryPrefix = true) {
        $bytes = (int)$bytes;

        if ($binaryPrefix) {
            $unit = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB');
            if ($bytes == 0) return '0 ' . $unit[0];
            return @round($bytes / pow(1024, ($i = floor(log($bytes, 1024)))), 2) . ' ' . (isset($unit[$i]) ? $unit[$i] : 'B');
        } else {
            $unit = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
            if ($bytes == 0) return '0 ' . $unit[0];
            return @round($bytes / pow(1000, ($i = floor(log($bytes, 1000)))), 2) . ' ' . (isset($unit[$i]) ? $unit[$i] : 'B');
        }
    }
}
