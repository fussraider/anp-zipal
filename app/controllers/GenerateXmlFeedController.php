<?php
namespace Controllers;

use Models\AnpProperties;
use Models\Users;
use Models\UserProfiles;
use \FluidXml\FluidXml;
use \FluidXml\FluidNamespace;

class GenerateXmlFeedController{

    public function __construct(){
        $query = AnpProperties::where('published', '1')->get();
    
        $xml = new FluidXml(null, ['encoding'   => 'UTF-8' ]);

        $xml->addChild('MassUploadRequest', true, ['xmlns' => 'http://assis.ru/ws/api', 'timestamp' => time()]);

        foreach($query as $object){
            $arr = [
                'object' => [
                    '@externalId' => $object->id,
                    '@publish'  => 'true',
                    'request' => [
                        '@xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
                        '@xsi:type' => $this->getRequestType($object->transaction_type, $object->type_id),
                        'common' => [
                            '@name' => $object->title,
                            '@description' => $object->description,
                            '@ownership' => 'AGENT', 
                            '@price' => $object->price,
                            '@currency' => 'RUR',
                            '@square' => $object->total_space,
                            '@districtName' => $object->district,
                            '@comission' => (double)$object->commission,
                            '@commissionType' => 'PERCENT',
                            '@url' => $this->getObjectUrl($object->id, $object->alias),
                            'address' => [
                                '@dom' => $object->PremiseNumber,
                                'street' => [
                                    '@xsi:type' => 'SimpleStreetType', 
                                    '@name' => str_replace(', ' . $object->PremiseNumber, '', $object->address_search)
                                ],
                                'coordinates' => [
                                    '@lat' => $object->latitude,
                                    '@lon' => $object->longitude
                                ]
                            ],
                            'contactInfo' => [
                                '@name' => $this->getUserName($object->responsible_officer),
                                '@phone' => $this->getUserPhone($object->responsible_officer),
                                '@email' => $this->getUserEmail($object->responsible_officer),
                                '@company' => 'АН Матвеев Дом'
                            ]
                        ]
                    ],
                ]
            ];

            if($object->transaction_type == 'RENTING'){
                $arr['object']['request']['common']['@deposit'] = $object->deposit;
                $arr['object']['request']['common']['@priceType'] = $this->getPriceType($object->rate_frequency);
                $arr['object']['request']['common']['@period'] = 'LONG'; //пока так, т.к. в админке нет поля для выбора этого параметра: https://zipal.ru/developers/xml#Period
            }

            if(!empty($object->video)){
                $arr['object']['request']['common']['@video'] = $object->video;
            }

            $object_xml = $xml->addChild($arr);
        }


        
        header ('Content-type: text/xml');
        echo $xml;
    }

    /**
     * Метод для выбора типа объявления, на вход принимает значениея из БД,
     * возвращает тип объявления в системе zipal.ru
     * type_id: 
     *          1 - Жилая площадь (квартира/комната)
     *          2 - Дома и дачи
     *          3 - Коммерческая
     *          4 - Земельные участки
     *          5 - Гаражи и машиноместа
     *          6 - Недвижимость за рубежом
     * 
     * @param string $transaction_type
     * @param integer $type_id
     * @return string
     */
    private function getRequestType($transaction_type, $type_id){
        $transaction_type = \strtolower($transaction_type);
        $result = 'Undefinded';

        //продажа жилой площади
        if($transaction_type == 'selling' && $type_id === 1)
            $result = 'FlatSellRequestType';
        //аренда жилой площади
        elseif($transaction_type == 'renting' && $type_id === 1)
            $result = 'FlatRentRequestType';
        //продажа дома
        elseif($transaction_type == 'selling' && $type_id === 2)
            $result = 'HouseSellRequestType';
        //аренда дома
        elseif($transaction_type == 'renting' && $type_id === 2)
            $result = 'HouseRentRequestType';
        //продажа коммерческого помещения
        elseif($transaction_type == 'selling' && $type_id === 3)
            $result = 'BusinessSellRequestType';
        //аренда коммерческого помещения
        elseif($transaction_type == 'renting' && $type_id === 3)
            $result = 'BusinessRentRequestType';
        //продажа земли
        elseif($transaction_type == 'selling' && $type_id === 4)
            $result = 'LandSellRequestType';

        return $result;
    }

    /**
     * Метод принимает на вход значение периода оплаты из БД и конвертирует его в
     * значение системы zipal.ru
     * 
     * @param string $price_type
     * @return string
     */
    private function getPriceType($price_type){
        $price_type = \strtolower($price_type);

        $result = 'undefinded';

        switch($price_type){
            case 'monthly':
                $result = 'MONTH';
                break;
            case 'daily':
                $result = 'DAY';
                break;
        }

        return $result;
    }

    /**
     * Метод возвращает имя пользователя из БД по его ID
     * 
     * @param integer $user_id
     * @return string
     */
    private function getUserName($user_id){
        return Users::where('id', $user_id)->first()->name;
    }

    /**
     * Метод возвращает телефон пользователя из БД по его ID
     * 
     * @param integer $user_id
     * @return string
     */
    private function getUserPhone($user_id){
        return \str_replace('"', null, UserProfiles::where('user_id', $user_id)->where('profile_key', 'profile.phone')->first()->profile_value);
    }

    /**
     * Метод возвращает email пользователя из БД по его ID
     * 
     * @param integer $user_id
     * @return string
     */
    private function getUserEmail($user_id){
        return Users::where('id', $user_id)->first()->email;
    }

    /**
     * Метод генерирует ссылку на объект по его ID и алиасу. Если алиас не передан ет его из БД
     * 
     * @param integer $object_id
     * @param string $object_alias
     * @return string
     */
    private function getObjectUrl($object_id, $object_alias = null){
        if(empty($object_alias)){
            $object_alias = AnpProperties::where('id', $object_id)->first()->alias;
        }
        return 'https://www.matveevdom.ru/component/anp/'.(int)$object_id.'-'.trim($object_alias);
    }
}