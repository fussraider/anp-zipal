<?php
namespace Controllers;

use Models\AnpProperties;
use Models\Users;
use Models\UserProfiles;
use \FluidXml\FluidXml;
use \FluidXml\FluidNamespace;
use \App\App;

class GenerateXmlFeedController{


    /**
     * При создании экземпляра класса принимаем на вход объект приложения для работы с 
     * конфигом и прочими дочерними объектами класса приложения
     * 
     * @param \App\App $app
     * @return mixed
     */
    public function __construct(App $app){
        $this->app = $app;
        $query = AnpProperties::where('published', '1')->get();
    
        $xml = new FluidXml(null, ['encoding'   => 'UTF-8' ]);

        $xml->addChild('MassUploadRequest', true, ['xmlns' => 'http://assis.ru/ws/api', 'timestamp' => time()]);
        $root = $xml->query('//MassUploadRequest');

        foreach($query as $object){
            $obj_request_type = $this->getRequestType($object->transaction_type, $object->type_id);
            $obj = new FluidXml(null);
            $arr = [
                'object' => [
                    '@externalId' => $object->id,
                    '@publish'  => 'true',
                    'request' => [
                        '@xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
                        '@xsi:type' => $obj_request_type,
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
                                '@company' => $this->app->conf->agency_name
                            ]
                        ],
                        'specific' => []
                    ],
                ]
            ];

            
            $obj->addChild($arr);
            $common = $obj->query('//object//request//common');
            $specific = $obj->query('//object//request//specific');

            if($object->transaction_type == 'RENTING')
                $common->setAttribute([
                    'deposit' => ($object->deposit)*100,
                    'priceType' => $this->getPriceType($object->rate_frequency),
                    'period' => 'LONG' //пока так, т.к. в админке нет поля для выбора этого параметра: https://zipal.ru/developers/xml#Period
                ]);

            if(!empty($object->video))
                $common->setAttribute(['video' => $object->video]);

            if(!empty($object->images)){
                $images_arr = \json_decode($object->images, true);
                if($images_arr){
                    $count = 0;
                    foreach($images_arr as $img){
                        if($count < 20){
                            $common->addChild([
                                'photos' => [
                                    '@description' => (!empty($img['title']) ? $img['title'].' ' : null) . (!empty($img['description']) ? $img['description'] : null),
                                    '@url'  => $img['name']
                                ]
                            ]);
                        }else
                            break;
                        $count++;
                    }
                }
            }

