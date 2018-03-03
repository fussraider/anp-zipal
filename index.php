<?php
namespace App;

if(!file_exists(__DIR__.'/app/config.php')){
    die('Не найден файл конфигурации. Для первоначальной настройки необходимо скопировать/переменовать файл '.__DIR__.'/app/config.example.php с именем config.php и заполнить необходимыми данными.');
}

require __DIR__.'/vendor/autoload.php';

$app = new App;

use Controllers\GenerateXmlFeedController;
$test = new GenerateXmlFeedController;
?>