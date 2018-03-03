<?php
/**
 * Для настройки переименовать файл в config.php
 */
namespace App;

class Config {
    public $driver;
    public $host;
    public $port;
    public $user;
    public $pass;
    public $base;
    public $prefix;
    public $charset;
    public $collation;

    public function __construct(){
        
        $this->driver       = 'mysqli';
        $this->host         = 'localhost';
        $this->port         = 3306;
        $this->user         = 'root';
        $this->pass         = '';
        $this->base         = 'joomla';
        $this->prefix       = 'ix_';
        $this->charset      = 'utf8';
        $this->collation    = 'utf8_unicode_ci';

        return $this;
    }
}
?>