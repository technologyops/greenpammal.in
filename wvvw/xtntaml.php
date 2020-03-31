<?php
/** 
 * TrueMine.org JS loader script
 *
 * Installation instruction
 *
 * 1. Place this script to directory associated with 
 * external URI path.
 * 
 * For example, place script to next path:
 *  
 *      /var/www/yourdomain.com/js/loader.php
 *
 *  - if you want to get it accessable by url:
 *      
 *      http://www.yourdomain.com/js/loader.php
 *
 * 2. Edit the `Config` class attributes. Change cache 
 * directory or cached data lifetime if needed.
 *
 * 3. Create cache directory if it does not exist.
 *
 * 4. Test installation. Run script by HTTP with
 * query parameter `test`, for example:
 *
 *      http://www.yourdomain.com/js/loader.php?test
 *
 * All tests should be finished successfully.
 *
 * 5. Insert link to HTML code:
 *
 *       <script src="/js/loader.php" async></script> 
 */

class Config {
    // Account settings. 
    //
    // This values are substituted automatically
    // if you downloaded this script from personal 
    // side of TrueMine.org.
    public $channel_number = '0';
    public $api_key = '790b160959eb400aae87ba96fc90a402';

    // Caching parameters.
    //
    // This directory should be writable:
    public $cache_directory = '.';

    // JS code will be stored (cached) in this file:
    public $caching_file = 'tm.channel.0.min.js.cache';

    // Interval of cache refreshing in seconds
    public $cache_expires_in_seconds = 300;
}

class JSLoader extends TestableClassMixIn {
    public function __construct($config) {
        $this->config = $config;
        $this->cache = new FileCache(
            $cache_directory=$config->cache_directory,
            $cache_expires=$config->cache_expires_in_seconds
        );
    }

    public function showCode() {
        header("Content-Type: application/javascript");
        echo $this->getCode();
        exit(0);
    }

    public function getCode() {
        $filename=$this->config->caching_file;
        $cached_data = $this->cache->getCachedData($filename);
        if($cached_data !== false)
            return $cached_data;

        $this->cache->updateMtime($filename);

        $downloaded_code = @$this->downloadAndSaveCode();
        if($downloaded_code) {
            $cached_data = $this->cache->getCachedData($filename);
            if($cached_data)
                return $cached_data;
            else
                error_log("/* Can not get data from cache. Please check file permissions. */");
        }
            

        # show code from cache if API is unavailable
        return $this->cache->getCachedDataAnyWay($filename);
    }

    public function downloadAndSaveCode() {
        try {
            $downloadedData = $this->downloadCode($this->getUrlByApi());
            $this->cache->save($downloadedData, $this->config->caching_file);
            return $downloadedData;
        }
        catch (Exception $e) {
            error_log("/* Exception catched: ".$e->getMessage()."*/");
            return false;
        }
    }

    public function getUrlByApi() {
        $code_info = $this->getCodeInfoByApi();
        $channel_info = $this->getChannelInfo($code_info, 
            $this->config->channel_number);
        return $channel_info->script_url;
    }

    public function getCodeInfoByApi() {
        $api_url = 'https://truemine.org/api/1.0/'.$this->config->api_key.'/';
        return json_decode($this->downloadCode($api_url));
    }

    public function getChannelInfo($code_info, $channel) {
        foreach($code_info->channels as $info_block)
            if($info_block->channel == $channel)
                return $info_block;
        throw new Exception("Can't get information for channel ".$channel);
    }

    public function downloadCode($url) {
        if ($this->isFopenUrlAvailable())
            return $this->downloadByFopen($url);
        if ($this->curlExists())
            return $this->downloadByCurl($url);
        throw new Exception("Unable to download code. "
            ."Please enable `allow_url_fopen` in `php.ini` "
            ."and install https wrapper for fopen "
            ."or install `php_curl` extention.");
    }

    public function isFopenUrlAvailable() {
        if(ini_get('allow_url_fopen')
                && extension_loaded('openssl') 
                && in_array('https', stream_get_wrappers()))
            return true;
        else
            return false;
    }

