<?php
namespace Controllers;

use Models\AnpProperties;
use \FluidXml\FluidXml;
use \FluidXml\FluidNamespace;

class GenerateXmlFeedController{

    public function __construct(){
        $query = AnpProperties::all();
    
        $xml = new FluidXml(null, ['encoding'   => 'UTF-8' ]);

        $xml->addChild('MassUploadRequest', true, ['xmlns' => 'http://assis.ru/ws/api', 'timestamp' => time()]);

        foreach($query as $object){
            $xml->addChild([
                'object' => [
                    '@externalId' => $object->id,
                    '@publish'  => true,
                    'request' => [
                        '@xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
                        '@xsi:type' => 'FlatSellRequestType',
                        'common' => [
                            '@name' => $object->title,
                            '@description' => $object->description,
                            '@ownership' => 'AGENT', 
                            '@price' => $object->price,
                            '@currency' => 'RUR',
                            '@districtName' => $object->district
                        ]
                    ],
                ]
            ]);
        }


        
        header ('Content-type: text/xml');
        echo $xml;
    }
}