            if($obj_request_type == 'FlatSellRequestType' || $obj_request_type == 'FlatRentRequestType'){
                $specific->setAttribute([
                        'type' => $this->getHouseType($object),
                        'roomsCount' => $object->rooms_offered ? $object->rooms_offered : $object->rooms,
                        'roomsCountTotal' => $object->rooms,
                        'separatedRoomsCount' => $object->rooms,
                        'floorNumber' => $object->floor,
                        'floorsNumber' => $object->floors_number,
                        'material' => $this->getMaterialType($object->material_id)
                ]);

                if($object->living_space > 0)
                    $specific->setAttribute(['usefulSquare' => $object->living_space]);
                
                if($object->kitchen_space > 0)
                    $specific->setAttribute(['kitchenSquare' => $object->kitchen_space]);

                if($object->celling > 0)
                    $specific->setAttribute(['ceilingHeight' => (int)($object->celling)*100]);
                
                if($object->condition_id)
                    $specific->setAttribute(['renovation' => $this->getRenovationType($object->condition_id)]);

                if($object->separateWcsCount != '0' || $object->combinedWcsCount != '0'){
                    $toilet = $this->getToiletType($object);

                    if($toilet)
                        $specific->setAttribute(['toilet' => $toilet]);
                }

                if($object->balconiesCount != '0' || $object->loggiasCount != '0'){
                    $balcony = $this->getBalcony($object);
                    if($balcony)
                        $specific->setAttribute(['balcony' => $balcony]);                        
                }

                if($object->heating_type != '0'){
                    $heating = false;
                    switch($object->heating_type){
                        case '1':
                            $heating = 'CENTRAL';
                            break;
                        case '2':
                            $heating = 'LOCAL';
                            break;
                    }

                    if($heating){
                        $specific->setAttribute(['heating' => $heating]);
                    }
                }

                if($object->transaction_type == 'SELLING'){
                    if($object->ipoteka == '1'){
                        $specific->setAttribute(['mortgage' => 'true']);
                    }

                    if(!empty($object->name_comp)){
                        $specific->setAttribute(['buildingName' => $object->name_comp]);
                    }

                    if(!empty($object->amenities)){
                        //отрезаем последний и первый символ тире в строке
                        $amentites = mb_substr($object->amenities, 0, -1);
                        $amentites = mb_substr($amentites, 1);
                        $feature_arr = explode('-', $amentites);

                        $features = [];
                        /**
                         * INTERNET - 7, TELEPHONE - 6, TV - 27, INVALIDS - ?, ELEVATOR - column, 
                         * SERVICE_ELEVATOR - column, CONCIERGE - 13, GUARDS = 22, RUBBISH_CHUTE - 12,
                         *  GAS - 10, FENCING - ?
                        */

                        if($object->buildingPassengerLiftsCount != '0'){
                            $features[] = 'ELEVATOR';
                        }
                        if($object->buildingCargoLiftsCount != '0'){
                            $features[] = 'SERVICE_ELEVATOR';
                        }
                        foreach($feature_arr as $f){
                            if($f == '3')
                                $specific->setAttribute(['credit' => 'true']);
                            if($f == '7')
                                $features[] = 'INTERNET';
                            if($f == '6')
                                $features[] = 'TELEPHONE';
                            if($f == '27')
                                $features[] = 'TV';
                            if($f == '13')
                                $features[] = 'CONCIERGE';
                            if($f == '22')
                                $features[] = 'GUARDS';
                            if($f == '12')
                                $features[] = 'RUBBISH_CHUTE';
                            if($f == '10')
                                $features[] = 'GAS';
                        }

                        if(count($features)){
                            foreach($features as $f){
                                $specific->addChild('feature', $f);
                            }
                        }
                    }
                }

                if($object->transaction_type == 'RENTING'){
                    if(!empty($object->amenities)){
                        //отрезаем последний и первый символ тире в строке
                        $amentites = mb_substr($object->amenities, 0, -1);
                        $amentites = mb_substr($amentites, 1);
                        $feature_arr = explode('-', $amentites);

                        $features = [];
                        /**
                         * INTERNET - 7, TELEPHONE - 6, TV - 27, INVALIDS - ?, ELEVATOR - column, 
                         * SERVICE_ELEVATOR - column, CONCIERGE - 13, GUARDS = 22, RUBBISH_CHUTE - 12, 
                         * GAS - 10, FENCING - ?, WASHING_MACHINE - 31, FURNITURE - 41,30, FRIDGE - 32, 
                         * WIRELESS_INTERNET - 7, WITH_PETS - 36, WITH_CHILDREN - 35, CONDITIONER - 32
                        */

                        if($object->buildingPassengerLiftsCount != '0'){
                            $features[] = 'ELEVATOR';
                        }
                        if($object->buildingCargoLiftsCount != '0'){
                            $features[] = 'SERVICE_ELEVATOR';
                        }
                        foreach($feature_arr as $f){
                            if($f == '31')
                                $features[] = 'WASHING_MACHINE';
                            if($f == '41' || $f == '30')
                                $features[] = 'FURNITURE';
                            if($f == '32')
                                $features[] = 'FRIDGE';
                            if($f == '7')
                                $features[] = 'WIRELESS_INTERNET';
                            if($f == '36')
                                $features[] = 'WITH_PETS';
                            if($f == '35')
                                $features[] = 'WITH_CHILDREN';
                            if($f == '32')
                                $features[] = 'CONDITIONER';
                            if($f == '7')
                                $features[] = 'INTERNET';
                            if($f == '6')
                                $features[] = 'TELEPHONE';
                            if($f == '27')
                                $features[] = 'TV';
                            if($f == '13')
                                $features[] = 'CONCIERGE';
                            if($f == '22')
                                $features[] = 'GUARDS';
                            if($f == '12')
                                $features[] = 'RUBBISH_CHUTE';
                            if($f == '10')
                                $features[] = 'GAS';
                        }

                        if(count($features)){
                            if(count($features)){
                                foreach($features as $f){
                                    $specific->addChild('feature', $f);
                                }
                            }
                        }
                    }
                }

            }
            elseif($obj_request_type == 'HouseSellRequestType' || $obj_request_type == 'HouseRentRequestType'){
                $specific->setAttribute([
                    'distance' => (int)$object->DistanceToCity,
                    'burden' => $this->isBurden($object->amenities),
                    'electricity' => $this->isElectricity($object->amenities),
                    'gas' => $this->isGas($object->amenities),
                    'plumbing' => $this->plumping($object->water_on_item),
                    'sewerage' => 'CESSPOOL',   // отсутствует поле в БД для этого параметра, есть только флаг наличия
                    'relief' => 'FLAT' //отсутствует поле в БД для этого параметра
                ]);
            }