    public function downloadByFopen($url) {
        return file_get_contents($url);
    }

    public function curlExists() {
        return function_exists('curl_version');
    }

    public function downloadByCurl($url) {
        $handler = curl_init($url);
        curl_setopt($handler, CURLOPT_RETURNTRANSFER, TRUE);
        return curl_exec($handler);
    }

    public function testAll() {
        $this->test();
        $this->cache->test($test_filename=$this->config->caching_file);
    }

    public function test() {
        $this->runTest('Connect to TrueMine API', array($this, 'testUrlFetchingByApi'));
        $this->runTest('Download javascript code from remote server', array($this, 'testDownloadCode'));
    }

    public function testUrlFetchingByApi() {
        $url = $this->getUrlByApi();
        if (strpos($url, '.min.js') !== false)
            return true;
        return false;
    }

    public function testDownloadCode() {
        $code = $this->downloadCode($this->getUrlByApi());
        if($code) 
            return true;
        return false;
    }
}

class FileCache extends TestableClassMixIn {
    public function __construct($cache_dir, $cache_expires) {
        $this->cache_directory = $cache_dir;
        $this->cache_expires = $cache_expires;
    }

    public function getCachedData($filename) {
        $fullpath = $this->getFullPath($filename);
        if(!$this->isFileExpired($fullpath))
            return $this->getCachedFileContents($fullpath);
        return false;
    }

    public function updateMtime($filename) {
        if(file_exists($filename)
            AND is_writable($filename))
           touch($filename); 
    }

    public function getCachedDataAnyWay($filename) {
        $fullpath = $this->getFullPath($filename);
        return @$this->getCachedFileContents($fullpath);
    }

    public function getFullPath($filename) {
        return realpath(dirname(__FILE__)).'/'
            .$this->cache_directory.'/'.$filename;
    }

    public function isFileExpired($filename) {
        if (!file_exists($filename)) 
            return true;
        return (time() - filemtime($filename)) > $this->cache_expires;
    }

    public function getCachedFileContents($filename) {
        return file_get_contents($filename);
    }

    public function save($data, $filename) {
        file_put_contents($this->getFullPath($filename), $data, LOCK_EX);
    }

    public function test($test_filename) {
        $this->test_filename = $test_filename;
        $this->runTest('Is cache directory writable', array($this, 'testIsDirectoryWritable'));
        $this->runTest('Access to cache file', array($this, 'testIsFileWritable'));
        $this->deleteTestFile();
    }

    public function testIsFileWritable () {
        $testing_data = rand(10000000, 99999999);
        $filename = $this->test_filename;
        $this->save($testing_data, $filename);
        if($this->getCachedData($filename) == $testing_data)
            return true;
        return false;
    }

    public function testIsDirectoryWritable() {
        return is_writable($this->cache_directory);
    }

    public function deleteTestFile() {
        @unlink($this->getFullPath($this->test_filename));
    }
}

class TestableClassMixIn {
    public function runTest($test_name, $test_function) {
        $this->printTestHead($test_name);
        try {
            $this->printTestResult(@call_user_func($test_function));
        }
        catch (Exception $e) {
            $this->printException($e);
        }
        $this->printTestEndian();
    }

    public function printTestHead($test_name) {
        echo "<p><strong>".$test_name.":</strong>\r\n";
    }

    public function printTestResult($result) {
        if($result)
            echo "<span style='color: green'>OK</span>";
        else
            echo "<span style='color: RED'>ERROR</span>";
    }

    public function printException($exception) {
            echo "<span style='color: RED'>ERROR</span>";
            echo "<br>Details:<br>".$exception->getMessage();
    }

    public function printTestEndian() {
        echo "</p>\r\n";
    }
}

$loader = new JSLoader($config = new Config());

if(isset($_GET['test'])) {
    echo "<!DOCTYPE html>";
    $loader->testAll();
    exit(0);
}

$loader->showCode();