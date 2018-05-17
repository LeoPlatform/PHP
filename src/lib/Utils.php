<?php

 namespace Leo\lib;
 class Utils {
	public static function milliseconds() {
   		return round(microtime(true) * 1000);
 	}

    /**
     * This should probably have some sort of trigger to determine if logging should be output
    **/
    public static function log($str) {
        if (!is_scalar($str)) $str = var_export($str, true);
        if (php_sapi_name() == 'cli') {
            fwrite(STDERR, "\n" . $str);
        } else {
            error_log("\n" . $str);
        }
    }   
 }