            $root->addChild($obj);
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

    /**
     * Метод возвращает тип квартиры, в соответствии со спецификами zipal.ru: 
     * FLAT         -   Типовая
     * STUDIO       -  	Студия
     * ELITE        -  	Элитная
     * PENTHOUSE    -   Пентхаус
     * APARTMENTS   -   Апартаменты
     * HOSTEL       -  	Общежитие
     * MALOSEMEIKA  -   Малосемейка
     * GOSTINKA     -  	Гостинка
     * HRUSHEVKA    -   Хрущевка
     * STALINKA     -  	Сталинка
     * 
     * На вход принимается объект объявления.
     * Значения в БД сайта:
     *      1   - Улучшеная планировка
     *      2   - Типовая планировка
     *      3   - Хрущевка
     *      4   - Полногабаритная
     *      5   - Общежитие
     *      6   - Студия
     *      7   - Эконом класс
     *      8   - Элитная
     *      9   - Малосемейка
     *      10  - Ленинградская
     *      11  - 2х уровневая
     *      12  - Гостинка
     *      13  - Малоэтажка
     *      14  - Новая
     *      15  - моспроект
     * 
     * @param AnpProperties $object
     * @return string
     */
    private function getHouseType(AnpProperties $object){
        $result = 'undefinded';

        if($object->pent == '1')
            return 'PENTHOUSE';
        elseif($object->apart == '1')
            return 'APARTMENTS';
        else{
            switch($object->housetype_id){
                case '1':
                case '2':
                    $result = 'FLAT';
                    break;
                case '3':
                    $result = 'HRUSHEVKA';
                    break;
                case '4':
                    $result = '??'; //Полногабаритная
                    break;
                case '5':
                    $result = 'HOSTEL';
                    break;
                case '6':
                    $result = 'STUDIO';
                    break;
                case '7':
                    $result = '??'; //Эконом класс
                    break;
                case '8':
                    $result = 'ELITE';
                    break;
                case '9':
                    $result = 'MALOSEMEIKA';
                    break;
                case '10':
                    $result = '??'; //Ленинградская
                    break;
                case '11':
                    $result = '??';  // 2х уровневая
                    break;
                case '12':
                    $result = 'GOSTINKA';
                    break;
                case '13':
                    $result = '??'; //Малоэтажка
                    break;
                case '14':
                    $result = '??'; //Новая
                    break;
                case '15':
                    $result = '??'; //моспроект
                    break;
            }
        }
        
        return $result;
    }

    /**
     * Метод возвращает тип материала стен в соответствии с классификацией zipal.ru:
     * 
     * BRICK	    -   Кирпич
     * CONCRETE	    -   Железобетон
     * PANEL	    -   Панель
     * MONOLITH	    -   Монолит
     * MONOBRICK    -   Монолит-кирпич
     * TIMBER	    -   Брус
     * WOOD	        -   Дерево
     * BLOCK	    -   Блок
     * OLD_FUND	    -   Старый фонд
     * 
     * На вход принимается значение material_id из объекта объявления:
     * 1    - 	панель
     * 2    - 	кирпич
     * 3    - 	монолит
     * 4    - 	кирпично-монолитный
     * 5    - 	блочный
     * 6    - 	деревянный
     * 7    - 	сталинский
     * 8    - 	каркасно-щитовой
     * 9    - 	старый фонд
     * 
     * @param integer $material_id
     * @return string
     */
    private function getMaterialType($material_id){
        $array = [
            '1' => 'PANEL',
            '2' => 'BRICK',
            '3' => 'MONOLITH', 
            '4' => 'MONOBRICK',
            '5' => 'BLOCK',
            '6' => 'WOOD',
            '7' => '??', //сталинский
            '8' => '??', //каркасно-щитовой
            '9' => 'OLD_FUND'
        ];


        return isset($array[$material_id]) ? $array[$material_id] : 'undefinded';
    }

    /**
     * Метод возвращает тип ремонта в соответствии с классификацией zipal.ru:
     * COSMETIC -   Косметический
     * EURO	    -   Евростандарт
     * NONE	    -   Под отделку
     * AUTHOR   -   Авторский
     * 
     * На вход принимается значение condition_id из БД олбъекта
     * 1    -   косметический
     * 2    -   евро
     * 3    -   дизайнерский
     * 4    -   отсутствует
     * 5    -   капитальный
     * 
     * @param integer $condition_id
     * @return string
     */
    private function getRenovationType($condition_id){
        $result = 'undefinded';
        switch($condition_id){
            case '1':
                $result = 'COSMETIC';
                break;
            case '2':
            case '5':
                $result = 'EURO';
                break;
            case '3':
                $result = 'AUTHOR';
                break;
            case '4':
                $result = 'NONE';
        }

        return $result;
    }

