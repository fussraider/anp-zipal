<?php
namespace App;

use App\Config;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
use Whoops\Run;
use Whoops\Handler\PrettyPageHandler;

class App {
    public $db;
    public $conf;


    public function __construct(){
        
        //init whoops
        $whoops = new Run;
        $whoops->pushHandler(new PrettyPageHandler);
        $whoops->register();
        
        //load config
        $this->conf = new Config;
        //init db
        $this->db = new Capsule;

        $this->db->addConnection([
            'driver'    => $this->conf->driver,
            'host'      => $this->conf->host,
            'database'  => $this->conf->base,
            'username'  => $this->conf->user,
            'password'  => $this->conf->pass,
            'charset'   => $this->conf->charset,
            'collation' => $this->conf->collation,
            'prefix'    => $this->conf->prefix,
        ]);

                
        $this->db->setEventDispatcher(new Dispatcher(new Container));
        $this->db->setAsGlobal();
        $this->db->bootEloquent();

    }
}

?>