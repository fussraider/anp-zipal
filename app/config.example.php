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
    public $agency_name;
    public $agency_id;
    public $site_url;

    public function __construct(){
        
        //database
        $this->driver       = 'mysqli';
        $this->host         = 'localhost';
        $this->port         = 3306;
        $this->user         = 'root';
        $this->pass         = '';
        $this->base         = 'joomla';
        $this->prefix       = 'ix_';
        $this->charset      = 'utf8';
        $this->collation    = 'utf8_unicode_ci';

        //other
        $this->agency_name  = 'My Agency';
        $this->agency_id    = '1111';
        $this->site_url     = 'https://my_agency.org';

        return $this;
    }
}
?>