    /**
     * Метод возвращает тип санузла в соответствии со спецификацией zipal.ru
     * На вход принимает объект объявления
     * 
     * @param AnpProperties $object
     * @return string;
     */
    private function getToiletType(AnpProperties $object){
        $wcs_count = (int)$object->separateWcsCount + (int)$object->combinedWcsCount;

        $toilet = false;
        if($wcs_count > 1){
            $toilet = $wcs_count == 2 ? 'TWO' : $wcs_count == 3 ? 'THREE' : $wcs_count == 4 ? 'FOUR' : false;
        }

        if($toilet === false && $object->separateWcsCount != '0')
            $toilet = 'SEPARATED';
        elseif($toilet === false && $object->combinedWcsCount != '0')
            $toilet = 'JOINED';
        return $toilet;
    }

    /**
     * Метод возвращает тип балкона в соответствии со спецификацией zipal.ru
     * На вход принимает объект объявления. Если в объявлении есть и балкон и лоджия - метод возвращает false
     * 
     * @param AnpProperties $object
     * @return mixed
     */
    private function getBalcony(AnpProperties $object){
        if($object->balconiesCount != '0' && $object->loggiasCount != '0')
            return false;
        else{
            if($object->balconiesCount != '0')
                return 'BALCONY';
            elseif($object->loggiasCount != '0')
                return 'LOGGIA';
            else
                return false;
        }
    }

    /**
     * Метод возвращает значение для параметра "burden" проверяя наличие флага "Обременение"
     * число 5 в поле "amenities" БД. На вход принимает строку поля "amenities"
     * 
     * @param string $aments
     * @return boolean
     */
    private function isBurden($aments){
        if(!empty($aments)){
            //отрезаем последний и первый символ тире в строке
            $amentites = mb_substr($object->amenities, 0, -1);
            $amentites = mb_substr($amentites, 1);
            $feature_arr = explode('-', $amentites);

            if(in_array('5', $feature_arr))
                return 'true';
            else
                return 'false';
        }
        else
            return 'false';
    }

    /**
     * Метод возвращает значение для параметра "electricity" проверяя наличие флага "Электричество"
     * число 9 в поле "amenities" БД. На вход принимает строку поля "amenities"
     * 
     * @param string $aments
     * @return string
     */
    private function isElectricity($aments){
        if(!empty($aments)){
            //отрезаем последний и первый символ тире в строке
            $amentites = mb_substr($object->amenities, 0, -1);
            $amentites = mb_substr($amentites, 1);
            $feature_arr = explode('-', $amentites);

            if(in_array('9', $feature_arr))
                return 'YES';
            else
                return 'NO';
        }
        else
            return 'NO';
    }

    /**
     * Метод возвращает значение для параметра "gas" проверяя наличие флага "газ"
     * число 10 в поле "amenities" БД. На вход принимает строку поля "amenities"
     * 
     * @param string $aments
     * @return string
     */
    private function isGas($aments){
        if(!empty($aments)){
            //отрезаем последний и первый символ тире в строке
            $amentites = mb_substr($object->amenities, 0, -1);
            $amentites = mb_substr($amentites, 1);
            $feature_arr = explode('-', $amentites);

            if(in_array('10', $feature_arr))
                return 'YES';
            else
                return 'NO';
        }
        else
            return 'NO';
    }

    /**
     * Метод возвращает значение "plumping" по спецификации zipal.ru по полю "water_on_item" из БД объекта,
     * на вход принимает значение поля "water_on_item"
     * 
     * NO	    Отсутствует
     * CENTRAL	Центральное
     * HOLE	    Скважина
     * WELL	    Колодец/колонка
     * BORDER	По границе
     * 
     * @param integer $water
     * @return string
     */
    private function plumping($water){
        switch($water){
            case '0':
            case '4':
                $res = 'NO';
                break;
            case '1':
                $res = 'CENTRAL';
                break;
            case '2':
                $res = 'HOLE';
                break;
            case '3':
                $res = 'WELL';
                break;
            case '5':
                $res = 'BORDER';
                break;
            default:
                $res = 'NO';
                break;
        }

        return $res;
    }

}